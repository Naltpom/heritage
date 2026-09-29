<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Repository\AuditLogRepository;
use App\Module\Membership\Enum\InvitationStatus;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\InvitationRepository;
use App\Module\Membership\Service\GenerateurDeJeton;

/**
 * Les invitations, côté administrateur — § 3 du contrat d'API.
 *
 * Trois propriétés y sont vérifiées, et la première est la seule qui ne se rattrape pas si
 * on l'oublie : **le serveur ne stocke que `hash(jeton)`**. Le jeton en clair n'existe que
 * dans la réponse de création, une fois. Un administrateur qui perd le lien révoque et
 * recrée ; il ne peut pas le relire, et c'est voulu.
 */
final class InvitationsTest extends CasDeTestMembership
{
    /**
     * **Le jeton n'apparaît qu'une fois, et la base n'en garde que l'empreinte.**.
     *
     * Le lien porte 43 caractères `[A-Za-z0-9_-]` ; `token_hash` en est le `sha256`
     * hexadécimal, et `token_prefix` les 8 premiers caractères — « 8 caractères sur 43 ne
     * reconstruisent rien ».
     */
    public function testLeJetonNExisteQueDansLaReponseDeCreation(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/invitations', [
            'personId' => ArbreFamilial::EGO,
            'maxUses' => 1,
            'note' => 'pour la cousine de Saule',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $creation = $this->charge();

        self::assertArrayHasKey('lien', $creation);
        self::assertIsString($creation['lien']);
        self::assertStringContainsString('/inscription/', $creation['lien']);

        $jeton = substr($creation['lien'], strrpos($creation['lien'], '/') + 1);
        self::assertSame(43, \strlen($jeton));
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', $jeton));

        $jetons = self::getContainer()->get(GenerateurDeJeton::class);
        \assert($jetons instanceof GenerateurDeJeton);

        $depot = self::getContainer()->get(InvitationRepository::class);
        \assert($depot instanceof InvitationRepository);

        $invitation = $depot->find($creation['id']);
        self::assertNotNull($invitation);
        self::assertSame($jetons->empreinte($jeton), $invitation->getTokenHash(), 'La base ne garde que l’empreinte.');
        self::assertSame(substr($jeton, 0, 8), $invitation->getTokenPrefix());

        // La liste, elle, ne porte **pas** de clé `lien` — absente, pas `null` (§ 1.3).
        $this->requete('GET', '/api/administration/invitations');
        $liste = $this->charge();
        self::assertIsArray($liste['elements']);
        self::assertArrayNotHasKey('lien', $liste['elements'][0]);

        // Et le jeton en clair n'apparaît nulle part dans la liste.
        $brut = $this->client->getResponse()->getContent();
        self::assertIsString($brut);
        self::assertStringNotContainsString($jeton, $brut);
    }

    /** Sans SMTP configuré, l'invitation est créée **quand même**, avec un avertissement. */
    public function testSansSmtpLInvitationEstCreeeAvecUnAvertissement(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/invitations', [
            'email' => 'cousin.saule@example.net',
            'envoyerCourriel' => true,
            'note' => 'pour le cousin de Saule',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $creation = $this->charge();

        self::assertFalse($creation['courrielEnvoye']);
        self::assertIsArray($creation['avertissements']);
        self::assertNotEmpty(
            $creation['avertissements'],
            'Perdre l’invitation parce que le SMTP est éteint serait absurde : on avertit, on ne refuse pas.',
        );
        self::assertIsString($creation['lien'], 'Le lien reste affiché pour copie manuelle — les deux chemins existent.');
    }

    /** Un `personId` inexistant est refusé **avant** écriture (mesure 1 de l'ADR 0002). */
    public function testUnPersonIdInexistantEstRefuseAvantEcriture(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/invitations', ['personId' => 999_999, 'maxUses' => 1, 'note' => 'fiche inconnue']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertIsArray($corps['erreur']['champs']);
        self::assertArrayHasKey('personId', $corps['erreur']['champs']);

        $depot = self::getContainer()->get(InvitationRepository::class);
        \assert($depot instanceof InvitationRepository);
        self::assertCount(0, $depot->findAll(), 'Rien ne doit être écrit.');
    }

    /** Deux liens concurrents vers la même fiche : `409 invitation_en_cours`. */
    public function testDeuxInvitationsConcurrentesVersLaMemePersonneSontRefusees(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/invitations', ['personId' => ArbreFamilial::EGO, 'maxUses' => 1, 'note' => 'pour Natron']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $premiere = $this->charge();

        $this->requete('POST', '/api/administration/invitations', ['personId' => ArbreFamilial::EGO, 'maxUses' => 1, 'note' => 'pour Natron']);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertSame('invitation_en_cours', $corps['erreur']['code']);
        self::assertIsArray($corps['erreur']['details']);
        self::assertSame($premiere['id'], $corps['erreur']['details']['invitationId']);
    }

