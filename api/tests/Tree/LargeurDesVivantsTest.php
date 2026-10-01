<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Service\AffinageDuPlacement;
use App\Module\Tree\Service\MesureDeFiche;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\PersonnePlacable;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  « TOUT CHAMP DÉRIVÉ D'UNE DONNÉE MASQUÉE EST UNE DONNÉE MASQUÉE » — `w` et `x`.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Révision du 2026-10-01 de l'ADR 0003 : un vivant hors cercle n'expose plus son nom.
 * Une liste blanche ne voit pas un champ **légitime** dont la valeur est calculée sur un
 * champ interdit — or la largeur affichée d'une fiche était mesurée sur le nom et sur la
 * naissance, et l'écart entre deux fiches voisines la redonnait par les `x`.
 *
 * Ce test ne compare pas des clés : il fait varier **la donnée masquée** — le nom et la
 * date d'un vivant — et exige que **rien** de la géométrie servie ne bouge.
 */
final class LargeurDesVivantsTest extends TestCase
{
    /**
     * La constante n'est pas un nombre magique : c'est la formule de la fiche affichée,
     * appliquée au plus long vivant qu'on affiche sans troncature — 21 signes de nom, une
     * date complète. Si la formule change, la constante doit suivre, et ce test le dit.
     */
    public function testLaConstanteEstLaFormuleAppliqueeALaFicheDeReference(): void
    {
        $texte = max(
            MoteurDePlacement::LARGEUR_MINIMALE,
            (int) (max(
                21 * MoteurDePlacement::LARG_CAR,
                iconv_strlen('n. 12.12.1985 · vivant·e', 'UTF-8') * MoteurDePlacement::LARG_CAR_DATES,
            ) + 2 * MoteurDePlacement::PAD),
        );

        self::assertSame($texte + MesureDeFiche::LARGEUR_PHOTO + MesureDeFiche::ESPACE_PHOTO, MesureDeFiche::LARGEUR_AFFICHEE_VIVANT);
    }

    public function testDeuxVivantsDeNomsEtDeDatesDifferentsOntLaMemeLargeur(): void
    {
        $mesure = new MesureDeFiche();

        self::assertSame(
            $mesure->largeurAffichee($this->vivant('P-a', 'Al', 'Po', '')),
            $mesure->largeurAffichee($this->vivant('P-b', 'Maximilienne-Héloïse', 'de la Tour d’Auvergne', '12.12.1985')),
        );

        // Un défunt, lui, reste mesuré sur son texte : sa fiche est visible de tous.
        self::assertNotSame(
            $mesure->largeurAffichee($this->defunt('Al', 'Po')),
            $mesure->largeurAffichee($this->defunt('Maximilienne-Héloïse', 'de la Tour d’Auvergne')),
        );
    }

    /**
     * **Le placement servi entier**, moteur puis affinage : changer le nom et la date d'un
     * vivant ne déplace aucune fiche ni n'en élargit aucune. C'est la propriété qui compte
     * — `w` constant ne suffirait pas si le moteur, qui mesure encore sur le nom pour
     * garder sa preuve des 377 positions, laissait passer cette mesure jusqu'aux `x`.
     */
    public function testLeNomEtLaDateDUnVivantNeDeplacentAucuneFiche(): void
    {
        $court = $this->placement($this->renomme(ArbreSynthetique::jeu(), 'P-cousin1', 'Al', 'Po', ''));
        $long = $this->placement($this->renomme(ArbreSynthetique::jeu(), 'P-cousin1', 'Maximilienne-Héloïse', 'de la Tour d’Auvergne', '12.12.1985'));

        self::assertSame($court, $long, 'La géométrie servie ne doit dépendre ni du nom ni de la date d’une personne vivante.');
        self::assertSame(MesureDeFiche::LARGEUR_AFFICHEE_VIVANT, $court['P-cousin1'][2]);
    }

    /** @return array<string, array{0: float, 1: float, 2: float, 3: float}> */
    private function placement(JeuDePlacement $jeu): array
    {
        // Le chemin du graphe servi : `PlacementPartage::calcule()`, entrée comprise.
        $jeu = $jeu->sansTexteDesVivants();
        $place = (new AffinageDuPlacement())->affine((new MoteurDePlacement())->calcule($jeu), $jeu);

        $sortie = [];
        foreach ($place->noeuds as $id => $noeud) {
            $sortie[$id] = [$noeud->x, $noeud->y, $noeud->w, $noeud->h];
        }
        ksort($sortie);

        return $sortie;
    }

    private function renomme(JeuDePlacement $jeu, string $id, string $prenom, string $nom, string $nais): JeuDePlacement
    {
        $personnes = [];

        foreach ($jeu->personnes as $p) {
            $personnes[] = $p->id === $id
                ? new PersonnePlacable($p->id, $prenom, $nom, $p->usage, $p->sexe, $nais, $p->deces, $p->anNais, $p->vivant, $p->generation)
                : $p;
        }

        return new JeuDePlacement($personnes, $jeu->familles, $jeu->egoId);
    }

    private function vivant(string $id, string $prenom, string $nom, string $nais): PersonnePlacable
    {
        return new PersonnePlacable($id, $prenom, $nom, '', \App\Module\Core\Enum\Sex::Inconnu, $nais, '', null, true, 0);
    }

    private function defunt(string $prenom, string $nom): PersonnePlacable
    {
        return new PersonnePlacable('P-d', $prenom, $nom, '', \App\Module\Core\Enum\Sex::Inconnu, '1.1.1900', '1.1.1980', 1900, false, -2);
    }
}
