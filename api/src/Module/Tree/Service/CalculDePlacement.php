<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\BoiteEnglobante;
use App\Module\Tree\ValueObject\FamillePlacable;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\PersonnePlacable;
use App\Module\Tree\ValueObject\Placement;

/**
 * Un calcul de placement, avec son état de travail. Portage ligne à ligne de
 * `docs/moteur-retrouve/graphe.py` 391-612.
 *
 * Instancié une fois par appel de {@see MoteurDePlacement::calcule()} : c'est ce qui
 * permet au moteur lui-même de rester sans état comme l'exige l'ADR 0005, alors que
 * l'algorithme a besoin de trois mémoïsations et d'un tableau de positions.
 *
 * ## Les quatre pièges du portage Python → PHP, et comment ils sont fermés ici
 *
 * Ils ne sont pas théoriques : chacun déplace réellement des nœuds, et aucun ne serait
 * vu par un test à ± 10 %. C'est pour eux que le critère d'acceptation est exact.
 *
 * **1. `len()` compte des caractères, `strlen()` compte des octets.** La largeur d'une
 * fiche est mesurée sur son texte ; « vivant·e » fait 8 caractères et 9 octets, et le
 * moindre nom accentué décale la fiche. On mesure donc avec `iconv_strlen()` — et pas
 * `mb_strlen()`, parce que `ext-iconv` est une dépendance ferme déclarée du projet
 * alors que `ext-mbstring` ne l'est pas.
 *
 * **2. L'ordre de parcours départage les égalités.** Python trie de façon *stable* et
 * itère ses dictionnaires dans l'ordre d'insertion. PHP aussi — mais s'y fier revient
 * à parier sur un détail d'implémentation. Les deux tris que le script d'origine laisse
 * à la stabilité (`sorted(reste, key=gen)` et `sorted(par_gen[g], key=posx)`) sont donc
 * écrits ici avec le **rang d'origine en critère de départage explicite** : le
 * comportement est prouvé par le code, pas hérité du moteur de tri.
 *
 * **3. Ne jamais relire un identifiant depuis une clé de tableau.** PHP convertit
 * silencieusement la clé `"12"` en entier `12` ; les identifiants de l'application sont
 * ceux de `tree_person`, donc numériques. On indexe par identifiant pour la recherche
 * en O(1), mais l'**ordre** est toujours porté par une liste de chaînes séparée
 * (`$ordrePersonnes`, `$ordrePosx`), jamais par `array_keys()`. Sans cette discipline,
 * un `strcmp()` recevrait un entier et lèverait une `TypeError` en mode strict.
 *
 * **4. `an_nais or 9999` n'est pas `an_nais ?? 9999`.** En Python, `0` est faux autant
 * que `None`. Le cas est improbable mais la traduction paresseuse est fausse, et elle
 * ne se verrait que sur une donnée aberrante.
 *
 * Reste la division flottante du barycentre (`sum / len`) : elle est reproduite à
 * l'identique en accumulant **dans le même ordre** et en ne divisant **qu'une fois, à
 * la fin**. Arrondir à chaque passe du point fixe donnerait un autre résultat après
 * 12 itérations.
 *
 * @internal cet objet n'est pas un service : il ne se déclare pas au conteneur et ne
 *           s'utilise que via {@see MoteurDePlacement}
 */
final class CalculDePlacement
{
    /** @var array<string, PersonnePlacable> */
    private array $personnes = [];

    /**
     * L'ordre d'origine des personnes — celui du GEDCOM. Il est **une entrée du
     * calcul** : la phase 2 et le décrochage y départagent les égalités.
     *
     * @var list<string>
     */
    private array $ordrePersonnes = [];

    /** @var list<FamillePlacable> */
    private array $familles;

    /** @var array<string, int> */
    private array $gen = [];

