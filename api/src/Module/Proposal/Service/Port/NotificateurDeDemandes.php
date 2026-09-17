<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Proposal\Entity\ChangeRequest;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * POINT DE BRANCHEMENT DU MODULE DE NOTIFICATION — à lire avant de l'écrire.
 *
 * `Proposal` ne sait pas notifier et n'a pas à l'apprendre : les notifications sont
 * une table et un écran de `Core` (`core_notification`, § 2.5 du modèle de données).
 * Cette interface est **la totalité** de ce que `Proposal` demande au module de
 * notification. Elle est volontairement minuscule : cinq événements, aucun retour,
 * aucune exception attendue.
 *
 * **Ce que l'agent « notifications » a à faire**, et rien d'autre :
 *
 * 1. écrire une classe de `Core` qui implémente cette interface et qui insère un
 *    `core_notification` par destinataire, avec le `NotificationKind` correspondant :
 *    `demande_soumise`, `demande_validee`, `demande_refusee`, `demande_caduque` —
 *    les quatre valeurs existent déjà dans `Core\Enum\NotificationKind`. Le cinquième
 *    événement, {@see demandeCaduqueParDependance()}, **réutilise `demande_caduque`** :
 *    c'est la même chose qui arrive à l'auteur, pour une raison qu'il ne pouvait pas
 *    prévoir. Seul le corps du message change, et un cas d'énumération de plus
 *    coûterait une migration pour une phrase ;
 * 2. faire pointer sur elle l'alias déclaré dans `config/services/proposal.yaml`.
 *    Ce fichier ne dit pas sur quoi il pointe à cet instant : une description d'état
 *    se périme sans prévenir, et `debug:container` répond mieux que n'importe quel
 *    commentaire.
 *
 * **Trois règles que l'implémentation doit respecter, et qui ne se devinent pas.**
 *
 * - **Le titre et le corps se composent à l'écriture, pas à la lecture** (§ 16.2 du
 *   contrat d'API), en appliquant le cercle de visibilité du **destinataire**. Une
 *   notification se relit des mois plus tard, quand le cercle a pu changer ; une
 *   composition paresseuse rouvrirait la question à chaque affichage, et la première
 *   fois qu'on l'oublierait, un membre lirait le nom d'une personne vivante hors de
 *   son cercle.
 * - **Le message de refus part tel quel à l'auteur.** C'est une exigence du § 6.3 du
 *   dossier de mission : « le message est obligatoire au refus et parvient à l'auteur
 *   dans sa notification ». Le tronquer ou le résumer viderait la règle de son sens.
 * - **Ces méthodes sont appelées après le `commit`** de la transaction de validation,
 *   jamais dedans. Une notification écrite dans la transaction et annulée par un
 *   `ROLLBACK` laisse l'auteur avec un courriel pour une validation qui n'a pas eu
 *   lieu ; une notification qui échoue dans la transaction annulerait une validation
 *   parfaitement valide. **Une implémentation ne doit donc jamais lever** : elle
 *   journalise et continue.
 *
 * Les destinataires ne figurent pas dans la signature parce qu'ils se déduisent de la
 * demande sans ambiguïté : l'auteur est `ChangeRequest::getAuthorUserId()`, et les
 * administrateurs sont ceux que `Core` connaît. `Proposal` n'a pas à savoir qui est
 * administrateur — il ne tient pas la liste des comptes.
 */
interface NotificateurDeDemandes
{
    /**
     * Une demande vient d'être soumise : elle entre dans la file d'attente.
     *
     * Destinataires : **les administrateurs**. Pas l'auteur — il vient de cliquer.
     */
    public function demandeSoumise(ChangeRequest $demande): void;

    /**
     * Une demande a été validée et appliquée à l'arbre.
     *
     * Destinataire : **l'auteur**.
     *
     * @param bool $rejouee `true` quand la demande a été **rejouée automatiquement**
     *                      sur une version plus récente (§ 6.8 du contrat d'API). Le
     *                      dire à l'auteur n'est pas un détail : c'est une écriture
     *                      qu'aucun humain n'a réexaminée champ par champ, et elle
     *                      doit se voir.
     */
    public function demandeValidee(ChangeRequest $demande, bool $rejouee): void;

    /**
     * Une demande a été refusée, avec son motif.
     *
     * Destinataire : **l'auteur**. Le motif est obligatoire — il n'est jamais vide ici,
     * la contrainte `ck_prp_request_motif_refus` l'interdit en base.
     */
    public function demandeRefusee(ChangeRequest $demande, string $message): void;

    /**
     * Une demande est devenue caduque : la donnée a changé sous ses pieds.
     *
     * Destinataire : **l'auteur**, avec le diff de ce qui a bougé et un lien
     * « refaire cette demande » (`GET /api/demandes/{id}/refaire`). Une caducité sans
     * ce lien est une contribution perdue, et un membre dont la première contribution
     * se perd n'en dépose pas de seconde (§ 9.6 du modèle de données).
     *
     * `caduque` **n'est pas** `refusee` : personne n'a refusé, le monde a bougé. Deux
     * notifications distinctes, pas un refus déguisé.
     *
     * @param list<array{field: string, propose: mixed, valeurALaSoumission: mixed, valeurCourante: mixed}> $champsEnConflit
     *                                                                                                                       Les champs dont l'ancienne valeur ne correspond plus. Forme identique à
     *                                                                                                                       `erreur.details.champs` du § 6.8 du contrat d'API, pour que la notification
     *                                                                                                                       et l'écran disent exactement la même chose.
     */
    public function demandeCaduque(ChangeRequest $demande, array $champsEnConflit): void;

    /**
     * Une demande est devenue caduque parce que **celle sur laquelle elle s'appuyait**
     * a été refusée, retirée ou rendue caduque — ou parce que sa fiche a disparu.
     *
     * Destinataire : **l'auteur**, avec le même lien « refaire cette demande ».
     *
     * ── Pourquoi ce n'est pas {@see demandeCaduque()} avec un tableau vide ──────────
     *
     * Parce que le diff de `demandeCaduque()` raconte « vous proposiez X pour ce champ,
     * la fiche porte désormais Y », et qu'ici **rien n'a bougé sur aucune fiche** : la
     * personne que l'auteur désignait n'existera simplement jamais. Passer une liste de
     * champs vide produirait une notification au corps `null` — « votre demande est
     * devenue caduque », sans un mot de plus —, c'est-à-dire exactement le message qui
     * fait qu'un membre ne dépose pas de seconde contribution.
     *
     * @param list<array{draftPersonId: int, demandeId: int|null, libelle: string, etat: string}> $dependances
     *                                                                                                         Les fiches proposées ailleurs que cette demande désignait, et l'état de
     *                                                                                                         chacune. Forme identique à `erreur.details.dependances` du § 6.8, pour que
     *                                                                                                         la notification et l'écran disent exactement la même chose.
     */
    public function demandeCaduqueParDependance(ChangeRequest $demande, array $dependances): void;
}
