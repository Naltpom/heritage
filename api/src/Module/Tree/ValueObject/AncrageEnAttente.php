<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * Par où un nœud « en attente » se rattache au graphe validé (§ 6.4 du dossier de
 * mission).
 *
 * Une demande de création porte **au moins un lien**, sinon la personne flotterait hors
 * de l'arbre. L'ancrage est ce lien, réduit à ce dont la projection a besoin pour poser
 * le nœud : un genre et une cible déjà placée.
 *
 * Il décrit une **intention**, pas une structure : « conjoint de X » ne dit pas quelle
 * famille créer. C'est la validation qui en déduit les familles, pas la projection —
 * celle-ci se contente d'un losange provisoire pour que le lien soit visible.
 */
final readonly class AncrageEnAttente
{
    /** Enfant d'une famille **existante**, désignée par son identifiant. */
    public const string ENFANT_DE_FAMILLE = 'enfant_de_famille';

    /** Conjoint d'une personne existante. Crée toujours une famille (§ 6.4). */
    public const string CONJOINT_DE = 'conjoint_de';

    /** Parent d'une personne existante. */
    public const string PARENT_DE = 'parent_de';

    /** @param self::* $genre */
    private function __construct(
        public string $genre,
        public string $cibleId,
    ) {
    }

    public static function enfantDe(string $familleId): self
    {
        return new self(self::ENFANT_DE_FAMILLE, $familleId);
    }

    public static function conjointDe(string $personneId): self
    {
        return new self(self::CONJOINT_DE, $personneId);
    }

    public static function parentDe(string $personneId): self
    {
        return new self(self::PARENT_DE, $personneId);
    }
}
