<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\Service\ProjecteurDePlacement;
use App\Module\Tree\ValueObject\AncrageEnAttente;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\NoeudEnAttente;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\PersonnePlacable;
use App\Module\Tree\ValueObject\Placement;
use PHPUnit\Framework\TestCase;

/**
 * L'étage 2 : la projection pour un lecteur, avec ses nœuds « en attente »
 * (ADR 0005, § 6.4 et § 9.9).
 *
 * Ce que ces tests protègent est moins le placement du nœud proposé — il a le droit
 * d'être légèrement de travers, c'est assumé — que **l'absence de réorganisation** : si
 * la projection se met à déplacer des nœuds validés, l'étage 1 cesse d'être partageable
 * et tout le cache à deux étages tombe avec lui.
 */
final class PlacementProjectionTest extends TestCase
{
    private const float EPSILON = 0.0001;

    private Placement $partage;

    protected function setUp(): void
    {
        $this->partage = (new MoteurDePlacement())->calcule(ArbreSynthetique::jeu());
    }

    /**
     * Le cas de loin le plus fréquent : l'immense majorité des lecteurs n'a aucune
     * demande en cours. On rend l'objet partagé **lui-même**, sans copie — c'est ce qui
     * rend la projection assez bon marché pour n'être jamais mise en cache.
     */
    public function testSansDemandeLObjetPartageEstRenduTelQuel(): void
    {
        self::assertSame($this->partage, (new ProjecteurDePlacement())->projette($this->partage, []));
    }

    public function testUnEnfantEnAttenteSePoseADroiteDeLaFratrie(): void
    {
        $projete = $this->projette($this->enAttente('brouillon-1', 0, AncrageEnAttente::enfantDe('F-parents')));

        $noeud = $projete->noeud('brouillon-1');
        self::assertNotNull($noeud, 'Le demandeur doit voir son nœud en attente dans l\'arbre.');
        self::assertSame(0.0, $noeud->y, 'Il est en génération 0, comme la fratrie qu\'il rejoint.');

        foreach (['P-soeur', 'P-ego', 'P-frere'] as $fratrie) {
            self::assertGreaterThan(
                $this->partage->noeud($fratrie)?->droite() ?? 0.0,
                $noeud->gauche(),
                \sprintf('Le nœud en attente se pose à droite de %s, sans recentrer la fratrie.', $fratrie),
            );
        }
    }

    /**
     * **Le cœur de l'ADR 0005 côté étage 2.** Un nœud en attente décale les seuls
     * voisins immédiats de sa rangée ; tout le reste du graphe est rigoureusement
     * identique à l'objet partagé.
     */
    public function testAucuneAutreRangeeNeBouge(): void
    {
        $projete = $this->projette($this->enAttente('brouillon-1', 0, AncrageEnAttente::enfantDe('F-parents')));

        foreach ($this->partage->noeuds as $avant) {
            $apres = $projete->noeud($avant->id);
            self::assertNotNull($apres);

            if (0.0 === $avant->y) {
                continue;
            }

            self::assertSame($avant->x, $apres->x, \sprintf('%s n\'est pas sur la rangée touchée : il ne doit pas bouger.', $avant->id));
            self::assertSame($avant->y, $apres->y);
        }
    }

    /** L'ordre horizontal porte la structure : l'insertion ne réordonne jamais. */
    public function testLOrdreDesNoeudsValidesEstPreserve(): void
    {
        $projete = $this->projette($this->enAttente('brouillon-1', 0, AncrageEnAttente::conjointDe('P-ego')));

        self::assertSame(
            $this->ordreSurLaRangee($this->partage->noeuds, 0.0),
            $this->ordreSurLaRangee($this->filtreValides($projete->noeuds), 0.0),
        );
    }

    public function testLEgoResteALOrigine(): void
    {
        foreach ([
            AncrageEnAttente::enfantDe('F-parents'),
            AncrageEnAttente::conjointDe('P-ego'),
            AncrageEnAttente::conjointDe('P-soeur'),
            AncrageEnAttente::parentDe('P-ego'),
        ] as $ancrage) {
            $projete = $this->projette($this->enAttente('brouillon-1', 0, $ancrage));

            self::assertSame(
                0.0,
                $projete->noeud(ArbreSynthetique::EGO)?->x,
                'L\'ego reste le point de lecture, quel que soit l\'ancrage.',
            );
        }
    }

    /**
     * Désigner un conjoint crée une famille — mais seulement à la validation. En
     * attendant, un losange provisoire porte le lien : il n'existe **pas** d'arête
     * directe personne → personne dans ce graphe.
     */
    public function testUnConjointEnAttenteRecoitUnLosangeProvisoire(): void
    {
        $attente = $this->enAttente('brouillon-2', 0, AncrageEnAttente::conjointDe('P-ego'));
        $projete = $this->projette($attente);

        $losange = $projete->noeud($attente->familleProvisoireId());
        self::assertNotNull($losange, 'Le lien d\'union proposé doit passer par un losange.');
        self::assertSame(MoteurDePlacement::LOSANGE_L, $losange->w);
        self::assertSame(MoteurDePlacement::LOSANGE_H, $losange->h);
        self::assertSame(
            (float) (MoteurDePlacement::H_FICHE / 2 + MoteurDePlacement::DESCENTE_NOEUD_FAMILLE),
            $losange->y,
            'Le losange se pose 26 px sous la rangée du couple, comme tous les autres.',
        );

        $unions = [];

        foreach ($projete->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre && $arete->vers === $losange->id) {
                $unions[] = $arete->de;
            }
        }

