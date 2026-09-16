<?php

declare(strict_types=1);

namespace App\Module\Membership\Service\Port;

/**
 * Une union, réduite à ce dont le cercle de visibilité a besoin : qui est marié à qui, et
 * qui en descend.
 *
 * Ni date de mariage, ni lieu, ni divorce. Le cercle de l'ADR 0003 ne lit que la
 * **structure** du graphe : `A(u)` remonte par `epoux`, `D(u)` redescend par `enfants`, et
 * la passe finale des conjoints relit `epoux`. Une union rompue reste une union pour le
 * cercle — le divorce ne retire personne de la famille de quelqu'un, et le § 6.2 du
 * dossier ne l'évoque nulle part.
 */
final readonly class FamilleDeLArbre
{
    /**
     * @param list<int> $epoux   0 à 2 identifiants de personnes
     * @param list<int> $enfants 0 à n identifiants de personnes
     */
    public function __construct(
        public array $epoux,
        public array $enfants,
    ) {
    }
}
