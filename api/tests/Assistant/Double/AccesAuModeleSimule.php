<?php

declare(strict_types=1);

namespace App\Tests\Assistant\Double;

use App\Module\Assistant\Contract\AccesAuModele;

/**
 * Une clé Gemini simulée, **présente par défaut** : un test qui veut le `503` la retire.
 *
 * État statique, comme {@see ModeleScripte}, pour survivre au redémarrage du noyau entre
 * deux requêtes du client HTTP.
 */
final class AccesAuModeleSimule implements AccesAuModele
{
    private static bool $configure = true;

    public static function oublierTout(): void
    {
        self::$configure = true;
    }

    public static function retirerLaCle(): void
    {
        self::$configure = false;
    }

    public function configure(): bool
    {
        return self::$configure;
    }

    public function cleApi(): string
    {
        return self::$configure ? 'cle-de-test' : throw new \LogicException('Clé absente.');
    }

    public function pointDacces(): string
    {
        return 'https://gemini.invalid/v1beta';
    }
}
