<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\Branch;
use App\Module\Tree\Enum\ParenteDansLArbre;
use App\Module\Tree\ValueObject\FamillePlacable;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\PlacementLogique;

/**
 * Les colonnes dérivées de l'arbre : `tree_person.generation` et `tree_person.branch`,
 * `tree_family.generation` et `tree_family.rank`.
 *
 * **Portage de `graphe.py`, lignes 178 à 281, pas une réinvention.** Les règles ci-dessous
 * ont l'air arbitraires quand on les lit ; elles ne le sont pas, elles ont été mises au
 * point contre ces données précises. Les commentaires du script d'origine, repris ici,
 * disent ce que chacune répare.
 *
 * **Ce service ne touche pas la base**, et c'est ce qui le rend testable : il prend des
 * adjacences et rend des entiers. C'est {@see RecalculDesColonnesDerivees} qui lit et
 * écrit. Le § 3.1 du modèle dit la même chose autrement : ces colonnes sont « recalculées
 * et persistées par un service de `Tree` après toute écriture structurante », et ce n'est
 * **pas** le moteur de placement qui les écrit — l'ADR 0005 lui interdit tout accès à la
 * base, et c'est cette contrainte qui le rend comparable à `graphe.json`.
 *
 * **L'entrée n'est pas un `JeuDePlacement` mais ses deux morceaux utiles**, et c'est
 * délibéré : l'import GEDCOM (supprimé depuis, ADR 0017) appelait ce calcul **avant** que l'arbre soit en base — c'est
 * ce qui permet à son `--dry-run` d'annoncer 12 générations et la répartition par branche
 * sans rien écrire. Lui imposer un `JeuDePlacement` complet l'obligerait à fabriquer des
 * `PersonnePlacable` dont il n'a pas encore les champs, donc à inventer de la donnée pour
 * satisfaire une signature. {@see pourLeJeu()} sert le cas où le jeu existe déjà.
 */
final class CalculAscendance
{
    /**
     * Le nombre d'itérations de mise en cohérence des générations, repris tel quel de
     * `graphe.py`. Sur les données réelles la propagation en largeur suffit et la boucle
     * sort au premier tour ; les six passages sont le filet pour un arbre où un couple
     * arriverait par deux chemins de longueurs différentes.
     */
    private const int ITERATIONS_COHERENCE = 6;

    /** Idem pour l'héritage des branches : 8 passages dans `graphe.py`. */
    private const int ITERATIONS_HERITAGE = 8;

    /**
     * Les quatre quarts du lecteur, dans l'ordre `pp`, `pm`, `mp`, `mm` — celui que
     * {@see souches()} attend, et **celui que l'implexe rend non neutre** : la première
     * souche qui atteint une personne la garde.
     */
    private const array QUARTS_DU_LECTEUR = [Branch::Pp, Branch::Pm, Branch::Mp, Branch::Mm];

    /**
     * Leurs pendants du côté du conjoint, dans le même ordre.
     *
     * Deux listes et **un seul** {@see souches()} : c'est ce qui garantit qu'un quart du
     * conjoint est découpé par le même geste qu'un quart du lecteur. Une seconde fonction
     * « comme l'autre mais pour le conjoint » aurait divergé au premier correctif porté
     * d'un seul côté — et la moitié droite du dessin se serait mise à ne plus ressembler
     * à la gauche sans que rien ne le signale.
     */
    private const array QUARTS_DU_CONJOINT = [Branch::Cpp, Branch::Cpm, Branch::Cmp, Branch::Cmm];

    /** Le cas courant, quand l'arbre est déjà chargé depuis la base. */
    public function pourLeJeu(JeuDePlacement $jeu): PlacementLogique
    {
        return $this->calculer(
            array_map(static fn (object $personne): string => $personne->id, $jeu->personnes),
            $jeu->familles,
            $jeu->egoId,
        );
    }

    /**
     * **L'arbre tel que le lecteur le lit** : les mêmes générations, la parenté de chaque
     * personne ({@see ParenteDansLArbre}), et des branches qui **ne débordent plus par
     * alliance**.
     *
     * ── POURQUOI UNE SECONDE ENTRÉE, ET PAS UNE CORRECTION DE {@see calculer()} ─────
     *
     * Décision du commanditaire du 2026-10-01 : une personne est « de la famille » si elle
     * est un ancêtre, un descendant d'ancêtre, ou le conjoint de l'un d'eux ; tout le reste
     * relève d'une **famille par alliance**, masquée par défaut et dessinée à part. Elle
     * remplace, **pour la vue du lecteur**, l'arbitrage du 23/09 — « tous dans un des 13
     * blocs ; les conjoints vont avec leur partenaire », puis leur famille suit — qui
     * rangeait l'ascendance entière du mari d'une tante dans la branche de la tante :
     * 252 fiches sur 281 dans la bande « Voulgre » de l'arbre synthétique des captures,
     * 446 croisements de traits.
     *
     * La colonne persistée `tree_person.branch`, elle, **garde l'ancienne règle**, et c'est
     * délibéré :
     *
     * - la chronologie en fait ses colonnes, et une barre sans branche **disparaît**
     *   (`NormaliseurDeChronologie::barre()`). Appliquer la règle à la colonne retirerait
     *   de la frise toutes les familles par alliance de la racine, ce que personne n'a
     *   demandé ;
     * - réécrire la colonne de centaines de fiches incrémenterait leur `#[ORM\Version]` et
     *   **périmerait les demandes en attente** qui les visent ({@see ArbreVuDepuis} : une
     *   lecture de l'arbre ne doit jamais faire mourir une proposition — et une commande
     *   de recalcul non plus, pour un changement de présentation).
     *
     * Le graphe, lui, ne lit jamais la colonne quand un lecteur est réglé : il sert ce que
     * rend cette méthode (`NormaliseurDeGraphe::libelles()`).
     */
    public function vuDuLecteur(JeuDePlacement $jeu): PlacementLogique
    {
        $idsPersonnes = array_map(static fn (object $personne): string => $personne->id, $jeu->personnes);
        $familles = $jeu->familles;
        $ego = $jeu->egoId;

        $adjacence = $this->adjacence($familles);
        $generations = $this->generations($idsPersonnes, $familles, $adjacence, $ego);
        [$parentes, $ponts] = $this->parentes($idsPersonnes, $adjacence, $ego);

        $exclus = [];

        foreach ($parentes as $id => $parente) {
            if (ParenteDansLArbre::Alliance === $parente) {
                $exclus[$id] = true;
            }
        }

        $branches = $this->branches($idsPersonnes, $adjacence, $ego, $exclus);
        [$generationsFamille, $rangs] = $this->familles($familles, $generations);

        return new PlacementLogique($generations, $branches, $generationsFamille, $rangs, $parentes, $ponts);
    }

