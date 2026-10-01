<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Ce qu'une filiation **fera** à l'arbre — calculé en lecture seule, pour l'écran de
 * décision.
 *
 * Même principe que `ResolutionPrevue` : rien n'est stocké, tout se recalcule à la
 * lecture, parce que la réponse dépend de l'état courant de l'arbre. La demande 715
 * (« Véronique, enfant de Jean-Marie ») ne dit pas la même chose avant et après la
 * validation de la 714 (« Hélène, conjointe de Jean-Marie ») : avant, Jean-Marie n'a
 * aucune union ; après, il en a une, et Véronique y sera rattachée. L'administrateur doit
 * le lire **avant** de cliquer.
 *
 * `etablirFiliation()` refait le même calcul dans la transaction, sur l'état d'alors :
 * c'est le seul qui fasse foi, celui-ci n'est qu'une annonce.
 */
final readonly class FiliationPrevue
{
    /**
     * @param int|null                                                     $familleId       la famille qui recevra l'enfant ; `null` si elle sera créée
     * @param string                                                       $libelle         le couple tel qu'il sera après l'écriture, « Jean-Marie × Hélène »
     * @param list<array{familleId: int, libelle: string, seraVide: bool}> $quittees        les familles à un parent que l'enfant quittera ; `seraVide` dit
     *                                                                                      que `Tree` n'y voit plus rien — `Proposal` peut encore la protéger
     *                                                                                      (un média, une demande en attente qui la désigne)
     * @param string|null                                                  $incompatibilite le motif de la règle 4, quand la validation lèvera
     */
    public function __construct(
        public MotifDeRattachement $motif,
        public ?int $familleId,
        public string $libelle,
        public array $quittees = [],
        public ?string $incompatibilite = null,
    ) {
    }
}
