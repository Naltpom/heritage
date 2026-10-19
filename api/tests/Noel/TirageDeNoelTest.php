<?php

declare(strict_types=1);

namespace App\Tests\Noel;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Service\ConnexionGoogle;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Noel\Entity\TirageNoel;
use App\Module\Noel\Service\ServiceDesTiragesNoel;
use App\Module\Tree\Serializer\ParenteDuLecteur;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Tree\ArbreWebTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le site du tirage de Noël, de bout en bout : l'inscription publique (compte en attente,
 * membre à rattacher), la création d'un tirage, les réponses, le tirage — en avance ou à
 * l'échéance —, son verrouillage, et ce que chacun découvre ensuite.
 */
final class TirageDeNoelTest extends ArbreWebTestCase
{
    private const string MOT_DE_PASSE = 'un-mot-de-passe-de-noel';

    protected function setUp(): void
    {
        parent::setUp();

        $pool = self::getContainer()->get('cache.rate_limiter');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }
    }

    public function testLInscriptionCreeUnCompteEnAttenteARattacherEtOuvreLaSession(): void
    {
        $this->inscrire('papa@example.invalid', 'Martin', 'Paul', '1960-05-12');

        self::assertSame(Response::HTTP_CREATED, $this->statut(), $this->corps());
        self::assertSame('en_attente', $this->charge()['utilisateur']['status'] ?? null);

        $compte = $this->compte('papa@example.invalid');
        self::assertSame(UserStatus::EnAttente, $compte->getStatus());
        self::assertSame('06 12 34 56 78', $compte->getTelephone());

        $membre = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $compte->getId()]);
        self::assertNotNull($membre);
        self::assertSame(MatchKind::ARattacher, $membre->getMatchKind());
        self::assertSame('Martin', $membre->getDeclaredSurname());
        self::assertSame('Paul', $membre->getDeclaredGivenNames());
        self::assertSame('1960-05-12', $membre->getDeclaredBirthDate()?->format('Y-m-d'));

        // La session est ouverte : le site lit le profil.
        $this->requete('/api/noel/moi');
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame(['Paul', 'Martin'], [$this->charge()['prenom'], $this->charge()['nom']]);
    }

    public function testLInscriptionQuiDesigneUneFicheSansAmbiguiteLaRattacheEtActiveLeCompte(): void
    {
        ArbreEnMemoire::reinitialiser();
        ArbreEnMemoire::ajouterPersonne(501, 'Paul Émile', 'Martin', true, 1960, '12.5.1960');
        ArbreEnMemoire::ajouterPersonne(502, 'Léa', 'Martin', true, 1990, '1990');
        ArbreEnMemoire::ajouterPersonne(503, 'Hugo', 'Durand', true, 2015, '25.12.2015');
        ArbreEnMemoire::ajouterPersonne(504, 'Hugo', 'Durand', true, 2015, '25.12.2015');

        // Prénom d'usage, casse et accents libres : rattaché à 501, actif d'emblée.
        $this->inscrire('papa@example.invalid', 'MARTIN', 'paul', '1960-05-12');
        self::assertSame(Response::HTTP_CREATED, $this->statut(), $this->corps());
        self::assertSame('actif', $this->charge()['utilisateur']['status'] ?? null);
        $membre = $this->membreDe('papa@example.invalid');
        self::assertSame([501, MatchKind::RechercheConfirmee, 'actif'], [$membre->getPersonId(), $membre->getMatchKind(), $membre->getStatus()->value]);
        self::assertSame(UserStatus::Actif, $this->compte('papa@example.invalid')->getStatus());

        // La même fiche ne se prend pas deux fois.
        $this->deconnexion();
        $this->inscrire('usurpateur@example.invalid', 'Martin', 'Paul', '1960-05-12');
        self::assertSame('en_attente', $this->charge()['utilisateur']['status'] ?? null);
        self::assertNull($this->membreDe('usurpateur@example.invalid')->getPersonId());

        // Une fiche datée à l'année seulement, ou deux fiches qui correspondent : à rattacher.
        $this->deconnexion();
        $this->inscrire('fille@example.invalid', 'Martin', 'Léa', '1990-07-08');
        self::assertSame('en_attente', $this->charge()['utilisateur']['status'] ?? null);
        $this->deconnexion();
        $this->inscrire('fils@example.invalid', 'Durand', 'Hugo', '2015-12-25');
        self::assertSame('en_attente', $this->charge()['utilisateur']['status'] ?? null);
        self::assertSame(MatchKind::ARattacher, $this->membreDe('fils@example.invalid')->getMatchKind());
    }

    public function testLInscriptionRefuseUnCourrielDejaPrisEtDesChampsInvalides(): void
    {
        $this->inscrire('papa@example.invalid', 'Martin', 'Paul', '1960-05-12');
        $this->deconnexion();

        $this->inscrire('papa@example.invalid', 'Martin', 'Pierre', '1962-01-01');
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('courriel_deja_pris', $this->codeDErreur());

        $this->envoyer('POST', '/api/noel/inscription', ['email' => 'x', 'password' => 'court', 'nomDeNaissance' => '', 'prenom' => 'A', 'dateDeNaissance' => '2999-01-01']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        $champs = $this->charge()['erreur']['champs'] ?? [];
        self::assertIsArray($champs);
        self::assertArrayHasKey('email', $champs);
        self::assertArrayHasKey('password', $champs);
        self::assertArrayHasKey('nomDeNaissance', $champs);
        self::assertArrayHasKey('dateDeNaissance', $champs);
    }

    public function testGoogleSansCompteMeneALInscriptionPuisConnecte(): void
    {
        $this->brancherGoogle();

        $this->envoyer('POST', '/api/connexion/google', ['credential' => 'id-token']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut(), $this->corps());
        self::assertSame('inscription_requise', $this->codeDErreur());
        $details = $this->charge()['erreur']['details'];
        self::assertIsArray($details);
        self::assertSame(['lea@example.com', 'Léa', 'Martin'], [$details['email'], $details['prenom'], $details['nom']]);
        self::assertArrayNotHasKey('sub', $details);

        // L'inscription reprend le courriel du jeton signé — pas celui du corps.
        $this->envoyer('POST', '/api/noel/inscription', [
            'googleJeton' => $details['jeton'],
            'email' => 'pirate@example.invalid',
            'nomDeNaissance' => 'Martin',
            'prenom' => 'Léa',
            'dateDeNaissance' => '1990-07-08',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), $this->corps());
        $compte = $this->compte('lea@example.com');
        self::assertSame('google-sub-1', $compte->getGoogleSub());
        self::assertSame('https://lh3.googleusercontent.com/a/photo', $compte->getAvatarUrl());

        $this->deconnexion();
        $this->envoyer('POST', '/api/connexion/google', ['credential' => 'id-token']);
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        self::assertSame('lea@example.com', $this->charge()['utilisateur']['email']);
    }

    public function testGoogleSeLieAUnCompteExistantParSonCourriel(): void
    {
        $this->creerUtilisateur('lea@example.com');
        $this->brancherGoogle();

        $this->envoyer('POST', '/api/connexion/google', ['credential' => 'id-token']);
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        self::assertSame('google-sub-1', $this->compte('lea@example.com')->getGoogleSub());
    }

    public function testUnTirageSePresenteSansSessionSansDonnerLesNoms(): void
    {
        $code = $this->tirageAvecQuatreParticipants();
        $this->deconnexion();

        $this->requete('/api/noel/tirages/'.$code);
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        $tirage = $this->charge();
        self::assertSame('Noël chez mamie', $tirage['titre']);
        self::assertSame(30, $tirage['budget']);
        self::assertSame(4, $tirage['nbParticipants']);
        self::assertArrayNotHasKey('participants', $tirage);

        $this->requete('/api/noel/tirages/INCONNU1');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
    }

    /**
     * La ligne de liste porte le budget et le lieu : heritage montre la carte d'un tirage
     * dans « Mes événements » sans l'ouvrir.
     */
    public function testLeResumeDUnTiragePorteSonBudgetEtSonLieu(): void
    {
        $this->inscrire('papa@example.invalid', 'Martin', 'Paul', '1960-05-12');
        $code = $this->creerUnTirage();

        $this->requete('/api/noel/mes-tirages');
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        $liste = array_values($this->charge());
        self::assertCount(1, $liste);
        $resume = $liste[0] ?? null;
        self::assertIsArray($resume);
        self::assertSame($code, $resume['code']);
        self::assertSame(30, $resume['budget']);
        self::assertSame('Chez mamie', $resume['lieu']);
    }

    /**
     * `GET /api/noel/tirages` : **tous** les tirages, le plus proche d'abord, pour un membre
     * actif d'heritage — même un tirage où il n'est ni organisateur ni inscrit. Un compte en
     * attente, fût-il l'organisateur, reçoit `403 regime_en_attente` ; sans session, `401`.
     */
    public function testTousLesTiragesSeListentPourUnMembreActifSeulement(): void
    {
        // Le double de l'arbre est statique : un test précédent a pu y laisser la fiche de
        // Paul Martin, et l'inscription l'activerait d'emblée.
        ArbreEnMemoire::reinitialiser();
        $this->inscrire('papa@example.invalid', 'Martin', 'Paul', '1960-05-12');
        self::assertSame('en_attente', $this->charge()['utilisateur']['status'] ?? null);
        $lointain = $this->creerUnTirage();
        $this->envoyer('POST', '/api/noel/tirages', [
            'titre' => 'Noël des cousins',
            'dateTirage' => (new \DateTimeImmutable('+5 days'))->format(\DateTimeInterface::ATOM),
            'dateEvenement' => (new \DateTimeImmutable('+40 days'))->format(\DateTimeInterface::ATOM),
            'budget' => 20,
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), $this->corps());
        $proche = $this->charge()['code'];

        // L'organisateur lui-même, compte en attente : refusé, avec la raison.
        $this->requete('/api/noel/tirages');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut(), $this->corps());
        self::assertSame('regime_en_attente', $this->codeDErreur());

        $this->deconnexion();
        $this->requete('/api/noel/tirages');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statut(), $this->corps());

        $this->creerLecteurHorsCercle('cousine@example.invalid');
        $this->envoyer('POST', '/api/connexion', ['email' => 'cousine@example.invalid', 'password' => 'motdepasse-de-test']);
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());

        $this->requete('/api/noel/tirages');
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        $liste = array_values($this->charge());
        self::assertSame([$proche, $lointain], array_column($liste, 'code'), 'Le plus proche d\'abord.');
        $premier = $liste[0] ?? null;
        self::assertIsArray($premier);
        self::assertSame([
            'code' => $proche,
            'titre' => 'Noël des cousins',
            'budget' => 20,
            'lieu' => null,
            'statut' => 'ouvert',
            'estCreateur' => false,
            'reponse' => null,
            'nbParticipants' => 0,
        ], array_diff_key($premier, ['dateTirage' => true, 'dateEvenement' => true]));
    }

    public function testLeTirageEstFaitSansSoiNiConjointPuisToutEstVerrouille(): void
    {
        $code = $this->tirageAvecQuatreParticipants();

        // Un participant qui n'organise pas ne lance pas le tirage.
        $this->connexion('fille@example.invalid');
        $this->envoyer('POST', "/api/noel/tirages/{$code}/lancer");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
        self::assertSame('non_createur', $this->codeDErreur());

        $this->connexion('papa@example.invalid');
        $this->envoyer('POST', "/api/noel/tirages/{$code}/lancer");
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        self::assertSame('tire', $this->charge()['statut']);
        // 2 votes « après l'ouverture » contre 1 « magie ».
        self::assertSame('apres_ouverture', $this->charge()['revelation']);

        $tirage = $this->em()->getRepository(TirageNoel::class)->findOneBy(['code' => $code]);
        self::assertNotNull($tirage);
        $papa = $this->compte('papa@example.invalid')->getId();
        $maman = $this->compte('maman@example.invalid')->getId();
        $attributions = [];
        foreach ($tirage->getAttributions() as $attribution) {
            $attributions[$attribution->getDonneur()->getId()] = $attribution->getReceveur()->getId();
        }
        self::assertCount(4, $attributions);
        self::assertCount(4, array_unique($attributions));
        foreach ($attributions as $donneur => $receveur) {
            self::assertNotSame($donneur, $receveur);
        }
        self::assertNotSame($maman, $attributions[$papa], 'On ne tire pas son conjoint.');
        self::assertNotSame($papa, $attributions[$maman], 'On ne tire pas son conjoint.');

        // Chaque participant est prévenu, sans que personne ne soit nommé.
        $notifications = $this->em()->getRepository(Notification::class)->findBy(['kind' => NotificationKind::NoelTirageEffectue]);
        self::assertCount(4, $notifications);
        self::assertSame(['code' => $code], $notifications[0]->getLinkParams());

        // Ma carte : à qui j'offre, avec son âge au jour de la remise.
        $this->requete("/api/noel/tirages/{$code}/mon-attribution");
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        self::assertSame($attributions[$papa], $this->charge()['userId']);
        self::assertIsInt($this->charge()['age']);

        // Plus personne ne rejoint, ne part, ne règle — et rien n'annule.
        $this->envoyer('PUT', "/api/noel/tirages/{$code}/participation", ['reponse' => 'non']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('tirage_verrouille', $this->codeDErreur());
        $this->envoyer('PUT', "/api/noel/tirages/{$code}", ['budget' => 50]);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        $this->envoyer('POST', "/api/noel/tirages/{$code}/lancer");
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        $this->envoyer('DELETE', "/api/noel/tirages/{$code}");
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->statut());

        $this->inscrire('tard@example.invalid', 'Retard', 'Léon', '1999-01-01');
        $this->envoyer('PUT', "/api/noel/tirages/{$code}/participation", ['reponse' => 'oui']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());

        // « Qui m'a offert ? » attend la remise des cadeaux, même quand le vote l'a voulu.
        $this->connexion('papa@example.invalid');
        $this->requete("/api/noel/tirages/{$code}/qui-ma-offert");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
    }

    public function testUneSeuleReponseLieLesDeuxConjoints(): void
    {
        $code = $this->tirageAvecQuatreParticipants();
        $papa = $this->compte('papa@example.invalid')->getId();
        $maman = $this->compte('maman@example.invalid')->getId();
        $fille = $this->compte('fille@example.invalid')->getId();

        // Julie a désigné Paul : Paul la voit dans sa réponse sans avoir rien fait.
        $this->connexion('papa@example.invalid');
        $this->requete('/api/noel/tirages/'.$code);
        self::assertSame($maman, $this->charge()['maParticipation']['conjointUserId']);

        // Léa désigne Paul : Julie n'est plus liée à personne.
        $this->connexion('fille@example.invalid');
        $this->repondre($code, ['reponse' => 'oui', 'presence' => 'absent', 'vote' => 'magie', 'conjointUserId' => $papa]);
        $this->connexion('maman@example.invalid');
        $this->requete('/api/noel/tirages/'.$code);
        self::assertNull($this->charge()['maParticipation']['conjointUserId']);
        $this->connexion('papa@example.invalid');
        $this->requete('/api/noel/tirages/'.$code);
        self::assertSame($fille, $this->charge()['maParticipation']['conjointUserId']);

        // Paul retire son conjoint : Léa n'en a plus non plus.
        $this->repondre($code, ['reponse' => 'oui', 'presence' => 'present', 'vote' => 'apres_ouverture']);
        $this->connexion('fille@example.invalid');
        $this->requete('/api/noel/tirages/'.$code);
        self::assertNull($this->charge()['maParticipation']['conjointUserId']);
    }

    public function testChacunLitSonLienDeParenteAvecChaqueParticipant(): void
    {
        $code = $this->tirageAvecQuatreParticipants();

        $attendu = self::getContainer()->get(ParenteDuLecteur::class)->depuis($this->idDe('aieul'), $this->idDe('ego'));
        self::assertNotNull($attendu);

        // Paul est rattaché à `aieul` ; Julie, sa conjointe au tirage, l'est
        // à `aieule` — son épouse dans l'arbre —, Léa à `ego`, Hugo à rien.
        foreach (['papa@example.invalid' => 'aieul', 'maman@example.invalid' => 'aieule', 'fille@example.invalid' => 'ego'] as $email => $fiche) {
            $membre = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $this->compte($email)->getId()]);
            self::assertNotNull($membre);
            $membre->rattacherA($this->idDe($fiche), $fiche, MatchKind::PreLie)->activer();
        }
        $this->em()->flush();

        $this->connexion('papa@example.invalid');
        $this->requete('/api/noel/tirages/'.$code);
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        $id = fn (string $email): int => (int) $this->compte($email)->getId();
        $participants = $this->charge()['participants'];
        self::assertIsArray($participants);
        // Le conjoint est celui de la réponse au tirage ; le reste vient de l'arbre.
        $liens = array_column($participants, 'lien', 'userId');
        self::assertNull($liens[$id('papa@example.invalid')]);
        self::assertSame('conjoint', $liens[$id('maman@example.invalid')]);
        self::assertSame($attendu, $liens[$id('fille@example.invalid')]);
        self::assertNull($liens[$id('fils@example.invalid')]);
        $conjoints = array_keys(array_filter(array_column($participants, 'conjointDansLArbre', 'userId')));
        self::assertSame([$id('maman@example.invalid')], $conjoints);

        // La carte du tirage le porte aussi.
        $this->envoyer('POST', "/api/noel/tirages/{$code}/lancer");
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        $this->requete("/api/noel/tirages/{$code}/mon-attribution");
        self::assertArrayHasKey('lien', $this->charge());

        // Léa, sans être administratrice, lit les siens depuis sa fiche (`ego`).
        $this->connexion('fille@example.invalid');
        $this->requete('/api/noel/tirages/'.$code);
        $participants = $this->charge()['participants'];
        self::assertIsArray($participants);
        $liens = array_column($participants, 'lien', 'userId');
        self::assertSame(
            self::getContainer()->get(ParenteDuLecteur::class)->depuis($this->idDe('ego'), $this->idDe('aieule')),
            $liens[$id('maman@example.invalid')],
        );
        self::assertNull($liens[$id('fils@example.invalid')]);
    }

    public function testMoinsDeTroisParticipantsNeSeTirentPas(): void
    {
        $this->inscrire('papa@example.invalid', 'Martin', 'Paul', '1960-05-12');
        $code = $this->creerUnTirage();
        $this->repondre($code, ['reponse' => 'oui']);

        $this->envoyer('POST', "/api/noel/tirages/{$code}/lancer");
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
        self::assertSame('trop_peu_de_participants', $this->codeDErreur());
    }

    public function testChacunChangeDAvisTantQueLeTirageEstOuvert(): void
    {
        $code = $this->tirageAvecQuatreParticipants();

        $this->connexion('fils@example.invalid');
        $this->repondre($code, ['reponse' => 'non']);
        self::assertSame(3, $this->charge()['nbParticipants']);
        self::assertSame('non', $this->charge()['maParticipation']['reponse']);
        self::assertNull($this->charge()['maParticipation']['presence']);

        $this->repondre($code, ['reponse' => 'oui', 'presence' => 'ne_sait_pas', 'vote' => 'magie']);
        self::assertSame(4, $this->charge()['nbParticipants']);

        // On ne se déclare pas conjoint de quelqu'un qui ne participe pas.
        $this->envoyer('PUT', "/api/noel/tirages/{$code}/participation", ['reponse' => 'oui', 'conjointUserId' => 999999]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
    }

    public function testLOrganisateurDecaleLaDateEtLesParticipantsSontPrevenus(): void
    {
        $code = $this->tirageAvecQuatreParticipants();

        $this->connexion('papa@example.invalid');
        $nouvelle = (new \DateTimeImmutable('+20 days'))->format(\DateTimeInterface::ATOM);
        $this->envoyer('PUT', "/api/noel/tirages/{$code}", ['dateTirage' => $nouvelle, 'budget' => 40]);
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
        self::assertSame(40, $this->charge()['budget']);

        // Les trois autres participants — pas l'organisateur, qui vient de le faire.
        self::assertCount(3, $this->em()->getRepository(Notification::class)->findBy(['kind' => NotificationKind::NoelTirageModifie]));

        // Le texte de présentation : propre au tirage, effaçable (retour au texte par défaut).
        $this->envoyer('PUT', "/api/noel/tirages/{$code}", ['presentation' => "Coucou les cousins !\nLe tirage se fera le {date_tirage}."]);
        self::assertSame("Coucou les cousins !\nLe tirage se fera le {date_tirage}.", $this->charge()['presentation']);
        $this->envoyer('PUT', "/api/noel/tirages/{$code}", ['presentation' => null]);
        self::assertNull($this->charge()['presentation']);
        $this->envoyer('PUT', "/api/noel/tirages/{$code}", ['presentation' => str_repeat('x', 5001)]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());

        $this->envoyer('PUT', "/api/noel/tirages/{$code}", ['dateTirage' => '2000-01-01T10:00:00+01:00']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
    }

    public function testLaCommandeDEcheanceTireLesTiragesArrivesASaDate(): void
    {
        $code = $this->tirageAvecQuatreParticipants();

        $service = self::getContainer()->get(ServiceDesTiragesNoel::class);
        self::assertInstanceOf(ServiceDesTiragesNoel::class, $service);

        self::assertSame(0, $service->tirerLesEcheances(new \DateTimeImmutable()));
        self::assertSame(1, $service->tirerLesEcheances(new \DateTimeImmutable('+30 days')));
        self::assertSame(0, $service->tirerLesEcheances(new \DateTimeImmutable('+30 days')), 'Un tirage fait ne se refait pas.');

        $this->connexion('papa@example.invalid');
        $this->requete('/api/noel/tirages/'.$code);
        self::assertSame('tire', $this->charge()['statut']);
    }

    public function testUnePhotoNeSeSertQuEntreParticipants(): void
    {
        $code = $this->tirageAvecQuatreParticipants();
        $fille = $this->compte('fille@example.invalid')->getId();

        // Aucune photo déposée : `404`, comme pour un compte étranger.
        $this->connexion('papa@example.invalid');
        $this->requete("/api/noel/photos/{$fille}");
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());

        $this->requete('/api/noel/tirages/'.$code);
        $participants = $this->charge()['participants'];
        self::assertIsArray($participants);
        self::assertCount(4, $participants);
        self::assertNull($participants[0]['photoUrl']);
    }

    /** Remplace la vérification par Google : `tokeninfo` rend toujours le même profil. */
    private function brancherGoogle(): void
    {
        // Sans redémarrage du noyau entre deux requêtes : le double survit à la première.
        $this->client->disableReboot();
        $profil = (string) json_encode([
            'aud' => 'client-de-test',
            'iss' => 'accounts.google.com',
            'sub' => 'google-sub-1',
            'email' => 'lea@example.com',
            'email_verified' => true,
            'given_name' => 'Léa',
            'family_name' => 'Martin',
            'picture' => 'https://lh3.googleusercontent.com/a/photo',
            'exp' => time() + 600,
        ]);
        $reponse = static fn (): MockResponse => new MockResponse($profil);
        $secret = self::getContainer()->getParameter('kernel.secret');
        \assert(\is_string($secret));
        self::getContainer()->set(ConnexionGoogle::class, new ConnexionGoogle(new MockHttpClient($reponse), 'client-de-test', $secret));
    }

    /** Papa organise ; papa et maman sont conjoints ; 4 « oui ». */
    private function tirageAvecQuatreParticipants(): string
    {
        $this->inscrire('papa@example.invalid', 'Martin', 'Paul', '1960-05-12');
        $code = $this->creerUnTirage();
        $this->repondre($code, ['reponse' => 'oui', 'presence' => 'present', 'vote' => 'apres_ouverture']);
        $papa = $this->compte('papa@example.invalid')->getId();

        $this->deconnexion();
        $this->inscrire('maman@example.invalid', 'Durand', 'Julie', '1962-03-04');
        $this->repondre($code, ['reponse' => 'oui', 'presence' => 'present', 'vote' => 'apres_ouverture', 'conjointUserId' => $papa]);

        $this->deconnexion();
        $this->inscrire('fille@example.invalid', 'Martin', 'Léa', '1990-07-08');
        $this->repondre($code, ['reponse' => 'oui', 'presence' => 'absent', 'vote' => 'magie']);

        $this->deconnexion();
        $this->inscrire('fils@example.invalid', 'Martin', 'Hugo', '2015-12-25');
        $this->repondre($code, ['reponse' => 'oui', 'presence' => 'ne_sait_pas']);

        return $code;
    }

    private function creerUnTirage(): string
    {
        $this->envoyer('POST', '/api/noel/tirages', [
            'titre' => 'Noël chez mamie',
            'dateTirage' => (new \DateTimeImmutable('+10 days'))->format(\DateTimeInterface::ATOM),
            'dateEvenement' => (new \DateTimeImmutable('+60 days'))->format(\DateTimeInterface::ATOM),
            'budget' => 30,
            'lieu' => 'Chez mamie',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), $this->corps());
        $code = $this->charge()['code'];
        self::assertIsString($code);
        self::assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);

        return $code;
    }

    /** @param array<string, mixed> $corps */
    private function repondre(string $code, array $corps): void
    {
        $this->envoyer('PUT', "/api/noel/tirages/{$code}/participation", $corps);
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
    }

    private function inscrire(string $email, string $nom, string $prenom, string $naissance): void
    {
        $this->envoyer('POST', '/api/noel/inscription', [
            'email' => $email,
            'password' => self::MOT_DE_PASSE,
            'nomDeNaissance' => $nom,
            'prenom' => $prenom,
            'telephone' => '06 12 34 56 78',
            'dateDeNaissance' => $naissance,
        ]);
    }

    private function connexion(string $email): void
    {
        $this->deconnexion();
        $this->envoyer('POST', '/api/connexion', ['email' => $email, 'password' => self::MOT_DE_PASSE]);
        self::assertSame(Response::HTTP_OK, $this->statut(), $this->corps());
    }

    private function deconnexion(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function membreDe(string $email): Member
    {
        $membre = $this->em()->getRepository(Member::class)->findOneBy(['userId' => $this->compte($email)->getId()]);
        self::assertNotNull($membre);

        return $membre;
    }

    private function compte(string $email): User
    {
        $compte = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($compte);

        return $compte;
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

    private function corps(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function codeDErreur(): mixed
    {
        $erreur = $this->charge()['erreur'] ?? null;
        self::assertIsArray($erreur);

        return $erreur['code'] ?? null;
    }
}
