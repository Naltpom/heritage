<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Gedcom\Service\CollecteurAnomalies;
use App\Module\Tree\ValueObject\PlacementLogique;

/**
 * Tout ce que la lecture d'un fichier GEDCOM produit, **avant** la moindre écriture.
 *
 * C'est le point de coupure qui rend le `--dry-run` possible et l'analyseur testable :
 * l'analyse ne connaît pas Doctrine, et l'écriture ne connaît pas la grammaire GEDCOM.
 * Un import qui échoue en écriture a donc déjà tous ses dénombrements et toutes ses
 * anomalies — on sait ce qu'on aurait dû obtenir.
 */
final class ResultatAnalyse
{
    /**
     * @param array<string, PersonneLue> $personnes
     * @param array<string, FamilleLue>  $familles
     * @param array<string, SourceLue>   $sources
     */
    public function __construct(
        public readonly array $personnes,
        public readonly array $familles,
        public readonly array $sources,
        public readonly GrapheFamilial $graphe,
        public readonly PlacementLogique $placement,
        public readonly string $ego,
        public readonly ?string $charset,
        public readonly int $lignesPhysiques,
        public readonly CollecteurAnomalies $anomalies,
    ) {
    }

    /**
     * Les dénombrements, tels qu'ils partent en `ged_run.counts` et dans le tableau de
     * contrôle du rapport.
     *
     * Ils sont recalculés depuis le modèle en mémoire, jamais accumulés au fil de la
     * lecture : un compteur qu'on incrémente à la main finit par rater un chemin.
     *
     * @return array<string, int>
     */
    public function totaux(): array
    {
        $citationsIndividus = 0;
        $notesIndividus = 0;
        $faits = 0;
        $medias = 0;
        $sansDeces = 0;
        $fichesActees = 0;
        $resnPrivacy = 0;
        $decesSansDate = 0;

        foreach ($this->personnes as $personne) {
            $citationsIndividus += \count($personne->citations);
            $notesIndividus += \count($personne->notes);
            $medias += $personne->medias;

            foreach ($personne->faits as $fait) {
                ++$faits;
                $notesIndividus += \count($fait->notes);
                $citationsIndividus += \count($fait->citations);
            }

            if (!$personne->decede) {
                ++$sansDeces;
            } elseif (!$personne->deces->isKnown()) {
                ++$decesSansDate;
            }

            if (null !== ($quay = $personne->meilleurQuay()) && $quay >= 2) {
                ++$fichesActees;
            }

            if ($personne->resnPrivacy) {
                ++$resnPrivacy;
            }
        }

        $citationsFamilles = 0;
        $notesFamilles = 0;
        $filiations = 0;

        foreach ($this->familles as $famille) {
            $citationsFamilles += \count($famille->citations);
            $notesFamilles += \count($famille->notes);
            $filiations += \count($famille->enfants);
        }

        $notesDeSource = 0;

        foreach ($this->sources as $source) {
            if (null !== $source->note) {
                ++$notesDeSource;
            }
        }

        $branches = $this->placement->repartitionParBranche();

        return [
            'personnes' => \count($this->personnes),
            'familles' => \count($this->familles),
            'filiations' => $filiations,
            'sources' => \count($this->sources),
            'citations' => $citationsIndividus + $citationsFamilles,
            'citations_individus' => $citationsIndividus,
            'citations_familles' => $citationsFamilles,
            'notes' => $notesIndividus + $notesFamilles,
            'notes_de_source' => $notesDeSource,
            'faits' => $faits,
            'medias' => $medias,
            'generations' => $this->placement->nombreDeGenerations(),
            'sans_deces' => $sansDeces,
            'deces_sans_date' => $decesSansDate,
            'fiches_actees' => $fichesActees,
            'resn_privacy' => $resnPrivacy,
            'branche_pp' => $branches['pp'],
            'branche_pm' => $branches['pm'],
            'branche_mp' => $branches['mp'],
            'branche_mm' => $branches['mm'],
            'branche_tr' => $branches['tr'],
        ];
    }
}
