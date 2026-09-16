<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\NoeudPropose;
use App\Module\Core\Enum\Branch;
use App\Module\Tree\ValueObject\AncrageEnAttente;
use App\Module\Tree\ValueObject\NoeudEnAttente;
use App\Module\Tree\ValueObject\PersonnePlacable;

/**
 * Un {@see NoeudPropose} du noyau, **traduit dans le vocabulaire du moteur de placement**.
 *
 * `Core\Contract\NoeudPropose` décrit une personne proposée en termes de fiche — prénoms,
 * nom, sexe, ligne de dates. Le moteur, lui, ne sait placer qu'un {@see PersonnePlacable}
 * et n'accepte qu'un {@see AncrageEnAttente}. Cette classe porte la traduction, et
 * seulement elle : aucune règle de confidentialité, aucune décision d'affichage.
 *
 * Deux choses que le contrat du noyau ne porte pas et que la traduction doit fournir,
 * parce que le placement en a besoin — elles sont **déduites de l'ancrage**, et
 * {@see PropositionsProjetees} explique comment :
 *
 * - **`generation`**, qui fixe `y = génération × 150` et n'a aucune liberté verticale ;
 * - **`branch`**, dont le canevas fait sa couleur par quart d'ascendance.
 */
final readonly class NoeudProposePlacable
{
    public function __construct(
        public NoeudPropose $source,
        public PersonnePlacable $mesure,
        public AncrageEnAttente $ancrage,
        public ?Branch $branche,
        /**
         * L'identifiant du losange provisoire tel que **`Proposal` l'a numéroté**
         * (`#12/f1`), quand le rendu en a besoin. Le projecteur en fabrique un autre pour
         * son usage interne ; c'est celui-ci qui sort de l'API.
         */
        public ?string $familleProvisoire,
    ) {
    }

    /** `#12/p1` — la forme du § 5.2. Jamais utilisable dans un chemin d'URL. */
    public function id(): string
    {
        return $this->source->noeudId;
    }

    /** Ce que {@see \App\Module\Tree\Service\ProjecteurDePlacement} attend en entrée. */
    public function pourLaProjection(): NoeudEnAttente
    {
        return new NoeudEnAttente($this->mesure, $this->ancrage);
    }
}