    /**
     * Les trois tables d'adjacence, reconstruites depuis les familles **dans leur
     * ordre**, exactement comme `graphe.py`. Ce sont des listes brutes : ni triées, ni
     * dédoublonnées. Les trier ici déplacerait des nœuds, parce que le repli « je me
     * raccroche à mon premier conjoint » de la phase 2 lit `conjoints[k][0]`.
     *
     * @var array<string, list<string>>
     */
    private array $parents = [];

    /** @var array<string, list<string>> */
    private array $enfants = [];

    /** @var array<string, list<string>> */
    private array $conjoints = [];

    /** @var array<string, int> */
    private array $largeurs = [];

    /** @var array<string, list<string>> */
    private array $memoRangFratrie = [];

    /** @var array<string, int> */
    private array $memoLargFratrie = [];

    /** @var array<string, int> */
    private array $memoLargBloc = [];

    /** @var array<string, float> */
    private array $posx = [];

    /**
     * L'ordre d'insertion dans `$posx`, tenu à la main parce qu'on ne relit jamais une
     * clé de tableau (piège 3). C'est l'équivalent exact de `list(posx)` en Python.
     *
     * @var list<string>
     */
    private array $ordrePosx = [];

    /** @var array<string, true> */
    private array $placeFaite = [];

    /**
     * Les personnes par rangée, triées de gauche à droite après décrochage. Porte
     * l'ordre de dessin final.
     *
     * @var array<int, list<string>>
     */
    private array $parRangee = [];

    public function __construct(
        private readonly JeuDePlacement $jeu,
        private readonly MesureDeFiche $mesure = new MesureDeFiche(),
    ) {
        foreach ($jeu->personnes as $p) {
            $this->personnes[$p->id] = $p;
            $this->ordrePersonnes[] = $p->id;
            $this->gen[$p->id] = $p->generation;
        }

        $this->familles = $jeu->familles;

        $this->construitLesAdjacences();
    }

    public function execute(): Placement
    {
        $this->poseAscendance();
        $this->posePointFixe();
        $this->decrocheLesChevauchements();

        $noeuds = $this->composeLesNoeuds();
        $noeuds = $this->recentreSurLEgo($noeuds);

        return new Placement(
            $noeuds,
            $this->composeLesAretes(),
            $this->boiteEnglobante($noeuds),
            $this->jeu->egoId,
        );
    }

    // ------------------------------------------------------------------ adjacences

    /**
     * `graphe.py` 161-168, à ceci près qu'il lit `HUSB` et `WIFE` alors qu'on reçoit
     * déjà `epoux = [p for p in (h, w) if p]`. La traduction est exacte : les deux
     * boucles ne distinguent jamais le mari de l'épouse, et `conjoints` n'est alimenté
     * que lorsque les deux sont présents.
     */
    private function construitLesAdjacences(): void
    {
        foreach ($this->familles as $famille) {
            $epoux = $this->connus($famille->epoux);

            foreach ($this->connus($famille->enfants) as $enfant) {
                // Écrasement volontaire : `graphe.py` fait `parents[c] = …`, donc la
                // **dernière** famille rencontrée gagne si un enfant en a deux. C'est
                // rare (adoption) et la reproduire à l'identique est le contrat.
                $this->parents[$enfant] = $epoux;

                foreach ($epoux as $parent) {
                    $this->enfants[$parent][] = $enfant;
                }
            }

            if (2 === \count($epoux)) {
                $this->conjoints[$epoux[0]][] = $epoux[1];
                $this->conjoints[$epoux[1]][] = $epoux[0];
            }
        }
    }

    /**
     * Filet défensif absent du script d'origine, qui lèverait une `KeyError`. Les
     * données réelles sont cohérentes ; une fixture mal construite ne doit pas faire
     * tomber le moteur, elle doit produire un graphe amputé et visible.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function connus(array $ids): array
    {
        $sortie = [];

        foreach ($ids as $id) {
            if (isset($this->personnes[$id])) {
                $sortie[] = $id;
            }
        }

        return $sortie;
    }

    // ------------------------------------------------------------------ mesures

    /**
     * `graphe.py` 412-416, délégué à {@see MesureDeFiche} pour que les nœuds « en
     * attente » de l'étage 2 soient mesurés par exactement la même règle.
     *
     * Mémoïsé par identifiant : la largeur est relue des dizaines de fois par nœud
     * pendant les 12 passes du point fixe et le décrochage.
     */
    private function largeur(string $id): int
    {
        return $this->largeurs[$id] ??= $this->mesure->largeur($this->personnes[$id]);
    }

