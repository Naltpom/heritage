<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/**
 * L'arbre entier, tel que les statistiques le lisent — **sans aucun filtre**.
 *
 * Un objet de transfert sérialisable (il est mis en cache tel quel) : des tableaux, des
 * énumérations et des `UncertainDate`. Les volumes sont ceux d'une famille — un millier de
 * personnes, quelques centaines de familles —, un relevé tient en mémoire sans effort.
 */
final readonly class ReleveDeLArbre
{
    /**
     * @param list<PersonneDuReleve> $personnes
     * @param list<FamilleDuReleve>  $familles
     * @param list<FaitDuReleve>     $faits     professions et résidences
     * @param list<AppuiDuReleve>    $appuis    les citations, avec leur qualité et leur date de saisie
     * @param list<LieuDuReleve>     $lieux     les lignes de `tree_place`, résolues ou non
     * @param int|null               $racineId  `tree.personne_racine_id` : la personne d'où se lisent les branches
     */
    public function __construct(
        public array $personnes,
        public array $familles,
        public array $faits,
        public array $appuis,
        public array $lieux,
        public ?int $racineId,
    ) {
    }
}
