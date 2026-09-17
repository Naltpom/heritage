<?php

declare(strict_types=1);

namespace App\Module\Proposal\Exception;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\RequestStatus;

/**
 * Les `409` du module.
 *
 * ── Les deux qu'il ne faut surtout pas confondre ─────────────────────────────────
 *
 * `demande_caduque` et `verrou_concurrent` sont toutes deux des `409`, et elles
 * donnent des **conseils opposés** (§ 6.8 et § 15.4 du contrat d'API) :
 *
 * |                              | `verrou_concurrent`              | `demande_caduque`                 |
 * |------------------------------|----------------------------------|-----------------------------------|
 * | Verrou en cause              | n° 1, `#[ORM\Version]`           | n° 2, `base_version` + `old_value`|
 * | Fenêtre                      | l'instant de la transaction      | les trois jours dépôt → validation|
 * | Écrit dans `tree_*`          | non                              | non                               |
 * | Écrit dans `prp_change_request` | **non**, reste `en_attente`   | **oui**, passe `caduque`          |
 * | Notification à l'auteur      | non                              | **oui**, avec le diff             |
 * | `details.rejouable`          | `true`                           | `false`                           |
 * | Ce que le front propose      | **« Réessayer »**                | **« Refaire cette demande »**     |
 *
 * Un front écrit contre « 409 ⇒ bouton Réessayer » — ce que tout le monde écrit — fait
 * **boucler l'administrateur sur une demande morte**. D'où deux codes distincts, **et**
 * un booléen `details.rejouable` pour que le front n'ait pas à connaître la liste des
 * codes. C'est la trouvaille la plus coûteuse du test des trois routes (§ 17.2).
 *
 * > **La version qui bouge ne condamne pas la demande ; la valeur qui bouge, si.**
 */
final class Conflit extends ErreurDeDemande
{
    /** @param array<string, mixed>|null $details */
    private function __construct(string $code, string $message, ?array $details = null)
    {
        parent::__construct($code, $message, 409, null, $details);
    }

    /**
     * **Une `409` qui écrit.** Rien n'est appliqué à l'arbre — « on n'écrase jamais » —
     * mais la demande passe en `caduque`, l'auteur est notifié et le journal reçoit
     * `demande_caduque`. Le validateur a donc déjà commité ce changement de statut
     * quand cette exception est levée : la lever sans l'avoir fait rendrait le code
     * menteur, et le front rechargerait une demande toujours dans la file.
     *
     * @param list<array{field: string, propose: mixed, valeurALaSoumission: mixed, valeurCourante: mixed}> $champs
     */
    public static function demandeCaduque(int $demandeId, array $champs): self
    {
        return new self(
            'demande_caduque',
            'La demande est devenue caduque : la fiche a changé depuis son dépôt.',
            [
                'demandeId' => $demandeId,
                'statut' => RequestStatus::Caduque->value,
                'rejouable' => false,
                'champs' => $champs,
            ],
        );
    }

    /**
     * La cible a été supprimée ou fusionnée. C'est une **caducité**, pas un refus :
     * personne n'a refusé, le monde a bougé.
     *
     * Ce cas est normalement traité à l'événement publié par `Tree` (ADR 0002,
     * compensation 2), avant même qu'on ouvre la demande ; le contrôle à la validation
     * est la **seconde barrière**, et il existe parce qu'un abonné qui rate un
     * événement laisserait sinon une demande pointer vers le vide.
     */
    public static function cibleDisparue(int $demandeId, SubjectKind $kind, int $id, ?string $libelle): self
    {
        return new self(
            'cible_disparue',
            'La fiche visée par cette demande n\'existe plus.',
            [
                'demandeId' => $demandeId,
                'statut' => RequestStatus::Caduque->value,
                'rejouable' => false,
                'cible' => ['kind' => $kind->value, 'id' => $id, 'label' => $libelle],
            ],
        );
    }

