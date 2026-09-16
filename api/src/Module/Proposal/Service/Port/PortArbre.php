<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * POINT DE BRANCHEMENT DU MODULE `Tree` — à lire avant d'écrire ses services.
 *
 * `Proposal` doit lire et écrire des lignes `tree_person`, `tree_family` et
 * `tree_citation`, et il n'a le droit de faire ni l'un ni l'autre directement :
 *
 * - **règle 5 de l'ADR 0002** : « valider une proposition écrit dans `Tree` **en
 *   appelant un service de `Tree`**, pas en manipulant `Tree\Person` depuis
 *   `Proposal` » ;
 * - **règle 3 de l'ADR 0002**, vérifiée en intégration continue : un `use
 *   App\Module\Tree\…` dans `Proposal` est une infraction. Seul `Core` est en liste
 *   blanche.
 *
 * D'où cette interface, **déclarée ici et implémentée dans `Tree`**. Elle est le seul
 * chemin par lequel une validation touche l'arbre, et ce n'est pas une contrainte de
 * façade : c'est `Tree` qui sait normaliser un couple selon le sexe, attribuer un
 * `gedcom_xref` après le `flush`, recalculer `display_name`, `search_key`,
 * `best_quay`, `branch` et `generation` — écrire ces règles une seconde fois dans
 * `Proposal` est exactement le mécanisme par lequel une frontière se dissout.
 *
 * ── Où elle est branchée ─────────────────────────────────────────────────────────
 *
 * L'alias vit dans `config/services/proposal.yaml` ; **ce fichier ne dit pas sur quoi
 * il pointe aujourd'hui**, et c'est délibéré. Un commentaire qui décrit l'état du
 * câblage devient faux le jour où quelqu'un le change, sans que rien ne le signale — un
 * commentaire sert justement à dispenser d'aller voir, donc personne ne va voir. La
 * seule chose durable à écrire ici, c'est **où regarder** : `debug:container` tranche.
 *
 * {@see ArbreNonBranche} est le repli. Il **lève** à la première écriture, pour qu'une
 * validation ne réussisse jamais en silence sans rien écrire.
 *
 * ── Le contrat des valeurs, à respecter au caractère près ────────────────────────
 *
 * Les valeurs qui traversent ce port sont des **valeurs PHP**, pas du JSON : la
 * conversion depuis et vers la forme d'op (§ 5.2 du modèle) est faite par
 * `Proposal\Service\NormaliseurDeValeur`, parce que c'est `Proposal` qui a défini
 * cette forme. La correspondance, par `ValueKind` :
 *
 * | `ValueKind`       | Type PHP au bord du port                                  |
 * |-------------------|-----------------------------------------------------------|
 * | `texte`           | `string` \| `null`                                        |
 * | `entier`          | `int` \| `null`                                           |
 * | `booleen`         | `bool`                                                    |
 * | `enum`            | **`string` \| `null`** — la *valeur* de l'énumération      |
 * | `date_incertaine` | `Core\ValueObject\UncertainDate`, jamais `null`           |
 *
 * **`enum` traverse en chaîne et non en objet, délibérément** : `CitationQuality` est
 * une énumération de `Tree`, que `Proposal` ne peut pas nommer sans enfreindre la
 * règle 3. C'est l'implémentation, côté `Tree`, qui convertit la chaîne en cas
 * d'énumération — et qui lève si la chaîne n'en est pas une.
 *
 * ── Les deux règles de transaction ───────────────────────────────────────────────
 *
 * - **`appliquerChamps()` est appelée à l'intérieur de la transaction du validateur.**
 *   Elle doit donc `flush()` — pour que `#[ORM\Version]` s'exerce et que la nouvelle
 *   version soit connue — mais **ne jamais** ouvrir, valider ni annuler de transaction
 *   elle-même.
 * - **Elle doit laisser remonter `Doctrine\ORM\OptimisticLockException`.** C'est elle,
 *   et elle seule, qui devient le `409 verrou_concurrent` du § 6.8 du contrat d'API —
 *   celui qui dit « réessayer ». L'attraper et la traduire dans `Tree` ferait perdre
 *   la distinction avec `demande_caduque`, qui dit exactement l'inverse.
 */
interface PortArbre
{
    /**
     * Lit l'état courant d'une ligne visée par une demande.
     *
     * Rend `null` **quand la ligne n'existe plus** — supprimée ou fusionnée. Ce n'est
     * pas une erreur : c'est le cas `cible_disparue` du § 6.8, qui est une caducité et
     * non un refus. Une implémentation qui lèverait ici ferait passer pour une panne
     * un événement prévu.
     *
     * @param SubjectKind $kind `personne`, `famille` ou `citation` — les trois tables
     *                          qui portent un `#[ORM\Version]` (§ 9.6 du modèle)
     */
    public function chargerCible(SubjectKind $kind, int $id): ?EtatCible;

