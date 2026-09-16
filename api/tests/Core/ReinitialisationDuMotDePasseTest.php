<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Service\UserAdminService;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/administration/utilisateurs/{id}/mot-de-passe`.
 *
 * Cette route existe parce que son absence rendait le journal aveugle : tant qu'aucune
 * route ne savait réinitialiser un mot de passe, toute réinitialisation passait par du
 * SQL — hors audit, sans toucher `updated_at` — et deux écrasements ont ainsi été
 * invisibles. *Un journal ne peut pas enregistrer ce que l'application ne sait pas faire.*
 *
 * Les quatre garanties testées ici sont celles qui se perdent au premier remaniement :
 * la trace, l'horodatage, l'absence du secret partout sauf dans la réponse, et la
 * **fermeture des sessions déjà ouvertes**.
 */
final class ReinitialisationDuMotDePasseTest extends CoreWebTestCase
{
    private int $administrateurId = 0;

    public function testElleRendUnMotDePasseUtilisableUneSeuleFois(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/utilisateurs/'.$cobalt->getId().'/mot-de-passe');

        self::assertResponseIsSuccessful();
        $charge = $this->charge();
        self::assertTrue($charge['motDePasseReinitialise']);
        $nouveau = $charge['motDePasse'];
        self::assertIsString($nouveau);
        self::assertGreaterThanOrEqual(20, mb_strlen($nouveau), 'Un mot de passe que personne n’a choisi doit être long.');

        // Il fonctionne, et l'ancien ne fonctionne plus.
        $this->requete('POST', '/api/deconnexion');
        $this->connexion('cobalt@example.net', 'motdepasse-de-test');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->connexion('cobalt@example.net', $nouveau);
        self::assertResponseIsSuccessful();
    }

    /**
     * La garantie **qu'on ne voit qu'en la cherchant**.
     *
     * Sans elle, l'ancien détenteur reste connecté avec un mot de passe qui n'existe
     * plus — et une réinitialisation faite en urgence, parce qu'un compte est compromis,
     * ne sert alors à rien.
     */
    public function testLesSessionsDejaOuvertesTombent(): void
    {
        $administrateurId = (int) $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN'])->getId();
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->administrateurId = $administrateurId;

        // Cobalt est connectée, et le reste : c'est le client de ce test.
        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/session');
        self::assertResponseIsSuccessful();

        // L'administrateur réinitialise, hors de la session de Cobalt. On passe par le
        // service et non par une seconde requête HTTP : `WebTestCase` n'autorise qu'un
        // client par test, et ce qu'on vérifie ici n'est pas la route — elle a son propre
        // cas — mais **l'effet sur une session ouverte ailleurs**.
        $service = self::getContainer()->get(UserAdminService::class);
        self::assertInstanceOf(UserAdminService::class, $service);
        $administrateur = $this->em()->find($cobalt::class, $this->administrateurId);
        self::assertNotNull($administrateur);
        $service->reinitialiserLeMotDePasse((int) $cobalt->getId(), $administrateur);

        // La session de Cobalt ne vaut plus rien, sans qu'elle ait rien fait.
        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
            'Une session ouverte doit tomber quand le mot de passe du compte est réinitialisé.',
        );
    }

    public function testElleEstJournaliseeAvecSonAuteurEtSaCible(): void
    {
        $administrateur = $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/utilisateurs/'.$cobalt->getId().'/mot-de-passe');
        self::assertResponseIsSuccessful();
        $nouveau = $this->charge()['motDePasse'];

        $trace = null;
        foreach ($this->journal() as $ligne) {
            if (AuditAction::MotDePasseReinitialise === $ligne->getAction()) {
                $trace = $ligne;
            }
        }

        self::assertInstanceOf(AuditLog::class, $trace, 'Une réinitialisation est une décision d’administration.');
        self::assertSame('natron@example.net', $trace->getActorEmail());
        self::assertSame($administrateur->getId(), $trace->getActorUserId());
        self::assertSame((string) $cobalt->getId(), $trace->getSubjectId());
        self::assertSame('cobalt@example.net', $trace->getSubjectLabel());
        self::assertSame(['soiMeme' => false], $trace->getChanges());

        // **Le secret n'est nulle part dans le journal**, ni en clair ni en empreinte.
        foreach ($this->journal() as $ligne) {
            $json = json_encode($ligne->getChanges(), \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($nouveau, $json);
            self::assertStringNotContainsString('$2y$', $json, 'Aucune empreinte bcrypt ne doit figurer au journal.');
        }
    }

    /** Une réinitialisation de son propre compte est licite, mais elle se lit d'un coup d'œil. */
    public function testUneReinitialisationDeSonPropreCompteSeVoitDansLeJournal(): void
    {
        $administrateur = $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/utilisateurs/'.$administrateur->getId().'/mot-de-passe');
        self::assertResponseIsSuccessful();

        foreach ($this->journal() as $ligne) {
            if (AuditAction::MotDePasseReinitialise === $ligne->getAction()) {
                self::assertSame(['soiMeme' => true], $ligne->getChanges());

                return;
            }
        }

        self::fail('La réinitialisation n’a pas été journalisée.');
    }

    /**
     * `updated_at` **bouge**, et le test le prouve au lieu de le supposer.
     *
     * On antidate volontairement la colonne : sans cela, la création et la
     * réinitialisation tombent dans la même seconde — SQLite ne garde pas les
     * microsecondes — et l'assertion passerait même si `toucher()` ne faisait rien. Un
     * test qui ne peut pas échouer ne prouve rien, et c'est précisément le défaut qu'on
     * corrige ici : la colonne était restée à l'heure de création sur les deux
     * écrasements invisibles.
     */
    public function testElleToucheUpdatedAtPourQuUnEcrasementSeVoie(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->em()->getConnection()->executeStatement(
            "UPDATE core_user SET updated_at = '2000-01-01 00:00:00' WHERE id = ?",
            [$cobalt->getId()],
        );

        $this->connexion('natron@example.net');
        $this->requete('POST', '/api/administration/utilisateurs/'.$cobalt->getId().'/mot-de-passe');
        self::assertResponseIsSuccessful();

        $stocke = $this->em()->getConnection()->fetchOne(
            'SELECT updated_at FROM core_user WHERE id = ?',
            [$cobalt->getId()],
        );

        self::assertIsString($stocke);
        self::assertStringNotContainsString('2000-01-01', $stocke, 'La réinitialisation doit avoir touché updated_at.');
    }

    public function testElleEstReserveeALAdministrateur(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('cobalt@example.net');
        $this->requete('POST', '/api/administration/utilisateurs/'.$cobalt->getId().'/mot-de-passe');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUnCompteInconnuRend404(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('POST', '/api/administration/utilisateurs/9999/mot-de-passe');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('introuvable', $this->charge()['erreur']['code']);
    }
}
