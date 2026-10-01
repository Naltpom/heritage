<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\Notification;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\UserRepository;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\InvitationRepository;
use App\Module\Membership\Repository\MemberRepository;

/**
 * « Je ne me trouve pas » — l'inscription **sans fiche**, de bout en bout (ADR 0013).
 *
 * Le besoin tient en une phrase de l'administrateur : un lien illimité circule dans le
 * groupe de la famille, et un cousin que l'arbre ne connaît pas exactement — pas le bon
 * prénom d'usage, pas la bonne date, pas du tout — ne pouvait pas créer de compte. Il
 * décrit maintenant sa famille, son compte naît `en_attente` **sans fiche**, et un
 * administrateur le rattache.
 *
 * Ce que ce fichier verrouille, en plus du chemin nominal :
 *
 * - **rien de la déclaration** ne sort ailleurs que vers l'écran d'administration — ni
 *   dans la charge de session, ni dans une notification, ni dans le journal d'audit ;
 * - les deux formes de corps sont **exclusives**, et toutes les erreurs de champ tiennent
 *   dans **un seul** `422` ;
 * - l'inscription sans fiche a son propre plafond par IP, avec un vrai `429` ;
 * - les **défunts** ne sont plus proposés par la recherche.
 */
final class InscriptionSansFicheTest extends CasDeTestMembership
{
    private const string DESCRIPTION = 'Mon père est Pyrite Romarin, né en 1956 à Lille ; ma mère Cobalt Cassis ; '
        .'mon grand-père paternel Jaspe Romarin, décédé.';

    private const string MOT_DE_PASSE = 'un-mot-de-passe-de-douze-signes';

    public function testLeCandidatQuiNeSeTrouvePasCreeUnCompteEnAttenteSansFiche(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$invitation, $jeton] = $this->creerInvitation($administrateur, maxUses: null);