    /**
     * @param list<string>          $idsPersonnes **dans l'ordre d'origine** — voir ci-dessous
     * @param list<FamillePlacable> $familles     **dans l'ordre d'origine**
     *
     * L'ordre des deux listes est de la donnée, pas de la présentation : la boucle
     * d'héritage des branches retient le **premier** voisin déjà classé, et la propagation
     * des générations visite parents, puis enfants, puis conjoints. Un autre ordre donne
     * une autre répartition — pas de beaucoup, mais assez pour que `pm 134, mm 64, mp 51,
     * pp 20, tr 5` ne retombe plus.
     */
    public function calculer(array $idsPersonnes, array $familles, string $ego): PlacementLogique
    {
        $adjacence = $this->adjacence($familles);
        $generations = $this->generations($idsPersonnes, $familles, $adjacence, $ego);
        $branches = $this->branches($idsPersonnes, $adjacence, $ego);
        [$generationsFamille, $rangs] = $this->familles($familles, $generations);

        return new PlacementLogique($generations, $branches, $generationsFamille, $rangs);
    }

    /**
     * Parents, enfants et conjoints, dérivés des familles.
     *
     * @param list<FamillePlacable> $familles
     *
     * @return array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>}
     */
    private function adjacence(array $familles): array
    {
        $parents = [];
        $enfants = [];
        $conjoints = [];

        foreach ($familles as $famille) {
            foreach ($famille->enfants as $enfant) {
                // Écrasement volontaire, et c'est le comportement du script d'origine :
                // une personne rattachée à deux familles — une adoption — garde la
                // dernière.
                $parents[$enfant] = $famille->epoux;

                foreach ($famille->epoux as $parent) {
                    $enfants[$parent][] = $enfant;
                }
            }

            if (2 === \count($famille->epoux)) {
                $conjoints[$famille->epoux[0]][] = $famille->epoux[1];
                $conjoints[$famille->epoux[1]][] = $famille->epoux[0];
            }
        }

        return ['parents' => $parents, 'enfants' => $enfants, 'conjoints' => $conjoints];
    }

    /**
     * Propagation de contraintes depuis l'ego : un enfant est un rang sous son parent,
     * les époux partagent leur rang.
     *
     * @param list<string>                                                                                                              $idsPersonnes
     * @param list<FamillePlacable>                                                                                                     $familles
     * @param array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>} $adjacence
     *
     * @return array<string, int>
     */
    private function generations(array $idsPersonnes, array $familles, array $adjacence, string $ego): array
    {
        $generation = '' === $ego ? [] : [$ego => 0];
        $file = '' === $ego ? [] : [$ego];

        while ([] !== $file) {
            $courant = array_shift($file);
            $rang = $generation[$courant];

            foreach ($adjacence['parents'][$courant] ?? [] as $parent) {
                if (!isset($generation[$parent])) {
                    $generation[$parent] = $rang - 1;
                    $file[] = $parent;
                }
            }

            foreach ($adjacence['enfants'][$courant] ?? [] as $enfant) {
                if (!isset($generation[$enfant])) {
                    $generation[$enfant] = $rang + 1;
                    $file[] = $enfant;
                }
            }

            foreach ($adjacence['conjoints'][$courant] ?? [] as $conjoint) {
                if (!isset($generation[$conjoint])) {
                    $generation[$conjoint] = $rang;
                    $file[] = $conjoint;
                }
            }
        }

        // Une personne qu'aucun lien ne relie à l'ego n'est pas une erreur : le fichier
        // actuel n'en a aucune, mais une fiche ajoutée puis détachée en produirait une.
        // Elle se pose au rang 0 plutôt que de faire tomber le calcul.
        foreach ($idsPersonnes as $id) {
            $generation[$id] ??= 0;
        }

        for ($passage = 0; $passage < self::ITERATIONS_COHERENCE; ++$passage) {
            $bouge = false;

            foreach ($familles as $famille) {
                $epoux = $famille->epoux;

                if (2 === \count($epoux) && ($generation[$epoux[0]] ?? 0) !== ($generation[$epoux[1]] ?? 0)) {
                    // Un couple partage son rang : on aligne sur le plus ancien des deux.
                    $rang = min($generation[$epoux[0]] ?? 0, $generation[$epoux[1]] ?? 0);

                    foreach ($epoux as $personne) {
                        if (($generation[$personne] ?? 0) !== $rang) {
                            $generation[$personne] = $rang;
                            $bouge = true;
                        }
                    }
                }

                if ([] === $epoux) {
                    continue;
                }

                $rangParents = min(array_map(static fn (string $p): int => $generation[$p] ?? 0, $epoux));

                foreach ($famille->enfants as $enfant) {
                    if (($generation[$enfant] ?? 0) <= $rangParents) {
                        $generation[$enfant] = $rangParents + 1;
                        $bouge = true;
                    }
                }
            }

            if (!$bouge) {
                break;
            }
        }

        // Ré-ancrage sur l'ego, qui doit valoir 0 par définition (§ 3.1 du modèle).
        //
        // Sur les données réelles c'est une opération neutre : la propagation en largeur
        // suffit et la boucle de cohérence sort au premier passage sans rien déplacer.
        // Elle ne sert que pour un arbre où la boucle *a* travaillé — typiquement une
        // filiation saisie à l'envers, qui fait remonter tout le monde d'un cran à chaque
        // passage, l'ego compris. Le rang générationnel est relatif : le décaler en bloc
        // ne change aucun écart, et rend à `generation = 0` le sens qu'il annonce.
        $decalage = $generation[$ego] ?? 0;

        if (0 !== $decalage) {
            foreach ($generation as $id => $rang) {
                $generation[$id] = $rang - $decalage;
            }
        }

        return $generation;
    }

