<?php

declare(strict_types=1);

namespace App\Module\Media\Enum;

/**
 * L'état d'un média dans le circuit de validation (§ 6.3 du modèle de données).
 *
 * Un média déposé par un membre suit le **même circuit** que les autres modifications
 * (§ 6.5 du dossier de mission) : il n'y a pas de seconde file d'attente.
 */
enum MediaStatus: string
{
    /** Déposé par un membre, pas encore validé. Visible de son seul déposant et des administrateurs. */
    case EnAttente = 'en_attente';
    /** Validé, visible selon le cercle. */
    case Actif = 'actif';
    /** Ancien portrait, conservé. Consultable dans l'historique de la fiche. */
    case Remplace = 'remplace';
    /**
     * Refusé, avec motif dans la demande associée. Le fichier est **conservé sur disque**
     * jusqu'à purge explicite : un refus n'est pas une destruction.
     */
    case Refuse = 'refuse';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente de validation',
            self::Actif => 'Actif',
            self::Remplace => 'Remplacé',
            self::Refuse => 'Refusé',
        };
    }
}
