<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\ValueObject\JeuDePlacement;

/**
 * Le libellé du lien de parenté entre une personne et le lecteur : « arrière-grand-mère »,
 * « cousine germaine, éloignée de 1 génération », « époux de ton oncle ».
 *
 * **Portage de `parente_sang()` de `build_data.py`**, seconde passe comprise. Les règles
 * n'ont pas été réinventées : elles sont éprouvées sur ces données précises, y compris
 * sur les cas tordus — un ancêtre commun atteint par deux chemins de longueurs
 * différentes, un conjoint sans aucun ancêtre commun avec la racine.
 *
 * **Ce libellé ne se stocke pas**, et c'est une décision du § 3.1 du modèle : il est
 * relatif au lecteur, et il y aura autant de lecteurs que de comptes. Le stocker serait
 * stocker la réponse d'une seule personne. Ce service se calcule donc à la volée, à
 * partir de la même structure d'adjacence que le cercle de visibilité — donc pour le
 * même prix, c'est-à-dire presque rien.
 *
 * **Il ne touche pas la base** : il prend un {@see JeuDePlacement} et rend des chaînes.
 * C'est le même contrat que le moteur de placement (ADR 0005), et pour la même raison —
 * un calcul qu'on peut nourrir à la main est un calcul qu'on peut prouver.
 *
 * Les identifiants sont ceux du jeu de placement, **préfixés** (`p1`, `f28`). Ce n'est pas
 * cosmétique : `tree_person.id` et `tree_family.id` sont deux séquences indépendantes, et
 * la personne 1 comme la famille 1 existent. Indexer par l'entier nu perdrait l'une des
 * deux.
 */
final class CalculParente
{
    /** Les préfixes d'éloignement : 1 génération = pas de préfixe, 2 = « arrière- », etc. */
    private const array PREFIXES = [1 => '', 2 => 'arrière-', 3 => 'arrière-arrière-', 4 => 'arrière-arrière-arrière-'];

    /**
     * Les libellés de tout l'arbre, vus depuis la racine du jeu.
     *
     * @return array<string, string> identifiant de nœud (`p1`) → libellé
     */
    public function pourTous(JeuDePlacement $jeu): array
    {
        $adjacence = $this->adjacence($jeu);
        $ascendantsEgo = $this->ascendants($adjacence['parents'], $jeu->egoId);

        $parSang = [];

        foreach ($jeu->personnes as $personne) {
            $parSang[$personne->id] = $this->parSang($adjacence['parents'], $ascendantsEgo, $jeu->egoId, $personne->id, $personne->sexe);
        }

        // Seconde passe : les pièces rapportées — celles qui n'ont aucun ancêtre commun
        // avec la racine — sont nommées par le conjoint qui, lui, est du sang. On lit la
        // **première passe figée**, pour ne pas nommer quelqu'un d'après un libellé qu'on
        // vient soi-même d'écrire (« épouse de l'époux de ta tante »).
        $libelles = [];

        foreach ($jeu->personnes as $personne) {
            $libelles[$personne->id] = $parSang[$personne->id]
                ?? $this->parAlliance($adjacence['conjoints'], $parSang, $personne->id, $personne->sexe);
        }

        return $libelles;
    }

    /**
     * Le libellé d'une seule personne.
     *
     * Le coût est celui de {@see pourTous()} dans le pire cas — la seconde passe doit
     * connaître le lien du conjoint —, mais l'appelant qui n'affiche qu'une fiche n'a pas
     * à porter 274 chaînes dont il jettera 273.
     */
    public function pour(JeuDePlacement $jeu, string $idPersonne): ?string
    {
        return $this->pourTous($jeu)[$idPersonne] ?? null;
    }

    /**
     * Parents, enfants et conjoints, dérivés des familles.
     *
     * **L'ordre des listes est de la donnée, pas de la présentation** : la seconde passe
     * retient le *premier* conjoint du sang, et le jeu de placement rend les familles dans
     * un ordre déterministe précisément pour que ce genre de choix ne dépende pas du hasard.
     *
     * @return array{parents: array<string, list<string>>, conjoints: array<string, list<string>>}
     */
    private function adjacence(JeuDePlacement $jeu): array
    {
        $parents = [];
        $conjoints = [];

        foreach ($jeu->familles as $famille) {
            foreach ($famille->enfants as $enfant) {
                // Écrasement volontaire : une personne rattachée à deux familles — une
                // adoption — garde la dernière. C'est le comportement du script d'origine.
                $parents[$enfant] = $famille->epoux;
            }

            if (2 === \count($famille->epoux)) {
                $conjoints[$famille->epoux[0]][] = $famille->epoux[1];
                $conjoints[$famille->epoux[1]][] = $famille->epoux[0];
            }
        }

        return ['parents' => $parents, 'conjoints' => $conjoints];
    }