    /**
     * **Une `409` qui n'écrit rien du tout** — pas même le statut.
     *
     * Cette demande désigne une fiche proposée par une autre demande, et cette autre
     * demande n'est pas tranchée : il y a un **ordre** à respecter, pas une erreur à
     * signaler. La demande reste `en_attente`, l'auteur n'est pas notifié — il n'a rien
     * à corriger —, et l'administrateur reçoit la liste des demandes à valider d'abord.
     *
     * `rejouable` vaut `true`, comme pour `verrou_concurrent` et pour la même raison :
     * le geste refusé redeviendra possible tel quel. Ce qui change, c'est **quand** —
     * d'où les dépendances nommées dans `details`, pour que le front dise « validez
     * d'abord la demande n° 12 » plutôt que « réessayez » sans dire quand.
     *
     * @param list<array{draftPersonId: int, demandeId: int|null, libelle: string, etat: string}> $dependances
     */
    public static function dependanceEnAttente(int $demandeId, array $dependances): self
    {
        return new self(
            'dependance_en_attente',
            'Cette demande s\'appuie sur une personne proposée par une demande encore en attente : validez celle-là d\'abord.',
            [
                'demandeId' => $demandeId,
                'statut' => RequestStatus::EnAttente->value,
                'rejouable' => true,
                'dependances' => $dependances,
            ],
        );
    }

    /**
     * La demande sur laquelle celle-ci s'appuyait a été refusée, retirée ou rendue
     * caduque — ou sa fiche a disparu. **La personne désignée ne naîtra jamais.**
     *
     * C'est une caducité de la même nature que `cible_disparue`, et elle écrit comme
     * elle : la demande passe en `caduque`, l'auteur est notifié, et le bouton
     * « refaire » lui permet de désigner quelqu'un d'autre. Confondre les deux codes
     * serait tentant — les deux disent « la référence ne vaut plus » — mais l'auteur
     * n'a pas le même geste à faire : une cible disparue se remplace par une autre
     * personne de l'arbre, une dépendance morte se remplace par une autre proposition,
     * ou attend d'être reproposée.
     *
     * @param list<array{draftPersonId: int, demandeId: int|null, libelle: string, etat: string}> $dependances
     */
    public static function dependanceMorte(int $demandeId, array $dependances): self
    {
        return new self(
            'dependance_morte',
            'La demande sur laquelle celle-ci s\'appuyait n\'aboutira pas : la personne désignée n\'existera jamais.',
            [
                'demandeId' => $demandeId,
                'statut' => RequestStatus::Caduque->value,
                'rejouable' => false,
                'dependances' => $dependances,
            ],
        );
    }

    /**
     * L'`OptimisticLockException` du verrou n° 1 : deux transactions **concurrentes**
     * — deux administrateurs, ou une validation qui croise un import. **Rien n'a été
     * écrit, la demande reste `en_attente`, et réessayer est la bonne action**.
     */
    public static function verrouConcurrent(int $demandeId): self
    {
        return new self(
            'verrou_concurrent',
            'Une autre écriture a touché cette fiche pendant la validation. Rien n\'a été écrit.',
            [
                'demandeId' => $demandeId,
                'statut' => RequestStatus::EnAttente->value,
                'rejouable' => true,
            ],
        );
    }

    /** On ne valide pas deux fois : un double clic ne doit pas produire deux révisions. */
    public static function dejaDecidee(RequestStatus $statut): self
    {
        return new self(
            'demande_deja_decidee',
            'Cette demande a déjà été tranchée.',
            ['statut' => $statut->value],
        );
    }

    public static function dejaSoumise(RequestStatus $statut): self
    {
        return new self(
            'demande_deja_soumise',
            'Cette demande a déjà été soumise.',
            ['statut' => $statut->value],
        );
    }

    /** `refaire` n'a de sens que sur une demande morte : `caduque` ou `refusee`. */
    public static function nonRefaisable(RequestStatus $statut): self
    {
        return new self(
            'demande_non_refaisable',
            'Seule une demande caduque ou refusée peut être refaite.',
            ['statut' => $statut->value],
        );
    }

    /**
     * Le § 6.2 du contrat d'API : l'`oldValue` ne correspond déjà plus à la base **au
     * moment du dépôt**. La fiche a changé pendant que le formulaire était ouvert ; il
     * faut la rouvrir, pas déposer une demande qu'on sait déjà fausse.
     *
     * @param array<string, mixed> $details
     */
    public static function valeurDejaChangee(array $details): self
    {
        return new self(
            'conflit',
            'La fiche a changé pendant la saisie : rouvrez-la avant de proposer.',
            $details,
        );
    }

    /**
     * Point 9 des points signalés (§ 18) : un administrateur ne peut pas écrire sous
     * une identité empruntée. Une `prp_revision` porterait l'administrateur pendant que
     * l'écran montre autre chose.
     */
    public static function vueCommeMembreActive(int $membreUserId): self
    {
        return new self(
            'vue_comme_membre_active',
            'Quittez la vue « comme un membre » avant d\'écrire.',
            ['memberId' => $membreUserId, 'memberLabel' => null],
        );
    }
}