    /**
     * **Famille, conjoint ou alliance**, pour chaque personne — {@see ParenteDansLArbre}.
     *
     * Quatre parcours, chacun borné et sans point fixe :
     *
     * 1. **le sang** — l'ascendance du lecteur (lui compris), puis toute la descendance de
     *    cette ascendance : sa fratrie, ses oncles, ses cousins à tout degré, ses enfants ;
     * 2. **la famille** — le sang, plus les conjoints du sang, **en une seule passe** : le
     *    conjoint entre, sa famille à lui non. C'est la forme du cercle de l'ADR 0003 (« les
     *    conjoints sont ajoutés en une seule passe, en dernier, et ne propagent rien »),
     *    sans sa borne de `N` générations ;
     * 3. **la famille du conjoint du lecteur** — tout ce qu'on atteint depuis ses
     *    conjoints, par n'importe quel lien, **sans repasser par la famille** : leurs
     *    parents et leur ascendance, leur fratrie, les enfants d'une autre union. C'est ce
     *    que le filtre « Famille du conjoint » couvrait déjà côté client, conservé tel quel ;
     * 4. **le reste est alliance**, et chacun y reçoit son **pont** : la personne
     *    non-alliance la plus proche par les liens, celle par laquelle son groupe se
     *    rattache — le mari de la tante, pour toute l'ascendance du mari de la tante. Le
     *    parcours part de **tous** les ponts à la fois (largeur d'abord, sources dans
     *    l'ordre de la liste) : un groupe touché par deux ponts va au plus proche, et à
     *    égalité au premier dans l'ordre des identifiants. Déterministe.
     *
     * Une personne qu'**aucun chemin** ne relie au lecteur — l'îlot détaché — n'est **pas**
     * `alliance` : la décision vise « tout le reste **qui est relié** », et l'îlot n'est
     * relié à rien. Elle est servie `famille`, sans pont, et garde son sort d'avant (pas de
     * branche, la zone « sans lien connu » du canevas) : la masquer par défaut cacherait les
     * fiches flottantes qu'on cherche justement à rattacher. Même règle côté client
     * (`classerParente()`, `app/src/histoire/alliances.ts`).
     *
     * Sans lecteur (`$ego` vide), personne n'est rangé : « famille de qui ? » n'a pas de
     * réponse, et le sérialiseur sert alors `famille` partout plutôt que de masquer au
     * hasard.
     *
     * @param list<string>                                                                                                              $idsPersonnes
     * @param array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>} $adjacence
     *
     * @return array{0: array<string, ParenteDansLArbre>, 1: array<string, string>}
     */
    private function parentes(array $idsPersonnes, array $adjacence, string $ego): array
    {
        if ('' === $ego) {
            return [[], []];
        }

        $voisins = static fn (string $p): array => array_merge(
            $adjacence['parents'][$p] ?? [],
            $adjacence['enfants'][$p] ?? [],
            $adjacence['conjoints'][$p] ?? [],
        );

        // 1. Le sang : l'ascendance, puis la descendance de chacun de ses membres. Le
        //    tableau sert de registre des vus — un implexe ne fait pas boucler.
        $sang = [];
        $pile = $this->remonte($adjacence['parents'], $ego);

        while ([] !== $pile) {
            $courant = array_pop($pile);

            if (isset($sang[$courant])) {
                continue;
            }

            $sang[$courant] = true;

            foreach ($adjacence['enfants'][$courant] ?? [] as $enfant) {
                $pile[] = $enfant;
            }
        }

        // 2. La famille : le sang et ses conjoints, une seule passe.
        $famille = $sang;

        foreach (array_keys($sang) as $personne) {
            foreach ($adjacence['conjoints'][$personne] ?? [] as $conjoint) {
                $famille[$conjoint] = true;
            }
        }

        // 3. La famille du conjoint du lecteur : depuis chacun de ses conjoints, sans
        //    jamais repasser par la famille. Le conjoint lui-même est « famille » (2.).
        $cote = [];
        $pile = [];

        foreach ($adjacence['conjoints'][$ego] ?? [] as $conjoint) {
            foreach ($voisins($conjoint) as $voisin) {
                $pile[] = $voisin;
            }
        }

        while ([] !== $pile) {
            $courant = array_pop($pile);

            if (isset($famille[$courant]) || isset($cote[$courant])) {
                continue;
            }

            $cote[$courant] = true;

            foreach ($voisins($courant) as $voisin) {
                $pile[] = $voisin;
            }
        }

        $parentes = [];

        foreach ($idsPersonnes as $id) {
            $parentes[$id] = match (true) {
                isset($famille[$id]) => ParenteDansLArbre::Famille,
                isset($cote[$id]) => ParenteDansLArbre::Conjoint,
                default => ParenteDansLArbre::Alliance,
            };
        }

        // 4. Les ponts. Toutes les sources sont posées AVANT que la file ne tourne : c'est
        //    ce qui en fait un vrai parcours multi-sources, où chacun va au pont le plus
        //    proche, et non au premier qui l'a atteint de proche en proche.
        $ponts = [];
        $file = [];
        $estAlliance = static fn (string $p): bool => ParenteDansLArbre::Alliance === ($parentes[$p] ?? null);

        foreach ($idsPersonnes as $id) {
            if ($estAlliance($id)) {
                continue;
            }

            foreach ($voisins($id) as $voisin) {
                if ($estAlliance($voisin) && !\array_key_exists($voisin, $ponts)) {
                    $ponts[$voisin] = $id;
                    $file[] = $voisin;
                }
            }
        }

        for ($i = 0; $i < \count($file); ++$i) {
            $courant = $file[$i];

            foreach ($voisins($courant) as $voisin) {
                if ($estAlliance($voisin) && !\array_key_exists($voisin, $ponts)) {
                    $ponts[$voisin] = $ponts[$courant];
                    $file[] = $voisin;
                }
            }
        }

        // L'îlot : aucun pont ne l'a atteint — il n'est relié à rien, il n'est donc pas
        // « par alliance ». Rendu à `famille`, sans pont (voir le docbloc).
        foreach ($idsPersonnes as $id) {
            if ($estAlliance($id) && !\array_key_exists($id, $ponts)) {
                $parentes[$id] = ParenteDansLArbre::Famille;
            }
        }

        return [$parentes, $ponts];
    }

