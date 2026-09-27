<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Tests\Tree\ArbreWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Se connecter en tant qu'un autre compte, et en revenir — ADR 0010, en vrai HTTP.
 *
 * Ce qui compte ici n'est pas que l'incarnation marche — Symfony fournit le jeton —, c'est
 * **ce qu'on en garde** : chaque ligne du journal écrite pendant l'incarnation dit quel
 * administrateur tenait le clavier, et les secrets du compte incarné restent les siens.
 */
final class IncarnationTest extends ArbreWebTestCase
{
    private const string ADMIN = 'admin@example.invalid';
    private const string JASPE = 'jaspe@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerUtilisateur(self::ADMIN, ['ROLE_ADMIN'], nomAffiche: 'Admin');
        $identifiant = $this->creerUtilisateur(self::JASPE, nomAffiche: 'Jaspe')->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, self::JASPE, $this->idDe('conjointe'), 'Cobalt Romarin', MatchKind::PreLie);
        $membre->activer();
        $this->em()->persist($membre);
        $this->em()->flush();
    }

    public function testLAdministrateurDevientLeMembreEtChaqueActionPorteSaTrace(): void
    {
        $this->connexionDe(self::ADMIN);

        $this->envoyer('POST', '/api/administration/incarnation', ['userId' => $this->idDe_(self::JASPE)]);
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame(self::JASPE, $this->charge()['utilisateur']['email']);
        self::assertSame($this->idDe_(self::ADMIN), $this->charge()['incarnation']['par']['id']);

        // La session suit : la requête suivante est toujours celle du membre.
        $this->requete('/api/session');
        self::assertSame(self::JASPE, $this->charge()['utilisateur']['email']);
        self::assertSame('Admin', $this->charge()['incarnation']['par']['displayName']);
        self::assertSame($this->idDe('conjointe'), $this->charge()['membre']['personId']);

        // Il agit comme le membre : il crée un tirage, dont il devient l'organisateur…
        $this->envoyer('POST', '/api/tirages', [
            'nom' => 'Noël côté maman', 'jour' => 25, 'mois' => 12,
            'participants' => [$this->idDe('conjointe'), $this->idDe('cousin'), $this->idDe('ego')],
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        self::assertTrue($this->charge()['organisateur']['estMoi']);

        // … et le journal dit qui tenait le clavier.
        $creation = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => AuditAction::Creation, 'subjectType' => 'Tirage\\Tirage']);
        self::assertNotNull($creation);
        self::assertSame($this->idDe_(self::JASPE), $creation->getActorUserId());
        self::assertSame($this->idDe_(self::ADMIN), $creation->getImpersonatorUserId());
        self::assertSame(self::ADMIN, $creation->getImpersonatorEmail());

        // Il n'a plus ses droits d'administrateur, puisqu'il est le membre.
        $this->requete('/api/administration/journal');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());

        $this->envoyer('DELETE', '/api/session/incarnation');
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame(self::ADMIN, $this->charge()['utilisateur']['email']);
        self::assertNull($this->charge()['incarnation']);

        $this->requete('/api/administration/journal');
        self::assertSame(Response::HTTP_OK, $this->statut(), 'De retour, il retrouve son compte et ses droits.');

        $debut = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => AuditAction::IncarnationDebut]);
        self::assertNotNull($debut);
        self::assertSame($this->idDe_(self::ADMIN), $debut->getActorUserId());
        self::assertSame((string) $this->idDe_(self::JASPE), $debut->getSubjectId());

        $fin = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => AuditAction::IncarnationFin]);
        self::assertNotNull($fin);
        self::assertSame($this->idDe_(self::JASPE), $fin->getActorUserId());
        self::assertSame($this->idDe_(self::ADMIN), $fin->getImpersonatorUserId());
    }

    public function testLesSecretsDuCompteIncarneRestentLesSiens(): void
    {
        $this->connexionDe(self::ADMIN);
        $this->envoyer('POST', '/api/administration/incarnation', ['userId' => $this->idDe_(self::JASPE)]);

        $this->envoyer('POST', '/api/session/mot-de-passe', ['motDePasseActuel' => 'motdepasse-de-test', 'nouveauMotDePasse' => 'un-autre-mot-de-passe']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('incarnation_active', $this->charge()['erreur']['code']);

        $this->envoyer('POST', '/api/session/courriel', ['motDePasseActuel' => 'motdepasse-de-test', 'nouveauCourriel' => 'autre@example.invalid']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
    }

    public function testCeQuOnNIncarnePas(): void
    {
        $this->creerUtilisateur('autre-admin@example.invalid', ['ROLE_ADMIN']);
        $this->creerUtilisateur('suspendu@example.invalid', statut: UserStatus::Suspendu);
        $this->connexionDe(self::ADMIN);

        foreach (['autre-admin@example.invalid', 'suspendu@example.invalid', self::ADMIN] as $courriel) {
            $this->envoyer('POST', '/api/administration/incarnation', ['userId' => $this->idDe_($courriel)]);
            self::assertSame(Response::HTTP_CONFLICT, $this->statut(), $courriel);
            self::assertSame('incarnation_impossible', $this->charge()['erreur']['code']);
        }

        $this->envoyer('POST', '/api/administration/incarnation', ['userId' => 999999]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());

        $this->envoyer('DELETE', '/api/session/incarnation');
        self::assertSame(Response::HTTP_CONFLICT, $this->statut(), 'Rien à quitter.');
    }

    public function testUnMembreNIncarnePersonne(): void
    {
        $this->connexionDe(self::JASPE);

        $this->envoyer('POST', '/api/administration/incarnation', ['userId' => $this->idDe_(self::ADMIN)]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
    }

    /** @param array<string, mixed>|null $corps */
    private function envoyer(string $methode, string $chemin, ?array $corps = null): void
    {
        $this->client->request($methode, $chemin, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $corps ? '' : json_encode($corps, \JSON_THROW_ON_ERROR));
    }

    private function statut(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function idDe_(string $courriel): int
    {
        $utilisateur = $this->em()->getRepository(User::class)->findOneBy(['email' => $courriel]);
        self::assertNotNull($utilisateur);

        return (int) $utilisateur->getId();
    }

    private function connexionDe(string $courriel): void
    {
        $this->envoyer('POST', '/api/connexion', ['email' => $courriel, 'password' => 'motdepasse-de-test']);
        self::assertSame(Response::HTTP_OK, $this->statut(), 'La connexion de test doit réussir.');
    }
}
