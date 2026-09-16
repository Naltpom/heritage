<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/administration/utilisateurs` — § 10.1 du contrat d'API.
 */
final class AdministrationDesComptesTest extends CoreWebTestCase
{
    public function testLaListeEstReserveeALAdministrateur(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);

        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/administration/utilisateurs');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->requete('POST', '/api/deconnexion');
        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/administration/utilisateurs');
        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(2, $charge['total']);

        foreach (['id', 'email', 'displayName', 'roles', 'status', 'totpEnabledAt', 'lastLoginAt', 'createdAt', 'membre'] as $cle) {
            self::assertArrayHasKey($cle, $charge['elements'][0], \sprintf('La clé « %s » du § 10.1 manque.', $cle));
        }

        // Aucune empreinte de mot de passe ni secret TOTP, jamais (§ 16.1).
        $json = json_encode($charge, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('password', $json);
        self::assertStringNotContainsString('totpSecret', $json);
    }

    public function testLeChangementDeRoleEstJournaliseCommeDecision(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('natron@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$cobalt->getId(), ['roles' => ['ROLE_ADMIN']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $this->charge()['roles']);

        $decision = $this->premiere(AuditAction::RoleModifie);
        self::assertNotNull($decision, 'Un changement de rôle est une décision d’administration.');
        self::assertSame('cobalt@example.net', $decision->getSubjectLabel());
        self::assertSame(
            ['roles' => ['avant' => ['ROLE_USER'], 'apres' => ['ROLE_ADMIN', 'ROLE_USER']]],
            $decision->getChanges(),
        );
        self::assertSame('natron@example.net', $decision->getActorEmail());
    }

    public function testLaSuspensionEstJournaliseeEtPrendEffetImmediatement(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('natron@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$cobalt->getId(), ['status' => 'suspendu']);

        self::assertResponseIsSuccessful();
        self::assertSame('suspendu', $this->charge()['status']);

        self::assertNotNull($this->premiere(AuditAction::CompteSuspendu));

        // Et le compte suspendu ne se connecte plus.
        $this->requete('POST', '/api/deconnexion');
        $this->connexion('cobalt@example.net');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('compte_suspendu', $this->charge()['erreur']['code']);
    }

    /**
     * « Se verrouiller dehors est un incident sans porte de secours autre que la
     * console » (§ 10.1, ADR 0004).
     */
    public function testLeDernierAdministrateurNePeutPasSeRetirerSonRole(): void
    {
        $natron = $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('natron@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$natron->getId(), ['roles' => ['ROLE_USER']]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('dernier_administrateur', $this->charge()['erreur']['code']);

        // Il reste administrateur : le refus a bien empêché l'écriture.
        $this->requete('GET', '/api/administration/utilisateurs/'.$natron->getId());
        self::assertContains('ROLE_ADMIN', $this->charge()['roles']);
    }

    public function testUnSecondAdministrateurLeveLeVerrou(): void
    {
        $natron = $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->creerUtilisateur('cobalt@example.net', roles: ['ROLE_ADMIN']);

        $this->connexion('natron@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$natron->getId(), ['roles' => ['ROLE_USER']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ROLE_USER'], $this->charge()['roles']);
    }

    /**
     * « **Pas `en_attente`** — on ne remet pas un compte en attente, on le suspend »
     * (§ 10.1).
     */
    public function testLeStatutEnAttenteNEstPasAccepte(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('natron@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$cobalt->getId(), ['status' => 'en_attente']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $erreur = $this->charge()['erreur'];
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertArrayHasKey('status', $erreur['champs']);
    }

    public function testUnRoleInventeEstRefuse(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('natron@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$cobalt->getId(), ['roles' => ['ROLE_SUPER_ADMIN']]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('donnees_invalides', $this->charge()['erreur']['code']);
    }

    public function testUnCompteInconnuRend404(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('GET', '/api/administration/utilisateurs/9999');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('introuvable', $this->charge()['erreur']['code']);
    }

    public function testLaListeSeFiltreParStatut(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);

        $this->connexion('natron@example.net');

        $this->requete('GET', '/api/administration/utilisateurs?status=en_attente');
        $charge = $this->charge();
        self::assertSame(1, $charge['total']);
        self::assertSame('cousin@example.net', $charge['elements'][0]['email']);

        // Un statut inconnu ne ramène rien plutôt que de lever.
        $this->requete('GET', '/api/administration/utilisateurs?status=inconnu');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->charge()['total']);
    }

    private function premiere(AuditAction $action): ?AuditLog
    {
        foreach ($this->journal() as $ligne) {
            if ($action === $ligne->getAction()) {
                return $ligne;
            }
        }

        return null;
    }
}
