<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\UserRepository;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Service\ComptesARattacherParMembership;
use App\Tests\Media\FabriqueDeFichiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le côté administrateur de l'inscription sans fiche (ADR 0013) : l'onglet « À
 * rattacher », le rattachement, le refus, la photo de l'inscription — et le contrat
 * `Membership\Contract\ComptesARattacher` que le module `Assistant` consomme.
 *
 * ## Ce qui est verrouillé
 *
 * - un compte sans fiche ne s'active **pas** sans fiche, ni sur un défunt, ni sur une
 *   personne déjà réclamée ;
 * - le rattachement et le refus **purgent** la déclaration — et le journal d'audit n'en
 *   garde pas l'ancienne valeur (`CHAMPS_SENSIBLES`) ;
 * - la photo de l'inscription attend sur le compte, invisible des autres membres, et
 *   devient le portrait de la fiche au rattachement ;
 * - le refus **suspend** : plus de connexion, plus de photo, plus de description.
 *
 * `MEDIA_RACINE` est détournée vers `var/`, suffixée du numéro de processus, comme dans
 * `tests/Tree/TeleversementDUnePreuveHttpTest.php` : un test qui dépose une photo n'écrit
 * pas dans l'arborescence du dépôt.
 */
final class RattachementDesComptesSansFicheTest extends CasDeTestMembership
{
    private const string DESCRIPTION = 'Mon père est Pyrite Romarin, né en 1956 à Lille ; ma mère Cobalt Cassis ; '
        .'mon grand-père paternel Jaspe Romarin.';

    protected function setUp(): void
    {
        // AVANT `createClient()` : le conteneur lit l'environnement au premier appel.
        $racine = self::racineDesMedias();
        $_ENV['MEDIA_RACINE'] = $racine;
        $_SERVER['MEDIA_RACINE'] = $racine;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::effacer(self::racineDesMedias());
        unset($_ENV['MEDIA_RACINE'], $_SERVER['MEDIA_RACINE']);
    }

    public function testLOngletARattacherListeLesComptesSansFicheAvecLeurDeclaration(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        [$invitation] = $this->creerInvitation($this->creerUtilisateur('autre-admin@example.net', ['ROLE_ADMIN']), maxUses: null);

        $compte = $this->creerUtilisateur('lave@example.net', statut: UserStatus::EnAttente);
        $membre = $this->creerMembreSansFiche($compte, description: self::DESCRIPTION, invitation: $invitation);

        // Un compte en attente **avec** fiche n'y figure pas : il a déjà sa carte ailleurs.
        $autre = $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);
        $this->creerMembre($autre, ArbreFamilial::COUSIN, 'Marbre Romarin', MemberStatus::EnAttente, MatchKind::RechercheConfirmee);

        $this->connexion('admin@example.net');
        $this->requete('GET', '/api/administration/rattachements');

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame(1, $charge['total']);
        self::assertIsArray($charge['elements']);

