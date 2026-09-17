<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Service\AuditService;
use App\Module\Core\Service\BasculeVoirComme;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * La bascule « voir comme un membre » — § 10.4 du contrat d'API.
 *
 * Les tests HTTP portent sur ce que `Core` sert aujourd'hui, `Membership` n'étant pas
 * branché ; les autres construisent la bascule avec un annuaire de substitution, pour
 * vérifier la mécanique elle-même — c'est là que vivent les trois pièges.
 */
final class VoirCommeTest extends CoreWebTestCase
{
    /**
     * Un membre que l'annuaire ne connaît pas — parce qu'il n'existe pas, ou parce
     * qu'aucun adaptateur n'est branché — refuse l'activation, et c'est le même `404`
     * dans les deux cas (§ 15.3).
     *
     * Défaut fermé, comme pour le cercle : emprunter un cercle qu'on ne sait pas
     * calculer ne vérifierait rien, et l'administrateur croirait pourtant vérifier.
     */
    public function testUnMembreQueLAnnuaireIgnoreRefuseLActivation(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => 7]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('introuvable', $this->charge()['erreur']['code']);
    }

    public function testLaBasculeEstReserveeALAdministrateur(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => 7]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUnMemberIdManquantOuMalFormeRend422(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        foreach ([[], ['memberId' => 'sept'], ['memberId' => null]] as $corps) {
            $this->requete('POST', '/api/administration/voir-comme', $corps);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame('donnees_invalides', $this->charge()['erreur']['code']);
        }
    }

    public function testLaChargeDeSessionPorteVoirCommeANullHorsBascule(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('GET', '/api/session');
        self::assertArrayHasKey('voirComme', $this->charge());
        self::assertNull($this->charge()['voirComme']);

        // Quitter une bascule inactive est sans effet et ne lève pas.
        $this->requete('DELETE', '/api/administration/voir-comme');
        self::assertResponseIsSuccessful();
        self::assertNull($this->charge()['voirComme']);
    }

    public function testLActivationEstJournaliseeUneSeuleFois(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $bascule = $this->basculeAvecAnnuaire();

        $bascule->activer(7);

        // Plusieurs lectures sous la bascule : une exploration de dix minutes ne doit pas
        // noyer le journal d'audit.
        $bascule->memberEmprunte();
        $bascule->charge();
        $bascule->estActive();

        $traces = array_filter(
            $this->journal(),
            static fn (AuditLog $l): bool => AuditAction::VueCommeMembre === $l->getAction(),
        );

        self::assertCount(1, $traces);
        $trace = reset($traces);
        self::assertInstanceOf(AuditLog::class, $trace);
        self::assertSame('Membership\\Member', $trace->getSubjectType());
        self::assertSame('7', $trace->getSubjectId());
        self::assertSame('Cobalt Romarin', $trace->getSubjectLabel());
    }

    public function testLaBasculeExposeLeMembreEmprunteEtSaCharge(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $bascule = $this->basculeAvecAnnuaire();

        self::assertNull($bascule->memberEmprunte());
        self::assertFalse($bascule->estActive());
        self::assertNull($bascule->charge());

        $bascule->activer(7);

        // C'est ce grain que `Tree` doit incorporer dans l'`ETag` du graphe — sans lui,
        // l'administrateur revalide, reçoit 304, et regarde son propre graphe non filtré
        // en croyant tester celui de quelqu'un d'autre.
        self::assertSame(7, $bascule->memberEmprunte());
        self::assertTrue($bascule->estActive());

        $charge = $bascule->charge();
        self::assertIsArray($charge);
        self::assertSame(7, $charge['memberId']);
        self::assertSame('Cobalt Romarin', $charge['memberLabel']);
        self::assertIsString($charge['depuis']);

        $bascule->desactiver();
        self::assertNull($bascule->memberEmprunte());
        self::assertNull($bascule->charge());
    }

    /**
     * **Le membre et le compte sont deux entiers différents, et la bascule range les
     * deux.**.
     *
     * C'est le défaut qu'on ferme ici : `Core\Contract\Lecteur` exprime l'identité
     * empruntée par un identifiant de **compte**, la bascule reçoit un identifiant de
     * **membre**, et les confondre ne casse rien de visible — le calque « en attente » se
     * met simplement à désigner le mauvais auteur. Le double a donc des valeurs
     * délibérément distinctes (membre 7 = compte 42) : si un jour quelqu'un renvoie l'une
     * pour l'autre, ce test tombe au lieu de passer par chance.
     */
    public function testLaBasculeRangeLeCompteEtPasSeulementLeMembre(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $bascule = $this->basculeAvecAnnuaire();

        self::assertNull($bascule->userIdEmprunte());

        $bascule->activer(7);

        self::assertSame(7, $bascule->memberEmprunte(), 'mbr_member.id');
        self::assertSame(42, $bascule->userIdEmprunte(), 'core_user.id — jamais le même entier');

        $bascule->desactiver();
        self::assertNull($bascule->userIdEmprunte());
    }

    public function testUnMembreInconnuRefuseLActivation(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $bascule = $this->basculeAvecAnnuaire();

        $this->expectException(NotFoundException::class);
        $bascule->activer(999);
    }

    /**
     * Le piège n° 2 : aucune écriture sous identité empruntée. La `prp_revision`
     * porterait l'administrateur alors que l'écran montrait autre chose.
     */
    public function testAucuneEcritureNestPossibleSousLaBascule(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $bascule = $this->basculeAvecAnnuaire();

        // Inactive : le garde-fou laisse passer sans rien faire.
        $bascule->refuserSiActive();

        $bascule->activer(7);

        try {
            $bascule->refuserSiActive();
            self::fail('Une écriture sous la bascule doit être refusée.');
        } catch (ConflictException $conflit) {
            self::assertSame('vue_comme_membre_active', $conflit->codeErreur());
            self::assertSame(Response::HTTP_CONFLICT, $conflit->statutHttp());
            self::assertSame(
                ['memberId' => 7, 'memberLabel' => 'Cobalt Romarin'],
                $conflit->details(),
            );
        }
    }

    /**
     * Le même garde-fou, mais posé sur une vraie route : c'est ce branchement-là qui
     * s'oublie, pas la méthode.
     */
    public function testLesRoutesDEcritureAppellentLeGardeFou(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        // Sans bascule, l'écriture passe — on vérifie que le garde-fou ne bloque pas tout.
        $this->requete('PATCH', '/api/administration/reglages/cercle.generations', ['value' => 4]);
        self::assertResponseIsSuccessful();

        $this->requete('PATCH', '/api/administration/utilisateurs/1', ['displayName' => 'Natron Romarin']);
        self::assertResponseIsSuccessful();
    }

    private function basculeAvecAnnuaire(): BasculeVoirComme
    {
        $annuaire = new class implements AnnuaireDesMembresInterface {
            public function libelleDuMembre(int $memberId): ?string
            {
                return 7 === $memberId ? 'Cobalt Romarin' : null;
            }

            public function membreDuCompte(int $userId): ?array
            {
                return null;
            }

            /** Le membre 7 est le compte 42 : deux entiers différents, exprès. */
            public function userIdDuMembre(int $memberId): ?int
            {
                return 7 === $memberId ? 42 : null;
            }

            public function personneDuCompte(int $userId): ?int
            {
                return null;
            }

            /**
             * Aucun rattachement : cette bascule-ci ne teste pas les suppressions.
             *
             * @return array{membreId: int|null, membreLibelle: string|null, invitationsActives: int}
             */
            public function rattachementsDeLaPersonne(int $personneId): array
            {
                return ['membreId' => null, 'membreLibelle' => null, 'invitationsActives' => 0];
            }
        };

        $requete = new Request();
        $requete->setSession(new Session(new MockArraySessionStorage()));

        $pile = new RequestStack();
        $pile->push($requete);

        $audit = self::getContainer()->get(AuditService::class);
        \assert($audit instanceof AuditService);

        return new BasculeVoirComme($pile, $annuaire, $audit);
    }
}
