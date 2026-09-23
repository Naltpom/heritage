<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\Branch;
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
 * délibéré : l'import GEDCOM appelle ce calcul **avant** que l'arbre soit en base — c'est
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
     * De laquelle des quatre lignes de grands-parents chaque personne relève.
     *
     * Les quatre souches sont **déduites de l'ego**, et non écrites en dur comme dans
     * `graphe.py` : le père du père donne `pp`, la mère du père `pm`, le père de la mère
     * `mp`, la mère de la mère `mm`. Sur le fichier de référence, la déduction retombe
     * exactement sur les quatre `xref` que le script codait en dur — et elle continue de
     * marcher le jour où `tree.personne_racine_id` change.
     *
     * @param list<string>                                                                                                              $idsPersonnes
     * @param array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>} $adjacence
     *
     * @return array<string, Branch>
     */
    private function branches(array $idsPersonnes, array $adjacence, string $ego): array
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
        // **Ce que la garde laisse en plan, et où c'est repris.** Les personnes qu'elle
        // écarte — la famille du conjoint d'un membre du tronc — ressortaient *sans
        // branche*, et le sérialiseur leur servait alors celle **de la racine** par un
        // repli silencieux : 179 fiches sur 468 pour le père de la racine. Le remède n'est
        // pas de lever cette condition, qui ne ferait que vider le mot « tronc » de son
        // sens — c'est de leur donner une région à elles, et c'est {@see Branch::Bf},
        // posée par {@see self::belleFamille()} juste après cette boucle.
        // ────────────────────────────────────────────────────────────────────
        for ($passage = 0; $passage < self::ITERATIONS_HERITAGE; ++$passage) {
            foreach ($idsPersonnes as $id) {
                if (isset($branche[$id])) {
                    continue;
                }

                $voisins = array_merge(
                    $adjacence['parents'][$id] ?? [],
                    $adjacence['enfants'][$id] ?? [],
                    $adjacence['conjoints'][$id] ?? [],
                );

                foreach ($voisins as $voisin) {
                    if (isset($branche[$voisin]) && Branch::Tr !== $branche[$voisin]) {
                        $branche[$id] = $branche[$voisin];
                        break;
                    }
                }
            }
        }

        $this->belleFamille($idsPersonnes, $adjacence, $branche);

        return $branche;
    }

    /**
     * **La belle-famille** : ce que le sang du lecteur n'atteint pas, et qui entre dans
     * l'arbre par une alliance avec son tronc.
     *
     * Cette passe vient **après** l'héritage par le sang : elle ne touche donc que des
     * personnes encore sans branche, et ne dispute rien aux quatre quarts. L'amorce est une
     * alliance — le conjoint d'un membre du tronc —, puis la région s'étend de proche en
     * proche, parce qu'une belle-famille est une famille entière et pas une personne.
     *
     * **Pourquoi elle est nécessaire.** Sans elle ces personnes sortaient sans branche, et
     * le sérialiseur du graphe leur servait alors celle **de la racine** par un repli
     * silencieux. Mesuré pour le père de la racine : 179 fiches sur 468 affichaient le quart
     * d'ascendance de quelqu'un d'autre — dont son épouse et ses trois enfants étiquetés
     * « Tronc ».
     *
     * **179 et 207 comptent deux choses différentes, et les deux sont justes.** 179 est le
     * nombre de fiches qui **reçoivent une branche qui n'est pas la leur** ; 207 est celui
     * des fiches qui **tombent sur le repli**. Les 28 de différence ont une colonne `NULL` :
     * le repli leur rend `null`, et elles ressortent sans branche **pour tout lecteur hors de
     * l'îlot**, avant comme après cette passe. Ce ne sont donc pas un reliquat du
     * correctif, mais l'îlot réellement détaché de l'arbre — rien de faux ne leur arrive, et
     * rien ici ne les concerne.
     *
     * **L'exception est bornée, et mesurée.** Les 28 membres de l'îlot, pris eux-mêmes comme
     * lecteurs, en classent chacun au moins un et jusqu'à 26 : leurs propres souches y
     * remontent, puisque l'îlot est alors leur arbre. Ils sont 28 lecteurs possibles sur 468,
     * aucun ne porte de compte, et pour les 440 autres la phrase ci-dessus vaut sans réserve.
     * Relevé sur le graphe servi, empreinte `91ad68db`, en rejouant `branches()` pour chacun
     * des 468 points de vue.
     *
     * Prises l'une pour l'autre, ces deux quantités ont déjà coûté
     * un arbitrage : elles se citent ensemble, ou avec leur prédicat.
     *
     * **Où vont les 179 : 176 reçoivent `Bf`, et 3 reviennent à `Tr`.** Ces trois-là sont
     * les enfants du lecteur, que {@see self::tronc()} laissait dehors tant qu'il
     * implémentait « l'ego, ses parents, sa fratrie » sans la descendance. C'est pourquoi
     * `Bf` en compte 176 et non 179 : les deux nombres sont exacts, ils ne désignent pas le
     * même ensemble. Mesuré en rejouant `branches()` d'avant le correctif sur le graphe
     * servi — empreinte `91ad68db`, 648 nœuds, 468 personnes, lecteur = le père de la
     * racine.
     *
     * **Pourquoi elle ne change rien pour la racine.** L'amorce exige un conjoint **sans
     * branche**. Sur la vue de la racine, les conjoints des membres de son tronc sont ses
     * propres parents et les conjoints de sa fratrie, que les quatre souches ont déjà
     * servis : la passe n'amorce rien et ne pose aucune `Bf`. Vérifié sur le graphe servi —
     * 0 fiche sur 468 change.
     *
     * @param list<string>                                                                                                              $idsPersonnes
     * @param array{parents: array<string, list<string>>, enfants: array<string, list<string>>, conjoints: array<string, list<string>>} $adjacence
     * @param array<string, Branch>                                                                                                     $branche      modifié sur place
     */
    private function belleFamille(array $idsPersonnes, array $adjacence, array &$branche): void
    {
        // L'amorce : un conjoint de membre du tronc que le sang n'a pas classé.
        foreach ($idsPersonnes as $id) {
            if (!isset($branche[$id]) || Branch::Tr !== $branche[$id]) {
                continue;
            }

            foreach ($adjacence['conjoints'][$id] ?? [] as $conjoint) {
                if (!isset($branche[$conjoint])) {
                    $branche[$conjoint] = Branch::Bf;
                }
            }
        }

        // Puis la famille autour de cette amorce, avec le même nombre de passages que
        // l'héritage : une belle-famille se remonte comme une ascendance.
        for ($passage = 0; $passage < self::ITERATIONS_HERITAGE; ++$passage) {
            foreach ($idsPersonnes as $id) {
                if (isset($branche[$id])) {
                    continue;
                }

                $voisins = array_merge(
                    $adjacence['parents'][$id] ?? [],
                    $adjacence['enfants'][$id] ?? [],
                    $adjacence['conjoints'][$id] ?? [],
                );

                foreach ($voisins as $voisin) {
                    if (isset($branche[$voisin]) && Branch::Bf === $branche[$voisin]) {
                        $branche[$id] = Branch::Bf;
                        break;
                    }
                }
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
     * que {@see Branch::Bf} avait justement supprimé.
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
     * {@see Branch::Bf}, qui existe précisément pour eux.
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
        // la famille opposée »*. Seule cette colonne disait autre chose, et lui servait
        // {@see Branch::Bf}.
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
