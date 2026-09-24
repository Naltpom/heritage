<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Assistant\Contract\AccesAuModele;

/**
 * Les réglages de Gemini de ce module, prêtés à l'assistant de proposition.
 *
 * Une enveloppe de trois lignes, et c'est tout son intérêt : **la clé reste lue à un seul
 * endroit** ({@see ReglagesDeGemini}), avec la seule discipline qui compte — un booléen
 * pour savoir, la valeur uniquement pour l'en-tête. L'assistant n'a pas le droit de citer
 * `Question\Service\…` (règle 3 de l'ADR 0002) ; c'est donc `Question` qui implémente le
 * contrat que l'assistant déclare, dans le sens licite.
 *
 * Le **modèle** n'est pas prêté : celui des questions est le plus léger de la famille,
 * choisi pour reformuler une phrase ; celui de l'assistant lit des actes manuscrits et
 * raisonne sur plusieurs tours. Il a sa propre variable, `HERITAGE_GEMINI_MODELE_ASSISTANT`.
 */
final readonly class AccesAuModelePourLAssistant implements AccesAuModele
{
    public function __construct(private ReglagesDeGemini $reglages)
    {
    }

    public function configure(): bool
    {
        return $this->reglages->configure();
    }

    public function cleApi(): string
    {
        return $this->reglages->cleApi();
    }

    public function pointDacces(): string
    {
        return $this->reglages->pointDacces();
    }
}
