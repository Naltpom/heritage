<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\Service\AffinageDuPlacement;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\BoiteEnglobante;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\PersonnePlacable;
use App\Module\Tree\ValueObject\Placement;
use PHPUnit\Framework\TestCase;

/**
 * ════════════════════════════════════════════════════════════════════════════════
 * **LA PREUVE QUI REMPLACE CELLE DU PORTAGE.**.
 *
 * {@see PlacementNonRegressionTest} tient une preuve d'**identité** : le moteur
 * reproduit `graphe.py` au centième sur 377 nœuds. Elle reste vraie, et elle reste
 * utile — mais elle ne décrit plus ce qui est servi, parce qu'une identité interdit
 * par construction toute amélioration. C'est exactement ce qui bloquait.
 *
 * Ce fichier tient une preuve de **propriétés** : les invariants arrêtés avec le
 * commanditaire, et une somme des carrés qui ne remonte jamais. Une preuve de
 * propriétés laisse le calcul évoluer tant que ce qui compte tient.
 *
 * Les invariants, et les deux exceptions structurelles qui les bornent, sont écrits
 * en tête de {@see AffinageDuPlacement}. Chaque cas ci-dessous en tient un.
 * ════════════════════════════════════════════════════════════════════════════════
 */
final class PlacementLisibleTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /* Sur l'arbre synthétique : les invariants, un par un */
    /* ------------------------------------------------------------------ */

    public function testI1LesOrdonneesNeBougentPas(): void
    {
        /* Une ordonnée est une propriété de la topologie — `génération × 150` — et
           l'affinage n'a aucune liberté verticale. */
        $avant = $this->brut();
        $apres = $this->affine();

        foreach ($avant->noeuds as $id => $noeud) {
            self::assertSame($noeud->y, $apres->noeuds[$id]->y ?? null, $id);
        }
    }

    public function testI2AucuneFicheNEnChevaucheUneAutre(): void
    {
        foreach ($this->rangees($this->affine()) as $y => $rangee) {
            for ($i = 1, $n = \count($rangee); $i < $n; ++$i) {
                $gauche = $rangee[$i - 1];
                $droite = $rangee[$i];

                self::assertGreaterThanOrEqual(
                    $gauche->droite() + MoteurDePlacement::ESPACE - 0.001,
                    $droite->gauche(),
                    \sprintf('%s et %s se chevauchent sur la rangée %d', $gauche->id, $droite->id, $y),
                );
            }
        }
    }

    public function testI3LesDeuxEpouxSontVoisinsImmediats(): void
    {
        $apres = $this->affine();

        foreach ($this->couplesSurUneRangee($apres) as $famille => [$a, $b]) {
            self::assertSame(
                1,
                abs($a - $b),
                \sprintf('Les deux époux de %s ne sont pas voisins.', $famille),
            );
        }
    }

    public function testI6LHommeEstAGauche(): void
    {
        $apres = $this->affine();
        $jeu = ArbreSynthetique::jeu();

        $sexes = [];
        foreach ($jeu->personnes as $personne) {
            $sexes[$personne->id] = $personne->sexe;
        }

        $mixtes = 0;

        foreach ($this->epouxParFamille($apres) as $famille => $membres) {
            if (2 !== \count($membres)) {
                continue;
            }

            usort($membres, fn (string $a, string $b): int => $apres->noeuds[$a]->x <=> $apres->noeuds[$b]->x);
            [$gauche, $droite] = $membres;

            $mixte = Sex::Inconnu !== $sexes[$gauche] && Sex::Inconnu !== $sexes[$droite]
                && $sexes[$gauche] !== $sexes[$droite];

            if (!$mixte) {
                continue;
            }

            ++$mixtes;

            self::assertSame(
                Sex::Masculin,
                $sexes[$gauche],
                \sprintf('Le couple %s a la femme à gauche.', $famille),
            );
        }

        // Sans ce compte, le cas serait vert sur un arbre sans aucun couple mixte —
        // c'est-à-dire vert pour la mauvaise raison.
        self::assertGreaterThan(0, $mixtes, 'L’arbre de test doit porter des couples mixtes.');
    }

    public function testI7LeLecteurEstALAbscisseZero(): void
    {
        $apres = $this->affine();

        self::assertSame(0.0, $apres->noeuds[ArbreSynthetique::EGO]->x);
    }

    public function testI8UneFratrieEstDansLOrdreDesNaissances(): void
    {
        $apres = $this->affine();
        $jeu = ArbreSynthetique::jeu();

        $annees = [];
        foreach ($jeu->personnes as $personne) {
            $annees[$personne->id] = $personne->anNais ?? 9999;
        }

        // `F-parents` porte trois enfants nés en 1988, 1990 et 1993.
        $fratrie = ['P-soeur', 'P-ego', 'P-frere'];
        usort($fratrie, fn (string $a, string $b): int => $apres->noeuds[$a]->x <=> $apres->noeuds[$b]->x);

        $lues = array_map(static fn (string $id): int => $annees[$id], $fratrie);
        $triees = $lues;
        sort($triees);

        self::assertSame($triees, $lues, 'La fratrie n’est pas dans l’ordre des naissances.');
    }

    /**
     * Le losange suit son couple — I5.
     *
     * Un nœud de jonction qui ne suit pas ses époux pointe à côté du couple qu'il
     * joint, et les deux traits d'union partent de travers.
     */
    public function testI5LeLosangeResteEntreSesEpoux(): void
    {
        $apres = $this->affine();

        foreach ($this->epouxParFamille($apres) as $famille => $membres) {
            if (2 !== \count($membres)) {
                continue;
            }

            $xs = array_map(static fn (string $id): float => $apres->noeuds[$id]->x, $membres);
            $losange = $apres->noeuds[$famille]->x;

            self::assertGreaterThanOrEqual(min($xs) - 0.001, $losange, $famille);
            self::assertLessThanOrEqual(max($xs) + 0.001, $losange, $famille);
        }
    }

    /* ------------------------------------------------------------------ */
    /* L'objectif */
    /* ------------------------------------------------------------------ */

    /**
     * **La somme des carrés ne remonte jamais.**.
     *
     * C'est la propriété qui remplace la preuve d'identité, et c'est la seule qu'un
     * algorithme de ce genre puisse tenir : les balayages oscillent, et garder le
     * dernier ordre au lieu du meilleur produit régulièrement un résultat moins bon
     * que le point de départ — sans que rien ne le signale, puisque l'arbre a changé
     * de forme et qu'on n'a plus l'ancienne sous les yeux.
     */
    public function testLaSommeDesCarresNeRemonteJamais(): void
    {
        self::assertLessThanOrEqual(
            $this->sommeDesCarres($this->brut()),
            $this->sommeDesCarres($this->affine()),
        );
    }

    public function testLeTraitLePlusLongNeSAllongeJamais(): void
    {
        self::assertLessThanOrEqual($this->traitLePlusLong($this->brut()), $this->traitLePlusLong($this->affine()));
    }

    /* ------------------------------------------------------------------ */
    /* Les deux exceptions structurelles */
    /* ------------------------------------------------------------------ */

    /**
     * **I3 cède sur un remariage, et seulement là.**.
     *
     * On ne peut pas être voisin de ses deux conjoints. La personne rejoint le bloc
     * de l'union **qui a des enfants** ; l'autre garde un trait plus long. Ce cas
     * est écrit à la main : l'arbre synthétique n'a pas de remariage, et c'est
     * justement ce qu'il faut éprouver.
     */
    public function testUnRemarieNEstVoisinQueDUnDeSesConjoints(): void
    {
        $apres = (new AffinageDuPlacement())->affine($this->deuxUnions(), $this->jeuDeuxUnions());

        $x = static fn (string $id): float => $apres->noeuds[$id]->x;

        // `p1` a deux unions ; `f1` porte un enfant, `f2` non.
        $ecartAvecLUnionAEnfant = abs($x('p1') - $x('p2'));
        $ecartAvecLAutre = abs($x('p1') - $x('p3'));

        self::assertLessThan(
            $ecartAvecLAutre,
            $ecartAvecLUnionAEnfant,
            'C’est l’union qui porte des enfants qui reste accolée : c’est elle dont l’allongement coûte le plus.',
        );
    }

    /**
     * **I4 cède quand deux fratries s'allient, et l'on casse la plus petite.**.
     *
     * Si un frère épouse quelqu'un d'une autre fratrie, il ne peut être qu'à un
     * endroit : l'une des deux sera cassée. Le bloc rejoint la plus nombreuse, qui
     * reste entière.
     */
    public function testLeCoupleRejointLaPlusGrandeDesDeuxFratries(): void
    {
        $place = $this->deuxFratries();
        $apres = (new AffinageDuPlacement())->affine($place, $this->jeuDeuxFratries());

        $x = static fn (string $id): float => $apres->noeuds[$id]->x;

        // La grande fratrie : `g1`, `g2`, `g3`. La petite : `q1`, `q2`.
        // `g3` épouse `q2`. Le bloc doit rester dans la grande.
        $etendueGrande = max($x('g1'), $x('g2'), $x('g3')) - min($x('g1'), $x('g2'), $x('g3'));
        $largeurDeTrois = 3 * 100 + 2 * MoteurDePlacement::ESPACE + 100 + MoteurDePlacement::ESPACE;

        self::assertLessThanOrEqual(
            $largeurDeTrois,
            $etendueGrande,
            'La grande fratrie doit rester entière, conjoint intercalé compris.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Sur l'arbre RÉEL — le seul cas qui dise quelque chose du produit */
    /* ------------------------------------------------------------------ */

    /**
     * ════════════════════════════════════════════════════════════════════
     * **LES INVARIANTS SUR LES 377 NŒUDS, ET LE GAIN CHIFFRÉ.**.
     *
     * L'arbre synthétique a dix personnes et deux couples : il éprouve les règles
     * une par une, il ne dit rien de ce qui arrive à un arbre de 377 nœuds où dix
     * fratries s'allient entre elles. Ce cas-ci le dit.
     *
     * Il **saute** hors de la machine du commanditaire : le fichier de référence
     * porte des personnes vivantes et n'est pas versionné (ADR 0006). C'est la même
     * convention que {@see PlacementNonRegressionTest}, et la même limite — cette
     * preuve-là ne tourne pas en intégration continue.
     * ════════════════════════════════════════════════════════════════════
     */
    public function testSurLArbreReelLesInvariantsTiennentEtLaMesureSAmeliore(): void
    {
        if (!ChargeurDeGrapheDeReference::disponible()) {
            self::markTestSkipped(\sprintf(
                "Fichier de référence absent : %s\n".
                "C'est normal hors de la machine du commanditaire — il n'est pas versionné (ADR 0006).",
                ChargeurDeGrapheDeReference::chemin(),
            ));
        }

        $jeu = ChargeurDeGrapheDeReference::charge()->jeu();
        $brut = (new MoteurDePlacement())->calcule($jeu);
        $apres = (new AffinageDuPlacement())->affine($brut, $jeu);

        // ── I2 : aucun chevauchement, sur aucune des vingt-trois rangées.
        foreach ($this->rangees($apres) as $y => $rangee) {
            for ($i = 1, $n = \count($rangee); $i < $n; ++$i) {
                self::assertGreaterThanOrEqual(
                    $rangee[$i - 1]->droite() + MoteurDePlacement::ESPACE - 0.001,
                    $rangee[$i]->gauche(),
                    \sprintf('%s et %s se chevauchent sur la rangée %d', $rangee[$i - 1]->id, $rangee[$i]->id, $y),
                );
            }
        }

        // ── I1 : aucune ordonnée n'a bougé.
        foreach ($brut->noeuds as $id => $noeud) {
            self::assertSame($noeud->y, $apres->noeuds[$id]->y, $id);
        }

        // ── I3 : les couples accolés. Les exceptions sont les remariages, et rien
        //    d'autre — c'est la borne qu'on tient, pas un seuil confortable.
        $separes = 0;
        foreach ($this->couplesSurUneRangee($apres) as [$a, $b]) {
            if (1 !== abs($a - $b)) {
                ++$separes;
            }
        }

        $accolesAvant = 0;
        foreach ($this->couplesSurUneRangee($brut) as [$a, $b]) {
            if (1 === abs($a - $b)) {
                ++$accolesAvant;
            }
        }

        $couples = \count($this->couplesSurUneRangee($apres));

        self::assertGreaterThan(
            $accolesAvant,
            $couples - $separes,
            'L’affinage doit accoler plus de couples que le moteur seul.',
        );
        self::assertLessThanOrEqual(
            (int) round($couples * 0.05),
            $separes,
            'Au-delà de cinq pour cent, ce ne sont plus les seuls remariages.',
        );

        // ── L'objectif : la somme des carrés s'effondre, et rien ne remonte.
        $avant = $this->sommeDesCarres($brut);
        $gagne = $this->sommeDesCarres($apres);

        self::assertLessThan($avant / 2, $gagne, 'La somme des carrés doit au moins être divisée par deux.');
        self::assertLessThan($this->traitLePlusLong($brut), $this->traitLePlusLong($apres));

        // ── I7, sur l'arbre réel comme sur l'autre.
        self::assertSame(0.0, $apres->noeuds[$brut->egoId]->x);
    }

    /* ------------------------------------------------------------------ */
    /* Le cas dégénéré */
    /* ------------------------------------------------------------------ */

    public function testUnPlacementVideTraverseSansDommage(): void
    {
        $vide = new Placement([], [], new BoiteEnglobante(0, 0, 0, 0), '');

        self::assertSame([], (new AffinageDuPlacement())->affine($vide, new JeuDePlacement([], [], ''))->noeuds);
    }

    /* ------------------------------------------------------------------ */
    /* Socle */
    /* ------------------------------------------------------------------ */

    private function brut(): Placement
    {
        return (new MoteurDePlacement())->calcule(ArbreSynthetique::jeu());
    }

    private function affine(): Placement
    {
        return (new AffinageDuPlacement())->affine($this->brut(), ArbreSynthetique::jeu());
    }

    /** @return array<int, list<NoeudPlace>> */
    private function rangees(Placement $place): array
    {
        $rangees = [];

        foreach ($place->noeuds as $noeud) {
            if (MoteurDePlacement::H_FICHE !== $noeud->h) {
                continue;
            }

            $rangees[(int) $noeud->y][] = $noeud;
        }

        foreach ($rangees as $y => $rangee) {
            usort($rangee, static fn (NoeudPlace $a, NoeudPlace $b): int => $a->x <=> $b->x);
            $rangees[$y] = $rangee;
        }

        return $rangees;
    }

    /** @return array<string, list<string>> */
    private function epouxParFamille(Placement $place): array
    {
        $epoux = [];

        foreach ($place->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre) {
                $epoux[$arete->vers][] = $arete->de;
            }
        }

        return $epoux;
    }

    /**
     * Les couples dont les deux époux partagent une rangée, avec leurs rangs.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function couplesSurUneRangee(Placement $place): array
    {
        $rangs = [];

        foreach ($this->rangees($place) as $y => $rangee) {
            foreach ($rangee as $index => $noeud) {
                $rangs[$noeud->id] = [$y, $index];
            }
        }

        $sortie = [];

        foreach ($this->epouxParFamille($place) as $famille => $membres) {
            if (2 !== \count($membres)) {
                continue;
            }

            [$a, $b] = $membres;

            if (!isset($rangs[$a], $rangs[$b]) || $rangs[$a][0] !== $rangs[$b][0]) {
                continue;
            }

            $sortie[$famille] = [$rangs[$a][1], $rangs[$b][1]];
        }

        return $sortie;
    }

    private function sommeDesCarres(Placement $place): float
    {
        $total = 0.0;

        foreach ($place->aretes as $arete) {
            $de = $place->noeuds[$arete->de] ?? null;
            $vers = $place->noeuds[$arete->vers] ?? null;

            if (null !== $de && null !== $vers) {
                $total += ($de->x - $vers->x) ** 2;
            }
        }

        return $total;
    }

    private function traitLePlusLong(Placement $place): float
    {
        $max = 0.0;

        foreach ($place->aretes as $arete) {
            $de = $place->noeuds[$arete->de] ?? null;
            $vers = $place->noeuds[$arete->vers] ?? null;

            if (null !== $de && null !== $vers) {
                $max = max($max, abs($de->x - $vers->x));
            }
        }

        return $max;
    }

    /* ----- deux fixtures écrites à la main, pour les deux exceptions ---- */

    /**
     * Une fiche réduite à ce que l'affinage lit : son sexe (I6) et son année de
     * naissance (I8). Le reste ne sert qu'à la mesure de largeur, que ces cas
     * fixent eux-mêmes à la main sur les `NoeudPlace`.
     */
    private static function fiche(string $id, Sex $sexe, ?int $anNais): PersonnePlacable
    {
        return new PersonnePlacable(
            id: $id,
            prenom: $id,
            nom: '',
            usage: '',
            sexe: $sexe,
            nais: '',
            deces: '',
            anNais: $anNais,
            vivant: false,
            generation: 0,
        );
    }

    private function jeuDeuxUnions(): JeuDePlacement
    {
        return new JeuDePlacement(
            [
                self::fiche('p1', Sex::Masculin, 1950),
                self::fiche('p2', Sex::Feminin, 1952),
                self::fiche('p3', Sex::Feminin, 1960),
                self::fiche('e1', Sex::Inconnu, 1980),
            ],
            [],
            'p1',
        );
    }

    /** `p1` marié à `p2` (un enfant) puis à `p3` (aucun). */
    private function deuxUnions(): Placement
    {
        $noeuds = [
            'p1' => new NoeudPlace('p1', 0.0, 0.0, 100, MoteurDePlacement::H_FICHE),
            'p2' => new NoeudPlace('p2', 400.0, 0.0, 100, MoteurDePlacement::H_FICHE),
            'p3' => new NoeudPlace('p3', 800.0, 0.0, 100, MoteurDePlacement::H_FICHE),
            'f1' => new NoeudPlace('f1', 200.0, 46.0, MoteurDePlacement::LOSANGE_L, MoteurDePlacement::LOSANGE_H),
            'f2' => new NoeudPlace('f2', 400.0, 46.0, MoteurDePlacement::LOSANGE_L, MoteurDePlacement::LOSANGE_H),
            'e1' => new NoeudPlace('e1', 200.0, 150.0, 100, MoteurDePlacement::H_FICHE),
        ];

        $aretes = [
            AreteDePlacement::union('p1', 'f1', false),
            AreteDePlacement::union('p2', 'f1', false),
            AreteDePlacement::filiation('f1', 'e1'),
            AreteDePlacement::union('p1', 'f2', false),
            AreteDePlacement::union('p3', 'f2', false),
        ];

        return new Placement($noeuds, $aretes, new BoiteEnglobante(-200, -200, 1400, 600), 'p1');
    }

    private function jeuDeuxFratries(): JeuDePlacement
    {
        return new JeuDePlacement(
            [
                self::fiche('g1', Sex::Masculin, 1980),
                self::fiche('g2', Sex::Feminin, 1982),
                self::fiche('g3', Sex::Masculin, 1984),
                self::fiche('q1', Sex::Masculin, 1981),
                self::fiche('q2', Sex::Feminin, 1983),
            ],
            [],
            'g1',
        );
    }

    /** Une fratrie de trois, une de deux, et `g3` épouse `q2`. */
    private function deuxFratries(): Placement
    {
        $noeuds = [
            'g1' => new NoeudPlace('g1', 0.0, 0.0, 100, MoteurDePlacement::H_FICHE),
            'g2' => new NoeudPlace('g2', 120.0, 0.0, 100, MoteurDePlacement::H_FICHE),
            'g3' => new NoeudPlace('g3', 240.0, 0.0, 100, MoteurDePlacement::H_FICHE),
            'q1' => new NoeudPlace('q1', 360.0, 0.0, 100, MoteurDePlacement::H_FICHE),
            'q2' => new NoeudPlace('q2', 480.0, 0.0, 100, MoteurDePlacement::H_FICHE),
            'fG' => new NoeudPlace('fG', 120.0, -104.0, MoteurDePlacement::LOSANGE_L, MoteurDePlacement::LOSANGE_H),
            'fQ' => new NoeudPlace('fQ', 420.0, -104.0, MoteurDePlacement::LOSANGE_L, MoteurDePlacement::LOSANGE_H),
            'fC' => new NoeudPlace('fC', 360.0, 46.0, MoteurDePlacement::LOSANGE_L, MoteurDePlacement::LOSANGE_H),
        ];

        $aretes = [
            AreteDePlacement::filiation('fG', 'g1'),
            AreteDePlacement::filiation('fG', 'g2'),
            AreteDePlacement::filiation('fG', 'g3'),
            AreteDePlacement::filiation('fQ', 'q1'),
            AreteDePlacement::filiation('fQ', 'q2'),
            AreteDePlacement::union('g3', 'fC', false),
            AreteDePlacement::union('q2', 'fC', false),
        ];

        return new Placement($noeuds, $aretes, new BoiteEnglobante(-200, -300, 1200, 700), 'g1');
    }
}