    /** Une personne déjà rattachée à un compte : `409 personne_deja_rattachee`. */
    public function testUnePersonneDejaRattacheeEstRefusee(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $membre = $this->creerUtilisateur('membre@example.net');
        $this->creerMembre($membre, ArbreFamilial::EGO, 'Natron Romarin', MemberStatus::Actif);

        $this->connexion($administrateur->getEmail());
        $this->requete('POST', '/api/administration/invitations', ['personId' => ArbreFamilial::EGO, 'maxUses' => 1, 'note' => 'pour Natron']);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertSame('personne_deja_rattachee', $corps['erreur']['code']);
    }

    /** La révocation rend le lien mort ; sur une invitation consommée, elle est refusée. */
    public function testLaRevocationRendLeLienMort(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$invitation, $jeton] = $this->creerInvitation($administrateur);

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/invitations/%d/revoquer', $invitation->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(InvitationStatus::Revoquee->value, $this->charge()['status']);

        $this->requete('POST', '/api/inscription/verifier', ['jeton' => $jeton]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** « Révoquer après coup ne défait pas le compte créé » : un lien **épuisé** rend `409 invitation_deja_consommee`. */
    public function testRevoquerUneInvitationConsommeeEstRefuse(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$invitation] = $this->creerInvitation($administrateur);

        $identifiant = $administrateur->getId();
        \assert(null !== $identifiant);
        $invitation->enregistrerUnUsage($identifiant, 'quelqun@example.net');
        $this->em()->flush();

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/invitations/%d/revoquer', $invitation->getId()));

        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertSame('invitation_deja_consommee', $corps['erreur']['code']);
    }

    /**
     * Le décompte `tentatives` montre qu'un lien est sondé — **sans jamais rendre les
     * critères**, qui désignent des personnes vivantes.
     */
    public function testLaListeMontreLeSondageSansRendreLesCriteres(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [, $jeton] = $this->creerInvitation($administrateur);

        for ($i = 0; $i < 3; ++$i) {
            $this->requete('POST', '/api/inscription/rattachement', [
                'jeton' => $jeton,
                'surname' => 'Romarin',
                'givenNames' => 'Natron',
                'birthDate' => '1980-07-27',
            ]);
        }

        $this->connexion('admin@example.net');
        $this->requete('GET', '/api/administration/invitations');

        $liste = $this->charge();
        self::assertIsArray($liste['elements']);
        self::assertSame(3, $liste['elements'][0]['tentatives']);

        $brut = $this->client->getResponse()->getContent();
        self::assertIsString($brut);
        self::assertStringNotContainsStringIgnoringCase('Natron', $brut, 'Les critères soumis ne sortent jamais.');
    }

    /** Toute décision d'administration est journalisée (§ 8 du dossier). */
    public function testLaCreationEtLaRevocationSontJournalisees(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/invitations', ['personId' => ArbreFamilial::EGO, 'maxUses' => 1, 'note' => 'pour Natron']);
        $creation = $this->charge();

        $this->requete('POST', \sprintf('/api/administration/invitations/%d/revoquer', $creation['id']));

        $journal = self::getContainer()->get(AuditLogRepository::class);
        \assert($journal instanceof AuditLogRepository);

        $actions = array_map(
            static fn (object $ligne): string => $ligne->getAction()->value,
            $journal->findBy([], ['id' => 'ASC']),
        );

        self::assertContains(AuditAction::InvitationCreee->value, $actions);
        self::assertContains(AuditAction::InvitationRevoquee->value, $actions);

        // Aucun jeton en clair, aucune empreinte de jeton dans le journal : « un journal
        // d'audit qui contient le secret est un journal qu'il faut protéger comme le
        // secret » (§ 2.4).
        $lignes = $journal->findBy(['action' => AuditAction::InvitationCreee]);
        self::assertCount(1, $lignes);
        $changes = $lignes[0]->getChanges();
        self::assertIsArray($changes);
        self::assertArrayNotHasKey('tokenHash', $changes);
        self::assertArrayHasKey('tokenPrefix', $changes);
    }

    /** La description est obligatoire ; plafond et échéance valent un entier ≥ 1 ou `null`. */
    public function testLeCorpsDeCreationEstValide(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/invitations', [
            'note' => '   ',
            'maxUses' => 0,
            'expiresInDays' => 'sept',
            'personId' => ArbreFamilial::EGO,
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertIsArray($corps['erreur']['champs']);
        foreach (['note', 'maxUses', 'expiresInDays', 'personId'] as $champ) {
            self::assertArrayHasKey($champ, $corps['erreur']['champs'], 'Toutes les erreurs d’un coup.');
        }

        $depot = self::getContainer()->get(InvitationRepository::class);
        \assert($depot instanceof InvitationRepository);
        self::assertCount(0, $depot->findAll());
    }

