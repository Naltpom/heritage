<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Contract\DeclarationDeQuotasInterface;
use App\Module\Core\Contract\Quota;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le quota des téléversements, tel que `Core` le compte et l'affiche.
 *
 * Défaut dans `config/services/media.yaml` ; identifiant `media_televersement` et fenêtre
 * d'une heure **repris** de l'ancienne `RateLimiterFactory`, pour que les dépôts déjà
 * comptés le restent (voir `Core\Contract\Quota`). L'administrateur en est exempté : voir
 * `MediaController::limiter()`.
 */
final readonly class QuotasDesMedias implements DeclarationDeQuotasInterface
{
    public function __construct(
        #[Autowire(param: 'media.quota.televersements')]
        private int $televersements,
    ) {
    }

    public function televersements(): Quota
    {
        return new Quota('media.televersements', 'Médias', 'media_televersement', $this->televersements, '1 hour', 'par heure', administrateurExempte: true);
    }

    public function quotas(): array
    {
        return [$this->televersements()];
    }
}