    // ------------------------------------------------------------------ parenté

    /**
     * `graphe.py` 419-426. Le père est le premier parent masculin, la mère la première
     * parente féminine ; à défaut de sexe connu, on prend les parents dans l'ordre de
     * la famille, ce qui garde un côté gauche et un côté droit stables.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function pereMere(string $id): array
    {
        $ps = $this->parents[$id] ?? [];
        $pere = null;
        $mere = null;

        foreach ($ps as $x) {
            if (Sex::Masculin === $this->personnes[$x]->sexe) {
                $pere = $x;
                break;
            }
        }

        foreach ($ps as $x) {
            if (Sex::Feminin === $this->personnes[$x]->sexe) {
                $mere = $x;
                break;
            }
        }

        if (null === $pere && null === $mere && [] !== $ps) {
            $pere = $ps[0];
            $mere = $ps[1] ?? null;
        }

        return [$pere, $mere];
    }

    /**
     * `graphe.py` 429-446 : la fratrie de `$id`, dans l'ordre des naissances, conjoints
     * accolés.
     *
     * Le conjoint de `$id` lui-même est exclu : c'est l'autre parent de la génération
     * suivante, il possède son propre bloc d'ascendance de l'autre côté de l'arbre.
     *
     * Mémoïsé ici alors que le script recalcule à chaque appel — la fonction est pure
     * et ses entrées sont figées, le résultat est identique.
     *
     * @return list<string>
     */
    private function rangFratrie(string $id): array
    {
        if (isset($this->memoRangFratrie[$id])) {
            return $this->memoRangFratrie[$id];
        }

        $fratrie = [$id];
        $vus = [$id => true];

        foreach ($this->parents[$id] ?? [] as $parent) {
            foreach ($this->enfants[$parent] ?? [] as $enfant) {
                if (!isset($vus[$enfant])) {
                    $vus[$enfant] = true;
                    $fratrie[] = $enfant;
                }
            }
        }

        // Ordre total (année, nom, identifiant) : le résultat ne dépend donc pas de
        // l'ordre d'entrée, qui en Python venait d'un `set` au parcours arbitraire.
        usort($fratrie, $this->comparateurDeFratrie(...));

        $dansLaFratrie = [];
        foreach ($fratrie as $membre) {
            $dansLaFratrie[$membre] = true;
        }

        $sortie = [];
        $deja = [];

        foreach ($fratrie as $membre) {
            $sortie[] = $membre;
            $deja[$membre] = true;

            if ($membre === $id) {
                continue;
            }

            foreach ($this->conjointsTries($membre) as $conjoint) {
                if (isset($deja[$conjoint]) || isset($dansLaFratrie[$conjoint])) {
                    continue;
                }

                if ($this->genDe($conjoint) !== $this->genDe($membre)) {
                    continue;
                }

                $sortie[] = $conjoint;
                $deja[$conjoint] = true;
            }
        }

        return $this->memoRangFratrie[$id] = $sortie;
    }

    /**
     * `sorted(set(conjoints.get(s, [])))` : dédoublonné puis trié par identifiant.
     *
     * @return list<string>
     */
    private function conjointsTries(string $id): array
    {
        $uniques = [];
        $vus = [];

        foreach ($this->conjoints[$id] ?? [] as $conjoint) {
            if (!isset($vus[$conjoint])) {
                $vus[$conjoint] = true;
                $uniques[] = $conjoint;
            }
        }

        usort($uniques, strcmp(...));

        return $uniques;
    }

