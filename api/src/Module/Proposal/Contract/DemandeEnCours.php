<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Une demande, telle qu'un module **extérieur** la voit.
 *
 * ── Pourquoi ce n'est pas `Entity\ChangeRequest` ────────────────────────────────
 *
 * Parce qu'une signature qui nomme une entité n'est pas un contrat : elle oblige son
 * implémenteur à importer le modèle du déclarant, donc à connaître ses colonnes, ses
 * relations et son cycle de vie Doctrine. Un module qui joint un média n'a besoin que
 * de l'identifiant de la demande et de sa nature — il recevait jusqu'ici l'entité
 * entière, et avec elle le droit d'appeler `setStatus()`, `decider()` ou `soumettre()`
 * sur une demande dont il n'est pas responsable.
 *
 * *(Arbitrage HH, ADR 0002 : « un contrat dont la signature nomme une `Entity/` ou un
 * `Repository/` de son déclarant n'est pas un contrat ».)*
 *
 * La contre-épreuve était déjà dans ce module : `PortArbre::creerPersonne()` prend un
 * {@see \App\Module\Proposal\Service\Port\PersonneAcreer} et rend une `LigneCreee`, si
 * bien que `Tree` l'implémente **sans voir une seule entité de `Proposal`**. C'est la
 * même forme ici.
 *
 * ── Les énumérations traversent en chaîne ───────────────────────────────────────
 *
 * `kind` et `cibleKind` portent la **valeur** de `RequestKind` et de `SubjectKind`, pas
 * le cas d'énumération. Même raison qu'au bord de `PortArbre` : une énumération de
 * `Proposal` dans une signature publique est un `use` de plus imposé à l'implémenteur,
 * pour une information qui est une chaîne de toute façon — celle qui part en base
 * (§ 5.7 du modèle).
 *
 * L'objet est **en lecture seule**, et c'est le second effet recherché : rien de ce
 * qu'un applicateur reçoit ne lui permet de modifier la demande. Seul `Proposal` décide
 * de son statut.
 */
final readonly class DemandeEnCours
{
    /**
     * @param int|null    $id        `null` seulement avant le premier `flush`, à la
     *                               construction d'une demande qui n'a pas encore
     *                               d'identifiant
     * @param string      $kind      valeur de `RequestKind`
     * @param string|null $cibleKind valeur de `SubjectKind`, `null` pour une création
     *                               pure qui ne vise rien d'existant
     */
    public function __construct(
        public ?int $id,
        public string $kind,
        public int $auteurUserId,
        public string $auteurLibelle,
        public ?string $cibleKind = null,
        public ?int $cibleId = null,
        public ?string $cibleLibelle = null,
        public ?string $message = null,
    ) {
    }
}
