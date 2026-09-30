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
 * Deux statuts attendent une réponse, et ce sont eux que la page « en attente » rassemble.
 */
enum StatutDeDemarche: string
{
    case Envoyee = 'envoyee';
    case Relancee = 'relancee';
    case Recue = 'recue';
    case Refusee = 'refusee';
    case SansSuite = 'sans_suite';

    public function libelle(): string
    {
        return match ($this) {
            self::Envoyee => 'Envoyée, en attente',
            self::Relancee => 'Relancée, en attente',
            self::Recue => 'Reçue',
            self::Refusee => 'Refusée',
            self::SansSuite => 'Abandonnée',
        };
    }

    public function enAttente(): bool
    {
        return self::Envoyee === $this || self::Relancee === $this;
    }
}
