<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Service\NotificationService;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Service\Port\NotificateurParNotifications;

/**
 * Les textes des deux notifications de la complétion (ADR 0017), **tels qu'ils partent en
 * base** — par l'implémentation réelle, pas par le double qui enregistre les appels.
 *
 * Trois règles s'y lisent, et aucune ne se devine :
 *
 * - **le numéro de la demande, jamais le nom de la personne** — le titre se relit des mois
 *   plus tard, et la personne est souvent vivante ;
 * - **les noms des champs, jamais leurs valeurs** ;
 * - **jamais le nom du membre qui a fourni un complément** à l'auteur de l'origine : pour
 *   lui, c'est l'administration qui a complété sa demande.
 *
 * Les tables `core_user` et `core_notification` ne sont pas vidées par le socle : ce
 * fichier vide ce qu'il y écrit, pour ne pas fausser les comptes d'un voisin.
 */
final class NotificationsDeLaCompletionTest extends BaseDeDemandes
{
    private int $administrateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viderLesTables();

        $admin = new User('natron@example.net', 'Natron Romarin');
        $admin->setRoles(['ROLE_ADMIN'])->setStatus(UserStatus::Actif)->setPassword('x');
        $this->em->persist($admin);
        $this->em->flush();

        $this->administrateur = (int) $admin->getId();
        self::assertNotContains($this->administrateur, [self::OPALE, self::TUF], 'Le compte administrateur doit être distinct des deux auteurs du scénario.');
    }

    protected function tearDown(): void
    {
        $this->viderLesTables();

        parent::tearDown();
    }

    public function testLAuteurApprendQueLAdministrationACompleteSaDemandeSansAucuneValeur(): void
    {
        $demande = $this->demande(self::OPALE, 12);

        $this->notificateur()->demandeCompletee($demande, true, ['Naissance', 'Note'], 1);

        $notifications = $this->notifications();
        self::assertCount(1, $notifications);
        $notification = $notifications[0];
        self::assertSame(self::OPALE, $notification->getRecipientUserId());
        self::assertSame(NotificationKind::DemandeCompletee, $notification->getKind());
        self::assertSame('L\'administration a complété votre demande n° 12.', $notification->getTitle());
        self::assertSame('Ce qui a changé : Naissance, Note ; 1 lien ajouté.', $notification->getBody());
        self::assertSame('demande.detail', $notification->getLinkRoute());
        self::assertSame(['id' => 12], $notification->getLinkParams());
    }

    public function testLesAdministrateursApprennentQueLAuteurACompleteSaDemande(): void
    {
        $this->notificateur()->demandeCompletee($this->demande(self::OPALE, 12), false, [], 2);

        $notifications = $this->notifications();
        self::assertCount(1, $notifications);
        self::assertSame($this->administrateur, $notifications[0]->getRecipientUserId());
        self::assertSame('Compte 11 a complété sa demande n° 12.', $notifications[0]->getTitle());
        self::assertSame('Ce qui a changé : 2 liens ajoutés.', $notifications[0]->getBody());
    }

    /** Ce n'est pas un refus, et ça ne part pas comme tel. */
    public function testLAuteurDuComplementApprendLaFusionEtNonUnRefus(): void
    {
        $complement = $this->demande(self::TUF, 21)->decider(RequestStatus::Refusee, $this->administrateur, 'Natron Romarin', 'Fusionné dans la demande n° 12.');

        $this->notificateur()->complementFusionne($complement, $this->demande(self::OPALE, 12));

        $notifications = $this->notifications();
        self::assertCount(1, $notifications);
        self::assertSame(self::TUF, $notifications[0]->getRecipientUserId());
        self::assertSame(NotificationKind::ComplementFusionne, $notifications[0]->getKind());
        self::assertSame('Votre complément (demande n° 21) a été fusionné dans la demande n° 12.', $notifications[0]->getTitle());
        self::assertStringNotContainsString('refus', (string) $notifications[0]->getBody());
    }

    /** L'administrateur qui fusionne son propre complément vient de cliquer. */
    public function testLAdministrateurQuiFusionneSonPropreComplementNEstPasPrevenu(): void
    {
        $complement = $this->demande($this->administrateur, 21)->decider(RequestStatus::Refusee, $this->administrateur, 'Natron Romarin', 'Fusionné.');

        $this->notificateur()->complementFusionne($complement, $this->demande(self::OPALE, 12));

        self::assertSame([], $this->notifications());
    }

    /** Une soumission de complément se dit comme telle : l'administrateur la fusionnera. */
    public function testLaSoumissionDUnComplementLeDitAuxAdministrateurs(): void
    {
        $origine = $this->demande(self::OPALE, 12);
        $complement = $this->demande(self::TUF, 21)->setComplementDe(new DraftPerson($origine, '#p1'));

        $this->notificateur()->demandeSoumise($complement);

        $notifications = $this->notifications();
        self::assertCount(1, $notifications);
        self::assertSame('Compte 12 propose un complément à la demande n° 12.', $notifications[0]->getTitle());
    }

    private function notificateur(): NotificateurParNotifications
    {
        $service = self::getContainer()->get(NotificationService::class);
        \assert($service instanceof NotificationService);

        return new NotificateurParNotifications($service);
    }

    /** Une demande non persistée, au numéro choisi : seul ce que la notification en lit compte. */
    private function demande(int $auteur, int $id): ChangeRequest
    {
        $demande = new ChangeRequest(RequestKind::CreationPersonne, $auteur, 'Compte '.$auteur);
        (new \ReflectionProperty(ChangeRequest::class, 'id'))->setValue($demande, $id);

        return $demande;
    }

    /** @return list<Notification> */
    private function notifications(): array
    {
        /** @var list<Notification> $lignes */
        $lignes = $this->em->getRepository(Notification::class)->findBy([], ['id' => 'ASC']);

        return $lignes;
    }

    private function viderLesTables(): void
    {
        $connexion = $this->em->getConnection();
        $connexion->executeStatement('DELETE FROM core_notification');
        $connexion->executeStatement("DELETE FROM core_user WHERE email = 'natron@example.net'");
    }
}