    /**
     * De laquelle des quatre lignes de grands-parents chaque personne relève.
     *
     * Les quatre souches sont **déduites de l'ego**, et non écrites en dur comme dans
     * `graphe.py` : le père du père donne `pp`, la mère du père `pm`, le père de la mère
     * `mp`, la mère de la mère `mm`. Sur le fichier de référence, la déduction retombe
     * exactement sur les quatre `xref` que le script codait en dur — et elle continue de
     * marcher le jour où `tree.personne_racine_id` change.
     *
     * **`$exclus` : les familles par alliance, que rien ne classe.** Vide pour la colonne
     * persistée ({@see calculer()}), qui garde l'arbitrage du 23/09 ; rempli par
     * {@see vuDuLecteur()}. Une personne exclue n'hérite d'aucune branche **et n'en
     * transmet aucune** : sans la seconde moitié, le mari de la tante reprendrait la
     * branche de ses propres parents s'ils en avaient reçu une par un autre chemin. Les
     * souches, le tronc et les quarts du conjoint ne sont pas filtrés : ils ne parcourent
     * que l'ascendance du lecteur et de son conjoint, sa descendance et ses conjoints —
     * jamais une personne `alliance`, par définition.
     *
     * Le mari de la tante, lui, n'est pas exclu : il est « famille », et il reçoit la
     * branche de sa femme par la boucle d'héritage — c'est le pont, et il reste à côté
     * d'elle.
     *
     * @param list<string>                                                                                                              $idsPersonnes
     * @param array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>} $adjacence
     * @param array<string, true>                                                                                                       $exclus
     *
     * @return array<string, Branch>
     */
    private function branches(array $idsPersonnes, array $adjacence, string $ego, array $exclus = []): array
    {
        if ('' === $ego) {
            // Sans racine, « branche paternelle du père » ne veut rien dire. On ne pose
            // rien plutôt que de poser au hasard : la colonne est `NULL`able exprès.
            return [];
        }

        $branche = [];

        foreach ($this->souches($adjacence['parents'], $ego, self::QUARTS_DU_LECTEUR) as $code => $souche) {
            foreach ($this->remonte($adjacence['parents'], $souche) as $ancetre) {
                // La première souche qui atteint une personne la garde. L'ordre pp, pm,
                // mp, mm n'est donc pas neutre — c'est celui du script d'origine, et
                // l'implexe fait qu'il y a des personnes atteintes deux fois.
                $branche[$ancetre] ??= Branch::from($code);
            }
        }

        // Le tronc : l'ego, ses parents et sa fratrie n'appartiennent à aucune des quatre
        // branches — ils sont le point où elles se rejoignent. Il écrase, il ne complète pas.
        foreach ($this->tronc($adjacence, $ego) as $personne) {
            $branche[$personne] = Branch::Tr;
        }

        // **La moitié droite du dessin** : les quatre quarts du conjoint, posés après ceux
        // du lecteur et après le tronc — donc sans jamais leur disputer une fiche.
        $this->quartsDuConjoint($adjacence, $ego, $branche);

        // Héritage : le SANG d'abord (parents, puis enfants), l'alliance en dernier.
        // Dans l'autre ordre, un homme héritait de la branche de sa femme — c'est ainsi
        // que le père de l'ego se retrouvait étiqueté du côté maternel.
        //
        // ────────────────────────────────────────────────────────────────────
        // **`Branch::Tr !== $branche[$voisin]` : LE TRONC NE SE PROPAGE PAS.** Cette
        // condition porte tout le sens du mot « tronc », et rien ne la justifiait — d'où
        // ces lignes.
        //
        // Le tronc est le point où les quatre branches se rejoignent : quelques personnes,
        // pas une région. Sans cette condition il déborde par les conjoints, puis de proche
        // en proche, et **mesuré sur le graphe servi (468 personnes) il passe de 8 à 180**
        // dès que l'ego n'est pas la racine. Le mot cesse alors de vouloir dire quelque
        // chose : « le lecteur, sa fratrie, ses parents » se retrouve sur un tiers de
        // l'arbre.
        //
        // **Pourquoi personne ne l'avait remarqué** : la condition est *inerte pour la
        // racine* — la lever ne change aucune fiche sur sa vue. Elle ne mord que pour un
        // ego non-racine, cas qu'aucun compte n'exerce tant que le rattachement retombe sur
        // la racine (tâche #74). Une condition porteuse que son seul cas révélateur
        // n'atteint jamais est une mine ; `testLeTroncNeSePropagePasParAlliance` la
        // désamorce.
        //
        // ⚠ **CE QUE CETTE GARDE VEUT DIRE A CHANGÉ. Le paragraphe ci-dessus raconte une
        // étape, ce n'est plus la règle.** Le commanditaire a arbitré le 23/09/2026 :
        // *« tous doivent forcément être dans un des 13 blocs ; si c'est les conjoints d'une
        // personne de ma famille, ils vont avec leur partenaire »*. Le tronc **se propage
        // donc bien par alliance**, et il grossit — c'est voulu, dit, et accepté.
        //
        // Ce que cette condition fait n'est plus d'interdire, c'est d'**ORDONNER** : elle
        // retient l'alliance une passe de plus, pour qu'une personne touchée à la fois par
        // du sang et par une alliance reparte du côté du sang. Ce que le mot « tronc »
        // perdrait en la levant ici, ce n'est pas sa taille — c'est la préséance du sang,
        // qui passerait au premier voisin venu dans l'ordre des identifiants. Ce qu'elle
        // laisse en plan est repris juste après, et plus rien n'en ressort sans branche :
        // {@see self::parAlliance()}.
        // ────────────────────────────────────────────────────────────────────
        for ($passage = 0; $passage < self::ITERATIONS_HERITAGE; ++$passage) {
            foreach ($idsPersonnes as $id) {
                if (isset($branche[$id]) || isset($exclus[$id])) {
                    continue;
                }

                $voisins = array_merge(
                    $adjacence['parents'][$id] ?? [],
                    $adjacence['enfants'][$id] ?? [],
                    $adjacence['conjoints'][$id] ?? [],
                );

                foreach ($voisins as $voisin) {
                    if (isset($exclus[$voisin])) {
                        continue;
                    }

                    if (isset($branche[$voisin]) && Branch::Tr !== $branche[$voisin]) {
                        $branche[$id] = $branche[$voisin];
                        break;
                    }
                }
            }
        }

        $this->parAlliance($idsPersonnes, $adjacence, $branche, $exclus);

        return $branche;
    }

