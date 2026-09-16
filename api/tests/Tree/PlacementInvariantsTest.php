<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\Service\MesureDeFiche;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\PersonnePlacable;
use PHPUnit\Framework\TestCase;

/**
 * Les invariants du moteur, mesurés sur une topologie **inventée** — le filet de la CI
 * (ADR 0005).
 *
 * Ces tests ne prouvent pas le portage : `graphe.json` n'étant pas versionné, la CI n'a
 * aucun étalon et **peut passer au vert sur un moteur faux**. Ils attrapent les
 * régressions grossières — une rangée qui se mélange, deux fiches qui se chevauchent,
 * l'ego qui dérive — et rien de plus fin.
 *
 * La preuve, c'est {@see PlacementNonRegressionTest}, et elle ne tourne qu'en local.
 */
final class PlacementInvariantsTest extends TestCase
{
    private const float EPSILON = 0.0001;

    /**
     * La taille de l'arbre synthétique, affirmée par les tests qui le parcourent.
     *
     * **Un test qui itère sur un ensemble découvert doit affirmer la taille de cet
     * ensemble** : sans cela, le jour où {@see ArbreSynthetique} rend une liste vide,
     * la boucle ne tourne plus, aucune assertion n'échoue, et le test reste vert en ne
     * vérifiant plus rien.
     */
    private const int PERSONNES = 13;
    private const int FAMILLES = 4;
    private const int RANGEES = 3;

    /**
     * Les filiations : 3 + 2 + 1 + 2. Cinq des treize personnes ne sont enfants
     * d'aucune famille — les quatre grands-parents et la tante, qui entrent dans
     * l'arbre par alliance.
     */
    private const int ENFANTS = 8;

    /** `y = génération × 150`. Aucune liberté verticale. */
    public function testChaqueGenerationEstSurSaRangee(): void
    {
        $jeu = ArbreSynthetique::jeu();
        $placement = (new MoteurDePlacement())->calcule($jeu);

        // Un test qui parcourt un ensemble devenu vide reste vert en ne vérifiant
        // plus rien : on affirme donc sa taille avant de le parcourir.
        self::assertCount(self::PERSONNES, $jeu->personnes);

        foreach ($jeu->personnes as $personne) {
            $noeud = $placement->noeud($personne->id);

            self::assertNotNull($noeud, \sprintf('%s doit être placé.', $personne->id));
            self::assertSame(
                (float) ($personne->generation * MoteurDePlacement::H_RANG),
                $noeud->y,
                \sprintf('%s est en génération %d.', $personne->id, $personne->generation),
            );
            self::assertSame(MoteurDePlacement::H_FICHE, $noeud->h);
        }
    }

    /** L'ego est le point de lecture : c'est lui qu'on centre, pas la boîte. */
    public function testLEgoEstALOrigine(): void
    {
        $placement = (new MoteurDePlacement())->calcule(ArbreSynthetique::jeu());

        self::assertSame(0.0, $placement->noeud(ArbreSynthetique::EGO)?->x);
    }

    /**
     * Le décrochage garantit `x[b] ≥ x[a] + w[a]/2 + 20 + w[b]/2` sur chaque rangée.
     * C'est l'invariant qui rend l'arbre lisible ; s'il tombe, deux fiches se
     * superposent à l'écran.
     */
    public function testAucuneFicheNEnChevaucheUneAutreSurSaRangee(): void
    {
        $placement = (new MoteurDePlacement())->calcule(ArbreSynthetique::jeu());

        $rangees = $this->parRangee($placement->noeuds);
        self::assertCount(self::RANGEES, $rangees, 'Trois générations, donc trois rangées de fiches.');

        foreach ($rangees as $y => $rangee) {
            for ($i = 1, $n = \count($rangee); $i < $n; ++$i) {
                $gauche = $rangee[$i - 1];
                $droite = $rangee[$i];

                self::assertGreaterThanOrEqual(
                    $gauche->droite() + MoteurDePlacement::ESPACE - self::EPSILON,
                    $droite->gauche(),
                    \sprintf('%s et %s se chevauchent sur la rangée %s.', $gauche->id, $droite->id, $y),
                );
            }
        }
    }