    /** Tri de fratrie : année de naissance, puis nom, puis identifiant. */
    private function comparateurDeFratrie(string $a, string $b): int
    {
        $ordre = $this->anneeDeTri($a) <=> $this->anneeDeTri($b);

        if (0 !== $ordre) {
            return $ordre;
        }

        $ordre = strcmp($this->personnes[$a]->nom, $this->personnes[$b]->nom);

        return 0 !== $ordre ? $ordre : strcmp($a, $b);
    }

    /** Tri des enfants en phase 2 : année de naissance, puis identifiant. Pas de nom. */
    private function comparateurDEnfants(string $a, string $b): int
    {
        $ordre = $this->anneeDeTri($a) <=> $this->anneeDeTri($b);

        return 0 !== $ordre ? $ordre : strcmp($a, $b);
    }

    /**
     * `an_nais or 9999` — piège 4 : en Python, `0` est faux autant que `None`. Les
     * personnes sans date de naissance ferment la fratrie.
     */
    private function anneeDeTri(string $id): int
    {
        $annee = $this->personnes[$id]->anNais;

        return null === $annee || 0 === $annee ? 9999 : $annee;
    }

    private function genDe(string $id): ?int
    {
        return $this->gen[$id] ?? null;
    }

    // ------------------------------------------------------------------ largeurs de bloc

    /** `graphe.py` 452-456. */
    private function largFratrie(string $id): int
    {
        if (isset($this->memoLargFratrie[$id])) {
            return $this->memoLargFratrie[$id];
        }

        $fratrie = $this->rangFratrie($id);
        $somme = 0;

        foreach ($fratrie as $membre) {
            $somme += $this->largeur($membre);
        }

        return $this->memoLargFratrie[$id] = $somme + MoteurDePlacement::ESPACE * (\count($fratrie) - 1);
    }

    /**
     * `graphe.py` 459-471 : la largeur du sous-arbre d'ascendance de `$id`, de bas en
     * haut — bloc paternel + 70 + bloc maternel, ou la fratrie si elle est plus large.
     *
     * `$vus` est le **garde-fou contre les boucles de consanguinité** : l'implexe existe
     * dans cet arbre, et sans lui la récursion ne terminerait pas. Il est passé **par
     * valeur** (`vus | {p}` en Python, la copie sur écriture des tableaux PHP ici) :
     * chaque branche a le sien, deux branches sœurs ne se bloquent pas l'une l'autre.
     *
     * Le retour du garde-fou n'est **pas mémoïsé**, volontairement : il dépend du chemin
     * parcouru, pas de `$id` seul. Le mémoïser figerait une valeur partielle.
     *
     * @param array<string, true> $vus
     */
    private function largBloc(string $id, array $vus = []): int
    {
        if (isset($this->memoLargBloc[$id])) {
            return $this->memoLargBloc[$id];
        }

        if (isset($vus[$id])) {
            return $this->largFratrie($id);
        }

        $vus[$id] = true;

        [$pere, $mere] = $this->pereMere($id);

        // L'ordre des deux appels compte : c'est lui qui décide quelle branche remplit
        // la mémoïsation en premier, donc laquelle voit l'autre comme déjà connue.
        $largeurPere = null !== $pere ? $this->largBloc($pere, $vus) : 0;
        $largeurMere = null !== $mere ? $this->largBloc($mere, $vus) : 0;

        $ascendance = $largeurPere + $largeurMere
            + (null !== $pere && null !== $mere ? MoteurDePlacement::GAP_BLOC : 0);

        return $this->memoLargBloc[$id] = max($ascendance, $this->largFratrie($id));
    }

    // ------------------------------------------------------------------ phase 1

    /** `graphe.py` 506 : la récursion part de l'ego, à `x = 0`. */
    private function poseAscendance(): void
    {
        if (isset($this->personnes[$this->jeu->egoId])) {
            $this->poseBloc($this->jeu->egoId, 0.0);
        }
    }

