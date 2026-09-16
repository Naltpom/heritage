<?php

declare(strict_types=1);

namespace App\Module\Media\Enum;

/**
 * Ce à quoi un média se rattache (§ 6.3 du modèle de données).
 *
 * `Citation` est la troisième valeur pour une raison précise : les images de
 * `recherche/` et `vignettes/` s'importent en preuves rattachées aux **citations**
 * existantes (§ 6.5 du dossier de mission), pas aux personnes.
 */
enum MediaSubject: string
{
    case Personne = 'personne';
    case Famille = 'famille';
    case Citation = 'citation';

    public function label(): string
    {
        return match ($this) {
            self::Personne => 'Personne',
            self::Famille => 'Union',
            self::Citation => 'Citation',
        };
    }
}
