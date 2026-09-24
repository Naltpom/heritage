<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Un appel `generateContent`, et rien d'autre.
 *
 * La boucle d'agent ({@see AgentDeLAssistant}) ne connaît que cette interface : c'est ce
 * qui permet de la dérouler en test avec un modèle **scripté** — il cherche, puis lit une
 * fiche, puis propose — sans réseau et sans clé. En production, {@see TransportGemini}.
 */
interface TransportDuModele
{
    /**
     * @param array<string, mixed> $charge le corps JSON de `generateContent`
     * @param float                $delai  secondes restantes pour CET appel
     *
     * @return array<string, mixed> la réponse décodée
     *
     * @throws \Throwable sur toute panne de transport ou réponse non JSON — l'appelant
     *                    la traduit en `502 assistant_erreur`
     */
    public function generer(string $modele, array $charge, float $delai): array;
}
