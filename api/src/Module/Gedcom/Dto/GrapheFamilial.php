<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

/**
 * Les adjacences de l'arbre, sous la forme exacte dont `graphe.py` et `build_data.py`
 * se servent.
 *
 * **L'ordre des listes est de la donnée, pas de la présentation.** La boucle d'héritage
 * des branches parcourt les individus dans l'ordre du fichier et retient le **premier**
 * voisin déjà classé ; le calcul des générations propage en largeur en visitant parents,
 * puis enfants, puis conjoints, dans cet ordre. Changer l'un ou l'autre change le
 * résultat — pas de beaucoup, mais assez pour que la répartition par branche ne retombe
 * plus sur `pm 134, mm 64, mp 51, pp 20, tr 5`.
 */
final class GrapheFamilial
{
    /**
     * @param list<string>                                                     $individus xrefs, dans l'ordre du fichier
     * @param array<string, list<string>>                                      $parents
     * @param array<string, list<string>>                                      $enfants
     * @param array<string, list<string>>                                      $conjoints
     * @param array<string, list<string>>                                      $fratrie
     * @param array<string, array{epoux: list<string>, enfants: list<string>}> $familles  dans l'ordre du fichier
     */
    public function __construct(
        public readonly array $individus,
        public readonly array $parents,
        public readonly array $enfants,
        public readonly array $conjoints,
        public readonly array $fratrie,
        public readonly array $familles,
    ) {
    }

    /**
     * @param array<string, PersonneLue> $personnes
     * @param array<string, FamilleLue>  $familles
     */
    public static function construire(array $personnes, array $familles): self
    {
        $parents = [];
        $enfants = [];
        $conjoints = [];
        $fratrie = [];
        $blocs = [];

        foreach ($familles as $xref => $famille) {
            $epoux = $famille->epoux();
            $blocs[$xref] = ['epoux' => $epoux, 'enfants' => $famille->enfants];

            foreach ($famille->enfants as $enfant) {
                // Écrasement volontaire, et c'est le comportement des scripts d'origine :
                // une personne rattachée à deux familles par `FAMC` — une adoption —
                // garderait la dernière. Le fichier n'en contient aucune ; l'import
                // signale le cas plutôt que de le traiter en silence.
                $parents[$enfant] = $epoux;

                foreach ($epoux as $parent) {
                    $enfants[$parent][] = $enfant;
                }
            }

            if (null !== $famille->mari && null !== $famille->femme) {
                $conjoints[$famille->mari][] = $famille->femme;
                $conjoints[$famille->femme][] = $famille->mari;
            }

            foreach ($famille->enfants as $a) {
                foreach ($famille->enfants as $b) {
                    if ($a !== $b) {
                        $fratrie[$a][] = $b;
                    }
                }
            }
        }

        return new self(array_keys($personnes), $parents, $enfants, $conjoints, $fratrie, $blocs);
    }

    /** @return list<string> */
    public function parentsDe(string $xref): array
    {
        return $this->parents[$xref] ?? [];
    }

    /** @return list<string> */
    public function enfantsDe(string $xref): array
    {
        return $this->enfants[$xref] ?? [];
    }

    /** @return list<string> */
    public function conjointsDe(string $xref): array
    {
        return $this->conjoints[$xref] ?? [];
    }

    /**
     * Tous les ascendants de `$depart`, lui compris, avec leur distance en générations.
     *
     * **Le garde-fou `vus` n'est pas décoratif** : il y a de l'implexe dans cet arbre —
     * des mariages entre parents —, donc des cycles dans le graphe d'ascendance. Sans
     * lui, ce parcours ne s'arrête pas.
     *
     * Parcours en largeur, et on garde la **plus courte** distance : c'est ce que fait
     * `build_data.py`, et c'est ce qu'il faut pour nommer un lien de parenté — deux
     * personnes qui descendent deux fois du même couple sont cousines par le chemin le
     * plus court, pas par le plus long.
     *
     * @return array<string, int>
     */
    public function ascendants(string $depart): array
    {
        $distance = [$depart => 0];
        $file = [$depart];

        while ([] !== $file) {
            $courant = array_shift($file);

            foreach ($this->parentsDe($courant) as $parent) {
                if (!isset($distance[$parent]) || $distance[$courant] + 1 < $distance[$parent]) {
                    $distance[$parent] = $distance[$courant] + 1;
                    $file[] = $parent;
                }
            }
        }

        return $distance;
    }
}
