<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * **À qui** une personne vivante montre une composante de sa date de naissance — l'année,
 * le mois ou le jour, chacun le sien (ADR 0016, révision du 2026-10-07).
 *
 * - `Tous` : tous les vivants de l'arbre — qui la montrent aussi ;
 * - `Proches` : ses proches seulement (ascendants, descendants, frères et sœurs, conjoints
 *   actuels, ascendants des conjoints, conjoints des descendants) — **le défaut** ;
 * - `Personne` : personne, et l'on ne voit alors cette composante chez personne.
 *
 * Les niveaux sont **ordonnés** : `Tous` inclut `Proches`. C'est ce qui permet de lire la
 * réciprocité en une phrase — un lecteur voit une composante d'un proche si les deux sont
 * au moins à `Proches`, d'un autre vivant si les deux sont à `Tous`.
 */
enum NiveauDePartage: string
{
    case Tous = 'tous';
    case Proches = 'proches';
    case Personne = 'personne';

    /** Ce niveau montre-t-il la composante à ce lecteur, proche ou non ? */
    public function montreA(bool $proche): bool
    {
        return match ($this) {
            self::Tous => true,
            self::Proches => $proche,
            self::Personne => false,
        };
    }

    /** Une lettre, pour les empreintes. */
    public function code(): string
    {
        return match ($this) {
            self::Tous => 't',
            self::Proches => 'p',
            self::Personne => '-',
        };
    }
}
