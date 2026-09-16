<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * L'identifiant d'un nœud du graphe : `p1` pour une personne, `f28` pour une famille.
 *
 * ## Pourquoi un préfixe, et pas l'identifiant de base tel quel
 *
 * **`tree_person.id` et `tree_family.id` sont deux séquences indépendantes.** La
 * personne 1 et la famille 1 existent toutes les deux, et un graphe qui les indexerait
 * par leur seul entier en perdrait une : les nœuds sont dans un même tableau, la seconde
 * écraserait la première. Le symptôme observé est net et trompeur — le graphe sort avec
 * 274 nœuds au lieu de 377, comme si aucune famille n'avait été calculée, alors qu'elles
 * l'ont toutes été.
 *
 * Le fichier de référence n'avait pas le problème : le GEDCOM nomme `@I500001@` et
 * `@F500001@`, deux espaces de noms distincts. C'est en passant aux identifiants de base
 * que la collision apparaît, donc **après** le portage du moteur — et c'est exactement ce
 * que le test de bout en bout du fournisseur existe pour attraper.
 *
 * Le format est celui du `docs/api-contract.md` (§ « La charge utile ») : `p1`, `f28`, et
 * `#12/p1` pour un nœud en attente de la demande 12. Il sort tel quel de l'API, donc il
 * ne se change pas sans changer le contrat et le viewer.
 */
final class IdentifiantDeNoeud
{
    public const string PREFIXE_PERSONNE = 'p';
    public const string PREFIXE_FAMILLE = 'f';

    private function __construct()
    {
    }

    public static function personne(int $id): string
    {
        return self::PREFIXE_PERSONNE.$id;
    }

    public static function famille(int $id): string
    {
        return self::PREFIXE_FAMILLE.$id;
    }

    public static function estPersonne(string $identifiant): bool
    {
        return 1 === preg_match('/^'.self::PREFIXE_PERSONNE.'\d+$/', $identifiant);
    }

    public static function estFamille(string $identifiant): bool
    {
        return 1 === preg_match('/^'.self::PREFIXE_FAMILLE.'\d+$/', $identifiant);
    }

    /**
     * L'identifiant de base derrière un identifiant de nœud, ou `null` si le nœud n'en a
     * pas — c'est le cas d'un nœud **en attente**, dont la personne n'existe pas encore
     * dans `tree_person` (§ 6.4 du dossier de mission).
     */
    public static function identifiantBase(string $identifiant): ?int
    {
        if (1 !== preg_match('/^[pf](\d+)$/', $identifiant, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