        $element = $charge['elements'][0];
        self::assertSame([
            'memberId', 'userId', 'email', 'inscritLe', 'invitationId', 'invitationPersonId',
            'invitationPersonLabel', 'declaration', 'photo', 'suggestionCalculeeLe',
        ], array_keys($element));
        self::assertSame($membre->getId(), $element['memberId']);
        self::assertSame('lave@example.net', $element['email']);
        self::assertSame($invitation->getId(), $element['invitationId']);
        self::assertNull($element['invitationPersonId']);
        self::assertSame([
            'surname' => 'Romarin',
            'givenNames' => 'Lave',
            'birthDate' => '1998-04-12',
            'description' => self::DESCRIPTION,
        ], $element['declaration']);
        self::assertNull($element['photo']);
        self::assertNull($element['suggestionCalculeeLe']);
    }

    public function testLOngletEstFermeAUnMembre(): void
    {
        $membre = $this->creerUtilisateur('cobalt@example.net');
        $this->creerMembre($membre, ArbreFamilial::MERE, 'Cobalt Cassis');

        $this->connexion('cobalt@example.net');
        $this->requete('GET', '/api/administration/rattachements');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnCompteSansFicheNeSActivePasSansFiche(): void
    {
        $membreId = $this->compteSansFiche();

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), []);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            ['personId' => 'Choisissez la fiche à laquelle rattacher ce compte.'],
            $this->charge()['erreur']['champs'],
        );
    }

    public function testOnNeRattachePasUnCompteAUnDefunt(): void
    {
        $membreId = $this->compteSansFiche();

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), ['personId' => ArbreFamilial::ANCETRE_ETRANGER]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(['personId' => 'Cette personne est décédée.'], $this->charge()['erreur']['champs']);
    }

    public function testOnNeRattachePasUnCompteAUnePersonneDejaReclamee(): void
    {
        $membreId = $this->compteSansFiche();
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->creerMembre($cobalt, ArbreFamilial::MERE, 'Cobalt Cassis');

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), ['personId' => ArbreFamilial::MERE]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('personne_deja_rattachee', $this->charge()['erreur']['code']);
    }

    /**
     * Le rattachement : le compte devient actif, `manuel_admin`, sur la fiche choisie — et
     * **la déclaration disparaît**, de la table comme du journal.
     */
    public function testLeRattachementActiveLeCompteEtPurgeLaDeclaration(): void
    {
        $membreId = $this->compteSansFiche();

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), ['personId' => ArbreFamilial::ETRANGER]);

        self::assertResponseIsSuccessful();

        $charge = $this->charge();
        self::assertSame('actif', $charge['status']);
        self::assertSame(MatchKind::ManuelAdmin->value, $charge['matchKind']);
        self::assertSame(ArbreFamilial::ETRANGER, $charge['personId']);
        self::assertSame('Onyx Lointain', $charge['personLabel']);

        $membre = $this->membre($membreId);
        self::assertSame(MemberStatus::Actif, $membre->getStatus());
        self::assertSame(ArbreFamilial::ETRANGER, $membre->getPersonId());
        self::assertNull($membre->getFamilyDescription());
        self::assertNull($membre->getDeclaredSurname());
        self::assertNull($membre->getDeclaredGivenNames());
        self::assertNull($membre->getDeclaredBirthDate());
        self::assertNull($membre->getAttachmentSuggestion());

        self::assertSame(UserStatus::Actif, $this->utilisateur('lave@example.net')->getStatus());

        // La purge est un changement que le journal mécanique consigne — en `***`.
        $this->aucunJournalNeContient(['Pyrite', 'Lave', '1998-04-12']);
        self::assertTrue($this->unJournalMasque('familyDescription'), 'La purge doit figurer au journal, masquée.');
    }

    /**
     * La photo de l'inscription : déposée par le compte en attente **sur lui-même**,
     * invisible des autres membres, montrée à l'administrateur, et **portrait de la fiche**
     * au rattachement.
     */
    public function testLaPhotoDeLInscriptionDevientLePortraitDeLaFiche(): void
    {
        $membreId = $this->compteSansFiche();

        $this->connexion('lave@example.net');
        $this->deposerPhoto();

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $photo = $this->charge();
        self::assertSame('en_attente', $photo['status']);
        self::assertNull($photo['subjectId']);
        self::assertSame('Portrait en attente de rattachement', $photo['subjectLabel']);
        self::assertFalse($photo['isActivePortrait']);
        $mediaId = $photo['id'];
        self::assertIsInt($mediaId);

        // Le titulaire la revoit…
        $this->requete('GET', \sprintf('/api/medias/%d', $mediaId));
        self::assertResponseIsSuccessful();

        // … l'écran d'attente sait qu'elle existe…
        $this->requete('GET', '/api/inscription/etat');
        self::assertTrue($this->charge()['portraitEnAttente']);

        // … un autre membre, non.
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->creerMembre($cobalt, ArbreFamilial::MERE, 'Cobalt Cassis');
        $this->connexion('cobalt@example.net');
        $this->requete('GET', \sprintf('/api/medias/%d', $mediaId));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // L'administrateur la voit sur la carte.
        $this->connexion('admin@example.net');
        $this->requete('GET', '/api/administration/rattachements');
        $carte = $this->charge()['elements'][0];
        self::assertIsArray($carte['photo']);
        self::assertSame($mediaId, $carte['photo']['mediaId']);
        self::assertStringStartsWith(\sprintf('/api/medias/%d/fichier', $mediaId), $carte['photo']['url']);

        // Au rattachement, elle devient le portrait de la fiche.
        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), ['personId' => ArbreFamilial::ETRANGER]);
        self::assertResponseIsSuccessful();

        $media = $this->em()->find(Media::class, $mediaId);
        self::assertInstanceOf(Media::class, $media);
        self::assertSame(ArbreFamilial::ETRANGER, $media->getSubjectId());
        self::assertNull($media->getSubjectUserId());
        self::assertSame(MediaStatus::Actif, $media->getStatus());
        self::assertTrue($media->isActivePortrait());
        self::assertSame('Onyx Lointain', $media->getSubjectLabel());
    }

    /**
     * Une **recherche confirmée** en attente — le compte s'est trouvé — se valide depuis
     * l'écran Utilisateurs, pas depuis l'onglet « À rattacher ». Sa photo d'inscription
     * deviendra pourtant le portrait de la fiche à la validation : la file des membres la
     * porte donc, pour que l'administrateur la voie avant de cliquer « Valider ».
     */
    public function testLaFileDesMembresMontreLaPhotoQuiDeviendraLePortrait(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $compte = $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);
        $membre = $this->creerMembre($compte, ArbreFamilial::COUSIN, 'Marbre Romarin', MemberStatus::EnAttente, MatchKind::RechercheConfirmee);

        $this->connexion('cousin@example.net');
        $this->deposerPhoto();
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $mediaId = $this->charge()['id'];
        self::assertIsInt($mediaId);

        $this->connexion('admin@example.net');
        $this->requete('GET', '/api/administration/membres');
        self::assertResponseIsSuccessful();

        $element = $this->charge()['elements'][0];
        self::assertSame($membre->getId(), $element['id']);
        self::assertIsArray($element['portraitEnAttente']);
        self::assertSame($mediaId, $element['portraitEnAttente']['mediaId']);
        self::assertStringStartsWith(\sprintf('/api/medias/%d/vignette', $mediaId), $element['portraitEnAttente']['vignetteUrl']);

        // L'administrateur la voit…
        $this->requete('GET', $element['portraitEnAttente']['vignetteUrl']);
        self::assertResponseIsSuccessful();

        // … et, à la validation, elle devient le portrait de la fiche — le transfert reste
        // automatique : c'est ce qu'il vient de voir.
        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', (int) $membre->getId()));
        self::assertResponseIsSuccessful();

        $media = $this->em()->find(Media::class, $mediaId);
        self::assertInstanceOf(Media::class, $media);
        self::assertSame(ArbreFamilial::COUSIN, $media->getSubjectId());
        self::assertTrue($media->isActivePortrait());
    }

    /** Un second dépôt **remplace** le premier, fichier compris : un choix de photo qu'on refait. */
    public function testUnSecondDepotRemplaceLePremier(): void
    {
        $this->compteSansFiche();

        $this->connexion('lave@example.net');
        $this->deposerPhoto();
        $premier = $this->charge()['id'];
        self::assertIsInt($premier);
        $fichierDuPremier = $this->cheminDu($premier);
        self::assertFileExists($fichierDuPremier);

        $this->deposerPhoto(largeur: 150);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $em = $this->em();
        $em->clear();
        self::assertNull($em->find(Media::class, $premier));
        self::assertFileDoesNotExist($fichierDuPremier);
        self::assertCount(1, $em->getRepository(Media::class)->findAll());
    }

    /** Sans rattachement du tout — le compte d'amorçage —, `409 compte_non_rattache`, comme avant. */
    public function testUnCompteSansRattachementNeDeposePasDePhoto(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);

        $this->connexion('admin@example.net');
        $this->deposerPhoto();

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('compte_non_rattache', $this->charge()['erreur']['code']);
    }

    /**
     * **Refuser** : le compte est suspendu — il ne se connecte plus —, sa déclaration et sa
     * photo disparaissent, et la décision ne se prend qu'une fois.
     */
    public function testRefuserSuspendLeCompteEtPurgeTout(): void
    {
        $membreId = $this->compteSansFiche();

        $this->connexion('lave@example.net');
        $this->deposerPhoto();
        $mediaId = $this->charge()['id'];
        self::assertIsInt($mediaId);
        $fichier = $this->cheminDu($mediaId);

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $membreId));

        self::assertResponseIsSuccessful();
        $charge = $this->charge();
        self::assertSame('suspendu', $charge['status']);
        self::assertSame('a_rattacher', $charge['matchKind']);
        self::assertArrayNotHasKey('personId', $charge);

        $membre = $this->membre($membreId);
        self::assertSame(MemberStatus::Suspendu, $membre->getStatus());
        self::assertNull($membre->getFamilyDescription());
        self::assertNull($membre->getDeclaredSurname());
        self::assertSame(UserStatus::Suspendu, $this->utilisateur('lave@example.net')->getStatus());
        self::assertNull($this->em()->find(Media::class, $mediaId));
        self::assertFileDoesNotExist($fichier);

        // Le journal garde la décision, pas le contenu.
        $refus = $this->em()->getRepository(AuditLog::class)->findBy(['subjectId' => (string) $membreId]);
        $actions = array_map(static fn (AuditLog $l): string => $l->getAction()->value, $refus);
        self::assertContains('compte_suspendu', $actions);
        $this->aucunJournalNeContient(['Pyrite', '1998-04-12']);

        // Une seconde fois : la décision est déjà prise.
        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $membreId));
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('compte_deja_decide', $this->charge()['erreur']['code']);

        // Et le compte refusé ne se connecte plus.
        $this->connexion('lave@example.net');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Un refus ne se défait pas par une activation venue après — un autre administrateur,
     * un autre onglet resté ouvert sur la file. Avant : le compte refusé était rattaché et
     * réactivé, `core_user` compris, sans que personne le voie. Désormais `409
     * compte_deja_decide`, et le compte reste suspendu, sans fiche.
     */
    public function testUnCompteRefuseNeSActivePlus(): void
    {
        $membreId = $this->compteSansFiche();

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $membreId));
        self::assertResponseIsSuccessful();

        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), ['personId' => ArbreFamilial::ETRANGER]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('compte_deja_decide', $this->charge()['erreur']['code']);

        $this->em()->clear();
        $membre = $this->membre($membreId);
        self::assertSame(MemberStatus::Suspendu, $membre->getStatus());
        self::assertNull($membre->getPersonId());
        self::assertSame(UserStatus::Suspendu, $this->utilisateur('lave@example.net')->getStatus());
    }

    /**
     * La même course, **pendant** la requête : le `Member` est déjà chargé — périmé — quand
     * le refus arrive par une autre connexion (simulé ici par du SQL direct, sans
     * `em->clear()`). La relecture sous verrou le voit.
     */
    public function testUneActivationRelitLeCompteSousVerrou(): void
    {
        $membreId = $this->compteSansFiche();
        $administrateur = $this->utilisateur('admin@example.net');

        $service = self::getContainer()->get(\App\Module\Membership\Service\ServiceDesMembres::class);
        \assert($service instanceof \App\Module\Membership\Service\ServiceDesMembres);

        // Chargé dans l'identity map, `en_attente`…
        self::assertSame(MemberStatus::EnAttente, $this->em()->find(Member::class, $membreId)?->getStatus());

        // … puis refusé ailleurs.
        $this->em()->getConnection()->executeStatement(
            "UPDATE mbr_member SET status = 'suspendu', family_description = NULL, declared_surname = NULL,
             declared_given_names = NULL, declared_birth_date = NULL WHERE id = ?",
            [$membreId],
        );

        try {
            $service->activer($membreId, ['personId' => ArbreFamilial::ETRANGER], $administrateur);
            self::fail('Un compte refusé entre-temps ne doit pas être activé.');
        } catch (\App\Module\Core\Exception\ConflictException $e) {
            self::assertSame('compte_deja_decide', $e->codeErreur());
        }

        self::assertSame('suspendu', $this->em()->getConnection()->fetchOne('SELECT status FROM mbr_member WHERE id = ?', [$membreId]));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT person_id FROM mbr_member WHERE id = ?', [$membreId]));
    }

    /** Refuser une recherche confirmée qui s'est trompée de personne : la même décision. */
    public function testRefuserVautAussiPourUneRechercheConfirmee(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $compte = $this->creerUtilisateur('cousin@example.net', statut: UserStatus::EnAttente);
        $membre = $this->creerMembre($compte, ArbreFamilial::COUSIN, 'Marbre Romarin', MemberStatus::EnAttente, MatchKind::RechercheConfirmee);

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', (int) $membre->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('suspendu', $this->charge()['status']);
    }

    /**
     * Un refus ne se défait pas par le bouton générique « Réactiver » de l'écran
     * Utilisateurs. Avant : `core_user` repassait `actif` sans toucher `mbr_member` — un
     * compte sans fiche, au cercle vide, mais admis comme membre par `RegimeVoter` pour
     * écrire (démarches, tirages). Désormais `409`, et le compte reste dehors.
     */
    public function testUnRefusNeSeDefaitPasParLaReactivation(): void
    {
        $membreId = $this->compteSansFiche();
        $compte = $this->utilisateur('lave@example.net');

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $membreId));
        self::assertResponseIsSuccessful();

        $this->requete('PATCH', '/api/administration/utilisateurs/'.$compte->getId(), ['status' => 'actif']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('rattachement_non_etabli', $this->charge()['erreur']['code']);

        $this->em()->clear();
        self::assertSame(UserStatus::Suspendu, $this->utilisateur('lave@example.net')->getStatus());
        self::assertSame(MemberStatus::Suspendu, $this->membre($membreId)->getStatus());

        // Le compte refusé ne se connecte toujours pas : il n'écrit donc rien.
        $this->requete('POST', '/api/deconnexion');
        $this->connexion('lave@example.net');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * La même brèche, plus ancienne : un compte **en attente** suspendu puis « réactivé »
     * devenait `actif` côté `core_user` seulement. Le bon geste est l'activation, qui
     * passe les deux statuts ensemble — et elle fonctionne sur ce compte suspendu.
     */
    public function testUnCompteEnAttenteSuspenduNeSeReactivePasSansActivation(): void
    {
        $membreId = $this->compteSansFiche();
        $compte = $this->utilisateur('lave@example.net');
        $id = $compte->getId();

        $this->connexion('admin@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$id, ['status' => 'suspendu']);
        self::assertResponseIsSuccessful();

        $this->requete('PATCH', '/api/administration/utilisateurs/'.$id, ['status' => 'actif']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('rattachement_non_etabli', $this->charge()['erreur']['code']);

        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membreId), ['personId' => ArbreFamilial::ETRANGER]);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame(UserStatus::Actif, $this->utilisateur('lave@example.net')->getStatus());
    }

    /** Un membre actif suspendu par l'écran Utilisateurs se réactive, lui, comme avant. */
    public function testUnMembreActifSuspenduSeReactive(): void
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->creerMembre($cobalt, ArbreFamilial::MERE, 'Cobalt Cassis');

        $this->connexion('admin@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$cobalt->getId(), ['status' => 'suspendu']);
        self::assertResponseIsSuccessful();

        $this->requete('PATCH', '/api/administration/utilisateurs/'.$cobalt->getId(), ['status' => 'actif']);
        self::assertResponseIsSuccessful();
        self::assertSame('actif', $this->charge()['status']);
    }

    /**
     * Le contrat que consomme `Assistant` — éprouvé sur l'implémentation **réelle**, par sa
     * classe concrète : en test, l'interface est aliasée vers un double de ce module-là.
     */
    public function testLeContratComptesARattacher(): void
    {
        $membreId = $this->compteSansFiche();
        $contrat = $this->contrat();

        $declaration = $contrat->declaration($membreId);
        self::assertNotNull($declaration);
        self::assertSame('lave@example.net', $declaration->email);
        self::assertSame('Romarin', $declaration->surname);
        self::assertSame('1998-04-12', $declaration->birthDate->format('Y-m-d'));
        self::assertNull($declaration->suggestion);
        self::assertNull($contrat->declaration(999_999));

        $le = new \DateTimeImmutable('2026-10-01T10:00:00+02:00');
        $contrat->enregistrerLaSuggestion($membreId, ['verdict' => 'absente'], $le);
        $relue = $contrat->declaration($membreId);
        self::assertSame(['verdict' => 'absente'], $relue?->suggestion);
        self::assertSame($le->getTimestamp(), $relue->suggestionAt?->getTimestamp());

        self::assertFalse($contrat->estDejaRattachee(ArbreFamilial::ETRANGER));

        // Une fiche « tout juste créée » : absente de l'arbre en mémoire, et c'est le point —
        // la méthode ne relit pas l'arbre.
        $administrateur = $this->utilisateur('admin@example.net');
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $charge = $em->wrapInTransaction(
            static fn (): array => $contrat->rattacherAFicheCreee($membreId, 5000, 'Lave Romarin', $administrateur),
        );

        self::assertSame('actif', $charge['status']);
        self::assertSame('manuel_admin', $charge['matchKind']);
        self::assertTrue($contrat->estDejaRattachee(5000));
        self::assertNull($contrat->declaration($membreId), 'Un compte rattaché n\'est plus « à rattacher ».');

        $membre = $this->membre($membreId);
        self::assertSame(5000, $membre->getPersonId());
        self::assertSame('Lave Romarin', $membre->getPersonLabel());
        self::assertNull($membre->getFamilyDescription());
        self::assertSame(UserStatus::Actif, $this->utilisateur('lave@example.net')->getStatus());
    }

    public function testLeContratRefuseUnePersonneDejaReclamee(): void
    {
        $membreId = $this->compteSansFiche();
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $this->creerMembre($cobalt, ArbreFamilial::MERE, 'Cobalt Cassis');

        $contrat = $this->contrat();
        $administrateur = $this->utilisateur('admin@example.net');

        // Dans une transaction, comme le contrat l'exige : la relecture sous verrou n'a pas
        // de sens hors d'elle, et Doctrine le refuse.
        $this->expectException(\App\Module\Core\Exception\ConflictException::class);
        $this->em()->wrapInTransaction(
            static fn (): array => $contrat->rattacherAFicheCreee($membreId, ArbreFamilial::MERE, 'Cobalt Cassis', $administrateur),
        );
    }

    /** Le premier pas de « Créer la fiche et rattacher » : un compte refusé ne passe pas le verrou. */
    public function testLeContratRefuseDeVerrouillerUnCompteRefuse(): void
    {
        $membreId = $this->compteSansFiche();

        $this->connexion('admin@example.net');
        $this->requete('POST', \sprintf('/api/administration/membres/%d/refuser', $membreId));
        self::assertResponseIsSuccessful();

        $contrat = $this->contrat();

        try {
            $this->em()->wrapInTransaction(static fn () => $contrat->verrouillerPourLeRattachement($membreId));
            self::fail('Un compte refusé ne doit pas passer le verrou.');
        } catch (\App\Module\Core\Exception\ConflictException $e) {
            self::assertSame('compte_deja_decide', $e->codeErreur());
        }
    }

    // ── Aides ───────────────────────────────────────────────────────────────────────

    /**
     * L'implémentation réelle, construite à la main : en test, l'interface est aliasée vers
     * le double commutable du module `Assistant` (qui délègue à cette classe par défaut).
     * Construire la classe concrète ici éprouve le contrat sans dépendre du mode dans
     * lequel un test précédent a laissé le double.
     */
    private function contrat(): ComptesARattacherParMembership
    {
        $conteneur = self::getContainer();
        $em = $conteneur->get('doctrine')->getManager();
        $membres = $conteneur->get(\App\Module\Membership\Repository\MemberRepository::class);
        $service = $conteneur->get(\App\Module\Membership\Service\ServiceDesMembres::class);
        \assert($em instanceof EntityManagerInterface);
        \assert($membres instanceof \App\Module\Membership\Repository\MemberRepository);
        \assert($service instanceof \App\Module\Membership\Service\ServiceDesMembres);

        return new ComptesARattacherParMembership($em, $membres, $service);
    }

    /** Un administrateur et un compte `a_rattacher` ; rend l'identifiant du membre. */
    private function compteSansFiche(): int
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $compte = $this->creerUtilisateur('lave@example.net', statut: UserStatus::EnAttente);
        $membre = $this->creerMembreSansFiche($compte, description: self::DESCRIPTION);

        $id = $membre->getId();
        \assert(null !== $id);

        return $id;
    }

    private function deposerPhoto(int $largeur = 120): void
    {
        $chemin = self::racineDesMedias().'/entree-'.bin2hex(random_bytes(6)).'.jpg';

        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0o777, true);
        }

        file_put_contents($chemin, FabriqueDeFichiers::jpegNu($largeur, 90));

        $this->client->request(
            'POST',
            '/api/medias/portrait-du-compte',
            files: ['fichier' => new UploadedFile($chemin, 'moi.jpg', 'image/jpeg', null, true)],
            server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.9.9.9'],
        );
    }

    private function cheminDu(int $mediaId): string
    {
        $media = $this->em()->find(Media::class, $mediaId);
        self::assertInstanceOf(Media::class, $media);

        return self::racineDesMedias().'/'.$this->sousChemin($media->getStorageName());
    }

    /**
     * Le chemin relatif d'un nom de stockage, tel que `StockageMedia` le range. Relu par
     * recherche plutôt que recopié : la répartition en sous-dossiers est l'affaire de `Media`.
     */
    private function sousChemin(string $nom): string
    {
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::racineDesMedias(), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterateur as $fichier) {
            if ($fichier instanceof \SplFileInfo && $fichier->getFilename() === $nom) {
                return substr(str_replace('\\', '/', $fichier->getPathname()), \strlen(self::racineDesMedias()) + 1);
            }
        }

        self::fail(\sprintf('Fichier « %s » introuvable sous la racine des médias.', $nom));
    }

    private function membre(int $membreId): Member
    {
        $em = $this->em();
        $em->clear();
        $membre = $em->find(Member::class, $membreId);
        self::assertInstanceOf(Member::class, $membre);

        return $membre;
    }

    private function utilisateur(string $courriel): User
    {
        $depot = self::getContainer()->get(UserRepository::class);
        \assert($depot instanceof UserRepository);
        $utilisateur = $depot->findOneBy(['email' => $courriel]);
        self::assertInstanceOf(User::class, $utilisateur);

        return $utilisateur;
    }

    /** @param list<string> $fragments */
    private function aucunJournalNeContient(array $fragments): void
    {
        foreach ($this->em()->getRepository(AuditLog::class)->findAll() as $ligne) {
            $texte = json_encode([$ligne->getSubjectLabel(), $ligne->getChanges()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

            foreach ($fragments as $fragment) {
                self::assertStringNotContainsString($fragment, $texte, \sprintf('Le journal « %s » cite la déclaration.', $ligne->getAction()->value));
            }
        }
    }

    private function unJournalMasque(string $champ): bool
    {
        foreach ($this->em()->getRepository(AuditLog::class)->findAll() as $ligne) {
            if ('***' === ($ligne->getChanges()[$champ] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function racineDesMedias(): string
    {
        return \dirname(__DIR__, 2).'/var/test-medias-mbr-'.getmypid();
    }

    private static function effacer(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterateur as $entree) {
            if ($entree instanceof \SplFileInfo) {
                $entree->isDir() ? @rmdir($entree->getPathname()) : @unlink($entree->getPathname());
            }
        }

        @rmdir($dossier);
    }
}