    /**
     * **AUCUNE PERSONNE SANS BLOC. Qui n'en a pas prend celui de son partenaire.**.
     *
     * C'est la règle générale du commanditaire, rendue le 23/09/2026, et c'est un
     * **invariant du produit** et non une passe de rattrapage : *« tous doivent forcément
     * être dans un des 13 blocs ; si c'est les conjoints d'une personne de ma famille, ils
     * vont avec leur partenaire »*. Puis leur famille suit, de proche en proche, depuis ce
     * point d'ancrage.
     *
     * ⚠ **RÉVISÉ LE 2026-10-01, POUR LA VUE DU LECTEUR SEULEMENT.** Le commanditaire a
     * remplacé « puis leur famille suit » par les **familles par alliance**
     * ({@see vuDuLecteur()}, {@see ParenteDansLArbre}) : le conjoint d'une personne de la
     * famille prend toujours le bloc de son partenaire, mais **sa** famille ne suit plus —
     * elle ne reçoit aucune branche et se dessine à part, sur demande. Ce qui suit reste la
     * règle de la colonne persistée, que la chronologie consomme. Dans la vue du lecteur,
     * l'invariant devient : *aucune personne sans bloc hors alliance et hors îlot*.
     *
     * ── CE QU'ELLE REMPLACE, ET POURQUOI CE N'EST PAS LE MÊME GESTE ──────────────
     *
     * Elle prend la place de `belleFamille()`, qui donnait à ces personnes **une région à
     * elles**, `Branch::Bf`. Cette clé n'existe plus. Le docbloc de la PR #44 annonçait
     * qu'elle serait « remplacée par quatre » ; la mesure a montré que sous son amorce
     * c'était faux — elle gardait la belle-famille de la fratrie et des enfants, que les
     * quatre quarts du conjoint ne couvrent pas. **Elle disparaît quand même, mais par un
     * autre mécanisme que celui qui était prévu** : pas « `bf` se découpe en quatre », mais
     * « ses membres héritent du bloc de leur partenaire ».
     *
     * La différence n'est pas de mise en œuvre, elle est de nature. `Bf` était une
     * **région** : elle répondait à « où ranger ces gens ? » en créant un endroit. La règle
     * répond « nulle part de nouveau — là où est déjà leur partenaire ». Treize blocs, pas
     * un de plus, et rien ne perd son fond.
     *
     * ⚠ **LE TRONC GROSSIT BEAUCOUP PLUS QUE CE QUI A ÉTÉ ANNONCÉ, ET LE CHIFFRE EST ICI.**
     * L'arbitrage parlait de « 155 personnes à côté de 5 ». Mesuré en rejouant `branches()`
     * pour les **468 points de vue** du graphe servi (empreinte `91ad68db`) :
     *
     *     regions non vides   AVANT  mediane 2  (max 6)   APRES  mediane 3  (max 9)
     *     plus grosse region  AVANT  mediane 381/468      APRES  mediane 431/468
     *
     *     taille du TRONC     min 1  ·  médiane 422  ·  max 440
     *     sa plus grosse région EST le tronc pour 356 lecteurs sur 468
     *
     * ⚠ **LA DEUXIÈME LIGNE ET LA TROISIÈME NE COMPTENT PAS LA MÊME CHOSE, et la première
     * rédaction de ce docbloc les confondait.** Elle disait « pour le lecteur médian, le
     * tronc porte 431 personnes » : 431 est la médiane de la **plus grosse région**, quelle
     * qu'elle soit, et le tronc médian vaut **422**. L'écart est petit, l'erreur ne l'est
     * pas — elle survit à une relecture parce que les deux nombres sont vrais, chacun de son
     * prédicat. Le lecteur tiré à la médiane pour la recette, `p420`, le montre en entier :
     * sa plus grosse région pèse 431 et **c'est `pm`**, son tronc n'en porte que 4.
     *
     * Trois choses à lire ensemble avant d'appeler cela une fuite :
     *
     * - **le bloc géant préexiste à cette règle.** Avant elle, les 381 du lecteur médian
     *   étaient dans `Bf`, une région unique elle aussi. La règle ne crée pas la
     *   concentration : elle la renomme `Tr` et l'élargit de cinquante ;
     * - **le nombre de régions AUGMENTE** — médiane 2 → 3, maximum 6 → 9 — parce que les
     *   quatre quarts du conjoint découpent ce qui était indistinct ;
     * - **la cause est la forme de l'arbre, pas la règle.** Pour un lecteur qui n'est pas
     *   la racine, l'essentiel du graphe ne l'atteint que par des chaînes d'alliances. Le
     *   cas favorable existe : pour le lecteur `p3`, les 176 de `Bf` se répartissent en
     *   **165 dans les quatre quarts du conjoint et 11 seulement au tronc**, qui passe de
     *   8 à 19, et ses régions de 6 à 9. C'est quand le conjoint a une ascendance connue
     *   que les treize blocs se remplissent.
     *
     * **Ne pas « corriger » cela par un découpage plus fin** — la simplicité est le but, et
     * elle a été choisie explicitement contre l'élégance de la symétrie.
     *
     * ── L'AMORCE EST SÉPARÉE DE LA PROPAGATION, ET L'ORDRE PORTE LA RÈGLE ────────
     *
     * Chaque passe sert d'abord **tous** les conjoints, puis seulement ensuite les parents
     * et les enfants. Ainsi une personne touchée à la fois par un partenaire et par un
     * ascendant reçoit le bloc de son partenaire, ce que la règle dit mot pour mot. Les
     * fondre en une seule boucle rendrait le résultat dépendant de l'ordre des
     * identifiants, sans que rien ne le signale.
     *
     * ⚠ **LA BOUCLE SORT SUR UN POINT FIXE, ET LE PLAFOND N'EST QU'UN FILET.** La
     * première rédaction réutilisait {@see ITERATIONS_HERITAGE} — huit passes, la constante
     * reprise de `graphe.py` — en supposant que ça suffirait. **C'était faux, et ça ne se
     * voyait pas sur un lecteur.** Mesuré en rejouant `branches()` pour les 468 points de
     * vue du graphe servi : **264 lecteurs gardaient de 4 à 33 personnes sans bloc alors
     * qu'un chemin les reliait à eux.** L'invariant tombait, et il tombait en silence.
     *
     * La cause est l'ordre de visite : une passe sert les identifiants dans l'ordre de la
     * liste, si bien qu'une personne placée avant son ancre n'est servie qu'à la passe
     * suivante. Une chaîne d'alliances de longueur *n* demande donc jusqu'à *n* passes, et
     * cet arbre en porte de plus de huit.
     *
     * Le plafond vaut désormais le nombre de personnes — une passe ne peut pas servir moins
     * d'une personne sans arrêter la boucle, donc il ne peut jamais mordre. **La sortie
     * réelle est le point fixe.** Sur les données réelles il tombe en trois à quatre passes ;
     * le coût du filet est nul puisqu'on n'y arrive jamais.
     *
     * ── CE QUI RESTE SANS BLOC APRÈS ELLE, ET C'EST LE SEUL CAS ──────────────────
     *
     * Une personne qu'**aucun chemin** ne relie à quelqu'un de classé. Sur le graphe servi
     * elles sont **28**, et forment l'îlot réellement détaché de l'arbre. Aucune règle ne
     * peut leur donner un bloc : elles n'ont ni sang ni alliance vers le reste, et leur en
     * attribuer un au hasard serait inventer une parenté. Le sérialiseur les sert à `null`,
     * et le canevas les range dans « sans branche », qui ne se dessine pas.
     *
     * **L'exception est bornée, et mesurée.** Les 28 membres de l'îlot, pris eux-mêmes comme
     * lecteurs, en classent chacun au moins un et jusqu'à 26 : leurs propres souches y
     * remontent, puisque l'îlot est alors leur arbre. Ils sont 28 lecteurs possibles sur 468,
     * aucun ne porte de compte, et pour les 440 autres la phrase ci-dessus vaut sans réserve.
     * Relevé sur le graphe servi, empreinte `91ad68db`, en rejouant `branches()` pour chacun
     * des 468 points de vue.
     *
     * **L'invariant se teste, et il est testé** :
     * `testAucunePersonneNeSortSansBlocHorsIlotDetache`. Une personne sans branche retombe
     * sur le repli silencieux du sérialiseur — elle reçoit alors le quart d'ascendance de
     * **la racine**, ce qui est faux sans en avoir l'air. C'est le défaut que tout ce
     * chantier ferme ; l'assertion est donc sur le compte, pas sur un relevé.
     *
     * @param list<string>                                                                                                              $idsPersonnes
     * @param array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>} $adjacence
     * @param array<string, Branch>                                                                                                     $branche      modifié sur place
     * @param array<string, true>                                                                                                       $exclus       les familles par alliance de la vue du lecteur : ni amorcées, ni relais — {@see branches()}
     */
    private function parAlliance(array $idsPersonnes, array $adjacence, array &$branche, array $exclus = []): void
    {
        // Le plafond ne peut pas mordre : chaque passe sert au moins une personne ou sort.
        for ($passage = 0, $filet = \count($idsPersonnes); $passage <= $filet; ++$passage) {
            $bouge = false;

            // L'amorce, pour tout le monde d'abord : le bloc du partenaire.
            foreach ($idsPersonnes as $id) {
                if (isset($branche[$id]) || isset($exclus[$id])) {
                    continue;
                }

                foreach ($adjacence['conjoints'][$id] ?? [] as $conjoint) {
                    if (isset($branche[$conjoint]) && !isset($exclus[$conjoint])) {
                        $branche[$id] = $branche[$conjoint];
                        $bouge = true;
                        break;
                    }
                }
            }

            // Puis la famille autour de cette amorce — une belle-famille est une famille
            // entière, pas une personne.
            foreach ($idsPersonnes as $id) {
                if (isset($branche[$id]) || isset($exclus[$id])) {
                    continue;
                }

                $voisins = array_merge(
                    $adjacence['parents'][$id] ?? [],
                    $adjacence['enfants'][$id] ?? [],
                );

                foreach ($voisins as $voisin) {
                    if (isset($branche[$voisin]) && !isset($exclus[$voisin])) {
                        $branche[$id] = $branche[$voisin];
                        $bouge = true;
                        break;
                    }
                }
            }

            if (!$bouge) {
                break;
            }
        }
    }