    /**
     * `graphe.py` 478-503 : descente de haut en bas.
     *
     * Le bloc paternel occupe toute la moitié gauche, le maternel toute la moitié
     * droite, et ainsi de suite à chaque génération. Une personne ne peut donc jamais
     * se retrouver du mauvais côté de l'arbre : **son côté est décidé par la récursion,
     * pas par un barycentre** — c'est le point que la première lecture de la sortie
     * avait pris pour un ordre par branche.
     *
     * Le recentrage de la fratrie sous le barycentre du couple parental est ce qui
     * produit les liens courts.
     */
    private function poseBloc(string $id, float $x0): void
    {
        if (isset($this->placeFaite[$id])) {
            return;
        }

        $this->placeFaite[$id] = true;

        $largeurBloc = $this->largBloc($id);
        [$pere, $mere] = $this->pereMere($id);

        if (null !== $pere || null !== $mere) {
            $largeurPere = null !== $pere ? $this->largBloc($pere) : 0;
            $largeurMere = null !== $mere ? $this->largBloc($mere) : 0;
            $total = $largeurPere + $largeurMere
                + (null !== $pere && null !== $mere ? MoteurDePlacement::GAP_BLOC : 0);

            $x = $x0 + ($largeurBloc - $total) / 2;

            if (null !== $pere) {
                $this->poseBloc($pere, $x);
                $x += $largeurPere + MoteurDePlacement::GAP_BLOC;
            }

            if (null !== $mere) {
                $this->poseBloc($mere, $x);
            }
        }

        $ancres = [];

        foreach ([$pere, $mere] as $ancetre) {
            if (null !== $ancetre && isset($this->posx[$ancetre])) {
                $ancres[] = $ancetre;
            }
        }

        // À défaut de parents placés, la fratrie se centre sur le bloc lui-même.
        $centre = [] !== $ancres
            ? $this->barycentre($ancres)
            : $x0 + $largeurBloc / 2;

        $x = $centre - $this->largFratrie($id) / 2;

        foreach ($this->rangFratrie($id) as $membre) {
            if (!isset($this->posx[$membre])) {
                $this->posePosx($membre, $x + $this->largeur($membre) / 2);
            }

            $x += $this->largeur($membre) + MoteurDePlacement::ESPACE;
        }
    }

    // ------------------------------------------------------------------ phase 2

    /**
     * `graphe.py` 510-545 : tout ce qui n'est ni ancêtre ni frère d'ancêtre — les
     * cousins, leurs conjoints, leurs enfants — se range sous ses propres parents.
     *
     * Douze passes de point fixe : à chaque tour, les personnes dont un parent vient
     * d'être placé le deviennent à leur tour, et la vague descend d'une génération.
     */
    private function posePointFixe(): void
    {
        for ($passe = 0; $passe < MoteurDePlacement::PASSES_POINT_FIXE; ++$passe) {
            $reste = [];

            foreach ($this->ordrePersonnes as $id) {
                if (!isset($this->posx[$id])) {
                    $reste[] = $id;
                }
            }

            if ([] === $reste) {
                break;
            }

            foreach ($this->triStableParGeneration($reste) as $id) {
                $this->poseNonAscendant($id);
            }

            $this->poseLesConjointsDesNouveauxVenus();
        }

        // Filet de sécurité du script d'origine : personne ne reste sans abscisse.
        foreach ($this->ordrePersonnes as $id) {
            if (!isset($this->posx[$id])) {
                $this->posePosx($id, 0.0);
            }
        }
    }

    private function poseNonAscendant(string $id): void
    {
        $ancres = [];

        foreach ($this->parents[$id] ?? [] as $parent) {
            if (isset($this->posx[$parent])) {
                $ancres[] = $parent;
            }
        }

        if ([] === $ancres) {
            $this->poseParRepli($id);

            return;
        }

        $centre = $this->barycentre($ancres);

        // La fratrie de référence est celle du **premier** parent placé : c'est
        // l'ordre de `epoux` qui la désigne, d'où l'interdiction de trier cette liste.
        $fratrie = $this->enfantsTriesDe($ancres[0]);

        $largeur = 0;
        foreach ($fratrie as $membre) {
            $largeur += $this->largeur($membre);
        }
        $largeur += MoteurDePlacement::ESPACE * (\count($fratrie) - 1);

        $x = $centre - $largeur / 2;

        foreach ($fratrie as $membre) {
            if (!isset($this->posx[$membre])) {
                $this->posePosx($membre, $x + $this->largeur($membre) / 2);
            }

            $x += $this->largeur($membre) + MoteurDePlacement::ESPACE;
        }
    }

