<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * Une arête du graphe. Deux genres seulement, et jamais de lien direct personne →
 * personne : tout passe par le nœud de jonction familial.
 *
 * - `union` : un époux vers sa famille, montant depuis la fiche vers le losange ;
 * - `filiation` : la famille vers chacun de ses enfants.
 *
 * Il n'existe **pas** de troisième genre « lien long ». Le § 7.3 du dossier de mission
 * retire la signalétique `.ar.long` du rendu : « ça sert à rien si c'est pour dire c'est
 * loin ». Aucune longueur n'est donc calculée ni transmise ici — c'est volontaire, et
 * c'est le genre de champ qu'on rajoute par réflexe avant de se rappeler pourquoi il a
 * été supprimé.
 */
final readonly class AreteDePlacement
{
    public const string GENRE_UNION = 'union';
    public const string GENRE_FILIATION = 'filiation';

    /**
     * @param self::GENRE_* $genre
     * @param bool          $divorce toujours `false` sur une filiation ; sur une union, il
     *                               porte `.ar.divorce`, que le § 7.3 **conserve** — un
     *                               divorce est une information, pas une distance
     */
    private function __construct(
        public string $de,
        public string $vers,
        public string $genre,
        public bool $divorce,
    ) {
    }

    public static function union(string $epouxId, string $familleId, bool $divorce): self
    {
        return new self($epouxId, $familleId, self::GENRE_UNION, $divorce);
    }

    public static function filiation(string $familleId, string $enfantId): self
    {
        return new self($familleId, $enfantId, self::GENRE_FILIATION, false);
    }
}
