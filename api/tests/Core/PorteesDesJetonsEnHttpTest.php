<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use App\Tests\Proposal\Double\NotificateurEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les portées des jetons d'API en HTTP (ADR 0021 § 2 et § 5) : ce que chaque portée ouvre et
 * ferme, la règle de l'auteur sur les décisions, et le cookie qui n'en voit rien.
 *
 * Trois couches peuvent dire non, et chaque refus se reconnaît à son message — c'est ce qui
 * permet de vérifier que la bonne a parlé :
 *
 * | Couche | Message |
 * |---|---|
 * | la liste blanche (`PorteeDuJetonSubscriber`) | « Ce jeton d’API n’ouvre pas cette route. » |
 * | `access_control` | « Accès refusé. » |
 * | `DemandeVoter::DECIDER`, `MODIFIER` | « Cette demande ne vous appartient pas. » |
 * | `DemandeVoter::RETIRER` | « Sous un jeton d'API, une demande en attente ne se retire pas : elle attend un humain. » |
 *
 * La liste elle-même, route par route, est éprouvée contre le routeur par
 * {@see PorteeDuJetonTest}. Aucun jeton n'est écrit en littéral
 * ({@see CoreWebTestCase::creerJetonDApi()}).
 */
final class PorteesDesJetonsEnHttpTest extends CoreWebTestCase
{
    private const string HORS_PORTEE = 'Ce jeton d’API n’ouvre pas cette route.';

    /** Marne Dolomite Sureau, la fiche que les demandes modifient. */
    private const int PERSONNE = 17;

    private const int VERSION_DE_DEPART = 7;

    /** La personne d'Opale, membre de la famille : distincte de la fiche modifiée. */
    private const int PERSONNE_D_OPALE = 41;

    protected function setUp(): void
    {
        parent::setUp();

        // L'arbre et le notificateur des tests de `Proposal`, que `when@test` branche à la
        // place des vrais (`config/services/proposal.yaml`). Leur état est statique : on le
        // remet à zéro ici.
        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();
        $this->arbre()->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', self::VERSION_DE_DEPART, [
            'givenNames' => 'Marne Dolomite',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => true,
            'birth' => new UncertainDate('1987', DateQualifier::Annee, new \DateTimeImmutable('1987-01-01'), new \DateTimeImmutable('1987-12-31')),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();

        parent::tearDown();
    }

    public function testUnJetonDeLectureLitMaisNeDeposePas(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Lecture, 'Lecture seule');

        $this->requete('GET', '/api/session', entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        $this->requete('GET', '/api/demandes', entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/demandes', $this->demande('surname', 'Sureau', 'SUREAU'), $this->bearer($jeton));
        $this->assertRefusHorsPortee();

        // Refusée avant le contrôleur : aucune demande n'a été écrite.
        $this->requete('GET', '/api/demandes', entetes: $this->bearer($jeton));
        self::assertSame(0, $this->charge()['total']);
    }

    /** La vérification de production du plan : le jeton des agents reçoit `403` sur `valider`. */
    public function testUnJetonDeContributionDeposeMaisNeDecidePas(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution);

        $this->requete('POST', '/api/demandes', $this->demande('surname', 'Sureau', 'SUREAU'), $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $demande = $this->charge()['id'];
        self::assertIsInt($demande);

        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider', [], $this->bearer($jeton));
        $this->assertRefusHorsPortee();
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', ['message' => 'Doublon.'], $this->bearer($jeton));
        $this->assertRefusHorsPortee();

        self::assertSame('en_attente', $this->statut($demande, $jeton));
        self::assertSame('Sureau', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));
    }

    /**
     * ADR 0021 § 2 : un agent retire le brouillon qu'il a déposé par erreur, jamais une demande
     * en attente — celle du commanditaire comprise, puisque le jeton est sur son compte. Par son
     * cookie, le commanditaire la retire comme avant.
     */
    public function testUnJetonDeContributionRetireSonBrouillonMaisPasUneDemandeEnvoyee(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution);

