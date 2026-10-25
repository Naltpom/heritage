<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\UserStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le parcours d'authentification, vérifié sur ce qu'il **fait** et non sur l'existence
 * des routes : une connexion réussie, une connexion refusée, le blocage au sixième essai,
 * la 401 JSON opposée à l'anonyme, et le compte suspendu.
 */
final class AuthentificationTest extends CoreWebTestCase
{
    public function testUneConnexionReussieRendLaChargeDeSession(): void
    {
        $this->creerUtilisateur('cobalt@example.net', nomAffiche: 'Cobalt Romarin');

        $this->connexion('cobalt@example.net');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $charge = $this->charge();
        self::assertSame('cobalt@example.net', $charge['utilisateur']['email']);
        self::assertSame('Cobalt Romarin', $charge['utilisateur']['displayName']);
        self::assertSame('actif', $charge['utilisateur']['status']);
        self::assertSame(['ROLE_USER'], $charge['utilisateur']['roles']);
        // Le socle n'a pas de contributeur `Membership` : le défaut est fermé.
        self::assertNull($charge['membre']);
        self::assertArrayHasKey('cercle', $charge);

        // Le cookie de session est le seul porteur du front — le jeton d'API de l'ADR 0021
        // ne sert que hors navigateur (`JetonsDApiEnHttpTest`) : aucun jeton dans la charge utile.
        $json = json_encode($charge, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsStringIgnoringCase('token', $json);
        self::assertStringNotContainsStringIgnoringCase('password', $json);

        // La session ouverte donne accès à la route protégée, sans rien renvoyer d'autre.
        $this->requete('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertSame('cobalt@example.net', $this->charge()['utilisateur']['email']);
    }

    public function testLeCookieDeSessionEstHttpOnlyEtSameSiteLax(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $cookies = $this->client->getResponse()->headers->getCookies();
        $session = null;
        foreach ($cookies as $cookie) {
            if (str_contains(strtoupper($cookie->getName()), 'SESS')) {
                $session = $cookie;
            }
        }

        self::assertNotNull($session, 'La connexion doit poser un cookie de session.');
        self::assertTrue($session->isHttpOnly(), 'Le cookie de session ne doit pas être lisible par JavaScript.');
        self::assertSame('lax', $session->getSameSite());
    }

    public function testUneConnexionEchoueeRendUneErreurJsonIndistincte(): void
    {
        $this->creerUtilisateur('cobalt@example.net');

        $this->requete('POST', '/api/connexion', ['email' => 'cobalt@example.net', 'password' => 'faux']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $erreur = $this->charge()['erreur'];
        self::assertSame('identifiants_invalides', $erreur['code']);
        // Enveloppe unique du § 15.1 : les quatre clés, `champs`/`details` à null.
        self::assertArrayHasKey('champs', $erreur);
        self::assertArrayHasKey('details', $erreur);
        self::assertNull($erreur['champs']);

        // Compte inexistant : exactement la même réponse, sinon la route énumère les
        // adresses de la famille.
        $this->requete('POST', '/api/connexion', ['email' => 'inconnu@example.net', 'password' => 'faux']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('identifiants_invalides', $this->charge()['erreur']['code']);
    }

    /**
     * Le cœur de `login_throttling` : cinq essais passent (et échouent sur les
     * identifiants), le sixième ne teste même plus le mot de passe.
     */
    public function testLeSixiemeEssaiEstBloqueParLeLimiteur(): void
    {
        $courriel = $this->courrielVierge();
        $this->creerUtilisateur($courriel);

        self::assertSame(
            [401, 401, 401, 401, 401, 429],
            $this->sixTentatives($courriel),
            'Cinq tentatives doivent passer et échouer sur les identifiants ; la sixième doit être bloquée.',
        );

        $erreur = $this->charge()['erreur'];
        self::assertSame('trop_de_tentatives', $erreur['code']);
        self::assertIsInt($erreur['details']['retryAfter']);
        self::assertGreaterThan(0, $erreur['details']['retryAfter']);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));

        // Et le blocage porte sur le couple identifiant+IP : **le bon** mot de passe est
        // refusé lui aussi, sinon le limiteur ne protégerait rien.
        $this->connexion($courriel);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testLeLimiteurNeBloquePasUnAutreCompte(): void
    {
        $bloque = $this->courrielVierge();
        $voisin = $this->courrielVierge();
        $this->creerUtilisateur($bloque);
        $this->creerUtilisateur($voisin);

        self::assertSame([401, 401, 401, 401, 401, 429], $this->sixTentatives($bloque));

        // Le plafond est par identifiant **et** par IP : un autre membre de la même
        // maison ne doit pas être enfermé dehors par les essais d'un cousin.
        $this->connexion($voisin);
        self::assertResponseIsSuccessful();
    }

    /**
     * Un identifiant que **personne n'a encore essayé**, dans aucun test.
     *
     * ## Pourquoi l'IP par test ne suffisait pas
     *
     * `login_throttling` compte sur **deux** dimensions : un limiteur local par couple
     * `identifiant + IP`, et un limiteur global par IP seule. Le socle donne déjà à
     * chaque test sa propre IP — ce qui isole le second. Mais plusieurs tests visaient le
     * **même identifiant**, `cobalt@example.net`, et une fenêtre déjà entamée pour ce
     * couple faisait basculer le `429` une tentative trop tôt. Le test devenait sensible
     * à l'ordre d'exécution.
     *
     * C'était un garde-fou correct appliqué à une seule des deux dimensions du problème :
     * il ne mentait pas, il ne voyait que la moitié.
     *
     * ## Pourquoi il n'y a plus de reprise
     *
     * Une version précédente rejouait la séquence quand la sixième tentative ne rendait
     * pas `429`, pour tolérer une remise à zéro du pool. C'était une mauvaise réponse :
     * la reprise **ne remet rien à zéro**, elle rejoue six tentatives sur une fenêtre
     * déjà entamée — d'où le `[401, 401, 401, 401, 429, 429]` observé, c'est-à-dire le
     * symptôme que la reprise était censée masquer, déplacé d'un cran.
     *
     * Un identifiant neuf isole **par construction**. Aucun nettoyage à ne pas oublier.
     */
    private function courrielVierge(): string
    {
        return \sprintf('limiteur-%s@example.net', bin2hex(random_bytes(8)));
    }

    /**
     * Six tentatives infructueuses, et les six codes de retour, sans aucune tolérance.
     *
     * L'assertion `[401, 401, 401, 401, 401, 429]` est sensible **dans les deux sens** —
     * c'est ce qui en fait un test et non une formalité : un limiteur débranché rendrait
     * six `401`, un limiteur trop strict rendrait `429` avant le sixième. Les deux font
     * tomber le test.
     *
     * @return list<int>
     */
    private function sixTentatives(string $courriel): array
    {
        $codes = [];

        for ($essai = 1; $essai <= 6; ++$essai) {
            $this->requete('POST', '/api/connexion', ['email' => $courriel, 'password' => 'faux']);
            $codes[] = $this->client->getResponse()->getStatusCode();
        }

        return $codes;
    }

    /**
     * **Le test qui vérifie que les tests du limiteur sont isolés.**.
     *
     * Il épuise le plafond deux fois de suite, dans le **même** test et depuis la
     * **même** IP, sur deux identifiants différents. Si le compteur était partagé entre
     * identifiants, la seconde série serait bloquée d'emblée et rendrait `429` dès sa
     * première tentative.
     *
     * Il documente donc la dimension qui a rendu `testLeLimiteurNeBloquePasUnAutreCompte`
     * sensible à l'ordre d'exécution, et il tomberait si quelqu'un remplaçait le limiteur
     * local `identifiant + IP` par un limiteur par IP seule — un changement qui, sans ce
     * cas, passerait pour un durcissement sans conséquence.
     */
    public function testDeuxIdentifiantsOntChacunLeurFenetre(): void
    {
        $premier = $this->courrielVierge();
        $second = $this->courrielVierge();
        $this->creerUtilisateur($premier);
        $this->creerUtilisateur($second);

        self::assertSame([401, 401, 401, 401, 401, 429], $this->sixTentatives($premier));
        self::assertSame(
            [401, 401, 401, 401, 401, 429],
            $this->sixTentatives($second),
            'La fenêtre du second identifiant doit être intacte : le plafond compte par identifiant, pas par IP seule.',
        );
    }

    /**
     * Le point le plus vérifié du § 2 du dossier : jamais de redirection HTML vers un
     * formulaire.
     */
    public function testUnAccesAnonymeRend401JsonEtNonUneRedirection(): void
    {
        foreach (['/api/session', '/api/administration/journal', '/api/administration/reglages'] as $chemin) {
            $this->requete('GET', $chemin);

            $reponse = $this->client->getResponse();
            self::assertSame(Response::HTTP_UNAUTHORIZED, $reponse->getStatusCode(), $chemin);
            self::assertFalse($reponse->isRedirection(), \sprintf('%s ne doit pas rediriger.', $chemin));
            self::assertFalse($reponse->headers->has('Location'), \sprintf('%s ne doit poser aucun Location.', $chemin));
            self::assertStringStartsWith('application/json', (string) $reponse->headers->get('Content-Type'), $chemin);
            self::assertSame('authentification_requise', $this->charge()['erreur']['code'], $chemin);
        }
    }

    public function testUnCompteSuspenduNObtientPasDeSession(): void
    {
        $this->creerUtilisateur('exclu@example.net', statut: UserStatus::Suspendu);

        $this->connexion('exclu@example.net');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('compte_suspendu', $this->charge()['erreur']['code']);

        // Pas de session ouverte « qu'il faudrait ensuite filtrer » (§ 1.6).
        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('authentification_requise', $this->charge()['erreur']['code']);
    }

    /**
     * Un mot de passe faux sur un compte suspendu doit rendre `identifiants_invalides` :
     * sans cela, la route dirait à n'importe qui quelles adresses existent.
     */
    public function testLeStatutSuspenduNEstPasUnOracleSurLExistenceDuCompte(): void
    {
        $this->creerUtilisateur('exclu@example.net', statut: UserStatus::Suspendu);

        $this->requete('POST', '/api/connexion', ['email' => 'exclu@example.net', 'password' => 'faux']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('identifiants_invalides', $this->charge()['erreur']['code']);
    }

    public function testUneSessionEnCoursEstFermeeQuandLeCompteEstSuspendu(): void
    {
        $utilisateur = $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $em->find($utilisateur::class, $utilisateur->getId())?->setStatus(UserStatus::Suspendu);
        $em->flush();

        $this->requete('GET', '/api/session');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('compte_suspendu', $this->charge()['erreur']['code']);
    }

    public function testLaDeconnexionRendUnJsonEtFermeLaSession(): void
    {
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');

        $this->requete('POST', '/api/deconnexion');

        self::assertResponseIsSuccessful();
        self::assertSame(['deconnecte' => true], $this->charge());

        $this->requete('GET', '/api/session');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $actions = array_map(static fn ($ligne) => $ligne->getAction(), $this->journal());
        self::assertContains(AuditAction::Deconnexion, $actions);
    }

    public function testUnCorpsIllisibleRendUne422EtNonUne400Nue(): void
    {
        $this->client->request(
            'POST',
            '/api/connexion',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"email": "cobalt@example.net"}',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('donnees_invalides', $this->charge()['erreur']['code']);
    }
}
