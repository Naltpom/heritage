<?php

declare(strict_types=1);

namespace App\Module\Soutien\Service;

/**
 * Liberapay n'a pas répondu, ou a répondu quelque chose qu'on ne sait pas lire.
 *
 * Ce n'est jamais une erreur pour le lecteur : la page affiche le dernier relevé connu.
 */
final class LiberapayIndisponible extends \RuntimeException
{
}
