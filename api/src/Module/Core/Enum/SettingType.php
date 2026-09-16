<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Le type porté par un réglage (§ 2.7 du modèle de données).
 *
 * `core_setting.value` est un `TEXT` : c'est cette colonne-ci qui dit comment le lire.
 * Une seule table de réglages, pas cinq. Le service de réglages caste à la lecture et
 * **refuse** à l'écriture une valeur qui ne caste pas.
 */
enum SettingType: string
{
    case Entier = 'entier';
    case Texte = 'texte';
    case Booleen = 'booleen';
    case Json = 'json';

    public function label(): string
    {
        return match ($this) {
            self::Entier => 'Nombre entier',
            self::Texte => 'Texte',
            self::Booleen => 'Oui / non',
            self::Json => 'JSON',
        };
    }
}
