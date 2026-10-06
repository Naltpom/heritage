<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use App\Module\Core\Enum\CibleDeReference;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * L'autre moitié de {@see ReferencesInterModulesInterface} : le module **propriétaire**
 * d'une cible dit lesquels des identifiants qu'on lui présente existent.
 *
 * Une cible a **un seul** propriétaire. La commande `heritage:verifier-integrite` refuse
 * de conclure si deux modules se déclarent pour la même, ou si une référence vise une cible
 * que personne ne déclare : une question sans répondant n'est pas une question à laquelle
 * on a répondu « rien à signaler ».
 */
#[AutoconfigureTag('core.cibles_de_reference')]
interface CiblesDeReferenceInterface
{
    /** @return list<CibleDeReference> */
    public function cibles(): array;

    /**
     * @param list<int> $identifiants
     *
     * @return list<int> ceux qui existent, dans un ordre quelconque
     */
    public function existants(CibleDeReference $cible, array $identifiants): array;
}