    /**
     * Applique **tous** les champs d'une même ligne, en un seul appel.
     *
     * Un appel par ligne et non par champ : c'est ce qui permet à `Tree` de recalculer
     * ses colonnes dérivées une fois, et c'est ce qui rend l'écriture atomique au
     * niveau où le verrou optimiste l'est — la ligne.
     *
     * @param array<string, mixed> $valeurs `field` (nom de propriété Doctrine) => valeur PHP.
     *                                      Les noms sont bornés par
     *                                      `Proposal\Service\SurfaceEditable` : jamais
     *                                      une colonne dérivée, jamais `version`.
     *
     * @throws \Doctrine\ORM\OptimisticLockException si une transaction concurrente a
     *                                               touché la ligne — à laisser remonter telle quelle
     */
    public function appliquerChamps(SubjectKind $kind, int $id, array $valeurs): EcritureAppliquee;

    /**
     * Insère une personne proposée, et rend son identifiant réel.
     *
     * C'est la passe 2 du § 9.5, et c'est `Tree` qui la fait de bout en bout : il copie
     * les colonnes, compose `display_name` et `search_key`, et pose lui-même
     * `gedcom_xref` **après le `flush`** — un identifiant GEDCOM se dérive de la clé
     * primaire, qui n'existe pas avant (§ 9.8).
     *
     * Appelée dans la transaction du validateur, jamais ailleurs : une personne créée
     * sans la famille qui la relie flotterait hors de l'arbre, et c'est exactement ce
     * que « tout ou rien » empêche.
     */
    public function creerPersonne(PersonneAcreer $fiche): LigneCreee;

    /**
     * Trouve **ou** crée la famille d'un couple — passe 4 du § 9.5.
     *
     * C'est `Tree` qui normalise le couple en `(husband_id, wife_id)` selon le sexe, qui
     * place une personne de sexe inconnu dans l'emplacement libre, qui interroge
     * `WHERE husband_id IS NOT DISTINCT FROM ? AND wife_id IS NOT DISTINCT FROM ?` et
     * qui connaît `uniq_tree_family_couple`. Réécrire ces règles ici serait le mécanisme
     * exact par lequel une frontière se dissout.
     *
     * **L'ordre des deux arguments n'est pas significatif** : c'est le sexe qui range,
     * pas la position. Un seul des deux peut être `null` — une famille d'un parent seul
     * est légitime, une famille sans personne ne l'est pas.
     *
     * `LigneCreee::$creee` dit si elle vient d'être insérée : une famille retrouvée ne
     * produit pas de révision.
     */
    public function trouverOuCreerFamille(?int $personneA, ?int $personneB): LigneCreee;

    /**
     * Porte les dates d'union sur une famille — passe 5 du § 9.5.
     *
     * **Toutes facultatives : une union sans date reste une union** (§ 6.4 du dossier).
     * Une date inconnue s'exprime par un `UncertainDate::inconnue()`, jamais par
     * l'absence d'appel : le demandeur peut vouloir effacer une date qu'il sait fausse.
     */
    public function porterUnion(int $familleId, UncertainDate $mariage, bool $divorce, UncertainDate $rupture): void;

    /**
     * Rattache un enfant à une famille — passe 5 du § 9.5.
     *
     * `$rangDansLaFratrie` vaut `null` quand le demandeur ne le connaît pas : c'est
     * alors à `Tree` de placer l'enfant à la fin, parce que lui seul sait combien la
     * famille en compte déjà.
     *
     * Rattacher deux fois le même enfant à la même famille ne doit **pas** lever : la
     * validation peut rejouer un lien que l'arbre porte déjà, et une demande refusée
     * pour cette raison serait refusée pour avoir eu raison.
     */
    public function rattacherEnfant(int $familleId, int $personneId, ?int $rangDansLaFratrie): void;

    /**
     * L'empreinte de l'arbre (§ 2.3 du modèle), recalculée depuis la base.
     *
     * Appelée **après** les écritures et **dans** la même transaction, pour être
     * reportée dans `prp_revision.tree_fingerprint_after`. Il n'y a rien à
     * incrémenter : les écritures l'ont déjà changée, et tous les caches qui
     * portaient l'ancienne sont déjà inatteignables. C'est aussi ce qui invalide
     * l'empreinte de l'arbre demandée au § 6.3 du dossier de mission — sans geste.
     */
    public function empreinteDeLArbre(): string;
}
