<?php

declare(strict_types=1);

namespace App\Module\Messagerie\Service;

use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Service\NotificationService;
use App\Module\Messagerie\Entity\Fil;

/**
 * Les notifications de la messagerie — dans la cloche, et sur le téléphone par
 * {@see NotificationService}, qui ne lève jamais : un message est écrit, qu'on ait pu
 * prévenir ou non.
 *
 * **Aucun texte de message dans une notification.** Une notification est persistée et se
 * relit hors du fil ; le message, lui, peut nommer des vivants, décrire un écran, citer
 * un courriel. Le membre lit le sujet qu'il a lui-même écrit ; les administrateurs, le
 * nom du compte et la nature du fil — de quoi trier, pas de quoi lire à sa place.
 */
final readonly class AvisDeMessage
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function auxAdministrateurs(Fil $fil, bool $nouveau): void
    {
        $nature = mb_strtolower($fil->getNature()->libelle());

        $this->notifications->notifierLesAdministrateurs(
            NotificationKind::MessageDUnMembre,
            $nouveau
                ? \sprintf('%s vous écrit (%s)', $fil->getMembreLibelle(), $nature)
                : \sprintf('%s a répondu (%s)', $fil->getMembreLibelle(), $nature),
            null,
            'administration.messagerie',
            ['id' => $fil->getId()],
            sauf: $fil->getMembreUserId(),
        );
    }

    public function auMembre(Fil $fil): void
    {
        $this->notifications->notifier(
            $fil->getMembreUserId(),
            NotificationKind::MessageDeLAdministration,
            'L’administration vous a répondu',
            \sprintf('« %s »', $fil->getSujet()),
            'messagerie',
            ['id' => $fil->getId()],
        );
    }
}