    /** Un pré-lien n'a de sens qu'à usage unique : une fiche ne se réclame qu'une fois. */
    public function testUnPreLienSurUnLienIllimiteEstRefuse(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/invitations', ['personId' => ArbreFamilial::EGO, 'note' => 'pour Natron']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertIsArray($corps['erreur']['champs']);
        self::assertArrayHasKey('personId', $corps['erreur']['champs']);
    }

    /** `maxUses: null` et `expiresInDays: null` : un lien illimité qui n'expire jamais. */
    public function testUnLienIllimiteSansEcheance(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/invitations', [
            'note' => 'groupe de la famille',
            'maxUses' => null,
            'expiresInDays' => null,
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $creation = $this->charge();
        self::assertNull($creation['maxUses']);
        self::assertSame(0, $creation['useCount']);
        self::assertNull($creation['expiresAt']);
        self::assertSame([], $creation['inscrits']);
        self::assertSame(InvitationStatus::Active->value, $creation['status']);

        // Clé `expiresInDays` absente : la durée réglée s'applique, pas « jamais ».
        $this->requete('POST', '/api/administration/invitations', ['note' => 'pour un cousin', 'maxUses' => 5]);
        $plafonne = $this->charge();
        self::assertSame(5, $plafonne['maxUses']);
        self::assertIsString($plafonne['expiresAt']);
    }

    /** Révoquer un lien déjà utilisé mais pas épuisé : permis, les comptes restent. */
    public function testRevoquerUnLienEntameEstPermis(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$invitation] = $this->creerInvitation($administrateur, maxUses: 3);

        $inscrit = $this->creerUtilisateur('cousin@example.net');
        $membre = $this->creerMembre($inscrit, ArbreFamilial::COUSIN, 'Marbre Romarin', MemberStatus::EnAttente);
        $membre->setInvitation($invitation);
        $identifiant = $inscrit->getId();
        \assert(null !== $identifiant);
        $invitation->enregistrerUnUsage($identifiant, $inscrit->getEmail());
        $this->em()->flush();

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/invitations/%d/revoquer', $invitation->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $ligne = $this->charge();
        self::assertSame(InvitationStatus::Revoquee->value, $ligne['status']);
        self::assertSame(1, $ligne['useCount']);
        self::assertIsArray($ligne['inscrits']);
        self::assertCount(1, $ligne['inscrits']);
    }

    /** La liste rend les inscrits de chaque lien, et `?revoquees=0` écarte les révoqués. */
    public function testLaListeRendLesInscritsEtFiltreLesRevoquees(): void
    {
        $administrateur = $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$collectif] = $this->creerInvitation($administrateur, maxUses: null);
        [$revoquee] = $this->creerInvitation($administrateur);

        $identifiantAdmin = $administrateur->getId();
        \assert(null !== $identifiantAdmin);
        $revoquee->revoquer($identifiantAdmin, $administrateur->getEmail());

        foreach ([['cousin@example.net', ArbreFamilial::COUSIN, 'Marbre Romarin'], ['tante@example.net', ArbreFamilial::TANTE, 'Marbrée Romarin']] as [$courriel, $personne, $libelle]) {
            $inscrit = $this->creerUtilisateur($courriel);
            $this->creerMembre($inscrit, $personne, $libelle)->setInvitation($collectif);
            $identifiant = $inscrit->getId();
            \assert(null !== $identifiant);
            $collectif->enregistrerUnUsage($identifiant, $courriel);
        }
        $this->em()->flush();

        $this->connexion('admin@example.net');

        $this->requete('GET', '/api/administration/invitations');
        $liste = $this->charge();
        self::assertSame(2, $liste['total']);

        $this->requete('GET', '/api/administration/invitations?revoquees=0');
        $liste = $this->charge();
        self::assertSame(1, $liste['total']);
        self::assertIsArray($liste['elements']);

        $ligne = $liste['elements'][0];
        self::assertSame($collectif->getId(), $ligne['id']);
        self::assertSame(InvitationStatus::Active->value, $ligne['status'], 'Un lien illimité reste actif.');
        self::assertNull($ligne['maxUses']);
        self::assertSame(2, $ligne['useCount']);
        self::assertIsArray($ligne['inscrits']);
        self::assertSame(['cousin@example.net', 'tante@example.net'], array_column($ligne['inscrits'], 'email'));
        self::assertSame(MemberStatus::Actif->value, $ligne['inscrits'][0]['memberStatus']);
        self::assertSame('tante@example.net', $ligne['usedByEmail'], '`usedByEmail` est le dernier inscrit.');
    }

    /** `/api/administration/*` est fermé à un membre ordinaire par `access_control`. */
    public function testUnMembreOrdinaireNAccedePasAuxInvitations(): void
    {
        $membre = $this->creerUtilisateur('membre@example.net');
        $this->creerMembre($membre, ArbreFamilial::EGO, 'Natron Romarin');

        $this->connexion('membre@example.net');
        $this->requete('GET', '/api/administration/invitations');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