    /**
     * **Les quatre quarts du conjoint du lecteur** — `pp'`, `pm'`, `mp'`, `mm'` de la
     * spécification, {@see Branch::Cpp} et ses trois sœurs.
     *
     * ── LE MÊME GESTE, DE L'AUTRE CÔTÉ DU TRONC ─────────────────────────────────
     *
     * Il n'y a **rien de neuf** dans cette passe, et c'est tout son intérêt : elle appelle
     * {@see souches()} puis {@see remonte()}, exactement comme la moitié gauche, avec la
     * seule autre liste de quatre valeurs. Le moteur de placement, lui, a dû s'y prendre
     * autrement (`AffinageDuPlacement::quatreQuartsDuConjoint()`, par fouille
     * d'atteignabilité) parce qu'il partitionne des **nœuds** — les familles comprises — et
     * qu'il doit tout classer. Ici on classe des personnes, et l'ascendance suffit.
     *
     * ── LES PARENTS DU CONJOINT SONT POSÉS ICI, ET IL A FALLU UN ÉCHEC POUR LE VOIR ──
     *
     * La première version ne posait que les **grands-parents** et leur remontée, en laissant
     * la boucle d'héritage servir le reste : le père du conjoint hérite de son propre père,
     * donc `cpp`, sa mère du sien, donc `cmp`. C'est exact **tant que le conjoint a des
     * grands-parents dans le graphe.** Sans eux, rien n'amorçait plus sa famille du tout :
     * elle ressortait **sans branche**, c'est-à-dire sur le repli silencieux du sérialiseur
     * que le repli silencieux du sérialiseur rend alors la branche de la racine.
     *
     * La cause est une asymétrie réelle entre les deux moitiés, et elle ne saute pas aux
     * yeux : du côté du lecteur, la génération des parents est couverte par {@see tronc()} ;
     * du côté du conjoint, **rien ne la couvre**. Le tronc s'arrête à son conjoint et ne
     * remonte pas chez ses beaux-parents. On les pose donc explicitement, et l'héritage
     * n'est plus qu'un chemin parmi deux vers le même résultat au lieu d'être le seul.
     *
     * Le père au premier quart, la mère au troisième — chacun à la tête de sa moitié. Un
     * couple se retrouve ainsi réparti sur deux quarts, ce qui est déjà le sort des
     * grands-parents du lecteur : `AffinageDuPlacement::deuxQuarts()` le dit en toutes
     * lettres, « c'est un choix par défaut, pas une évidence ».
     *
     * **UN SEUL CONJOINT, ET LEQUEL.** Le premier, dans l'ordre des familles, dont les
     * grands-parents sont dans le graphe. Un lecteur remarié n'a donc qu'une moitié droite,
     * comme dans le moteur (`quartsDuLecteur()` s'arrête au premier lui aussi) : le dessin
     * a une moitié droite, pas trois. Les autres conjoints et leur famille restent à
     * servis par {@see self::parAlliance()}, qui leur donne le bloc de leur partenaire.
     *
     * **SI RIEN NE S'APPLIQUE, RIEN NE CHANGE.** Lecteur sans conjoint, ou conjoint sans
     * grands-parents connus : la passe ne pose aucune valeur et l'arbre sort exactement
     * comme avant. **C'est le témoin de ce chantier sur la vue de la racine**, qui n'a pas
     * de conjoint — aucune des quatre valeurs n'y est posée, sa colonne `branch` ne bouge
     * pas d'une ligne, et `testLaRacineSansConjointNeVoitAucunQuartDeConjoint` le tient.
     *
     * @param array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>} $adjacence
     * @param array<string, Branch>                                                                                                     $branche   modifié sur place
     */
    private function quartsDuConjoint(array $adjacence, string $ego, array &$branche): void
    {
        foreach ($adjacence['conjoints'][$ego] ?? [] as $conjoint) {
            $parents = $adjacence['parents'][$conjoint] ?? [];

            if ([] === $parents) {
                // Un conjoint dont l'arbre ne connaît pas les parents n'amène aucune
                // famille à découper. On passe au suivant plutôt que de consommer sur lui
                // l'unique moitié droite du dessin.
                continue;
            }

            foreach ($this->souches($adjacence['parents'], $conjoint, self::QUARTS_DU_CONJOINT) as $code => $souche) {
                foreach ($this->remonte($adjacence['parents'], $souche) as $ancetre) {
                    // `??=`, comme pour le lecteur, et ici il porte davantage : sur un arbre
                    // endogame un aïeul appartient aux deux moitiés, et il **reste du côté
                    // du lecteur**. C'est l'ordre d'appel qui le décide, pas cette ligne.
                    $branche[$ancetre] ??= Branch::from($code);
                }
            }

            // La clé est le rang dans `$famille->epoux` : 0 le père, 1 la mère.
            foreach ([0 => Branch::Cpp, 1 => Branch::Cmp] as $rang => $quart) {
                if (isset($parents[$rang])) {
                    $branche[$parents[$rang]] ??= $quart;
                }
            }

            return;
        }
    }