    /**
     * L'ordre des fratries est porté par la récursion et **ne doit jamais être
     * réordonné** : les enfants d'une famille, triés par année de naissance, se lisent
     * de gauche à droite dans cet ordre.
     */
    public function testLOrdreDesFratriesEstPreserve(): void
    {
        $jeu = ArbreSynthetique::jeu();
        $placement = (new MoteurDePlacement())->calcule($jeu);
        $verifiees = 0;

        self::assertCount(self::FAMILLES, $jeu->familles);

        foreach ($jeu->familles as $famille) {
            $enfants = $famille->enfants;

            usort($enfants, static function (string $a, string $b) use ($jeu): int {
                $annee = static function (string $id) use ($jeu): int {
                    foreach ($jeu->personnes as $p) {
                        if ($p->id === $id) {
                            return $p->anNais ?? 9999;
                        }
                    }

                    return 9999;
                };

                return $annee($a) <=> $annee($b) ?: strcmp($a, $b);
            });

            $precedent = null;

            foreach ($enfants as $enfantId) {
                $noeud = $placement->noeud($enfantId);
                self::assertNotNull($noeud);

                if (null !== $precedent) {
                    self::assertGreaterThan(
                        $precedent->x,
                        $noeud->x,
                        \sprintf(
                            'Dans %s, %s est né après %s : il doit être à sa droite.',
                            $famille->id,
                            $enfantId,
                            $precedent->id,
                        ),
                    );
                }

                $precedent = $noeud;
                ++$verifiees;
            }
        }

        self::assertSame(self::ENFANTS, $verifiees, 'Chaque enfant de chaque famille doit avoir été comparé.');
    }

    /** Losanges 22 × 14, 26 px sous la rangée des époux. Pas un petit cercle (§ 7.1). */
    public function testLesNoeudsDeFamilleSontDesLosangesSousLeursEpoux(): void
    {
        $jeu = ArbreSynthetique::jeu();
        $placement = (new MoteurDePlacement())->calcule($jeu);

        self::assertCount(self::FAMILLES, $jeu->familles);

        foreach ($jeu->familles as $famille) {
            $noeud = $placement->noeud($famille->id);
            self::assertNotNull($noeud, \sprintf('La famille %s doit avoir son losange.', $famille->id));

            self::assertSame(MoteurDePlacement::LOSANGE_L, $noeud->w);
            self::assertSame(MoteurDePlacement::LOSANGE_H, $noeud->h);

            $premierEpoux = $placement->noeud($famille->epoux[0]);
            self::assertNotNull($premierEpoux);
            self::assertSame(
                $premierEpoux->y + MoteurDePlacement::H_FICHE / 2 + MoteurDePlacement::DESCENTE_NOEUD_FAMILLE,
                $noeud->y,
            );

            // Au barycentre des époux : le losange est le milieu du couple.
            $somme = 0.0;
            foreach ($famille->epoux as $epouxId) {
                $epoux = $placement->noeud($epouxId);
                self::assertNotNull($epoux);
                $somme += $epoux->x;
            }
            self::assertEqualsWithDelta($somme / \count($famille->epoux), $noeud->x, self::EPSILON);
        }
    }

    /** La boîte contient tout, marge de 60 comprise : elle alimente le `viewBox`. */
    public function testLaBoiteEnglobeTousLesNoeuds(): void
    {
        $placement = (new MoteurDePlacement())->calcule(ArbreSynthetique::jeu());
        $boite = $placement->boite;

        foreach ($placement->noeuds as $noeud) {
            self::assertGreaterThanOrEqual($boite->x, $noeud->gauche());
            self::assertLessThanOrEqual($boite->x + $boite->w, $noeud->droite());
            self::assertGreaterThanOrEqual($boite->y, $noeud->haut());
            self::assertLessThanOrEqual($boite->y + $boite->h, $noeud->bas());
        }

        self::assertSame(2.0 * MoteurDePlacement::MARGE_BOITE, $boite->w - $this->etendue($placement->noeuds, 'x'));
        self::assertSame(2.0 * MoteurDePlacement::MARGE_BOITE, $boite->h - $this->etendue($placement->noeuds, 'y'));
    }

