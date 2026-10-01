<?php

declare(strict_types=1);

namespace App\Module\Demarche\Enum;

/**
 * Où en est la démarche.
 *
 * Le statut est **posé** par celui qui suit la démarche, pas déduit des échanges : une
 * réponse de mairie peut dire « nous transmettons au service compétent » sans que rien ne
 * soit reçu, et seule une personne qui l'a lue sait si la pièce est arrivée.
 *
 * ## Deux attentes différentes
 *
 * Trois statuts attendent encore **la pièce** ({@see enAttente()}) : la page « en
 * attente » les rassemble. Mais deux seulement attendent **une réponse**
 * ({@see attendUneReponse()}) :
 *
 * - `envoyee` et `relancee` — la mairie n'a rien dit. Passé un mois, il faut relancer :
 *   une demande sans réponse est le plus souvent une demande perdue.
 * - `expediee` (révision du 2026-10-01) — la mairie **a répondu** : « traité, l'acte part
 *   par la poste ». On attend le facteur, pas la mairie. La relancer serait lui réécrire
 *   pour une demande qu'elle a déjà traitée ; la démarche n'est donc jamais « à relancer ».
 */
enum StatutDeDemarche: string
{
    case Envoyee = 'envoyee';
    case Relancee = 'relancee';
    case Expediee = 'expediee';
    case Recue = 'recue';
    case Refusee = 'refusee';
    case SansSuite = 'sans_suite';

    public function libelle(): string
    {
        return match ($this) {
            self::Envoyee => 'Envoyée, en attente',
            self::Relancee => 'Relancée, en attente',
            self::Expediee => 'Expédiée par courrier, en attente de réception',
            self::Recue => 'Reçue',
            self::Refusee => 'Refusée',
            self::SansSuite => 'Abandonnée',
        };
    }

    /** La pièce n'est pas encore là : la démarche reste ouverte. */
    public function enAttente(): bool
    {
        return self::Envoyee === $this || self::Relancee === $this || self::Expediee === $this;
    }

    /**
     * Le service n'a pas encore répondu : c'est **la seule** attente qui se relance. Une
     * démarche expédiée attend encore, mais plus la mairie.
     */
    public function attendUneReponse(): bool
    {
        return self::Envoyee === $this || self::Relancee === $this;
    }
}