    /**
     * Tous les ascendants de `$depart`, lui compris, avec leur distance en générations.
     *
     * **Le garde-fou `$distance` n'est pas décoratif** : il y a de l'implexe dans cet
     * arbre — des mariages entre parents —, donc des cycles dans le graphe d'ascendance.
     * Sans lui, ce parcours ne s'arrête pas.
     *
     * Parcours en largeur, et on garde la **plus courte** distance : deux personnes qui
     * descendent deux fois du même couple sont cousines par le chemin le plus court, pas
     * par le plus long.
     *
     * @param array<string, list<string>> $parents
     *
     * @return array<string, int>
     */
    private function ascendants(array $parents, string $depart): array
    {
        $distance = [$depart => 0];
        $file = [$depart];

        while ([] !== $file) {
            $courant = array_shift($file);

            foreach ($parents[$courant] ?? [] as $parent) {
                if (!isset($distance[$parent]) || $distance[$courant] + 1 < $distance[$parent]) {
                    $distance[$parent] = $distance[$courant] + 1;
                    $file[] = $parent;
                }
            }
        }

        return $distance;
    }

    /**
     * Le lien **par le sang** uniquement. `null` s'il n'y a pas d'ancêtre commun.
     *
     * @param array<string, list<string>> $parents
     * @param array<string, int>          $ascendantsEgo
     */
    private function parSang(array $parents, array $ascendantsEgo, string $ego, string $id, Sex $sexe): ?string
    {
        if ($id === $ego) {
            return 'toi';
        }

        $ascendants = $this->ascendants($parents, $id);

        // L'ancêtre commun retenu est celui qui minimise la **somme** des deux distances,
        // et non la distance à la racine seule : c'est ce qui nomme correctement un cousin
        // atteint par deux chemins, ce que l'implexe rend possible ici.
        $meilleur = null;

        foreach ($ascendantsEgo as $ancetre => $distanceEgo) {
            if (!isset($ascendants[$ancetre])) {
                continue;
            }

            $somme = $distanceEgo + $ascendants[$ancetre];

            if (null === $meilleur || $somme < $meilleur[0]) {
                $meilleur = [$somme, $distanceEgo, $ascendants[$ancetre]];
            }
        }

        if (null === $meilleur) {
            return null;
        }

        [, $coteEgo, $coteLui] = $meilleur;
        $masculin = Sex::Masculin === $sexe;
        $e = $masculin ? '' : 'e';

        // Ligne directe ascendante : l'ancêtre commun est la personne elle-même.
        if (0 === $coteLui) {
            return match (true) {
                1 === $coteEgo => $masculin ? 'père' : 'mère',
                2 === $coteEgo => $masculin ? 'grand-père' : 'grand-mère',
                default => $this->prefixe($coteEgo - 1).($masculin ? 'grand-père' : 'grand-mère'),
            };
        }

        // Ligne directe descendante : l'ancêtre commun est la racine.
        if (0 === $coteEgo) {
            return match (true) {
                1 === $coteLui => $masculin ? 'fils' : 'fille',
                2 === $coteLui => $masculin ? 'petit-fils' : 'petite-fille',
                default => $this->prefixe($coteLui - 1).($masculin ? 'petit-fils' : 'petite-fille'),
            };
        }

        if (1 === $coteEgo && 1 === $coteLui) {
            return $masculin ? 'frère' : 'sœur';
        }

        if (1 === $coteEgo) {
            return 2 === $coteLui
                ? ($masculin ? 'neveu' : 'nièce')
                : $this->prefixe($coteLui - 2).($masculin ? 'petit-neveu' : 'petite-nièce');
        }

        if (1 === $coteLui) {
            return 2 === $coteEgo
                ? ($masculin ? 'oncle' : 'tante')
                : $this->prefixe($coteEgo - 2).($masculin ? 'grand-oncle' : 'grand-tante');
        }

        $degre = min($coteEgo, $coteLui);
        $ecart = abs($coteEgo - $coteLui);
        $base = $masculin ? 'cousin' : 'cousine';

        $libelle = match (true) {
            2 === $degre => $base.' germain'.$e,
            3 === $degre => $base.' issu'.$e.' de germain',
            default => $base.' au '.($degre - 1).'e degré',
        };

        if (0 !== $ecart) {
            $libelle .= ', éloigné'.$e.' de '.$ecart.' génération'.($ecart > 1 ? 's' : '');
        }

        return $libelle;
    }

    /**
     * @param array<string, list<string>> $conjoints
     * @param array<string, string|null>  $parSang
     */
    private function parAlliance(array $conjoints, array $parSang, string $id, Sex $sexe): string
    {
        foreach ($conjoints[$id] ?? [] as $conjoint) {
            $lien = $parSang[$conjoint] ?? null;

            if (null !== $lien && 'toi' !== $lien) {
                return (Sex::Masculin === $sexe ? 'époux' : 'épouse').' de ton '.$lien;
            }
        }

        return 'relié par alliance';
    }

    private function prefixe(int $generations): string
    {
        return self::PREFIXES[$generations] ?? str_repeat('arrière-', $generations - 1);
    }
}