    /**
     * `graphe.py` 518-528. Sans ce repli, une personne dont le seul lien va vers le bas
     * tombait à `x = 0` — le commentaire d'origine parle de « pères à 7 000 px de leur
     * fils ».
     *
     * Un conjoint d'abord, sinon le barycentre des enfants. L'ordre compte : c'est
     * `conjoints[k][0]`, c'est-à-dire la **première union rencontrée**, pas la première
     * par identifiant.
     */
    private function poseParRepli(string $id): void
    {
        foreach ($this->conjoints[$id] ?? [] as $conjoint) {
            if (isset($this->posx[$conjoint]) && $this->genDe($conjoint) === $this->genDe($id)) {
                $this->posePosx($id, $this->posx[$conjoint]
                    + $this->largeur($conjoint) / 2
                    + MoteurDePlacement::ESPACE
                    + $this->largeur($id) / 2);

                return;
            }
        }

        $enfantsPlaces = [];

        foreach ($this->enfants[$id] ?? [] as $enfant) {
            if (isset($this->posx[$enfant])) {
                $enfantsPlaces[] = $enfant;
            }
        }

        if ([] !== $enfantsPlaces) {
            $this->posePosx($id, $this->barycentre($enfantsPlaces));
        }
    }

    /** `graphe.py` 539-542 : les conjoints des nouveaux venus se posent à côté d'eux. */
    private function poseLesConjointsDesNouveauxVenus(): void
    {
        // Instantané pris avant la boucle — `list(posx)` en Python : les conjoints
        // posés pendant ce tour ne sont pas parcourus à leur tour.
        $instantane = $this->ordrePosx;

        foreach ($instantane as $id) {
            foreach ($this->conjointsTries($id) as $conjoint) {
                if (isset($this->posx[$conjoint]) || $this->genDe($conjoint) !== $this->genDe($id)) {
                    continue;
                }

                $this->posePosx($conjoint, $this->posx[$id]
                    + $this->largeur($id) / 2
                    + MoteurDePlacement::ESPACE
                    + $this->largeur($conjoint) / 2);
            }
        }
    }

    /**
     * `sorted(set(enfants.get(p, [])), key=(an_nais, id))` — dédoublonné, trié par
     * année puis identifiant. **Sans le nom**, contrairement au tri de fratrie de la
     * phase 1 : les deux tris diffèrent dans le script d'origine, et les confondre
     * décale les fratries de cousins.
     *
     * @return list<string>
     */
    private function enfantsTriesDe(string $id): array
    {
        $uniques = [];
        $vus = [];

        foreach ($this->enfants[$id] ?? [] as $enfant) {
            if (!isset($vus[$enfant])) {
                $vus[$enfant] = true;
                $uniques[] = $enfant;
            }
        }

        usort($uniques, $this->comparateurDEnfants(...));

        return $uniques;
    }

    // ------------------------------------------------------------------ phase 3

    /**
     * `graphe.py` 549-559 : on écarte les chevauchements rangée par rangée, par poussée
     * vers la droite, **sans jamais changer l'ordre** — l'ordre vient de la récursion
     * et il porte la structure.
     */
    private function decrocheLesChevauchements(): void
    {
        foreach ($this->ordrePersonnes as $id) {
            $this->parRangee[$this->gen[$id]][] = $id;
        }

        ksort($this->parRangee, \SORT_NUMERIC);

        foreach ($this->parRangee as $rangee => $membres) {
            $membres = $this->triStableParPosition($membres);
            $this->parRangee[$rangee] = $membres;

            $total = \count($membres);

            for ($i = 1; $i < $total; ++$i) {
                $gauche = $membres[$i - 1];
                $droite = $membres[$i];

                $minimum = $this->posx[$gauche]
                    + $this->largeur($gauche) / 2
                    + MoteurDePlacement::ESPACE
                    + $this->largeur($droite) / 2;

                if ($this->posx[$droite] < $minimum) {
                    $this->posx[$droite] = $minimum;
                }
            }
        }
    }