    /**
     * Les quatre grands-parents d'une personne, dans l'ordre `pp`, `pm`, `mp`, `mm`.
     *
     * Les quatre souches sont **déduites** et non écrites en dur : le père du père donne le
     * premier quart, la mère du père le deuxième, le père de la mère le troisième, la mère
     * de la mère le quatrième. `$quarts` dit lesquelles des quatre valeurs recevoir —
     * {@see QUARTS_DU_LECTEUR} depuis l'ego, {@see QUARTS_DU_CONJOINT} depuis son conjoint.
     *
     * @param array<string, list<string>>           $parents
     * @param array{Branch, Branch, Branch, Branch} $quarts
     *
     * @return array<string, string> code de branche → identifiant de la souche
     */
    private function souches(array $parents, string $ego, array $quarts): array
    {
        [$quartPp, $quartPm, $quartMp, $quartMm] = $quarts;
        $pere = $parents[$ego][0] ?? null;
        $mere = $parents[$ego][1] ?? null;
        $souches = [];

        foreach ([[$quartPp, $quartPm, $pere], [$quartMp, $quartMm, $mere]] as [$cotePere, $coteMere, $parent]) {
            if (null === $parent) {
                continue;
            }

            if (isset($parents[$parent][0])) {
                $souches[$cotePere->value] = $parents[$parent][0];
            }

            if (isset($parents[$parent][1])) {
                $souches[$coteMere->value] = $parents[$parent][1];
            }
        }

        return $souches;
    }

