<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Service\LimiteurDesOptionsDeConnexion;
use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\Passkey;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\PasskeyRepository;
use App\Module\Core\Service\PreuveDIdentiteRecente;
use App\Tests\Core\Double\AuthentificateurSimule;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les **clés d'accès** (passkeys) — § 2.4 du contrat d'API, en vrai HTTP et avec de
 * vraies signatures.
 *
 * Aucun contrôle n'est doublé : {@see AuthentificateurSimule} fabrique des réponses
 * WebAuthn authentiques (clé P-256, CBOR, ECDSA) et la bibliothèque serveur les vérifie
 * comme elle vérifierait celles d'un iPhone. Ce que ces tests attestent, c'est donc la
 * chaîne entière : défi en session, cérémonie, table, pare-feu, limiteur, journal.
 *
 * Le temps est piloté par l'horloge globale de `symfony/clock`
 * ({@see Clock::set()}) — c'est elle que sert le service `clock`, donc
 * `Core\Service\ClesDAcces`. Elle est remise à l'heure après chaque test.
 */
final class ClesDAccesTest extends CoreWebTestCase
{
    private const string COBALT = 'cobalt@example.net';
    private const string JASPE = 'jaspe@example.net';

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    //  Enregistrement
    // -------------------------------------------------------------------------

    public function testLesOptionsDEnregistrementFixentLaPolitique(): void
    {
        $this->creerUtilisateur(self::COBALT, nomAffiche: 'Cobalt Romarin');
        $this->connexion(self::COBALT);

        $options = $this->optionsDEnregistrement();

        self::assertSame(['id' => 'localhost', 'name' => 'Heritage'], $options['rp']);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertTrue($options['authenticatorSelection']['requireResidentKey']);
        self::assertSame('none', $options['attestation']);
        self::assertSame(300000, $options['timeout']);
        self::assertContains(-7, array_column($options['pubKeyCredParams'], 'alg'));
        self::assertSame([], $options['excludeCredentials']);

        // Un défi de 32 octets, en base64url sans remplissage.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $options['challenge']);

        // `user.id` est opaque : ni le courriel, ni l'identifiant en clair.
        self::assertSame(self::COBALT, $options['user']['name']);
        self::assertSame('Cobalt Romarin', $options['user']['displayName']);
        self::assertStringNotContainsString('cobalt', base64_decode(strtr($options['user']['id'], '-_', '+/'), true) ?: '');
    }