        // L'étape 1b ne le trouve pas : c'est ce qui lui fait cliquer « Je ne me trouve pas ».
        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton, 'surname' => 'Romarin', 'givenNames' => 'Lave', 'birthDate' => '1998-04-12',
        ]);
        self::assertSame(['candidats' => [], 'tentativesRestantes' => null], $this->charge());

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'declaration' => [
                'surname' => '  Romarin ',
                'givenNames' => 'Lave Marie',
                'birthDate' => '1998-04-12',
                'description' => self::DESCRIPTION,
            ],
            'email' => 'lave@example.net',
            'password' => self::MOT_DE_PASSE,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $charge = $this->charge();
        self::assertIsArray($charge['utilisateur']);
        self::assertSame('en_attente', $charge['utilisateur']['status']);
        self::assertIsArray($charge['membre']);
        self::assertSame('en_attente', $charge['membre']['status']);
        self::assertSame(MatchKind::ARattacher->value, $charge['membre']['matchKind']);
        self::assertNull($charge['membre']['activatedAt']);
        self::assertArrayNotHasKey('personId', $charge['membre']);
        self::assertSame(['regime' => 'aucun'], $charge['cercle']);

        // Rien de la déclaration dans la charge de session.
        $json = json_encode($charge, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Pyrite', $json);
        self::assertStringNotContainsString('Lave Marie', $json);

        // En base : un membre sans fiche, qui garde sa déclaration — nettoyée des blancs.
        $membre = $this->membreDe('lave@example.net');
        self::assertNull($membre->getPersonId());
        self::assertNull($membre->getPersonLabel());
        self::assertSame(MemberStatus::EnAttente, $membre->getStatus());
        self::assertSame('Romarin', $membre->getDeclaredSurname());
        self::assertSame('Lave Marie', $membre->getDeclaredGivenNames());
        self::assertSame('1998-04-12', $membre->getDeclaredBirthDate()?->format('Y-m-d'));
        self::assertSame(self::DESCRIPTION, $membre->getFamilyDescription());
        self::assertSame($invitation->getId(), $membre->getInvitation()?->getId());

        // L'invitation compte l'usage, comme pour un compte avec fiche.
        $invitations = self::getContainer()->get(InvitationRepository::class);
        \assert($invitations instanceof InvitationRepository);
        self::assertSame(1, $invitations->find($invitation->getId())?->getUseCount());

        // Les administrateurs sont prévenus — par le courriel, jamais par la description.
        $notifications = $this->em()->getRepository(Notification::class)->findAll();
        self::assertCount(1, $notifications);
        self::assertSame('Une inscription attend son rattachement', $notifications[0]->getTitle());
        self::assertSame('administration.rattachements', $notifications[0]->getLinkRoute());
        self::assertStringContainsString('lave@example.net', (string) $notifications[0]->getBody());
        self::assertStringNotContainsString('Pyrite', (string) $notifications[0]->getBody());

        // Et le journal d'audit ne garde **aucun** mot de la déclaration.
        $this->aucunJournalNeContient(['Pyrite', 'Lave Marie', '1998-04-12']);

        // L'écran d'attente : la nature, et pas de photo pour l'instant.
        $this->requete('GET', '/api/inscription/etat');
        $etat = $this->charge();
        self::assertSame('a_rattacher', $etat['matchKind']);
        self::assertSame('aucun', $etat['regime']);
        self::assertFalse($etat['portraitEnAttente']);
    }

    /** Les deux formes ensemble : on ne devine pas laquelle le candidat voulait dire. */
    public function testChoixEtDeclarationSontExclusifs(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'choix' => 'c1',
            'declaration' => $this->declaration(),
            'email' => 'lave@example.net',
            'password' => self::MOT_DE_PASSE,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            ['choix' => 'Choisissez une fiche ou décrivez votre famille, pas les deux.'],
            $this->champsEnErreur(),
        );
    }

    /** Ni l'une ni l'autre : le `404` d'avant, inchangé. */
    public function testSansChoixNiDeclarationLaRouteRend404(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton, 'email' => 'lave@example.net', 'password' => self::MOT_DE_PASSE,
        ]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** **Un seul `422`** pour toutes les erreurs : courriel, mot de passe et déclaration. */
    public function testToutesLesErreursDeChampTiennentDansUnSeul422(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'declaration' => [
                'surname' => '   ',
                'givenNames' => str_repeat('a', 161),
                'birthDate' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
                'description' => 'Trop court.',
            ],
            'email' => 'pas-un-courriel',
            'password' => 'court',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $champs = array_keys($this->champsEnErreur());
        sort($champs);

        self::assertSame([
            'declaration.birthDate',
            'declaration.description',
            'declaration.givenNames',
            'declaration.surname',
            'email',
            'password',
        ], $champs);

        self::assertNull(
            $this->utilisateurs()->findOneBy(['email' => 'pas-un-courriel']),
            'Un 422 ne crée rien.',
        );
    }

    /**
     * Les dates qui n'existent pas, et celles d'avant 1900, sont refusées. `createFromFormat()`
     * reporterait le 31 février en mars sans rien dire : la validation relit ce qu'elle a lu.
     */
    public function testUneDateImpossibleOuTropAncienneEstRefusee(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        foreach (['1998-02-31', '1899-12-31', '12/04/1998'] as $date) {
            $this->requete('POST', '/api/inscription/compte', [
                'jeton' => $jeton,
                'declaration' => $this->declaration(naissance: $date),
                'email' => 'lave@example.net',
                'password' => self::MOT_DE_PASSE,
            ]);

            self::assertSame(422, $this->client->getResponse()->getStatusCode(), $date);
            self::assertSame(['declaration.birthDate'], array_keys($this->champsEnErreur()), $date);
        }
    }

    /**
     * **Cinq comptes sans fiche par heure et par IP**, puis un vrai `429` avec
     * `Retry-After`. Le limiteur passe **avant** le jeton : même un jeton inventé le
     * consomme, et la sixième requête est refusée sans qu'on regarde son jeton.
     */
    public function testLInscriptionSansFicheEstPlafonneeParIp(): void
    {
        for ($essai = 1; $essai <= 5; ++$essai) {
            $this->requete('POST', '/api/inscription/compte', [
                'jeton' => 'jeton-invente-'.$essai,
                'declaration' => $this->declaration(),
                'email' => \sprintf('essai%d@example.net', $essai),
                'password' => self::MOT_DE_PASSE,
            ]);

            self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'essai '.$essai);
        }

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => 'jeton-invente-6',
            'declaration' => $this->declaration(),
            'email' => 'essai6@example.net',
            'password' => self::MOT_DE_PASSE,
        ]);

        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /** Le plafond ne touche pas le chemin avec fiche : `choix` ne consomme rien ici. */
    public function testLePlafondSansFicheNeFreinePasLeCheminAvecFiche(): void
    {
        for ($essai = 1; $essai <= 6; ++$essai) {
            $this->requete('POST', '/api/inscription/compte', [
                'jeton' => 'jeton-invente-'.$essai,
                'choix' => 'c1',
                'email' => 'x@example.net',
                'password' => self::MOT_DE_PASSE,
            ]);

            self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'essai '.$essai);
        }
    }

    /**
     * **Les défunts ne sont plus proposés** — par la route, pas seulement par le service.
     * `901 Jadéite Lointain, 1891` était trouvé hier ; il ne l'est plus, et la réponse est
     * la même que pour une personne absente de l'arbre.
     */
    public function testLaRechercheNeProposePlusDeDefunt(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton, 'surname' => 'Lointain', 'givenNames' => 'Jadéite', 'birthDate' => '1891-05-05',
        ]);

        self::assertSame(['candidats' => [], 'tentativesRestantes' => null], $this->charge());
    }

    /**
     * Une invitation **pré-liée à un défunt** ne se confirme pas, même avec la bonne date :
     * `candidats: []`, comme une date fausse. Le candidat peut alors décrire sa famille.
     */
    public function testUnPreLienVersUnDefuntRendUneListeVide(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur, ArbreFamilial::ANCETRE_ETRANGER, 'Jadéite Lointain');

        $this->requete('POST', '/api/inscription/rattachement', [
            'jeton' => $jeton, 'surname' => 'Lointain', 'givenNames' => 'Jadéite', 'birthDate' => '1891-05-05',
        ]);

        self::assertSame(['candidats' => [], 'tentativesRestantes' => null], $this->charge());

        // Et « Je ne me trouve pas » reste ouvert sur ce lien.
        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'declaration' => $this->declaration(),
            'email' => 'lave@example.net',
            'password' => self::MOT_DE_PASSE,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame(UserStatus::EnAttente, $this->utilisateurs()->findOneBy(['email' => 'lave@example.net'])?->getStatus());
    }

    /** `409 courriel_deja_pris`, inchangé — et rien n'est créé. */
    public function testUnCourrielDejaPrisRend409(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        $this->requete('POST', '/api/inscription/compte', [
            'jeton' => $jeton,
            'declaration' => $this->declaration(),
            'email' => 'admin@example.net',
            'password' => self::MOT_DE_PASSE,
        ]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $this->em()->getRepository(Member::class)->findAll());
    }

    /** @return array<string, string> */
    private function declaration(string $naissance = '1998-04-12'): array
    {
        return [
            'surname' => 'Romarin',
            'givenNames' => 'Lave',
            'birthDate' => $naissance,
            'description' => self::DESCRIPTION,
        ];
    }

    /** @return array<string, mixed> */
    private function champsEnErreur(): array
    {
        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertSame('donnees_invalides', $corps['erreur']['code']);
        self::assertIsArray($corps['erreur']['champs']);

        return $corps['erreur']['champs'];
    }

    private function membreDe(string $courriel): Member
    {
        $utilisateur = $this->utilisateurs()->findOneBy(['email' => $courriel]);
        self::assertNotNull($utilisateur);
        $id = $utilisateur->getId();
        self::assertNotNull($id);

        $membres = self::getContainer()->get(MemberRepository::class);
        \assert($membres instanceof MemberRepository);
        $membre = $membres->pourUtilisateur($id);
        self::assertNotNull($membre);

        return $membre;
    }

    private function utilisateurs(): UserRepository
    {
        $depot = self::getContainer()->get(UserRepository::class);
        \assert($depot instanceof UserRepository);

        return $depot;
    }

    /** @param list<string> $fragments */
    private function aucunJournalNeContient(array $fragments): void
    {
        $journaux = $this->em()->getRepository(AuditLog::class)->findAll();
        self::assertNotEmpty($journaux, 'Le parcours laisse bien des lignes de journal.');

        foreach ($journaux as $ligne) {
            $texte = json_encode([$ligne->getSubjectLabel(), $ligne->getChanges()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

            foreach ($fragments as $fragment) {
                self::assertStringNotContainsString($fragment, $texte, \sprintf('Le journal « %s » cite la déclaration.', $ligne->getAction()->value));
            }
        }
    }
}
