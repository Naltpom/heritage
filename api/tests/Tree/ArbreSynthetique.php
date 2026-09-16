<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\ValueObject\FamillePlacable;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\PersonnePlacable;

/**
 * Un petit arbre inventé, sans aucune donnée réelle — celui des tests qui **tournent en
 * CI** (ADR 0005, ADR 0006).
 *
 * La CI n'a pas d'étalon à comparer : `graphe.json` n'est pas versionné. Elle ne peut
 * donc vérifier que des **invariants**, et c'est une couverture plus faible, assumée.
 * C'est aussi le seul endroit où les invariants subsistent : ils ne sont plus le critère
 * d'acceptation du portage, seulement le filet de la CI.
 *
 * > **La conséquence à ne pas manquer : la CI peut passer au vert sur un portage faux.**
 * > Seul {@see PlacementNonRegressionTest} prouve l'équivalence, et il ne tourne que sur
 * > la machine du commanditaire.
 *
 * La topologie reproduit celle qui compte pour le moteur : un ego avec fratrie, deux
 * lignées de grands-parents (donc le bloc paternel à gauche et le bloc maternel à
 * droite), et une branche d'oncle avec cousins — qui passe, elle, par le point fixe de
 * la phase 2 et non par la récursion d'ascendance.
 */
final class ArbreSynthetique
{
    public const string EGO = 'P-ego';

    public static function jeu(): JeuDePlacement
    {
        $personnes = [
            // Génération 0 : l'ego, sa fratrie, puis les cousins.
            self::personne('P-ego', 'Camille', 'Dupont', Sex::Masculin, 1990, 0, vivant: true),
            self::personne('P-soeur', 'Alix', 'Dupont', Sex::Feminin, 1988, 0, vivant: true),
            self::personne('P-frere', 'Noé', 'Dupont', Sex::Masculin, 1993, 0, vivant: true),
            self::personne('P-cousin1', 'Élise', 'Dupont', Sex::Feminin, 1991, 0, vivant: true),
            self::personne('P-cousin2', 'Hugo', 'Dupont', Sex::Masculin, 1994, 0, vivant: true),

            // Génération -1 : les parents, l'oncle et sa conjointe.
            self::personne('P-pere', 'Bernard', 'Dupont', Sex::Masculin, 1960, -1, nais: '3.4.1960'),
            self::personne('P-mere', 'Sylvie', 'Moreau', Sex::Feminin, 1962, -1, nais: '9.9.1962'),
            self::personne('P-oncle', 'Gérard', 'Dupont', Sex::Masculin, 1958, -1, nais: '1.2.1958', deces: '7.7.2015'),
            self::personne('P-tante', 'Martine', 'Leroy', Sex::Feminin, 1959, -1, nais: '5.5.1959'),

            // Génération -2 : les deux couples de grands-parents.
            self::personne('P-gp-pat', 'Henri', 'Dupont', Sex::Masculin, 1930, -2, nais: '2.1.1930', deces: '8.3.2001'),
            self::personne('P-gm-pat', 'Jeanne', 'Petit', Sex::Feminin, 1932, -2, nais: '4.6.1932', deces: '1.11.2010'),
            self::personne('P-gp-mat', 'Robert', 'Moreau', Sex::Masculin, 1931, -2, nais: '7.2.1931', deces: '3.9.1999'),
            self::personne('P-gm-mat', 'Odette', 'Girard', Sex::Feminin, 1934, -2, nais: '12.12.1934'),
        ];

        $familles = [
            new FamillePlacable('F-parents', ['P-pere', 'P-mere'], ['P-soeur', 'P-ego', 'P-frere'], false),
            new FamillePlacable('F-gp-pat', ['P-gp-pat', 'P-gm-pat'], ['P-oncle', 'P-pere'], false),
            new FamillePlacable('F-gp-mat', ['P-gp-mat', 'P-gm-mat'], ['P-mere'], false),
            // Divorce conservé au rendu (§ 7.3) : c'est une information, pas une distance.
            new FamillePlacable('F-oncle', ['P-oncle', 'P-tante'], ['P-cousin1', 'P-cousin2'], true),
        ];

        return new JeuDePlacement($personnes, $familles, self::EGO);
    }

    private static function personne(
        string $id,
        string $prenom,
        string $nom,
        Sex $sexe,
        ?int $anNais,
        int $generation,
        string $nais = '',
        string $deces = '',
        bool $vivant = false,
    ): PersonnePlacable {
        return new PersonnePlacable(
            id: $id,
            prenom: $prenom,
            nom: $nom,
            usage: '',
            sexe: $sexe,
            nais: $nais,
            deces: $deces,
            anNais: $anNais,
            vivant: $vivant,
            generation: $generation,
        );
    }
}
