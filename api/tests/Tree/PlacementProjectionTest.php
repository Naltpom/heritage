<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\Service\ProjecteurDePlacement;
use App\Module\Tree\ValueObject\AncrageEnAttente;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\BoiteEnglobante;
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

    /**
     * ════════════════════════════════════════════════════════════════════════════
     *  DEUX PARENTS PROPOSÉS POUR LE MÊME ENFANT : **UN SEUL LOSANGE**.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * C'est la forme la plus courante de la file : « j'ajoute le père et la mère de
     * quelqu'un ». Le premier se pose comme parent de l'enfant — ce qui lui crée un
     * losange, avec la filiation dessous —, la seconde arrive comme sa conjointe.
     *
     * En lui créant son propre losange, le couple en portait **deux** : l'un les joignant,
     * l'autre tenant l'enfant sous un seul parent. Deux unions dessinées là où il n'y en a
     * qu'une, et un enfant qui n'a l'air d'avoir qu'un parent.
     *
     * `P-gm-mat` est choisie parce qu'elle n'a **pas** de parents dans l'arbre
     * synthétique : c'est la condition du cas, et c'est aussi celle du cas réel — on
     * ajoute des parents à qui n'en a pas.
     */
    public function testLaConjointeProposeeRejointLeLosangeQueSonConjointVientDeCreer(): void
    {
        $pere = $this->enAttente('#12/p1', -3, AncrageEnAttente::parentDe('P-gm-mat'));
        $mere = $this->enAttente('#12/p2', -3, AncrageEnAttente::conjointDe('#12/p1'));

        $projete = (new ProjecteurDePlacement())->projette($this->partage, [$pere, $mere]);

        $losanges = [];
        foreach ($projete->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre
                && \in_array($arete->de, ['#12/p1', '#12/p2'], true)
            ) {
                $losanges[$arete->vers] = true;
            }
        }

        self::assertCount(1, $losanges, 'Le couple proposé partage un losange, il n’en porte pas deux.');

        $losange = array_key_first($losanges);
        self::assertIsString($losange);

        // … et l'enfant pend bien à ce losange-là, pas à un second.
        $filiations = [];
        foreach ($projete->aretes as $arete) {
            if (AreteDePlacement::GENRE_FILIATION === $arete->genre && 'P-gm-mat' === $arete->vers) {
                $filiations[] = $arete->de;
            }
        }

        self::assertSame([$losange], $filiations, 'L’enfant pend au losange du couple, et à lui seul.');
    }

    /**
     * La garde du cas précédent : **une famille validée ne se laisse pas rejoindre**,
     * même quand elle n'a qu'un époux connu.
     *
     * Un remariage proposé absorberait sinon la nouvelle conjointe dans l'ancien couple —
     * et lui donnerait par la même occasion les enfants du premier lit. Seul un losange
     * inventé par cette projection se partage : il n'existe nulle part ailleurs, et
     * personne d'autre ne le regarde.
     *
     * Le placement est écrit à la main plutôt que pris de l'arbre synthétique : il faut
     * une famille à **un seul** époux, portée par un identifiant de la forme `f<N>` —
     * celle que `estFamille()` reconnaît, et donc celle que la garde interroge.
     */
    public function testUnConjointProposeNeRejointPasUneFamilleValideeAUnSeulEpoux(): void
    {
        $partage = new Placement(
            [
                'p1' => new NoeudPlace('p1', 0.0, 0.0, 120, MoteurDePlacement::H_FICHE),
                'p2' => new NoeudPlace('p2', 300.0, 150.0, 120, MoteurDePlacement::H_FICHE),
                'f9' => new NoeudPlace('f9', 0.0, 46.0, MoteurDePlacement::LOSANGE_L, MoteurDePlacement::LOSANGE_H),
            ],
            [
                AreteDePlacement::union('p1', 'f9', false),
                AreteDePlacement::filiation('f9', 'p2'),
            ],
            new BoiteEnglobante(-400.0, -260.0, 800.0, 400.0),
            'p1',
        );

        $projete = (new ProjecteurDePlacement())->projette(
            $partage,
            [$this->enAttente('#12/p9', 0, AncrageEnAttente::conjointDe('p1'))],
        );

        $losanges = [];
        foreach ($projete->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre && '#12/p9' === $arete->de) {
                $losanges[] = $arete->vers;
            }
        }

        self::assertCount(1, $losanges);
        self::assertNotSame('f9', $losanges[0], 'La conjointe proposée n’entre pas dans le mariage validé.');
        self::assertArrayNotHasKey($losanges[0], $partage->noeuds, 'Son losange est neuf.');
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