    /**
     * Tous les ascendants d'une souche, elle comprise.
     *
     * Pile et non file : l'ordre de visite est sans importance ici, tous reçoivent le même
     * code. `$vus` est le garde-fou contre les boucles de consanguinité — l'implexe existe
     * dans cet arbre, et sans lui la remontée ne termine pas.
     *
     * @param array<string, list<string>> $parents
     *
     * @return list<string>
     */
    private function remonte(array $parents, string $depart): array
    {
        $vus = [];
        $pile = [$depart];

        while ([] !== $pile) {
            $courant = array_pop($pile);

            if (isset($vus[$courant])) {
                continue;
            }

            $vus[$courant] = true;

            foreach ($parents[$courant] ?? [] as $parent) {
                $pile[] = $parent;
            }
        }

        return array_keys($vus);
    }

    /**
     * **La lignée directe du lecteur, sa fratrie et son conjoint** : l'ego, ses parents,
     * tous les enfants de ses parents — donc ses demi-frères et demi-sœurs —, **sa
     * descendance**, et **les personnes qu'il a épousées**.
     *
     * La descendance manquait, alors que {@see Branch::Tr} la nomme depuis toujours : « le
     * tronc : la **lignée directe** et la fratrie ». L'oubli ne se voyait pas, parce que la
     * racine de cet arbre n'a pas d'enfant : sa vue ne change pas d'une fiche. Il se voyait
     * dès qu'un autre lecteur regardait — ses propres enfants n'étant d'aucun de ses quatre
     * quarts ni de son tronc, ils finissaient rangés avec la belle-famille, c'est-à-dire du
     * côté de leur mère plutôt que dans sa ligne.
     *
     * @param array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>} $adjacence
     *
     * @return list<string>
     */
    private function tronc(array $adjacence, string $ego): array
    {
        $tronc = [$ego => true];

        foreach ($adjacence['parents'][$ego] ?? [] as $parent) {
            $tronc[$parent] = true;

            foreach ($adjacence['enfants'][$parent] ?? [] as $enfant) {
                $tronc[$enfant] = true;
            }
        }

        // La descendance, de proche en proche. Une pile et le tableau lui-même comme
        // registre des vus, plutôt qu'une récursion : un arbre réel porte des implexes, et
        // une boucle de filiation ferait tourner sans fin ({@see testUnImplexeNeFaitPasBoucler}).
        $pile = [$ego];

        while ([] !== $pile) {
            $courant = array_pop($pile);

            foreach ($adjacence['enfants'][$courant] ?? [] as $enfant) {
                if (isset($tronc[$enfant])) {
                    continue;
                }

                $tronc[$enfant] = true;
                $pile[] = $enfant;
            }
        }

        // ── LE CONJOINT EST AU TRONC. SA FRATRIE N'Y EST PAS. ───────────────────────
        //
        // **Ajouté après coup, et pas pour faire joli : la donnée et le dessin se
        // contredisaient.** Le moteur de placement garde déjà le conjoint dans le tronc —
        // « le conjoint reste dans le tronc, c'est le foyer du lecteur »
        // (`AffinageDuPlacement::ascendanceDuConjoint()`), qui part de ses *parents* et
        // jamais de lui. La définition du commanditaire dit la même chose : *« moi, mes
        // parents, mon conjoint — mais pas ses frères et sœurs, eux restent dans le bloc de
        // la famille opposée »*. Seule cette colonne disait autre chose.
        //
        // **Pourquoi cela ne pouvait pas rester ainsi une fois les régions peintes.** Un
        // aplat de région est découpé par rangée, et une bande s'arrête là où une personne
        // d'une AUTRE région s'intercale (`app/src/components/arbre/zones.ts`). Le conjoint
        // étant posé au milieu du tronc par le moteur, une branche `bf` en faisait
        // exactement cela : **un intrus au centre du tronc**, qui coupait sa bande en deux
        // et posait un troisième aplat d'une région dont tout le reste est ailleurs. Le
        // désaccord était invisible tant que la branche ne servait qu'à teinter un liseré de
        // 4 unités ; il devient une entaille dès qu'elle dessine une surface.
        //
        // Il est posé **après** la descendance, et n'entre jamais dans la pile : un conjoint
        // peut avoir des enfants d'une autre union, qui ne sont pas la lignée du lecteur.
        foreach ($adjacence['conjoints'][$ego] ?? [] as $conjoint) {
            $tronc[$conjoint] = true;
        }

        return array_keys($tronc);
    }

    /**
     * Le rang générationnel d'une famille et celui de son nœud de jonction.
     *
     * **Rangs entrelacés** : les personnes occupent les rangs pairs, les nœuds-famille les
     * rangs impairs, d'où `rang = 2 × génération + 1`. Sans cela, un nœud-famille partage
     * sa rangée avec les époux, et le barycentre — qui ne regarde que les rangées
     * voisines — ne les rapproche jamais : les conjoints finissaient aux deux bouts de
     * leur génération.
     *
     * @param list<FamillePlacable> $familles
     * @param array<string, int>    $generations
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function familles(array $familles, array $generations): array
    {
        $generationsFamille = [];
        $rangs = [];

        foreach ($familles as $famille) {
            if ([] !== $famille->epoux) {
                $rang = min(array_map(static fn (string $p): int => $generations[$p] ?? 0, $famille->epoux));
            } elseif ([] !== $famille->enfants) {
                $rang = min(array_map(static fn (string $c): int => $generations[$c] ?? 0, $famille->enfants)) - 1;
            } else {
                $rang = 0;
            }

            $generationsFamille[$famille->id] = $rang;
            $rangs[$famille->id] = 2 * $rang + 1;
        }

        return [$generationsFamille, $rangs];
    }
}
