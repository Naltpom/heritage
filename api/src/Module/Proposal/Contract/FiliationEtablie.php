<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Ce qu'une filiation **a fait** à l'arbre, dans la transaction — de quoi écrire une
 * révision par ligne touchée.
 *
 * Une filiation n'est plus « une famille, un enfant » : elle peut compléter une famille
 * (règle 1), retirer l'enfant d'une ou deux familles à un parent (règles 1 et 2), et
 * l'historique doit le dire comme il dit le reste. Ce qui n'est pas écrit en révision
 * n'a pas eu lieu, pour qui relira la fiche dans six mois.
 *
 * La suppression des familles vidées n'est **pas** ici : elle se décide après, parce
 * qu'une famille sans rien côté `Tree` peut encore porter un média ou être désignée par
 * une demande en attente, ce que seul `Proposal` sait voir
 * ({@see \App\Module\Proposal\Service\Port\PortArbre::supprimerFamilleVide()}).
 */
final readonly class FiliationEtablie
{
    /**
     * @param string|null                                  $libelleAvant le couple **avant** complément (règle 1) ; `null` si la famille n'a pas été complétée
     * @param list<array{familleId: int, libelle: string}> $quittees     les familles que l'enfant a quittées
     */
    public function __construct(
        public int $familleId,
        public string $libelle,
        public bool $creee,
        public MotifDeRattachement $motif,
        public string $libelleDeLEnfant,
        public ?string $libelleAvant = null,
        public array $quittees = [],
    ) {
    }
}