        $corps = $this->demande('surname', 'Sureau', 'SUREAU');
        $corps['soumettre'] = false;
        $this->requete('POST', '/api/demandes', $corps, $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $brouillon = $this->charge()['id'];
        self::assertIsInt($brouillon);

        $this->requete('POST', '/api/demandes/'.$brouillon.'/retirer', [], $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        self::assertSame('annulee', $this->charge()['status']);
        self::assertNull($this->charge()['submittedAt']);

        $envoyee = $this->deposerParJeton($jeton, 'nickname', null, 'Marnette');
        $this->requete('POST', '/api/demandes/'.$envoyee.'/retirer', [], $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertRefusDuRetraitSousJeton();
        self::assertSame('en_attente', $this->statut($envoyee, $jeton));

        $this->connexion('administration@example.net');
        $this->requete('POST', '/api/demandes/'.$envoyee.'/retirer');
        self::assertResponseIsSuccessful();
        self::assertSame('annulee', $this->charge()['status']);
    }

    /**
     * Les bords de la règle : un retrait rejoué (la réponse perdue) apprend que la demande est
     * tranchée ; le jeton `validation` n'en sait pas plus que `contribution` ; le brouillon d'un
     * membre reste au membre ; par cookie, une demande tranchée rend toujours `409`.
     */
    public function testLeRetraitSousJetonAuxBordsDeLaRegle(): void
    {
        $administration = $this->administration();
        $contribution = $this->creerJetonDApi($administration, PorteeDeJeton::Contribution);
        $validation = $this->creerJetonDApi($administration, PorteeDeJeton::Validation, 'Claude — session principale');

        $corps = $this->demande('surname', 'Sureau', 'SUREAU');
        $corps['soumettre'] = false;
        $this->requete('POST', '/api/demandes', $corps, $this->bearer($contribution));
        $brouillon = $this->charge()['id'];
        self::assertIsInt($brouillon);
        $this->requete('POST', '/api/demandes/'.$brouillon.'/retirer', [], $this->bearer($contribution));
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/demandes/'.$brouillon.'/retirer', [], $this->bearer($contribution));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'Le rejeu d’un retrait');
        self::assertSame('demande_deja_decidee', $this->charge()['erreur']['code']);

        $envoyee = $this->deposerParJeton($validation, 'nickname', null, 'Marnette');
        $this->requete('POST', '/api/demandes/'.$envoyee.'/retirer', [], $this->bearer($validation));
        $this->assertRefusDuRetraitSousJeton('jeton validation');

        $this->opale();
        $this->connexion('opale@example.net');
        $this->requete('POST', '/api/demandes', $corps);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $deMembre = $this->charge()['id'];
        self::assertIsInt($deMembre);
        $this->requete('POST', '/api/demandes/'.$deMembre.'/retirer', [], $this->bearer($contribution));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('Cette demande ne vous appartient pas.', $this->charge()['erreur']['message']);

        $this->connexion('administration@example.net');
        $this->requete('POST', '/api/administration/demandes/'.$envoyee.'/valider');
        self::assertResponseIsSuccessful();
        $this->requete('POST', '/api/demandes/'.$envoyee.'/retirer');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'Par cookie, une demande tranchée');
        self::assertSame('demande_deja_decidee', $this->charge()['erreur']['code']);
    }

    public function testUnJetonDeValidationDecideLesDemandesDeSonCompte(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Validation, 'Claude — session principale');

        $aValider = $this->deposerParJeton($jeton, 'surname', 'Sureau', 'SUREAU');
        $aRefuser = $this->deposerParJeton($jeton, 'nickname', null, 'Marnette');

        // Le diff du décideur, relu avant de trancher.
        $this->requete('GET', '/api/administration/demandes/'.$aValider, entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/administration/demandes/'.$aValider.'/valider', [], $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        self::assertSame('validee', $this->charge()['resultat']);
        self::assertTrue($this->charge()['demande']['autoValidated'], 'Valider sa propre demande reste marqué (ADR 0004).');
        self::assertSame('SUREAU', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));

        $this->requete('POST', '/api/administration/demandes/'.$aRefuser.'/refuser', ['message' => 'Surnom non attesté par l’acte.'], $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        self::assertSame('refusee', $this->statut($aRefuser, $jeton));
    }

    /**
     * ADR 0021 § 5 : la portée `validation` ne suffit pas, il faut être l'auteur. La demande
     * d'un membre reste à un humain — et l'humain, par son cookie, la décide comme avant.
     */
    public function testUnJetonDeValidationNeDecidePasLaDemandeDUnAutreAuteur(): void
    {
        $administration = $this->administration();
        $jeton = $this->creerJetonDApi($administration, PorteeDeJeton::Validation, 'Claude — session principale');

        $this->opale();
        $this->connexion('opale@example.net');
        $this->requete('POST', '/api/demandes', $this->demande('surname', 'Sureau', 'SUREAU'));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $deMembre = $this->charge()['id'];
        self::assertIsInt($deMembre);

        foreach ([
            ['GET', '/api/administration/demandes/'.$deMembre, null],
            ['POST', '/api/administration/demandes/'.$deMembre.'/valider', []],
            ['POST', '/api/administration/demandes/'.$deMembre.'/refuser', ['message' => 'Doublon.']],
        ] as [$methode, $chemin, $corps]) {
            $this->requete($methode, $chemin, $corps, $this->bearer($jeton));
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $methode.' '.$chemin);
            self::assertSame('acces_refuse', $this->charge()['erreur']['code']);
            self::assertSame('Cette demande ne vous appartient pas.', $this->charge()['erreur']['message'], 'C’est le voter qui refuse : la route est dans la portée.');
        }
        self::assertSame('Sureau', $this->arbre()->valeur(SubjectKind::Personne, self::PERSONNE, 'surname'));

        // Le cookie n'est pas concerné : l'administrateur valide la demande du membre.
        $this->connexion('administration@example.net');
        $this->requete('POST', '/api/administration/demandes/'.$deMembre.'/valider');
        self::assertResponseIsSuccessful();
        self::assertSame('validee', $this->charge()['resultat']);
        self::assertFalse($this->charge()['demande']['autoValidated']);
    }