    // ------------------------------------------------------------------ coordonnées

    /**
     * `graphe.py` 562-579 : les fiches d'abord, rangée par rangée, puis les nœuds de
     * jonction familiaux.
     *
     * @return array<string, NoeudPlace>
     */
    private function composeLesNoeuds(): array
    {
        $noeuds = [];

        foreach ($this->parRangee as $rangee => $membres) {
            $y = (float) ($rangee * MoteurDePlacement::H_RANG);

            foreach ($membres as $id) {
                $noeuds[$id] = new NoeudPlace(
                    $id,
                    $this->posx[$id],
                    $y,
                    $this->largeur($id),
                    MoteurDePlacement::H_FICHE,
                );
            }
        }

        foreach ($this->familles as $famille) {
            $epoux = [];
            foreach ($famille->epoux as $id) {
                if (isset($noeuds[$id])) {
                    $epoux[] = $id;
                }
            }

            $enfants = [];
            foreach ($famille->enfants as $id) {
                if (isset($noeuds[$id])) {
                    $enfants[] = $id;
                }
            }

            if ([] !== $epoux) {
                $x = $this->barycentreDe($noeuds, $epoux);
                $y = $noeuds[$epoux[0]]->y
                    + MoteurDePlacement::H_FICHE / 2
                    + MoteurDePlacement::DESCENTE_NOEUD_FAMILLE;
            } elseif ([] !== $enfants) {
                // Famille sans époux connu : le losange se pose une rangée au-dessus
                // de ses enfants, au milieu d'eux.
                $x = $this->barycentreDe($noeuds, $enfants);
                $y = min(array_map(static fn (string $id): float => $noeuds[$id]->y, $enfants))
                    - MoteurDePlacement::H_RANG
                    + MoteurDePlacement::H_FICHE / 2
                    + MoteurDePlacement::DESCENTE_NOEUD_FAMILLE;
            } else {
                continue;
            }

            $noeuds[$famille->id] = new NoeudPlace(
                $famille->id,
                $x,
                $y,
                MoteurDePlacement::LOSANGE_L,
                MoteurDePlacement::LOSANGE_H,
            );
        }

        return $noeuds;
    }

    /**
     * `graphe.py` 583-585 : **l'ego est le point de lecture, c'est lui qu'on centre, pas
     * la boîte.** Un arbre qui grandit d'un côté ne doit pas faire glisser la vue.
     *
     * @param array<string, NoeudPlace> $noeuds
     *
     * @return array<string, NoeudPlace>
     */
    private function recentreSurLEgo(array $noeuds): array
    {
        $ego = $noeuds[$this->jeu->egoId] ?? null;

        if (null === $ego) {
            return $noeuds;
        }

        $dx = -$ego->x;
        $recentres = [];

        foreach ($noeuds as $noeud) {
            $recentres[$noeud->id] = $noeud->decaleDe($dx);
        }

        return $recentres;
    }

    /**
     * `graphe.py` 588-594. Jamais de lien direct personne → personne.
     *
     * @return list<AreteDePlacement>
     */
    private function composeLesAretes(): array
    {
        $aretes = [];

        foreach ($this->familles as $famille) {
            foreach ($famille->epoux as $epoux) {
                $aretes[] = AreteDePlacement::union($epoux, $famille->id, $famille->aDivorce);
            }

            foreach ($famille->enfants as $enfant) {
                $aretes[] = AreteDePlacement::filiation($famille->id, $enfant);
            }
        }

        return $aretes;
    }

