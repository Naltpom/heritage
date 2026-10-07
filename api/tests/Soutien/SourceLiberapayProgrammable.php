<?php

declare(strict_types=1);

namespace App\Tests\Soutien;

use App\Module\Soutien\Service\LiberapayIndisponible;
use App\Module\Soutien\Service\ProfilLiberapay;
use App\Module\Soutien\Service\SourceLiberapay;

/**
 * Le Liberapay du conteneur de test : il rend le profil qu'on lui a programmé, ou se dit
 * indisponible. Il compte les appels — c'est ce qui prouve qu'un relevé ne se reprend pas.
 *
 * **L'état est statique**, et c'est voulu : le client de test redémarre le noyau entre deux
 * requêtes, donc reconstruit ce service. Un état d'instance serait perdu à la deuxième
 * requête. Chaque cas le remet à zéro par {@see self::reinitialiser()}.
 */
final class SourceLiberapayProgrammable implements SourceLiberapay
{
    public static ?ProfilLiberapay $profil = null;

    public static int $appels = 0;

    public static function reinitialiser(): void
    {
        self::$profil = null;
        self::$appels = 0;
    }

    public function profil(string $pseudo): ProfilLiberapay
    {
        ++self::$appels;

        return self::$profil ?? throw new LiberapayIndisponible('Liberapay programmé indisponible.');
    }
}
