<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Remise à zéro du schéma entre deux tests — la recette commune.
 *
 * Elle existe pour qu'il n'y en ait qu'une. Au moment où elle a été écrite, trois
 * modules avaient chacun bricolé la leur, avec trois comportements légèrement
 * différents sur les mêmes pièges. Un module qui a besoin d'une base vierge appelle
 * {@see self::reinitialiser()} et n'écrit rien de plus.
 *
 * L'isolation ENTRE PROCESSUS, elle, est réglée ailleurs et une fois pour toutes :
 * `tests/bootstrap.php` donne à chaque processus son propre fichier SQLite et son
 * propre répertoire de pools de cache. Il n'y a rien à refaire ici.
 */
final class ReinitialisationDuSchema
{
    /**
     * Vide puis reconstruit le schéma depuis le mapping Doctrine.
     *
     * Le schéma est reconstruit depuis les entités et non par la migration : la
     * migration est écrite en PostgreSQL et ne joue pas sur le SQLite des tests. La
     * cohérence entre les deux est vérifiée séparément, par le test de schéma.
     *
     * ON N'APPELLE PAS `SchemaTool::dropSchema()`. JAMAIS. Ce n'est pas une préférence
     * de style, et la boucle manuelle ci-dessous n'est pas de la verbosité inutile —
     * quelqu'un voudra un jour la « simplifier », alors voici ce que ça coûte :
     *
     * `dropSchema()` exécute ses `DROP` un par un EN AVALANT LES EXCEPTIONS. Quand
     * l'ordre de suppression heurte une clé étrangère, une partie des tables survit
     * sans que rien ne le signale. Le `createSchema()` suivant échoue alors sur la
     * première table déjà présente, en laissant la base à moitié construite — et c'est
     * un test ULTÉRIEUR qui tombe, sur un « no such table » à des kilomètres de la
     * cause. On cherche dans le test qui échoue, où il n'y a rien à trouver.
     *
     * Avec `PRAGMA foreign_keys = OFF`, l'ordre cesse d'exister comme problème : on
     * supprime dans l'ordre du catalogue, et rien ne peut échouer à moitié.
     */
    public static function reinitialiser(EntityManagerInterface $em): void
    {
        $connexion = $em->getConnection();
        $sqlite = str_contains($connexion->getDatabasePlatform()::class, 'SQLite');

        if ($sqlite) {
            $connexion->executeStatement('PRAGMA foreign_keys = OFF');
        }

        foreach ($connexion->createSchemaManager()->listTableNames() as $table) {
            $connexion->executeStatement(\sprintf('DROP TABLE IF EXISTS "%s"%s', $table, $sqlite ? '' : ' CASCADE'));
        }

        if ($sqlite) {
            $connexion->executeStatement('PRAGMA foreign_keys = ON');
        }

        $metadonnees = $em->getMetadataFactory()->getAllMetadata();

        // Un mapping vide ne se traite pas en silence. Sans entité, `createSchema()`
        // ne bâtit rien et la remise à zéro « réussit » en laissant une base nue ;
        // ce sont les tests suivants qui tombent, sur des « no such table » dont la
        // cause est ici. Un dispositif qui ne peut annoncer qu'un seul résultat
        // n'informe de rien : on affirme donc la taille de l'ensemble découvert.
        if ([] === $metadonnees) {
            throw new \LogicException('Aucune entité Doctrine mappée : la remise à zéro du schéma bâtirait une base vide. Vérifier les `mappings` de config/packages/doctrine.yaml.');
        }

        (new SchemaTool($em))->createSchema($metadonnees);
    }
}
