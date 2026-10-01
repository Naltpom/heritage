<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Core\Contract\DeclarationDeQuotasInterface;
use App\Module\Core\Contract\Quota;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Les trois quotas de l'assistant, tels que `Core` les compte et les affiche.
 *
 * Les **défauts** restent dans `config/services/assistant.yaml` ; les **identifiants**
 * (`assistant_analyses`, `assistant_questions`, `assistant_rattachements`) sont ceux des
 * trois `RateLimiterFactory` qu'ils remplacent, et ne doivent plus changer : ils nomment
 * les compteurs déjà écrits dans le cache (voir `Core\Contract\Quota`). Les contrôleurs
 * passent l'objet `Quota` lui-même au port, jamais sa clé : aucune chaîne à recopier.
 */
final readonly class QuotasDeLAssistant implements DeclarationDeQuotasInterface
{
    public function __construct(
        #[Autowire(param: 'assistant.quota.analyses')]
        private int $analyses,
        #[Autowire(param: 'assistant.quota.questions')]
        private int $questions,
        #[Autowire(param: 'assistant.quota.rattachements')]
        private int $rattachements,
    ) {
    }

    /** « Proposer avec l'assistant » — par compte, administrateur compris. */
    public function analyses(): Quota
    {
        return new Quota('assistant.analyses', 'Analyses IA', 'assistant_analyses', $this->analyses, '24 hours', 'par 24 h');
    }

    /** « Poser une question » — par compte, administrateur compris. */
    public function questions(): Quota
    {
        return new Quota('assistant.questions', 'Questions IA', 'assistant_questions', $this->questions, '24 hours', 'par 24 h');
    }

    /** Le rattachement assisté — **un** compteur pour toute l'installation (`rattachements`). */
    public function rattachements(): Quota
    {
        return new Quota('assistant.rattachements', 'Rattachements assistés', 'assistant_rattachements', $this->rattachements, '24 hours', 'par 24 h', compteurGlobal: 'rattachements');
    }

    public function quotas(): array
    {
        return [$this->analyses(), $this->questions(), $this->rattachements()];
    }
}