    /** Elle atteignait le contrôleur et levait une `LogicException` : un `403`, désormais. */
    public function testLaDeconnexionParJetonEstRefuseeSansErreurInterne(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Validation);

        $this->requete('POST', '/api/deconnexion', entetes: $this->bearer($jeton));

        $this->assertRefusHorsPortee();
    }

    /** Comptes, réglages, incarnation, voir-comme : fermés même au jeton le plus fort. */
    public function testUneRouteExclueEstRefuseeMemeAUnJetonDeValidation(): void
    {
        $administration = $this->administration();
        $jeton = $this->creerJetonDApi($administration, PorteeDeJeton::Validation);
        $autre = $this->opale();

        foreach ([
            ['GET', '/api/administration/utilisateurs', null],
            ['PATCH', '/api/administration/utilisateurs/'.$autre->getId(), ['roles' => ['ROLE_ADMIN']]],
            ['PATCH', '/api/administration/reglages/cercle.generations', ['valeur' => '6']],
            ['POST', '/api/administration/incarnation', ['userId' => $autre->getId()]],
            ['POST', '/api/administration/voir-comme', ['memberId' => 1]],
            ['POST', '/api/session/mot-de-passe', ['actuel' => 'motdepasse-de-test', 'nouveau' => 'un-autre-mot-de-passe-long']],
        ] as [$methode, $chemin, $corps]) {
            $this->requete($methode, $chemin, $corps, $this->bearer($jeton));
            $this->assertRefusHorsPortee($methode.' '.$chemin);
        }

        // Rien n'a bougé : le compte d'Opale n'est pas devenu administrateur.
        $this->em()->clear();
        self::assertSame(['ROLE_USER'], $this->em()->find(User::class, $autre->getId())?->getRoles());

        // Par cookie, l'administrateur lit les comptes comme avant.
        $this->connexion('administration@example.net');
        $this->requete('GET', '/api/administration/utilisateurs');
        self::assertResponseIsSuccessful();
    }

    /** Fermé par défaut : une route que la liste ne nomme pas est refusée, le cookie y passe. */
    public function testUneRouteHorsDeLaListeEstRefuseeEtLeCookieYPasse(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Validation);

        $this->requete('GET', '/api/notifications', entetes: $this->bearer($jeton));
        $this->assertRefusHorsPortee();

        $this->connexion('administration@example.net');
        $this->requete('GET', '/api/notifications');
        self::assertResponseIsSuccessful();
    }

    /**
     * La portée restreint, elle n'élargit jamais : `access_control` juge d'abord, et une route
     * `^/api/administration` de la liste reste refusée à un compte sans `ROLE_ADMIN`.
     */
    public function testLaPorteeNOuvreRienQueLeCompteNAitPas(): void
    {
        $sansRole = $this->creerJetonDApi($this->opale(), PorteeDeJeton::Validation);

        $this->requete('GET', '/api/administration/sources', entetes: $this->bearer($sansRole));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('acces_refuse', $this->charge()['erreur']['code']);
        self::assertSame('Accès refusé.', $this->charge()['erreur']['message'], 'C’est `access_control` qui refuse, avant la portée.');

        $administrateur = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Lecture);
        $this->requete('GET', '/api/administration/sources', entetes: $this->bearer($administrateur));
        self::assertResponseIsSuccessful();
    }

    // ─── Outils ──────────────────────────────────────────────────────────────────

    private function assertRefusDuRetraitSousJeton(string $contexte = ''): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $contexte);
        self::assertSame(
            ['code' => 'acces_refuse', 'message' => 'Sous un jeton d\'API, une demande en attente ne se retire pas : elle attend un humain.', 'champs' => null, 'details' => null],
            $this->charge()['erreur'],
            $contexte,
        );
    }

    private function assertRefusHorsPortee(string $contexte = ''): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $contexte);
        self::assertSame(
            ['code' => 'acces_refuse', 'message' => self::HORS_PORTEE, 'champs' => null, 'details' => null],
            $this->charge()['erreur'],
            $contexte,
        );
    }

    /** Le compte du commanditaire, celui des deux jetons du plan : actif, administrateur, sans fiche. */
    private function administration(): User
    {
        return $this->creerUtilisateur('administration@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Administration');
    }

    /**
     * Opale, membre de la famille : un compte actif, rattaché à sa fiche — sans quoi il n'a
     * pas de cercle et ne dépose rien.
     */
    private function opale(): User
    {
        $opale = $this->creerUtilisateur('opale@example.net', nomAffiche: 'Opale Romarin');
        $id = $opale->getId();
        \assert(null !== $id);

        $membre = new Member($id, $opale->getEmail(), self::PERSONNE_D_OPALE, 'Opale Romarin', MatchKind::PreLie);
        $membre->setStatus(MemberStatus::Actif);
        $membre->activer();
        $this->em()->persist($membre);
        $this->em()->flush();

        return $opale;
    }

    /** @return array<string, mixed> */
    private function demande(string $champ, ?string $ancienne, string $nouvelle): array
    {
        return [
            'kind' => 'modification_personne',
            'message' => null,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'personne',
                'subjectId' => self::PERSONNE,
                'field' => $champ,
                'valueKind' => 'texte',
                'oldValue' => $ancienne,
                'newValue' => $nouvelle,
                'baseVersion' => self::VERSION_DE_DEPART,
            ]],
        ];
    }

    private function deposerParJeton(string $jeton, string $champ, ?string $ancienne, string $nouvelle): int
    {
        $this->requete('POST', '/api/demandes', $this->demande($champ, $ancienne, $nouvelle), $this->bearer($jeton));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->charge()['id'];
        self::assertIsInt($id);

        return $id;
    }

    private function statut(int $demande, string $jeton): string
    {
        $this->requete('GET', '/api/demandes/'.$demande, entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        $statut = $this->charge()['status'];
        self::assertIsString($statut);

        return $statut;
    }

    private function arbre(): ArbreEnMemoire
    {
        $arbre = self::getContainer()->get(ArbreEnMemoire::class);
        \assert($arbre instanceof ArbreEnMemoire);

        return $arbre;
    }

    /** @return array{Authorization: string} */
    private function bearer(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }
}
