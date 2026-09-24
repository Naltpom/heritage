<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * POINT DE BRANCHEMENT DU MODULE `Question` — la clé et le point d'accès de Gemini.
 *
 * `Question` possède déjà les réglages de l'appel au modèle (`HERITAGE_GEMINI_CLE_API`,
 * `HERITAGE_GEMINI_POINT_DACCES`, ADR 0008) et la discipline qui va avec : la clé ne sort
 * que pour l'en-tête de la requête, jamais dans un message, un journal ou une trace.
 * L'assistant **réutilise ces réglages tels quels** plutôt que de relire les variables
 * d'environnement une seconde fois — deux lectures de la même clé, c'est deux endroits où
 * elle peut fuir, et deux endroits à corriger le jour où elle passe à Vertex AI.
 *
 * Seul le **modèle** diffère (lecture d'actes manuscrits, raisonnement sur plusieurs
 * tours) : il vit dans `Assistant\Service\ReglagesDeLAssistant`, sous sa propre variable.
 *
 * Même discipline que la classe enveloppée : {@see configure()} rend un booléen, jamais la
 * valeur, et {@see cleApi()} n'est appelée qu'à un endroit — l'en-tête HTTP.
 */
interface AccesAuModele
{
    /** Un appel sortant est-il possible ? Clé vide ⇒ `false`, sans bruit. */
    public function configure(): bool;

    /**
     * La clé, pour l'en-tête `x-goog-api-key` et **rien d'autre**.
     *
     * @throws \LogicException si elle est absente — appeler sans avoir vérifié
     *                         {@see configure()} est un défaut de programmation
     */
    public function cleApi(): string;

    /** L'URL de base, sans barre oblique finale. */
    public function pointDacces(): string;
}
