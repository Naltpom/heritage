<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Service\NotificationService;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Service\Port\NotificateurParNotifications;

/**
 * L'administrateur qui valide ou refuse **sa propre** demande n'en est pas notifié : il
 * vient de cliquer (2026-10-07). La décision d'un autre, elle, part toujours.
 */
final class NotificationDeSaPropreDecisionTest extends BaseDeDemandes
{
    public function testValiderOuRefuserSaPropreDemandeNeNotifiePas(): void
    {
        $notificateur = $this->notificateur();

        $notificateur->demandeValidee($this->demandeDecidee(self::ADMIN, self::ADMIN, RequestStatus::Validee), false);
        $notificateur->demandeRefusee($this->demandeDecidee(self::ADMIN, self::ADMIN, RequestStatus::Refusee), 'Doublon.');

        self::assertSame(0, $this->notifications());
    }

    public function testLaDecisionDUnAutreNotifieToujours(): void
    {
        $notificateur = $this->notificateur();

        $notificateur->demandeValidee($this->demandeDecidee(self::OPALE, self::ADMIN, RequestStatus::Validee), false);
        $notificateur->demandeRefusee($this->demandeDecidee(self::OPALE, self::ADMIN, RequestStatus::Refusee), 'Doublon.');

        self::assertSame(2, $this->notifications());
    }

    private function notificateur(): NotificateurParNotifications
    {
        $service = self::getContainer()->get(NotificationService::class);
        \assert($service instanceof NotificationService);

        return new NotificateurParNotifications($service);
    }

    private function demandeDecidee(int $auteur, int $decideur, RequestStatus $statut): ChangeRequest
    {
        return (new ChangeRequest(RequestKind::ModificationPersonne, $auteur, 'Compte '.$auteur))
            ->decider($statut, $decideur, 'Compte '.$decideur);
    }

    private function notifications(): int
    {
        return \count($this->em->getRepository(Notification::class)->findAll());
    }
}