    public function testUnAnonymeNObtientPasDOptionsDEnregistrement(): void
    {
        $this->requete('POST', '/api/cles-d-acces/options-d-enregistrement');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testEnregistrerPuisListerUneCle(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $appareil = new AuthentificateurSimule();

        $this->requete('POST', '/api/cles-d-acces', ['reponse' => $appareil->creer($this->optionsDEnregistrement())]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $cle = $this->charge();
        self::assertIsInt($cle['id']);
        // Sans nom donné : celui de l'authentificateur, reconnu par son AAGUID.
        self::assertSame('Trousseau iCloud', $cle['nom']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $cle['creeeLe']));
        self::assertNull($cle['derniereUtilisation']);
        self::assertArrayNotHasKey('publicKey', $cle);
        self::assertArrayNotHasKey('credentialId', $cle);

        $this->requete('GET', '/api/cles-d-acces');
        self::assertResponseIsSuccessful();
        $liste = $this->liste();
        self::assertCount(1, $liste);
        self::assertSame($cle['id'], $liste[0]['id']);
        self::assertSame('Trousseau iCloud', $liste[0]['appareil']);

        // En base : la moitié publique, le compteur, les transports.
        $ligne = $this->cles()->parCredentialId($appareil->credentialId());
        self::assertNotNull($ligne);
        self::assertSame(['internal', 'hybrid'], $ligne->getTransports());
        self::assertSame(AuthentificateurSimule::AAGUID_TROUSSEAU_ICLOUD, $ligne->getAaguid());
    }

    public function testLeNomDonneEstGardeEtLesClesExistantesSontExclues(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $appareil = new AuthentificateurSimule();

        $this->requete('POST', '/api/cles-d-acces', [
            'reponse' => $appareil->creer($this->optionsDEnregistrement()),
            'nom' => '  Téléphone   de Cobalt ',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('Téléphone de Cobalt', $this->charge()['nom']);

        $exclues = $this->optionsDEnregistrement()['excludeCredentials'];
        self::assertSame([[
            'type' => 'public-key',
            'id' => $appareil->credentialId(),
            'transports' => ['internal', 'hybrid'],
        ]], $exclues);
    }

    public function testUnDefiDEnregistrementNeSertQuUneFois(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $reponse = (new AuthentificateurSimule())->creer($this->optionsDEnregistrement());

        $this->requete('POST', '/api/cles-d-acces', ['reponse' => $reponse]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->requete('POST', '/api/cles-d-acces', ['reponse' => $reponse]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('donnees_invalides', $this->charge()['erreur']['code']);
        self::assertCount(1, $this->cles()->findAll());
    }

    public function testUnDefiDEnregistrementExpireApresCinqMinutes(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $horloge = new MockClock();
        Clock::set($horloge);

        $reponse = (new AuthentificateurSimule())->creer($this->optionsDEnregistrement());
        $horloge->sleep(301);

        $this->requete('POST', '/api/cles-d-acces', ['reponse' => $reponse]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->cles()->findAll());
    }

    public function testUneReponseVenueDUneAutreOrigineEstRefusee(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);

        $reponse = (new AuthentificateurSimule())->creer($this->optionsDEnregistrement(), origine: 'https://heritage.attaquant.example');
        $this->requete('POST', '/api/cles-d-acces', ['reponse' => $reponse]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testUneCleSansVerificationDeLUtilisateurEstRefusee(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);

        $reponse = (new AuthentificateurSimule())->creer($this->optionsDEnregistrement(), verificationUtilisateur: false);
        $this->requete('POST', '/api/cles-d-acces', ['reponse' => $reponse]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testUnCompteEnAttentePeutEnregistrerUneCle(): void
    {
        $this->creerUtilisateur(self::COBALT, statut: UserStatus::EnAttente);
        $this->connexion(self::COBALT);
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/cles-d-acces', ['reponse' => (new AuthentificateurSimule())->creer($this->optionsDEnregistrement())]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testAucuneCleNeSEnregistrePendantUneIncarnation(): void
    {
        $this->creerUtilisateur('admin@example.net', roles: ['ROLE_ADMIN']);
        $jaspe = $this->creerUtilisateur(self::JASPE);
        $this->connexion('admin@example.net');

        $this->requete('POST', '/api/administration/incarnation', ['userId' => $jaspe->getId()]);
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/cles-d-acces/options-d-enregistrement');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('incarnation_active', $this->charge()['erreur']['code']);

        $this->requete('POST', '/api/cles-d-acces', ['reponse' => ['id' => 'x']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    // -------------------------------------------------------------------------
    //  Gestion : renommer, supprimer, isolation
    // -------------------------------------------------------------------------

    public function testRenommerPuisSupprimerSaCle(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $id = $this->enregistrer(new AuthentificateurSimule());

        $this->requete('PATCH', '/api/cles-d-acces/'.$id, ['nom' => 'Ordinateur du bureau']);
        self::assertResponseIsSuccessful();
        self::assertSame('Ordinateur du bureau', $this->charge()['nom']);

        $this->requete('PATCH', '/api/cles-d-acces/'.$id, ['nom' => '   ']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->requete('PATCH', '/api/cles-d-acces/'.$id, ['nom' => str_repeat('a', 81)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->requete('DELETE', '/api/cles-d-acces/'.$id);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->requete('GET', '/api/cles-d-acces');
        self::assertSame([], $this->charge());
    }

    public function testLesClesDUnAutreCompteNExistentPas(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->creerUtilisateur(self::JASPE);
        $this->connexion(self::COBALT);
        $id = $this->enregistrer(new AuthentificateurSimule());

        $this->client->getCookieJar()->clear();
        $this->connexion(self::JASPE);

        $this->requete('GET', '/api/cles-d-acces');
        self::assertSame([], $this->charge());

        $this->requete('PATCH', '/api/cles-d-acces/'.$id, ['nom' => 'À moi']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->requete('DELETE', '/api/cles-d-acces/'.$id);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertCount(1, $this->cles()->findAll());
    }

    public function testLaSuppressionDuCompteEmporteSesCles(): void
    {
        $cobalt = $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $this->enregistrer(new AuthentificateurSimule());

        $em = $this->em();
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $em->getConnection()->executeStatement('DELETE FROM core_user WHERE id = ?', [$cobalt->getId()]);

        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM core_passkey'));
    }

    // -------------------------------------------------------------------------
    //  Connexion
    // -------------------------------------------------------------------------

    public function testLesOptionsDeConnexionSontPubliquesEtSansListeDeCles(): void
    {
        $this->requete('POST', '/api/connexion/cle-d-acces/options');

        self::assertResponseIsSuccessful();
        $options = $this->charge()['options'];
        self::assertSame('localhost', $options['rpId']);
        self::assertSame('required', $options['userVerification']);
        self::assertSame([], $options['allowCredentials']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $options['challenge']);
    }

    /**
     * La route d'options est publique et écrit une session (le défi) : sans plafond, une
     * boucle anonyme remplirait `var/sessions`. Au-delà du plafond horaire par IP : `429`.
     */
    public function testLesOptionsDeConnexionSontPlafonneesParAdresse(): void
    {
        for ($i = 0; $i < LimiteurDesOptionsDeConnexion::REQUETES_PAR_IP; ++$i) {
            $this->requete('POST', '/api/connexion/cle-d-acces/options');
            self::assertResponseIsSuccessful();
        }

        $this->requete('POST', '/api/connexion/cle-d-acces/options');
        self::assertResponseStatusCodeSame(429);
        self::assertSame('trop_de_tentatives', $this->charge()['erreur']['code']);
    }

    public function testSeConnecterAvecUneCleRendLaChargeDeLaConnexionParMotDePasse(): void
    {
        $this->creerUtilisateur(self::COBALT, nomAffiche: 'Cobalt Romarin');
        $this->connexion(self::COBALT);
        $chargeParMotDePasse = $this->charge();
        $appareil = new AuthentificateurSimule();
        $this->enregistrer($appareil);

        $this->client->getCookieJar()->clear();
        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion())]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        // La même charge, champ pour champ — sauf l'heure de la connexion, qui est celle
        // de **cette** connexion-ci : elle tombe une seconde plus tard dès que
        // l'enregistrement (courriel d'avis compris) chevauche une seconde.
        $chargeParCle = $this->charge();
        self::assertIsString($chargeParCle['utilisateur']['lastLoginAt']);
        unset($chargeParMotDePasse['utilisateur']['lastLoginAt'], $chargeParCle['utilisateur']['lastLoginAt']);
        self::assertSame($chargeParMotDePasse, $chargeParCle);

        // La session est ouverte.
        $this->requete('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertSame(self::COBALT, $this->charge()['utilisateur']['email']);

        // La clé est datée, son compteur avancé.
        $cle = $this->cles()->parCredentialId($appareil->credentialId());
        self::assertNotNull($cle);
        self::assertNotNull($cle->getLastUsedAt());
        self::assertSame(1, $cle->getSignCount());

        $this->requete('GET', '/api/cles-d-acces');
        self::assertNotNull($this->liste()[0]['derniereUtilisation']);
    }

    public function testUnDefiDeConnexionNeSertQuUneFois(): void
    {
        [$appareil] = $this->compteAvecUneCle();

        $reponse = $appareil->obtenir($this->optionsDeConnexion());
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $reponse]);
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $reponse]);
        $this->assertEchecIndistinct();
    }

    public function testPlusieursDefisDeConnexionCoexistent(): void
    {
        [$appareil] = $this->compteAvecUneCle();

        // L'écran de connexion pose un défi pour l'autoremplissage, puis un second au clic.
        $premieres = $this->optionsDeConnexion();
        $this->optionsDeConnexion();

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($premieres)]);
        self::assertResponseIsSuccessful();
    }

    public function testUnDefiDeConnexionExpireApresCinqMinutes(): void
    {
        [$appareil] = $this->compteAvecUneCle();
        $horloge = new MockClock();
        Clock::set($horloge);

        $options = $this->optionsDeConnexion();
        $horloge->sleep(301);

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($options)]);
        $this->assertEchecIndistinct();
    }

    public function testUneCleInconnueRendLeMemeEchecQueLeMotDePasse(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->requete('POST', '/api/connexion', ['email' => self::COBALT, 'password' => 'faux']);
        $echecParMotDePasse = $this->charge();
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $inconnu = new AuthentificateurSimule();
        $inconnu->creer($this->optionsFactices());

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $inconnu->obtenir($this->optionsDeConnexion())]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame($echecParMotDePasse, $this->charge());
    }

    public function testUnCorpsInexploitableRendLeMemeEchec(): void
    {
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => 'pas une réponse']);
        $this->assertEchecIndistinct();

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => ['id' => 'abc', 'response' => ['clientDataJSON' => '%%%']]]);
        $this->assertEchecIndistinct();
    }

    public function testUneSignatureFausseEstRefusee(): void
    {
        [$appareil] = $this->compteAvecUneCle();

        $this->requete('POST', '/api/connexion/cle-d-acces', [
            'reponse' => $appareil->obtenir($this->optionsDeConnexion(), signatureCorrompue: true),
        ]);

        $this->assertEchecIndistinct();
    }

    public function testUneConnexionSansVerificationDeLUtilisateurEstRefusee(): void
    {
        [$appareil] = $this->compteAvecUneCle();

        $this->requete('POST', '/api/connexion/cle-d-acces', [
            'reponse' => $appareil->obtenir($this->optionsDeConnexion(), verificationUtilisateur: false),
        ]);

        $this->assertEchecIndistinct();
    }

    public function testUnCompteurQuiReculeTrahitUnCloneEtEstRefuse(): void
    {
        [$appareil] = $this->compteAvecUneCle();

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion(), compteur: 7)]);
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion(), compteur: 3)]);
        $this->assertEchecIndistinct();

        $cle = $this->cles()->parCredentialId($appareil->credentialId());
        self::assertNotNull($cle);
        self::assertSame(7, $cle->getSignCount(), 'Le compteur enregistré ne recule pas.');
    }

    public function testUnCompteSuspenduEstRefuseCommeAuMotDePasse(): void
    {
        [$appareil, $compte] = $this->compteAvecUneCle();
        $compte = $this->em()->find($compte::class, $compte->getId());
        self::assertNotNull($compte);
        $compte->setStatus(UserStatus::Suspendu);
        $this->em()->flush();

        $this->requete('POST', '/api/connexion', ['email' => self::COBALT, 'password' => 'motdepasse-de-test']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $refusParMotDePasse = $this->charge();
        self::assertSame('compte_suspendu', $refusParMotDePasse['erreur']['code']);

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion())]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame($refusParMotDePasse, $this->charge());

        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLaCleEtLeMotDePasseSeCumulentDansLeLimiteur(): void
    {
        [$appareil] = $this->compteAvecUneCle();

        // Cinq mots de passe faux : le plafond du couple courriel + IP est atteint…
        for ($i = 0; $i < 5; ++$i) {
            $this->requete('POST', '/api/connexion', ['email' => self::COBALT, 'password' => 'faux']);
        }

        // … et la clé d'accès du même compte, pourtant valide, bute dessus.
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion())]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('trop_de_tentatives', $this->charge()['erreur']['code']);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    public function testLesClesInconnuesSontPlafonneesElles(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $inconnu = new AuthentificateurSimule();
            $inconnu->creer($this->optionsFactices());
            $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $inconnu->obtenir($this->optionsDeConnexion())]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        $inconnu = new AuthentificateurSimule();
        $inconnu->creer($this->optionsFactices());
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $inconnu->obtenir($this->optionsDeConnexion())]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    // -------------------------------------------------------------------------
    //  Journal d'audit
    // -------------------------------------------------------------------------

    public function testLaConnexionParCleEstJournaliseeAvecSaMethode(): void
    {
        [$appareil, $compte] = $this->compteAvecUneCle();

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion(), signatureCorrompue: true)]);
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion())]);
        self::assertResponseIsSuccessful();

        $connexions = $this->lignes(AuditAction::Connexion);
        self::assertCount(2, $connexions, 'Une par mot de passe (mise en place), une par clé.');
        self::assertSame(['methode' => 'mot_de_passe'], $connexions[0]->getChanges());
        self::assertSame(['methode' => 'cle_d_acces'], $connexions[1]->getChanges());
        self::assertSame($compte->getId(), $connexions[1]->getActorUserId());
        self::assertSame(self::COBALT, $connexions[1]->getActorEmail());

        $echecs = $this->lignes(AuditAction::ConnexionEchouee);
        self::assertCount(1, $echecs);
        self::assertSame(['methode' => 'cle_d_acces'], $echecs[0]->getChanges());
        self::assertNull($echecs[0]->getActorUserId());

        // Avancer le compteur et dater la clé ne laisse pas de ligne « modification ».
        foreach ($this->lignes(AuditAction::Modification) as $ligne) {
            self::assertNotSame('Core\Passkey', $ligne->getSubjectType());
        }

        // Aucune ligne ne porte de matériel de clé.
        $cle = $this->cles()->parCredentialId($appareil->credentialId());
        self::assertNotNull($cle);
        foreach ($this->journal() as $ligne) {
            $json = json_encode($ligne->getChanges(), \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($cle->getPublicKey(), $json);
            self::assertStringNotContainsString($cle->getCredentialId(), $json);
        }
    }

    public function testLEnregistrementEtLaSuppressionSontJournalises(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $id = $this->enregistrer(new AuthentificateurSimule());
        $this->requete('DELETE', '/api/cles-d-acces/'.$id);

        $creations = array_values(array_filter(
            $this->lignes(AuditAction::Creation),
            static fn (AuditLog $l): bool => 'Core\Passkey' === $l->getSubjectType(),
        ));
        $suppressions = array_values(array_filter(
            $this->lignes(AuditAction::Suppression),
            static fn (AuditLog $l): bool => 'Core\Passkey' === $l->getSubjectType(),
        ));

        self::assertCount(1, $creations);
        self::assertCount(1, $suppressions);
    }

    // -------------------------------------------------------------------------
    //  Une session ne suffit pas : preuve récente, avis, révocation
    // -------------------------------------------------------------------------

    public function testUneConnexionAncienneExigeLeMotDePasseActuel(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $horloge = new MockClock();
        Clock::set($horloge);
        // Onze minutes après la connexion : l'onglet oublié, la session volée.
        $horloge->sleep(PreuveDIdentiteRecente::FRAICHEUR + 60);

        $this->requete('POST', '/api/cles-d-acces/options-d-enregistrement');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey('motDePasseActuel', $this->charge()['erreur']['champs']);

        // `422` et non `401` : la session reste ouverte.
        $this->requete('POST', '/api/cles-d-acces/options-d-enregistrement', ['motDePasseActuel' => 'pas-le-bon']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('Le mot de passe actuel est incorrect.', $this->charge()['erreur']['champs']['motDePasseActuel']);

        $this->requete('POST', '/api/cles-d-acces/options-d-enregistrement', ['motDePasseActuel' => 'motdepasse-de-test']);
        self::assertResponseIsSuccessful();

        // Le mot de passe vaut preuve fraîche : l'essai suivant (Face ID annulé) passe sans.
        $appareil = new AuthentificateurSimule();
        $this->enregistrer($appareil);
        self::assertCount(1, $this->cles()->findAll());
    }

    public function testSansPreuveAucunDefiNEstEmisDoncAucuneCleNEntre(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $horloge = new MockClock();
        Clock::set($horloge);
        $horloge->sleep(PreuveDIdentiteRecente::FRAICHEUR + 1);

        $this->requete('POST', '/api/cles-d-acces/options-d-enregistrement');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // Une réponse fabriquée sur des options factices ne trouve aucun défi en session.
        $this->requete('POST', '/api/cles-d-acces', ['reponse' => (new AuthentificateurSimule())->creer($this->optionsFactices())]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->cles()->findAll());
    }

    public function testUneConnexionParCleVautPreuveRecente(): void
    {
        [$appareil] = $this->compteAvecUneCle();
        $horloge = new MockClock();
        Clock::set($horloge);
        $horloge->sleep(PreuveDIdentiteRecente::FRAICHEUR + 60);

        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion())]);
        self::assertResponseIsSuccessful();

        $this->requete('POST', '/api/cles-d-acces/options-d-enregistrement');
        self::assertResponseIsSuccessful();
    }

    public function testChaqueAjoutEstAnnonceParCourrielAuTitulaire(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);

        $this->requete('POST', '/api/cles-d-acces', [
            'reponse' => (new AuthentificateurSimule())->creer($this->optionsDEnregistrement()),
            'nom' => 'Téléphone',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertEmailCount(1);
        $message = self::getMailerMessage(0);
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'to', self::COBALT);
        self::assertEmailHeaderSame($message, 'subject', 'Une clé d’accès a été ajoutée à votre compte Heritage');
        self::assertEmailTextBodyContains($message, '« Téléphone »');
    }

    public function testChangerSonMotDePasseRetireToutesSesCles(): void
    {
        [$appareil] = $this->compteAvecUneCle();
        $this->connexion(self::COBALT);

        $this->requete('POST', '/api/session/mot-de-passe', [
            'motDePasseActuel' => 'motdepasse-de-test',
            'nouveauMotDePasse' => 'un-tout-nouveau-mot-de-passe',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(['motDePasseChange' => true, 'clesDAccesRetirees' => 1], $this->charge());
        self::assertSame([], $this->cles()->findAll());

        // La clé plantée ne rouvre plus rien…
        $this->client->getCookieJar()->clear();
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion())]);
        $this->assertEchecIndistinct();
    }

    public function testApresUnChangementDeMotDePasseOnReenregistreSansLeRedemander(): void
    {
        $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $horloge = new MockClock();
        Clock::set($horloge);
        $horloge->sleep(PreuveDIdentiteRecente::FRAICHEUR + 60);

        $this->requete('POST', '/api/session/mot-de-passe', [
            'motDePasseActuel' => 'motdepasse-de-test',
            'nouveauMotDePasse' => 'un-tout-nouveau-mot-de-passe',
        ]);
        self::assertResponseIsSuccessful();

        $this->enregistrer(new AuthentificateurSimule());
    }

    public function testLaReinitialisationParLAdministrateurRetireLesCles(): void
    {
        [$appareil, $cobalt] = $this->compteAvecUneCle();
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('natron@example.net');

        $this->requete('POST', '/api/administration/utilisateurs/'.$cobalt->getId().'/mot-de-passe');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->cles()->findAll());

        $this->client->getCookieJar()->clear();
        $this->requete('POST', '/api/connexion/cle-d-acces', ['reponse' => $appareil->obtenir($this->optionsDeConnexion())]);
        $this->assertEchecIndistinct();
    }

    // -------------------------------------------------------------------------
    //  Aides
    // -------------------------------------------------------------------------

    /**
     * La réponse de `GET /api/cles-d-acces` : une liste, pas un objet.
     *
     * @return list<array<string, mixed>>
     */
    private function liste(): array
    {
        $liste = $this->charge();
        self::assertTrue(array_is_list($liste), 'Une liste JSON est attendue.');

        /* @var list<array<string, mixed>> $liste */
        return $liste;
    }

    /** @return array<string, mixed> */
    private function optionsDEnregistrement(): array
    {
        $this->requete('POST', '/api/cles-d-acces/options-d-enregistrement');
        self::assertResponseIsSuccessful();

        $options = $this->charge()['options'];
        self::assertIsArray($options);

        /* @var array<string, mixed> $options */
        return $options;
    }

    /** @return array<string, mixed> */
    private function optionsDeConnexion(): array
    {
        $this->requete('POST', '/api/connexion/cle-d-acces/options');
        self::assertResponseIsSuccessful();

        $options = $this->charge()['options'];
        self::assertIsArray($options);

        /* @var array<string, mixed> $options */
        return $options;
    }

    /**
     * Des options d'enregistrement fabriquées hors du serveur : pour une clé que la base
     * ne connaîtra jamais.
     *
     * @return array<string, mixed>
     */
    private function optionsFactices(): array
    {
        return [
            'rp' => ['name' => 'Heritage', 'id' => 'localhost'],
            'user' => ['id' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'name' => 'x', 'displayName' => 'x'],
            'challenge' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
        ];
    }

    private function enregistrer(AuthentificateurSimule $appareil): int
    {
        $this->requete('POST', '/api/cles-d-acces', ['reponse' => $appareil->creer($this->optionsDEnregistrement())]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $id = $this->charge()['id'];
        self::assertIsInt($id);

        return $id;
    }

    /**
     * Un compte avec une clé, **déconnecté** : prêt pour une connexion par clé.
     *
     * @return array{0: AuthentificateurSimule, 1: \App\Module\Core\Entity\User}
     */
    private function compteAvecUneCle(): array
    {
        $compte = $this->creerUtilisateur(self::COBALT);
        $this->connexion(self::COBALT);
        $appareil = new AuthentificateurSimule();
        $this->enregistrer($appareil);
        $this->client->getCookieJar()->clear();

        return [$appareil, $compte];
    }

    /** Le `401` de la connexion par mot de passe, mot pour mot. */
    private function assertEchecIndistinct(): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame([
            'erreur' => [
                'code' => 'identifiants_invalides',
                'message' => 'Courriel ou mot de passe incorrect.',
                'champs' => null,
                'details' => null,
            ],
        ], $this->charge());
    }

    private function cles(): PasskeyRepository
    {
        $depot = $this->em()->getRepository(Passkey::class);
        \assert($depot instanceof PasskeyRepository);
        $this->em()->clear();

        return $depot;
    }

    /** @return list<AuditLog> */
    private function lignes(AuditAction $action): array
    {
        return array_values(array_filter($this->journal(), static fn (AuditLog $l): bool => $action === $l->getAction()));
    }
}
