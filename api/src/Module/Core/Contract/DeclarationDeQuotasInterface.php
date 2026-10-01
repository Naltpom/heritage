<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * La **couture** par laquelle un module fait connaître ses quotas d'usage à l'écran
 * d'administration — même motif que {@see SessionPayloadContributorInterface}.
 *
 * `Core` sert `GET /api/administration/utilisateurs` et doit y rendre, par compte, la
 * consommation de chaque quota. Il ne peut pas les connaître sans citer `Assistant` ni
 * `Media` (interdit : `tests/Frontieres`, « le noyau ne référence aucun module »). Chaque
 * module implémente donc cette interface ; le conteneur les rassemble par l'étiquette, et
 * `Core\Service\QuotasDUsage` les liste dans l'ordre où il les reçoit.
 */
#[AutoconfigureTag('core.declaration_de_quotas')]
interface DeclarationDeQuotasInterface
{
    /** @return list<Quota> */
    public function quotas(): array;
}
