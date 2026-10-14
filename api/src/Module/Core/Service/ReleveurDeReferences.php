<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Les deux requêtes dont `heritage:verifier-integrite` a besoin, écrites une fois.
 *
 * Chaque module déclare ses références inter-modules
 * ({@see \App\Module\Core\Contract\ReferencesInterModulesInterface}) et répond pour ses
 * cibles ({@see \App\Module\Core\Contract\CiblesDeReferenceInterface}) ; sans cet outil
 * commun, neuf modules recopieraient le même `GROUP BY` et le même découpage de `IN`, et le
 * jour où l'un des neuf se tromperait de colonne de comptage, rien ne le dirait.
 *
 * **Il ne lit que ce que l'appelant lui nomme** : un module lui passe ses propres tables, et
 * c'est là toute la règle de frontière. L'outil ne connaît aucune table, et ne joint jamais
 * deux modules.
 *
 * ## Les deux moteurs
 *
 * Rien ici n'est propre à PostgreSQL ni à SQLite : `GROUP BY` sur une colonne entière,
 * `IN` sur des entiers, des paramètres nommés. Le `IN` est découpé par paquets de
 * {@see self::PAQUET}, sous la limite de variables de SQLite (999 sur les vieilles versions),
 * et pour que PostgreSQL ne reçoive jamais une requête à dix mille paramètres.
 *
 * Les noms de table et de colonne sont **vérifiés** avant d'être assemblés : ils viennent du
 * code et jamais d'une requête HTTP, mais une faute de frappe y deviendrait du SQL.
 */
final readonly class ReleveurDeReferences
{
    private const int PAQUET = 500;

    public function __construct(private Connection $connexion)
    {
    }

    /**
     * Combien de lignes désignent chaque identifiant — `identifiant => nombre`.
     *
     * Les valeurs nulles ne sont pas relevées : une référence facultative absente n'est pas
     * pendante.
     *
     * @param string                                 $table      la table, avec un alias facultatif (`prp_change_op o`)
     * @param string                                 $colonne    la colonne, préfixée de l'alias s'il y en a un (`o.subject_id`)
     * @param string|null                            $condition  un filtre SQL sur les lignes relevées, à paramètres nommés
     * @param array<string, int|string|list<string>> $parametres
     * @param string|null                            $jointure   une jointure **interne au module** (`JOIN prp_change_request r ON …`)
     *
     * @return array<int, int>
     */
    public function occurrences(string $table, string $colonne, ?string $condition = null, array $parametres = [], ?string $jointure = null): array
    {
        $this->exigerUnNom($table, '/^[a-z][a-z0-9_]*( [a-z][a-z0-9_]*)?$/');
        $this->exigerUnNom($colonne, '/^([a-z][a-z0-9_]*\.)?[a-z][a-z0-9_]*$/');

        $sql = \sprintf(
            'SELECT %2$s AS cible, COUNT(*) AS nombre FROM %1$s %3$s WHERE %2$s IS NOT NULL%4$s GROUP BY %2$s',
            $table,
            $colonne,
            $jointure ?? '',
            null === $condition ? '' : ' AND ('.$condition.')',
        );

        $types = [];
        foreach ($parametres as $nom => $valeur) {
            if (\is_array($valeur)) {
                $types[$nom] = ArrayParameterType::STRING;
            }
        }

        $occurrences = [];

        foreach ($this->connexion->executeQuery($sql, $parametres, $types)->fetchAllAssociative() as $ligne) {
            $occurrences[(int) $ligne['cible']] = (int) $ligne['nombre'];
        }

        ksort($occurrences);

        return $occurrences;
    }

    /**
     * Ceux des identifiants qui sont une clé primaire de `$table`.
     *
     * @param list<int> $identifiants
     *
     * @return list<int>
     */
    public function existants(string $table, array $identifiants): array
    {
        $this->exigerUnNom($table, '/^[a-z][a-z0-9_]*$/');

        $existants = [];

        foreach (array_chunk(array_values(array_unique($identifiants)), self::PAQUET) as $paquet) {
            $lignes = $this->connexion->executeQuery(
                \sprintf('SELECT id FROM %s WHERE id IN (:ids)', $table),
                ['ids' => $paquet],
                ['ids' => ArrayParameterType::INTEGER],
            )->fetchFirstColumn();

            foreach ($lignes as $id) {
                $existants[] = (int) $id;
            }
        }

        return $existants;
    }

    private function exigerUnNom(string $nom, string $motif): void
    {
        if (1 !== preg_match($motif, $nom)) {
            throw new \InvalidArgumentException(\sprintf('« %s » n\'est pas un nom de table ou de colonne que ce relevé accepte.', $nom));
        }
    }
}