    /**
     * `graphe.py` 605-609 : la boîte englobante, marge de 60 comprise.
     *
     * @param array<string, NoeudPlace> $noeuds
     */
    private function boiteEnglobante(array $noeuds): BoiteEnglobante
    {
        if ([] === $noeuds) {
            return new BoiteEnglobante(0.0, 0.0, 0.0, 0.0);
        }

        $xMin = $xMax = $yMin = $yMax = null;

        foreach ($noeuds as $noeud) {
            $xMin = null === $xMin ? $noeud->gauche() : min($xMin, $noeud->gauche());
            $xMax = null === $xMax ? $noeud->droite() : max($xMax, $noeud->droite());
            $yMin = null === $yMin ? $noeud->haut() : min($yMin, $noeud->haut());
            $yMax = null === $yMax ? $noeud->bas() : max($yMax, $noeud->bas());
        }

        $marge = MoteurDePlacement::MARGE_BOITE;

        return new BoiteEnglobante(
            $xMin - $marge,
            $yMin - $marge,
            $xMax - $xMin + 2 * $marge,
            $yMax - $yMin + 2 * $marge,
        );
    }

    // ------------------------------------------------------------------ outils

    /**
     * `sum(posx[q] for q in ids) / len(ids)`.
     *
     * On accumule dans l'ordre donné et on ne divise **qu'une fois, à la fin** : arrondir
     * à chaque passe du point fixe ne donne pas le même résultat après 12 itérations.
     *
     * @param list<string> $ids
     */
    private function barycentre(array $ids): float
    {
        $somme = 0.0;

        foreach ($ids as $id) {
            $somme += $this->posx[$id];
        }

        return $somme / \count($ids);
    }

    /**
     * @param array<string, NoeudPlace> $noeuds
     * @param list<string>              $ids
     */
    private function barycentreDe(array $noeuds, array $ids): float
    {
        $somme = 0.0;

        foreach ($ids as $id) {
            $somme += $noeuds[$id]->x;
        }

        return $somme / \count($ids);
    }

    private function posePosx(string $id, float $x): void
    {
        $this->posx[$id] = $x;
        $this->ordrePosx[] = $id;
    }

    /**
     * `sorted(reste, key=gen)` — piège 2 : Python trie de façon stable, donc les
     * personnes d'une même génération gardent l'ordre du GEDCOM. On l'écrit ici au lieu
     * de s'en remettre au moteur de tri de PHP.
     *
     * @param list<string> $ids déjà dans l'ordre d'origine
     *
     * @return list<string>
     */
    private function triStableParGeneration(array $ids): array
    {
        return $this->triStable($ids, fn (string $id): float => (float) $this->gen[$id]);
    }

    /**
     * `sorted(par_gen[g], key=posx)` — même piège, et c'est celui qui compte le plus :
     * deux fiches exactement à la même abscisse sont départagées par l'ordre du GEDCOM,
     * et c'est cet ordre qui décide laquelle des deux sera poussée vers la droite.
     *
     * @param list<string> $ids déjà dans l'ordre d'origine
     *
     * @return list<string>
     */
    private function triStableParPosition(array $ids): array
    {
        return $this->triStable($ids, fn (string $id): float => $this->posx[$id]);
    }

    /**
     * @param list<string>            $ids
     * @param \Closure(string): float $cle
     *
     * @return list<string>
     */
    private function triStable(array $ids, \Closure $cle): array
    {
        $decore = [];

        foreach ($ids as $rang => $id) {
            $decore[] = [$cle($id), $rang, $id];
        }

        usort(
            $decore,
            /**
             * @param array{0: float, 1: int, 2: string} $a
             * @param array{0: float, 1: int, 2: string} $b
             */
            static function (array $a, array $b): int {
                $ordre = $a[0] <=> $b[0];

                return 0 !== $ordre ? $ordre : $a[1] <=> $b[1];
            },
        );

        $sortie = [];

        foreach ($decore as $entree) {
            $sortie[] = $entree[2];
        }

        return $sortie;
    }
}
