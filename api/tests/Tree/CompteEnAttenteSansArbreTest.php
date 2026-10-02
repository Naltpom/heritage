<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * **Un compte en attente ne voit rien de l'arbre** — ADR 0003, révision du 01/10/2026.
 *
 * ## Pourquoi ce fichier existe
 *
 * Jusqu'au 30/09/2026, un compte inscrit mais pas encore validé lisait « l'arbre
 * ancien » : les défunts en entier, et chaque vivant en **nœud restreint** — nom complet,
 * sexe, génération, branche. Le lien d'invitation circule dans un groupe de messagerie :
 * n'importe qui pouvait s'inscrire sous un faux nom et lire les noms des vivants de la
 * famille. La décision : un compte en attente ne lit **plus rien** de l'arbre, ni vivants
 * ni défunts.
 *
 * Ce fichier parcourt **toutes** les portes de lecture, à travers le vrai pare-feu, et
 * vérifie trois choses à chacune :
 *
 * 1. le compte en attente reçoit `403 regime_en_attente`, dans l'enveloppe du § 15.1 ;
 * 2. un membre validé passe toujours (rien n'a été fermé à ceux qui y ont droit) ;
 * 3. les routes **du compte lui-même** — session, notifications, push — restent ouvertes
 *    au compte en attente : c'est par elles qu'il apprendra sa validation.
 *
 * Une route de lecture ajoutée demain sans `#[ReserveAuxMembres]` ne rougira pas ici
 * d'elle-même : il faut l'ajouter à {@see fournirLesLectures()}. C'est la liste à relire
 * en même temps que le contrat (§ 12.2).
 */
final class CompteEnAttenteSansArbreTest extends ArbreWebTestCase
{
    private const string ATTENTE = 'attente@heritage.test';

    private const string MEMBRE = 'membre-valide@heritage.test';

    private const string SANS_FICHE = 'ancien-admin@heritage.test';

    private const string ADMIN = 'admin-sans-fiche@heritage.test';

    /**
     * Les lectures de l'arbre, une par porte. `{aieule}`, `{cousin}`, `{famille}` et
     * `{media}` sont remplacés au moment du test : les identifiants naissent avec la base.
     *
     * @return iterable<string, array{string}>
     */
    public static function fournirLesLectures(): iterable
    {
        yield 'graphe' => ['/api/arbre/graphe'];
        yield 'fiche d’un défunt' => ['/api/arbre/personnes/{aieule}'];
        yield 'fiche d’un vivant' => ['/api/arbre/personnes/{cousin}'];
        yield 'famille' => ['/api/arbre/familles/{famille}'];
        yield 'recherche' => ['/api/arbre/recherche?q=sureau'];
        yield 'chronologie' => ['/api/chronologie'];
        yield 'préférences de chronologie' => ['/api/chronologie/preferences'];
        yield 'carte' => ['/api/carte'];
        yield 'anniversaires' => ['/api/anniversaires'];
        yield 'médias d’une personne' => ['/api/medias?subjectKind=personne&subjectId={aieule}'];
        yield 'média d’une personne' => ['/api/medias/{media}'];
        yield 'fichier d’un média' => ['/api/medias/{media}/fichier'];
        yield 'vignette d’un média' => ['/api/medias/{media}/vignette'];
        yield 'média inexistant' => ['/api/medias/987654'];
        yield 'historique d’une fiche' => ['/api/demandes/historique/personne/{aieule}'];
        yield 'inventaire d’une suppression' => ['/api/demandes/suppression/personne/{aieule}'];
        yield 'questions' => ['/api/questions'];
        yield 'tirages' => ['/api/tirages'];
        yield 'événements' => ['/api/evenements'];
        yield 'actes demandés' => ['/api/demarches'];
    }

    #[DataProvider('fournirLesLectures')]
    public function testUnCompteEnAttenteEstRefuseSurChaqueLecture(string $chemin): void
    {
        $chemin = $this->resoudre($chemin);

        $this->creerUtilisateur(self::ATTENTE, statut: UserStatus::EnAttente);
        $this->connexionDe(self::ATTENTE);
        $this->requete($chemin);

        $reponse = $this->client->getResponse();
        self::assertSame(Response::HTTP_FORBIDDEN, $reponse->getStatusCode(), $chemin.' : '.mb_substr((string) $reponse->getContent(), 0, 300));

        $erreur = $this->charge()['erreur'];
        self::assertSame('regime_en_attente', $erreur['code'], $chemin);
        self::assertIsString($erreur['message']);
        self::assertStringContainsString('en cours de vérification', $erreur['message'], $chemin);

        // Rien de l'arbre ne traverse le refus — pas même un nom de famille.
        foreach (['Sureau', 'Tilleul', 'Dolomite'] as $nom) {
            self::assertStringNotContainsStringIgnoringCase($nom, (string) $reponse->getContent(), $chemin);
        }
    }

    /**
     * Le pendant : un **membre validé** lit toujours l'arbre. Sans ce cas, une fermeture
     * générale passerait le test précédent au vert.
     *
     * @return iterable<string, array{string}>
     */
    public static function fournirLesLecturesDeMembre(): iterable
    {
        yield 'graphe' => ['/api/arbre/graphe'];
        yield 'fiche d’un défunt' => ['/api/arbre/personnes/{aieule}'];
        yield 'famille' => ['/api/arbre/familles/{famille}'];
        yield 'recherche' => ['/api/arbre/recherche?q=sureau'];
        yield 'chronologie' => ['/api/chronologie'];
        yield 'carte' => ['/api/carte'];
        yield 'anniversaires' => ['/api/anniversaires'];
        // La liste, et non le détail : le détail d'un média passe par la vue du cercle
        // de `Membership`, que ce socle ne sème pas — il est couvert par `tests/Media`.
        yield 'médias d’une personne' => ['/api/medias?subjectKind=personne&subjectId={aieule}'];
    }

    #[DataProvider('fournirLesLecturesDeMembre')]
    public function testUnMembreValideLitToujours(string $chemin): void
    {
        $chemin = $this->resoudre($chemin);

        // Un membre **validé**, c'est un compte actif ET un rattachement actif : le
        // compte actif seul n'a pas de cercle, donc rien à lire (cas suivant).
        $this->rattacher($this->creerUtilisateur(self::MEMBRE), activer: true);
        $this->connexionDe(self::MEMBRE);
        $this->requete($chemin);

        $reponse = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $reponse->getStatusCode(), $chemin.' : '.mb_substr((string) $reponse->getContent(), 0, 300));
    }

    /**
     * **Un compte actif sans membre actif ne lit rien non plus** (correction du
     * 01/10/2026). Le cas qui arrive vraiment : un compte créé par
     * `app:creer-administrateur`, donc sans `mbr_member`, à qui l'on retire ensuite
     * `ROLE_ADMIN`. Son statut est `actif` — le voter `MEMBRE` le laissait passer —, mais
     * le cercle lui donne `Regime::Aucun`, et la session dit `cercle.regime: aucun`. Il
     * lisait donc l'arbre ancien, noms des vivants compris. Variante : un compte actif
     * dont le rattachement attend encore sa validation.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function fournirLesLecturesSansCercle(): iterable
    {
        foreach (self::fournirLesLectures() as $nom => [$chemin]) {
            yield 'sans membre — '.$nom => [$chemin, false];
            yield 'membre non actif — '.$nom => [$chemin, true];
        }
    }

    #[DataProvider('fournirLesLecturesSansCercle')]
    public function testUnCompteActifSansCercleEstRefuseSurChaqueLecture(string $chemin, bool $avecMembreEnAttente): void
    {
        $chemin = $this->resoudre($chemin);

        // Créé administrateur, puis rétrogradé : le rôle retiré, le statut reste actif.
        $compte = $this->creerUtilisateur(self::SANS_FICHE, roles: ['ROLE_ADMIN']);
        $compte->setRoles([]);
        $this->em()->flush();

        if ($avecMembreEnAttente) {
            $this->rattacher($compte, activer: false);
        }

        $this->connexionDe(self::SANS_FICHE);

        // Ce que le front lit pour le ranger « en attente » : la même source.
        $this->requete('/api/session');
        $session = $this->charge();
        self::assertIsArray($session['cercle'] ?? null);
        self::assertSame('aucun', $session['cercle']['regime']);

        $this->requete($chemin);

        $reponse = $this->client->getResponse();
        self::assertSame(Response::HTTP_FORBIDDEN, $reponse->getStatusCode(), $chemin.' : '.mb_substr((string) $reponse->getContent(), 0, 300));
        self::assertSame('regime_en_attente', $this->charge()['erreur']['code'], $chemin);

        foreach (['Sureau', 'Tilleul', 'Dolomite'] as $nom) {
            self::assertStringNotContainsStringIgnoringCase($nom, (string) $reponse->getContent(), $chemin);
        }
    }

    /**
     * Le pendant : un **administrateur** sans fiche garde le cercle complet. Fermer le
     * régime `Aucun` ne doit pas fermer l'arbre à ceux qui l'administrent.
     */
    public function testUnAdministrateurSansFicheLitToujours(): void
    {
        $this->creerUtilisateur(self::ADMIN, roles: ['ROLE_ADMIN']);
        $this->connexionDe(self::ADMIN);

        foreach (['/api/arbre/graphe', '/api/chronologie', '/api/carte', '/api/anniversaires'] as $chemin) {
            $this->requete($chemin);
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $chemin.' : '.mb_substr((string) $this->client->getResponse()->getContent(), 0, 300));
        }
    }

    /**
     * Ce qui est **à lui** reste ouvert au compte en attente : sa session, ses
     * notifications — c'est par elles qu'il apprendra sa validation —, et la clé de push
     * qui les lui fera arriver sur le téléphone.
     *
     * @return iterable<string, array{string}>
     */
    public static function fournirLesRoutesDuCompte(): iterable
    {
        yield 'session' => ['/api/session'];
        yield 'notifications' => ['/api/notifications'];
        yield 'compteur de notifications' => ['/api/notifications/compteur'];
        yield 'clé de push' => ['/api/push/cle'];
    }

    #[DataProvider('fournirLesRoutesDuCompte')]
    public function testLesRoutesDuCompteRestentOuvertes(string $chemin): void
    {
        $this->creerUtilisateur(self::ATTENTE, statut: UserStatus::EnAttente);
        $this->connexionDe(self::ATTENTE);
        $this->requete($chemin);

        $reponse = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $reponse->getStatusCode(), $chemin.' : '.mb_substr((string) $reponse->getContent(), 0, 300));
    }

    /**
     * **Sa propre photo d'inscription**, elle, lui reste lisible : l'écran d'attente la
     * lui remontre. C'est la seule exception, et elle tient à ce qu'il l'a déposée
     * lui-même — un média sans sujet, rattaché à son compte.
     */
    public function testUnCompteEnAttenteRelitSaPhotoDInscription(): void
    {
        $compte = $this->creerUtilisateur(self::ATTENTE, statut: UserStatus::EnAttente);
        $userId = $compte->getId();
        self::assertNotNull($userId);

        $photo = new Media(
            MediaKind::Portrait,
            MediaSubject::Personne,
            'Portrait en attente de rattachement',
            'photo-d-inscription.jpg',
            'image/jpeg',
            2048,
            str_repeat('b', 64),
            $userId,
            'Compte de test',
        );
        $photo->rattacherAuCompte($userId)->setStatus(MediaStatus::EnAttente);
        $this->em()->persist($photo);
        $this->em()->flush();

        $this->connexionDe(self::ATTENTE);
        $this->requete('/api/medias/'.$photo->getId());

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame($photo->getId(), $this->charge()['id']);
    }

    /**
     * Le refus **ne dépend pas de l'existence** du média : un `404` sur l'inexistant et un
     * `403` sur l'existant feraient de `/api/medias/{id}` un oracle d'énumération.
     */
    public function testLeRefusNeDitPasSiLeMediaExiste(): void
    {
        $existant = $this->resoudre('/api/medias/{media}');

        $this->creerUtilisateur(self::ATTENTE, statut: UserStatus::EnAttente);
        $this->connexionDe(self::ATTENTE);

        $this->requete($existant);
        $surLExistant = [$this->client->getResponse()->getStatusCode(), $this->charge()];

        $this->requete('/api/medias/987654');
        $surLInexistant = [$this->client->getResponse()->getStatusCode(), $this->charge()];

        self::assertSame($surLExistant, $surLInexistant);
    }

    private function resoudre(string $chemin): string
    {
        if (str_contains($chemin, '{media}')) {
            $chemin = str_replace('{media}', (string) $this->mediaDeLAieule(), $chemin);
        }

        $famille = $this->familles['mixte']->getId();
        self::assertNotNull($famille);

        return strtr($chemin, [
            '{aieule}' => (string) $this->idDe('aieule'),
            '{cousin}' => (string) $this->idDe('cousin'),
            '{famille}' => (string) $famille,
        ]);
    }

    private function connexionDe(string $courriel): void
    {
        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }

    /**
     * Rattache le compte à la fiche d'`ego`. `activer: false` laisse le rattachement en
     * attente de validation : le compte n'a alors pas de cercle.
     */
    private function rattacher(User $compte, bool $activer): void
    {
        $identifiant = $compte->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $compte->getEmail(), $this->idDe('ego'), 'Ego', MatchKind::PreLie);

        if ($activer) {
            $membre->activer();
        }

        $this->em()->persist($membre);
        $this->em()->flush();
    }

    /** Un portrait actif sur la fiche d'une défunte : ce que tout membre peut voir. */
    private function mediaDeLAieule(): int
    {
        $media = new Media(
            MediaKind::Portrait,
            MediaSubject::Personne,
            'Marne Dolomite Sureau',
            'stockage-de-test.jpg',
            'image/jpeg',
            2048,
            str_repeat('a', 64),
            1,
            'Compte de test',
        );
        $media->rattacherA($this->idDe('aieule'))->setStatus(MediaStatus::Actif);

        $this->em()->persist($media);
        $this->em()->flush();

        $id = $media->getId();
        self::assertNotNull($id);

        return $id;
    }
}
