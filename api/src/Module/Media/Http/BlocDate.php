<?php

declare(strict_types=1);

namespace App\Module\Media\Http;

use App\Module\Core\Service\LectureDeBlocDate;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Lit la partie `taken` du `multipart/form-data` : un bloc date à **cinq clés**,
 * sérialisé en JSON (§ 7.1 et § 14.1 du contrat).
 *
 * Cinq clés, toujours les cinq, « pour qu'un champ absent soit toujours une erreur et
 * jamais un non-renseigné implicite ». `texte` n'en fait **pas** partie : il est en
 * lecture seule, et un back qui l'accepterait en entrée accepterait une sixième clé que
 * la comparaison `JSONB` de la détection de conflit ferait diverger (§ 14.1).
 *
 * Les deux contraintes de cohérence sont vérifiées et rendent `422` **avant**
 * d'atteindre la base : le `CHECK` de PostgreSQL est le filet, pas le contrôle. Une
 * violation de contrainte remonterait en `500`, c'est-à-dire en panne, là où c'est une
 * saisie invalide.
 *
 * **La lecture elle-même est dans `Core`** ({@see LectureDeBlocDate}), depuis que la
 * ressemblance de l'arbre et celle des propositions reçoivent elles aussi des blocs date
 * dans leur corps : `Tree` et `Proposal` n'ont pas le droit d'importer `Media`, et une
 * troisième copie de ces contrôles aurait été une troisième façon de les rater. Cette
 * classe ne garde que ce qui appartient au téléversement — le nom du champ, `taken` — et
 * sa signature, que `MediaController` et `BlocDateTest` appellent telle quelle.
 */
final class BlocDate
{
    public static function depuisJson(?string $json): ?UncertainDate
    {
        return (new LectureDeBlocDate())->depuisJson($json, 'taken');
    }
}
