<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Service\NotificationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/notifications` — § 8 du contrat d'API.
 *
 * Ce qui est vérifié ici n'est pas qu'une table se lit, c'est que **la promesse du
 * produit tient** : un refus motivé parvient à son auteur, et à lui seul.
 */
final class NotificationsTest extends CoreWebTestCase
{
    public function testUnRefusMotiveParvientASonAuteurAvecSonMessage(): void
    {
        $auteur = $this->creerUtilisateur('cobalt@example.net');
        $notifications = $this->service();

        // Ce que fera l'adaptateur de `Proposal` : composer le texte et le déposer.
        $notifications->notifier(
            (int) $auteur->getId(),
            NotificationKind::DemandeRefusee,
            'Votre demande a été refusée.',
            'La date de naissance proposée ne correspond pas à l’acte cité.',
            'demande.detail',
            ['id' => 14],
        );

        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/notifications');
        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(1, $charge['total']);

        $notification = $charge['elements'][0];
        self::assertSame('demande_refusee', $notification['kind']);
        // **Le motif parvient tel quel** : le tronquer ou le résumer viderait de son sens
        // la règle « le message est obligatoire au refus » (§ 6.3 du dossier).
        self::assertSame('La date de naissance proposée ne correspond pas à l’acte cité.', $notification['body']);
        self::assertSame('demande.detail', $notification['linkRoute']);
        self::assertSame(['id' => 14], $notification['linkParams']);
        self::assertNull($notification['readAt']);
    }

    public function testUneNotificationNAppartientQuASonDestinataire(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->creerUtilisateur('pyrite@example.net');

        $notification = $this->service()->notifier(
            (int) $cobalt->getId(),
            NotificationKind::DemandeValidee,
            'Votre demande a été validée.',
        );
        self::assertNotNull($notification);

        $this->connexion('pyrite@example.net');

        $this->requete('GET', '/api/notifications');
        self::assertSame(0, $this->charge()['total'], 'Pyrite ne voit pas les notifications de Cobalt.');

        // `404` et non `403` : l'existence même de la notification dirait qu'il se passe
        // quelque chose sur une fiche que Pyrite ne voit peut-être pas (§ 15.3).
        $this->requete('POST', \sprintf('/api/notifications/%d/lue', $notification->getId()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('introuvable', $this->charge()['erreur']['code']);
    }

    public function testLeCompteurEtLaMarqueDeLecture(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $service = $this->service();

        $premiere = $service->notifier((int) $cobalt->getId(), NotificationKind::DemandeSoumise, 'Une.');
        $service->notifier((int) $cobalt->getId(), NotificationKind::DemandeValidee, 'Deux.');
        $service->notifier((int) $cobalt->getId(), NotificationKind::DemandeRefusee, 'Trois.');
        self::assertNotNull($premiere);

        $this->connexion('cobalt@example.net');

        $this->requete('GET', '/api/notifications/compteur');
        self::assertSame(['nonLues' => 3], $this->charge());

        $this->requete('POST', \sprintf('/api/notifications/%d/lue', $premiere->getId()));
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->charge()['readAt']);

        $this->requete('GET', '/api/notifications/compteur');
        self::assertSame(['nonLues' => 2], $this->charge());

        $this->requete('GET', '/api/notifications?nonLues=1');
        self::assertSame(2, $this->charge()['total']);

        $this->requete('POST', '/api/notifications/lues');
        self::assertSame(['marquees' => 2], $this->charge());

        $this->requete('GET', '/api/notifications/compteur');
        self::assertSame(['nonLues' => 0], $this->charge());
    }

    /**
     * Le cas que le § 12.1 nomme explicitement : **un compte en attente de validation
     * reçoit la notification de son activation**. Lui fermer cette route reviendrait à ne
     * jamais lui apprendre qu'il est activé — rien ne le lui pousse.
     */
    public function testUnCompteEnAttenteLitSesNotifications(): void
    {
        $enAttente = $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);

        $this->service()->notifier(
            (int) $enAttente->getId(),
            NotificationKind::CompteActive,
            'Votre compte est activé.',
        );

        $this->connexion('cousin@example.net');
        $this->requete('GET', '/api/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->charge()['total']);
    }

    public function testLesAdministrateursSeNotifientSansQueLAppelantSacheQuiIlsSont(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->creerUtilisateur('cobalt@example.net');
        $this->creerUtilisateur('ancien@example.net', roles: ['ROLE_ADMIN'], statut: UserStatus::Suspendu);

        $remises = $this->service()->notifierLesAdministrateurs(
            NotificationKind::DemandeSoumise,
            'Une demande attend votre validation.',
        );

        // Un administrateur suspendu ne compte pas : il ne s'authentifie pas.
        self::assertSame(1, $remises);

        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/notifications');
        self::assertSame(1, $this->charge()['total']);

        $this->requete('POST', '/api/deconnexion');
        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/notifications');
        self::assertSame(0, $this->charge()['total'], 'Un membre n’est pas notifié des dépôts.');
    }

    public function testLAdministrateurAuteurNEstPasNotifieDeSaPropreDemande(): void
    {
        $auteur = $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->creerUtilisateur('opale@example.net', roles: ['ROLE_ADMIN']);

        $remises = $this->service()->notifierLesAdministrateurs(
            NotificationKind::DemandeSoumise,
            'Natron propose une modification.',
            sauf: (int) $auteur->getId(),
        );

        self::assertSame(1, $remises, 'Seul l’autre administrateur est prévenu.');

        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/notifications');
        self::assertSame(0, $this->charge()['total'], 'L’auteur n’est pas notifié de ce qu’il vient de déposer.');
    }

    /**
     * La propriété qui permet à l'adaptateur de `Proposal` d'être une simple délégation :
     * une notification est produite **après** le `commit`, et ne doit jamais faire échouer
     * l'écriture qui l'a causée.
     */
    public function testNotifierNeLevePasQuandLeDepotEchoue(): void
    {
        $this->creerUtilisateur('cobalt@example.net');

        // Une table absente reproduit fidèlement la panne d'infrastructure.
        $this->em()->getConnection()->executeStatement('DROP TABLE core_notification');

        $resultat = $this->service()->notifier(1, NotificationKind::DemandeRefusee, 'Un refus perdu.');

        self::assertNull($resultat, 'L’échec est signalé par null, pas par une exception.');
    }

    public function testLaListeEstPagineeSelonLEnveloppeDuContrat(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $service = $this->service();

        for ($i = 1; $i <= 30; ++$i) {
            $service->notifier((int) $cobalt->getId(), NotificationKind::DemandeSoumise, \sprintf('Numéro %d.', $i));
        }

        $this->connexion('cobalt@example.net');

        $this->requete('GET', '/api/notifications?page=2&parPage=25');
        $charge = $this->charge();

        self::assertSame(30, $charge['total']);
        self::assertSame(2, $charge['page']);
        self::assertSame(25, $charge['parPage']);
        self::assertCount(5, $charge['elements']);

        // « Au-delà, la valeur est ramenée à 100 sans erreur » (§ 1.5).
        $this->requete('GET', '/api/notifications?parPage=5000');
        self::assertSame(100, $this->charge()['parPage']);
    }

    private function service(): NotificationService
    {
        $service = self::getContainer()->get(NotificationService::class);
        \assert($service instanceof NotificationService);

        return $service;
    }
}