        self::assertSame(['P-ego', 'brouillon-2'], $unions, 'Les deux époux sont reliés au losange provisoire.');
    }

    /**
     * Un parent proposé pour quelqu'un qui a **déjà** une famille rejoint ce losange :
     * c'est le complément d'une fiche, pas une nouvelle union.
     */
    public function testUnParentEnAttenteRejointLaFamilleExistante(): void
    {
        $attente = $this->enAttente('brouillon-3', -1, AncrageEnAttente::parentDe('P-ego'));
        $projete = $this->projette($attente);

        self::assertNull(
            $projete->noeud($attente->familleProvisoireId()),
            'Aucun losange provisoire : la famille de l\'ego existe déjà.',
        );

        $cibles = [];

        foreach ($projete->aretes as $arete) {
            if ('brouillon-3' === $arete->de) {
                $cibles[] = $arete->vers;
            }
        }

        self::assertSame(['F-parents'], $cibles);
    }

    /** Le décrochage local vaut aussi pour les nœuds en attente : rien ne se superpose. */
    public function testRienNeSeChevaucheApresInsertion(): void
    {
        $projete = $this->projette($this->enAttente('brouillon-1', 0, AncrageEnAttente::conjointDe('P-soeur')));

        $rangee = [];

        foreach ($projete->noeuds as $noeud) {
            if (0.0 === $noeud->y && MoteurDePlacement::H_FICHE === $noeud->h) {
                $rangee[] = $noeud;
            }
        }

        usort($rangee, static fn (NoeudPlace $a, NoeudPlace $b): int => $a->x <=> $b->x);

        for ($i = 1, $n = \count($rangee); $i < $n; ++$i) {
            self::assertGreaterThanOrEqual(
                $rangee[$i - 1]->droite() + MoteurDePlacement::ESPACE - self::EPSILON,
                $rangee[$i]->gauche(),
                \sprintf('%s chevauche %s.', $rangee[$i]->id, $rangee[$i - 1]->id),
            );
        }
    }

    /** La boîte alimente le `viewBox` : elle doit contenir le nœud en attente. */
    public function testLaBoiteEnglobeLeNoeudEnAttente(): void
    {
        $projete = $this->projette($this->enAttente('brouillon-1', 0, AncrageEnAttente::enfantDe('F-parents')));
        $noeud = $projete->noeud('brouillon-1');

        self::assertNotNull($noeud);
        self::assertLessThanOrEqual($projete->boite->x + $projete->boite->w, $noeud->droite());
        self::assertGreaterThanOrEqual($projete->boite->x, $noeud->gauche());
    }

    /**
     * La cible a pu disparaître entre le dépôt de la demande et l'affichage. On omet le
     * nœud plutôt que de le poser à `x = 0`, où il se superposerait à l'ego.
     */
    public function testUnAncrageIntrouvableNAjouteRien(): void
    {
        $projete = $this->projette($this->enAttente('brouillon-1', 0, AncrageEnAttente::enfantDe('F-disparue')));

        self::assertNull($projete->noeud('brouillon-1'));
        self::assertCount(\count($this->partage->noeuds), $projete->noeuds);
        self::assertCount(\count($this->partage->aretes), $projete->aretes);
    }

    /** L'objet de l'étage 1 est partagé : la projection ne doit jamais le modifier. */
    public function testLObjetPartageNEstJamaisModifie(): void
    {
        $avant = [];

        foreach ($this->partage->noeuds as $noeud) {
            $avant[$noeud->id] = $noeud->x;
        }

        $this->projette($this->enAttente('brouillon-1', 0, AncrageEnAttente::conjointDe('P-soeur')));

        foreach ($this->partage->noeuds as $noeud) {
            self::assertSame($avant[$noeud->id], $noeud->x, \sprintf('%s a été modifié dans l\'objet partagé.', $noeud->id));
        }
    }

    private function projette(NoeudEnAttente $attente): Placement
    {
        return (new ProjecteurDePlacement())->projette($this->partage, [$attente]);
    }

    private function enAttente(string $id, int $generation, AncrageEnAttente $ancrage): NoeudEnAttente
    {
        return new NoeudEnAttente(
            new PersonnePlacable(
                id: $id,
                prenom: 'Proposé',
                nom: 'Dupont',
                usage: '',
                sexe: Sex::Inconnu,
                nais: '',
                deces: '',
                anNais: 1996,
                vivant: true,
                generation: $generation,
            ),
            $ancrage,
        );
    }

    /**
     * @param array<string, NoeudPlace> $noeuds
     *
     * @return array<string, NoeudPlace>
     */
    private function filtreValides(array $noeuds): array
    {
        $valides = [];

        foreach ($noeuds as $noeud) {
            if (isset($this->partage->noeuds[$noeud->id])) {
                $valides[$noeud->id] = $noeud;
            }
        }

        return $valides;
    }

    /**
     * @param array<string, NoeudPlace> $noeuds
     *
     * @return list<string>
     */
    private function ordreSurLaRangee(array $noeuds, float $y): array
    {
        $rangee = [];

        foreach ($noeuds as $noeud) {
            if ($noeud->y === $y && MoteurDePlacement::H_FICHE === $noeud->h) {
                $rangee[] = $noeud;
            }
        }

        usort($rangee, static fn (NoeudPlace $a, NoeudPlace $b): int => $a->x <=> $b->x);

        return array_map(static fn (NoeudPlace $n): string => $n->id, $rangee);
    }
}