    /**
     * Une arête par époux et par enfant, et **aucun lien direct personne → personne** :
     * tout passe par le losange.
     */
    public function testLesAretesPassentToutesParUnNoeudDeFamille(): void
    {
        $jeu = ArbreSynthetique::jeu();
        $placement = (new MoteurDePlacement())->calcule($jeu);

        $attendu = 0;
        $familles = [];

        foreach ($jeu->familles as $famille) {
            $attendu += \count($famille->epoux) + \count($famille->enfants);
            $familles[$famille->id] = true;
        }

        self::assertCount($attendu, $placement->aretes);

        foreach ($placement->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre) {
                self::assertArrayHasKey($arete->vers, $familles, 'Une union pointe vers une famille.');
            } else {
                self::assertArrayHasKey($arete->de, $familles, 'Une filiation part d\'une famille.');
            }
        }
    }

    /** Le divorce reste porté par l'arête : c'est une information, pas une distance (§ 7.3). */
    public function testLeDivorceEstPorteParLesAretesDUnion(): void
    {
        $placement = (new MoteurDePlacement())->calcule(ArbreSynthetique::jeu());

        $divorces = [];

        foreach ($placement->aretes as $arete) {
            if (AreteDePlacement::GENRE_UNION === $arete->genre && $arete->divorce) {
                $divorces[] = $arete->vers;
            }
        }

        self::assertSame(['F-oncle', 'F-oncle'], $divorces, 'Les deux époux de la famille divorcée portent la marque.');
    }

    /**
     * La largeur se mesure en **caractères**, pas en octets — le piège de portage le plus
     * facile à commettre et le plus difficile à voir.
     *
     * Les valeurs attendues viennent de `graphe.py` :
     * `max(104, int(max(len(nom) × 6.6, len(dates) × 5.8) + 28))`.
     */
    public function testLaLargeurSeMesureEnCaracteresPasEnOctets(): void
    {
        $mesure = new MesureDeFiche();

        // « Éon Célestène » : 13 caractères, 16 octets. En octets on obtiendrait 133.
        self::assertSame(113, $mesure->largeur($this->fiche('Éon', 'Célestène', vivant: true)));

        // « 1.1.1900 – 31.12.1980 » : 21 caractères, 23 octets (le tiret cadratin en
        // fait 3). En octets on obtiendrait 161.
        self::assertSame(149, $mesure->largeur($this->fiche('Jade', 'Roc', nais: '1.1.1900', deces: '31.12.1980')));

        // Le plancher de 104 s'applique aux fiches courtes.
        self::assertSame(104, $mesure->largeur($this->fiche('A', 'B')));
        self::assertSame(104, $mesure->largeur($this->fiche('Onyx', 'Persil', nais: '1.1.1900')));
    }

    /**
     * La constante « vivant·e » court-circuite les dates d'une personne vivante. Sans
     * elle, **la largeur de la fiche encoderait la longueur des dates masquées** — une
     * fuite qu'aucun test de liste blanche ne verrait (ADR 0003).
     */
    public function testUnePersonneVivanteNExposePasLaLongueurDeSesDates(): void
    {
        $mesure = new MesureDeFiche();

        $courtes = $this->fiche('Jade', 'Roc', nais: '1.1.1900', vivant: true);
        $longues = $this->fiche('Jade', 'Roc', nais: '1.1.1900', deces: '31.12.1980', vivant: true);

        self::assertSame('vivant·e', $mesure->ligneDates($courtes));
        self::assertSame(
            $mesure->largeur($courtes),
            $mesure->largeur($longues),
            'Deux personnes vivantes aux dates différentes doivent avoir exactement la même largeur.',
        );
    }

    /**
     * **Une même personne porte deux chaînes différentes dans la même réponse, et c'est
     * un invariant.**.
     *
     * Trois formes coexistent, et une seule est mesurée par le moteur :
     *
     * | Forme | Produite par | Pour une personne vivante |
     * |---|---|---|
     * | prose | `UncertainDate::format()` | « 26 septembre 2012 » |
     * | compacte | {@see EtiquetteDeDateGedcom} | `26.9.2012` |
     * | **étiquette du moteur** | {@see MesureDeFiche::ligneDates()} | **`vivant·e`** |
     *
     * Seule la troisième court-circuite sur `vivant`, et seule la troisième décide de `w`.
     * Le motif est de confidentialité, pas de présentation : la largeur est calculée à
     * **l'étage 1 du cache, partagé et sans filtre** (ADR 0005). Si l'étiquette portait
     * les vraies dates d'une personne vivante, **la largeur de son nœud les encoderait
     * pour tous les lecteurs**, y compris hors cercle.
     *
     * Ce test existe parce que l'unification des trois est une simplification que
     * quelqu'un proposera un jour de bonne foi — et qu'elle **ne ferait rougir aucun test
     * d'affichage**.
     */
    public function testLEtiquetteDuMoteurNeSeConfondAvecAucuneAutreForme(): void
    {
        // Des dates assez longues pour dépasser le plancher de 104 px : en deçà, les
        // deux formes mesurent pareil et le test ne prouverait rien.
        $vivante = $this->fiche('Zir', 'Persil', nais: '12.3.1921', deces: '31.12.2024', vivant: true);

        $etiquetteDuMoteur = (new MesureDeFiche())->ligneDates($vivante);
        $formeCompacte = '12.3.1921 – 31.12.2024';

        self::assertSame('vivant·e', $etiquetteDuMoteur);
        self::assertNotSame(
            $formeCompacte,
            $etiquetteDuMoteur,
            'Le nœud et la barre de chronologie portent délibérément deux chaînes différentes pour la même personne.',
        );

        // Et la conséquence qui compte : les deux ne mesurent pas pareil. Servir la forme
        // compacte au moteur changerait la largeur du nœud — donc la position de tous ses
        // voisins de rangée.
        $commeSiElleEtaitDecedee = $this->fiche('Zir', 'Persil', nais: '12.3.1921', deces: '31.12.2024');

        self::assertSame($formeCompacte, (new MesureDeFiche())->ligneDates($commeSiElleEtaitDecedee));
        self::assertNotSame(
            (new MesureDeFiche())->largeur($vivante),
            (new MesureDeFiche())->largeur($commeSiElleEtaitDecedee),
            'La largeur doit distinguer les deux, sinon le court-circuit ne protège rien.',
        );
    }

    /**
     * `etiquetteNom()` compose ce que le moteur mesure : le nom d'usage à défaut du
     * prénom, **réduit à son premier mot**, puis le nom de famille.
     */
    public function testLEtiquetteDuNomNeGardeQueLePremierPrenom(): void
    {
        $mesure = new MesureDeFiche();

        self::assertSame(
            'Marne Sureau',
            $mesure->etiquetteNom($this->fiche('Marne Dolomite', 'Sureau')),
            'Le canevas dessine « Marne Sureau », pas « Marne Dolomite Sureau ».',
        );

        // **Le nom de famille n'est jamais découpé.** C'est la raison pour laquelle le
        // client ne peut pas dériver cette chaîne de `displayName` : aucune règle ne
        // retrouve la frontière entre « Jade » et « de la Tour » dans leur concaténation.
        self::assertSame('Jade de la Tour', $mesure->etiquetteNom($this->fiche('Jade Bérylite', 'de la Tour')));

        // Le nom d'usage prime — c'est le `NICK` du GEDCOM.
        $usage = new PersonnePlacable(
            id: 'X', prenom: 'Marne Dolomite', nom: 'Sureau', usage: 'Dolomite',
            sexe: Sex::Inconnu, nais: '', deces: '', anNais: null, vivant: false, generation: 0,
        );
        self::assertSame('Dolomite Sureau', $mesure->etiquetteNom($usage));
    }

    /**
     * **La largeur d'un nœud dépend de deux chaînes, et de deux seulement.**.
     *
     * Le test recalcule `w` depuis les deux étiquettes **publiques** et le compare à celui
     * du moteur, sur chaque personne. Il tient le contrat du § 5.2 par le **résultat** et
     * non par le chemin : si `etiquetteNom()` et ce que `largeur()` mesure divergeaient
     * d'un seul caractère, il tomberait.
     *
     * C'est le pendant, côté moteur, de l'invariant que `Tree\Serializer` maintient sur
     * les chaînes réellement émises. Les deux gardent la même règle depuis les deux bouts,
     * et aucun ne rend l'autre inutile.
     */
    public function testLaLargeurNeDependQueDesDeuxEtiquettes(): void
    {
        $jeu = ArbreSynthetique::jeu();
        $mesure = new MesureDeFiche();
        $placement = (new MoteurDePlacement())->calcule($jeu);
        $verifiees = 0;

        self::assertCount(self::PERSONNES, $jeu->personnes);

        foreach ($jeu->personnes as $personne) {
            $nom = $mesure->etiquetteNom($personne);
            $dates = $mesure->ligneDates($personne);

            // `iconv_strlen` et non `mb_strlen` : `ext-iconv` est une dépendance ferme
            // déclarée du projet, `ext-mbstring` ne l'est pas. Un test qui tomberait
            // sur une machine où la production marche ne vaut rien.
            $recalculee = max(104, (int) (max(
                (int) iconv_strlen($nom, 'UTF-8') * MoteurDePlacement::LARG_CAR,
                (int) iconv_strlen($dates, 'UTF-8') * MoteurDePlacement::LARG_CAR_DATES,
            ) + 2 * MoteurDePlacement::PAD));

            self::assertSame(
                $recalculee,
                $placement->noeud($personne->id)?->w,
                \sprintf('La largeur de %s doit se retrouver depuis « %s » et « %s ».', $personne->id, $nom, $dates),
            );
            ++$verifiees;
        }

        self::assertSame(self::PERSONNES, $verifiees);
    }

    /**
     * La formule tronque, elle n'arrondit pas — `int()` en Python, `(int)` en PHP, tous
     * deux vers zéro. La confusion a déjà coûté à ce projet, et elle est invisible :
     * elle ne déplace la fiche que d'un pixel, sur une partie seulement des nœuds.
     */
    public function testLaLargeurEstTronqueeEtNonArrondie(): void
    {
        // « Éon Célestène » : 13 signes → 13 × 6.6 + 28 = 113.8.
        // Tronqué : 113. Arrondi : 114.
        self::assertSame(113, (new MesureDeFiche())->largeur($this->fiche('Éon', 'Célestène', vivant: true)));
    }

    private function fiche(string $prenom, string $nom, string $nais = '', string $deces = '', bool $vivant = false): PersonnePlacable
    {
        return new PersonnePlacable(
            id: 'X',
            prenom: $prenom,
            nom: $nom,
            usage: '',
            sexe: Sex::Inconnu,
            nais: $nais,
            deces: $deces,
            anNais: null,
            vivant: $vivant,
            generation: 0,
        );
    }

    /**
     * @param array<string, NoeudPlace> $noeuds
     *
     * @return array<int|string, list<NoeudPlace>>
     */
    private function parRangee(array $noeuds): array
    {
        $rangees = [];

        foreach ($noeuds as $noeud) {
            if (MoteurDePlacement::H_FICHE === $noeud->h) {
                $rangees[(string) $noeud->y][] = $noeud;
            }
        }

        foreach ($rangees as $y => $rangee) {
            usort($rangee, static fn (NoeudPlace $a, NoeudPlace $b): int => $a->x <=> $b->x);
            $rangees[$y] = $rangee;
        }

        return $rangees;
    }

    /** @param array<string, NoeudPlace> $noeuds */
    private function etendue(array $noeuds, string $axe): float
    {
        $min = null;
        $max = null;

        foreach ($noeuds as $noeud) {
            $bas = 'x' === $axe ? $noeud->gauche() : $noeud->haut();
            $haut = 'x' === $axe ? $noeud->droite() : $noeud->bas();

            $min = null === $min ? $bas : min($min, $bas);
            $max = null === $max ? $haut : max($max, $haut);
        }

        return ($max ?? 0.0) - ($min ?? 0.0);
    }
}
