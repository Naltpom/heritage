<?php

declare(strict_types=1);

namespace App\Module\Proposal\Enum;

/**
 * L'état d'une demande (§ 5.7 du modèle de données).
 *
 * **`Caduque` n'est pas `Refusee`, et c'est le point à ne pas fondre.** Un refus est une
 * décision : quelqu'un a lu et dit non, avec un motif obligatoire. Une caducité est un
 * constat : le monde a bougé sous la demande, **personne ne l'a refusée**. Deux
 * notifications, deux écrans — et un bouton « refaire cette demande », sans lequel une
 * caducité est une contribution perdue, et un membre dont la première contribution se
 * perd n'en dépose pas de seconde (§ 9.6, ADR 0004).
 */
enum RequestStatus: string
{
    /** En cours de saisie. Visible de son seul auteur, pas dans la file d'attente. */
    case Brouillon = 'brouillon';
    /** Soumise : dans la file de l'administrateur, et appliquée au calque du seul auteur. */
    case EnAttente = 'en_attente';
    case Validee = 'validee';
    /** Refusée avec motif obligatoire. **Reste consultable par son auteur.** */
    case Refusee = 'refusee';
    /** Retirée par son auteur avant décision. */
    case Annulee = 'annulee';
    /**
     * À la validation, l'`old_value` d'un op ne correspondait plus à la base, ou la
     * cible avait disparu. **Rien n'a été appliqué.** Constatée à la validation, jamais
     * par une tâche de fond : à ce volume, un balayage périodique serait de la mécanique
     * pour rien.
     */
    case Caduque = 'caduque';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::EnAttente => 'En attente de validation',
            self::Validee => 'Validée',
            self::Refusee => 'Refusée',
            self::Annulee => 'Annulée',
            self::Caduque => 'Caduque',
        };
    }

    /** Une demande tranchée ne bouge plus ; seul son historique se consulte. */
    public function estDefinitif(): bool
    {
        return \in_array($this, [self::Validee, self::Refusee, self::Annulee, self::Caduque], true);
    }
}
