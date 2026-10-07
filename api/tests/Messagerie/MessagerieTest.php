<?php

declare(strict_types=1);

namespace App\Tests\Messagerie;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\UserStatus;
use App\Tests\Core\CoreWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Écrire à l'administration » : qui lit un fil, qui y répond, et ce que chacun a vu.
 *
 * Aucun arbre n'est semé : la messagerie n'en lit rien, et c'est ce qui permet de l'ouvrir
 * à un compte en attente.
 */
final class MessagerieTest extends CoreWebTestCase
{
    private const string OPALE = 'opale@example.invalid';
    private const string JASPE = 'jaspe@example.invalid';
    private const string ADMIN = 'admin@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerUtilisateur(self::OPALE, nomAffiche: 'Opale Sureau');
        $this->creerUtilisateur(self::JASPE, nomAffiche: 'Jaspe Sureau');
        $this->creerUtilisateur(self::ADMIN, roles: ['ROLE_ADMIN'], nomAffiche: 'Nathan');
    }

    public function testUnMembreOuvreUnFilEtLAdministrationEstPrevenueSansLeTexte(): void
    {
        $this->connexion(self::OPALE);
        $this->requete('POST', '/api/messagerie/fils', [
            'nature' => 'bug',
            'texte' => "La carte ne s'affiche pas sur mon téléphone.\nJ'ai un iPhone 12.",
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $fil = $this->charge();
        self::assertSame('bug', $fil['nature']);
        // Pas de sujet saisi : la première ligne du message en tient lieu.
        self::assertSame("La carte ne s'affiche pas sur mon téléphone.", $fil['sujet']);
        self::assertSame('ouvert', $fil['statut']);
        self::assertCount(1, $fil['messages']);
        self::assertTrue($fil['messages'][0]['estMoi']);
        self::assertNull($fil['messages'][0]['vuLe'], 'Envoyé, pas encore vu.');

        $notifications = $this->notificationsDe(self::ADMIN);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationKind::MessageDUnMembre, $notifications[0]->getKind());
        self::assertStringNotContainsString('iPhone', $notifications[0]->getTitle().$notifications[0]->getBody());
        self::assertCount(0, $this->notificationsDe(self::OPALE), 'On ne se notifie pas soi-même.');
    }

    public function testUnFilNeSeMontreQuASonMembreEtAuxAdministrateurs(): void
    {
        $id = $this->ouvrirUnFil(self::OPALE);

        $this->connexion(self::JASPE);
        $this->requete('GET', "/api/messagerie/fils/{$id}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->requete('POST', "/api/messagerie/fils/{$id}/messages", ['texte' => 'Moi aussi !']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->requete('GET', '/api/messagerie/fils');
        self::assertSame(0, $this->charge()['total']);

        $this->requete('GET', '/api/administration/messagerie/fils');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testLAccuseDeLectureSePoseALOuvertureParLAutreCote(): void
    {
        $id = $this->ouvrirUnFil(self::OPALE);

        // L'administration voit un non-lu, puis l'ouvre : le message du membre est vu.
        $this->connexion(self::ADMIN);
        $this->requete('GET', '/api/administration/messagerie/fils');
        $liste = $this->charge();
        self::assertSame(1, $liste['nonLus']);
        self::assertSame(1, $liste['elements'][0]['nonLus']);

        $this->requete('GET', "/api/administration/messagerie/fils/{$id}");
        $fil = $this->charge();
        self::assertNotNull($fil['messages'][0]['vuLe']);
        self::assertSame('Nathan', $fil['messages'][0]['vuPar']);
        self::assertSame(0, $fil['nonLus']);

        $this->requete('POST', "/api/administration/messagerie/fils/{$id}/messages", ['texte' => 'Pouvez-vous mettre à jour Safari ?']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $fil = $this->charge();
        self::assertTrue($fil['messages'][1]['deLAdministration']);
        self::assertNull($fil['messages'][1]['vuLe']);

        $notifications = $this->notificationsDe(self::OPALE);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationKind::MessageDeLAdministration, $notifications[0]->getKind());
        self::assertSame('messagerie', $notifications[0]->getLinkRoute());

        // Le membre voit la réponse non lue dans sa liste, puis l'ouvre.
        $this->connexion(self::OPALE);
        $this->requete('GET', '/api/messagerie/fils');
        self::assertSame(1, $this->charge()['elements'][0]['nonLus']);

        $this->requete('GET', "/api/messagerie/fils/{$id}");
        $fil = $this->charge();
        self::assertNotNull($fil['messages'][1]['vuLe']);
        self::assertSame(0, $fil['nonLus']);

        // Le premier regard compte : une seconde ouverture ne déplace pas l'heure.
        $vuLe = $fil['messages'][1]['vuLe'];
        $this->requete('GET', "/api/messagerie/fils/{$id}");
        self::assertSame($vuLe, $this->charge()['messages'][1]['vuLe']);
    }

    public function testDesMessagesDAffileeNeSonnentQuUneFoisTantQueRienNEstLu(): void
    {
        $id = $this->ouvrirUnFil(self::OPALE);
        $this->requete('POST', "/api/messagerie/fils/{$id}/messages", ['texte' => 'Et aussi la chronologie.']);
        $this->requete('POST', "/api/messagerie/fils/{$id}/messages", ['texte' => 'Pardon, c’est réglé pour la chronologie.']);

        self::assertCount(1, $this->notificationsDe(self::ADMIN));

        // Une fois lu, le message suivant sonne de nouveau.
        $this->connexion(self::ADMIN);
        $this->requete('GET', "/api/administration/messagerie/fils/{$id}");
        $this->connexion(self::OPALE);
        $this->requete('POST', "/api/messagerie/fils/{$id}/messages", ['texte' => 'Une dernière chose.']);

        self::assertCount(2, $this->notificationsDe(self::ADMIN));
    }

    public function testUnFilRegleSeRouvreQuandLeMembreEcrit(): void
    {
        $id = $this->ouvrirUnFil(self::OPALE);

        $this->connexion(self::ADMIN);
        $this->requete('PUT', "/api/administration/messagerie/fils/{$id}/statut", ['statut' => 'clos']);
        self::assertSame('clos', $this->charge()['statut']);

        $this->requete('GET', '/api/administration/messagerie/fils?statut=ouvert');
        self::assertSame(0, $this->charge()['total']);

        $this->connexion(self::OPALE);
        $this->requete('POST', "/api/messagerie/fils/{$id}/messages", ['texte' => 'Le problème est revenu.']);
        self::assertSame('ouvert', $this->charge()['statut']);
    }

    public function testUnCompteEnAttentePeutEcrireALAdministration(): void
    {
        $this->creerUtilisateur('attente@example.invalid', statut: UserStatus::EnAttente);
        $this->connexion('attente@example.invalid');

        $this->requete('POST', '/api/messagerie/fils', [
            'nature' => 'question',
            'sujet' => 'Mon inscription',
            'texte' => 'Mon inscription est-elle bien arrivée ?',
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        self::assertSame('Mon inscription', $this->charge()['sujet']);
        // Un compte sans nom affiché est désigné par son courriel.
        self::assertSame('attente@example.invalid', $this->charge()['membre']['libelle']);
    }

    public function testUnCorpsInvalideNommeChaqueChampFautif(): void
    {
        $this->connexion(self::OPALE);
        $this->requete('POST', '/api/messagerie/fils', ['nature' => 'plainte', 'texte' => '   ', 'sujet' => str_repeat('x', 161)]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $champs = $this->charge()['erreur']['champs'];
        self::assertIsArray($champs);
        self::assertArrayHasKey('nature', $champs);
        self::assertArrayHasKey('texte', $champs);
        self::assertArrayHasKey('sujet', $champs);
    }

    private function ouvrirUnFil(string $courriel): int
    {
        $this->connexion($courriel);
        $this->requete('POST', '/api/messagerie/fils', ['nature' => 'question', 'texte' => 'Comment ajoute-t-on une photo ?']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $id = $this->charge()['id'];
        self::assertIsInt($id);

        return $id;
    }

    /** @return list<Notification> */
    private function notificationsDe(string $courriel): array
    {
        $em = $this->em();
        $em->clear();
        $utilisateur = $em->getRepository(User::class)->findOneBy(['email' => $courriel]);
        self::assertNotNull($utilisateur);

        /** @var list<Notification> */
        return $em->getRepository(Notification::class)->findBy(['recipientUserId' => $utilisateur->getId()], ['id' => 'ASC']);
    }
}
