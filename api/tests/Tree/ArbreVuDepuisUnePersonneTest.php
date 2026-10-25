<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * L'ARBRE D'UNE PERSONNE SANS COMPTE — `?depuis=<personId>` (demande du 25/10/2026).
 *
 * La bascule « voir comme un membre » emprunte un **membre** : elle ne sait rien montrer
 * de quelqu'un qui n'a pas de compte. L'administrateur choisit donc la personne elle-même,
 * et le graphe se dessine depuis elle — `ego`, rangée zéro, quarts, parentés —, sans que
 * le cercle ni le calque changent.
 *
 * Ce que ces tests gardent, dans l'ordre où ça casserait :
 *
 * 1. l'ego servi est bien la personne choisie, sur le graphe **et** sur la fiche ;
 * 2. l'`ETag` distingue le point de vue — sinon un `304` rendrait l'arbre de
 *    l'administrateur sous un bandeau qui annonce celui d'un autre ;
 * 3. personne d'autre qu'un administrateur hors bascule ne choisit le point de vue.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
final class ArbreVuDepuisUnePersonneTest extends ArbreWebTestCase
{
    private const string ADMIN = 'admin-depuis@heritage.test';

    private const string MEMBRE = 'membre-depuis@heritage.test';

    public function testLAdministrateurRegardeLArbreDepuisLaPersonneChoisie(): void
    {
        $this->connexionAdministrateur();
        $this->requete('/api/arbre/graphe?depuis='.$this->idDe('aieule'));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->extrait());

        $charge = $this->charge();
        self::assertSame('p'.$this->idDe('aieule'), $charge['ego'], 'L’ego servi est la personne choisie.');

        $rangees = $this->rangees($charge);
        self::assertSame(0.0, $rangees['p'.$this->idDe('aieule')], 'La personne choisie est sur la rangée zéro.');
        self::assertNotSame(0.0, $rangees['p'.$this->idDe('ego')], 'Vue de l’aïeule, la racine n’est plus à la rangée zéro.');
    }

    /**
     * Sans `?depuis=`, rien ne change : l'administrateur sans fiche regarde depuis la racine.
     */
    public function testSansLeParametreLAdministrateurGardeSonPointDeVue(): void
    {
        $this->connexionAdministrateur();
        $this->requete('/api/arbre/graphe');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('p'.$this->idDe('ego'), $this->charge()['ego']);
    }

    /**
     * **Aucun `304` croisé entre deux points de vue.** L'`ETag` porte déjà le point de vue
     * (§ 5.2) ; ce test vérifie qu'il s'agit bien du point de vue **choisi**, et non de
     * celui du compte, qui est le même dans les deux requêtes.
     */
    public function testLEtagDistingueLePointDeVueChoisi(): void
    {
        $this->connexionAdministrateur();

        $this->requete('/api/arbre/graphe');
        $sien = (string) $this->client->getResponse()->headers->get('ETag');

        $chemin = '/api/arbre/graphe?depuis='.$this->idDe('aieule');
        $this->requete($chemin);
        $depuis = (string) $this->client->getResponse()->headers->get('ETag');

        self::assertNotSame('', $depuis);
        self::assertNotSame($sien, $depuis, 'Deux points de vue, deux ETag.');

        $this->revalider($chemin, $sien);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'L’ETag de son propre arbre ne vaut pas pour celui d’un autre.');

        $this->revalider($chemin, $depuis);
        self::assertSame(Response::HTTP_NOT_MODIFIED, $this->client->getResponse()->getStatusCode(), 'Le même point de vue se revalide.');
    }

    /**
     * La parenté de la fiche lue à côté de l'arbre est celle de la personne choisie : le
     * volet de `/arbre` doit dire « mère » sur l'arbre de son fils, et non la parenté de
     * l'administrateur — ici aucune, il n'a pas de fiche.
     */
    public function testLaFicheDitLaParenteVueDeLaPersonneChoisie(): void
    {
        $this->connexionAdministrateur();

        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->extrait());
        self::assertNull($this->charge()['parente'], 'Sans fiche, l’administrateur n’a aucune parenté.');

        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule').'?depuis='.$this->idDe('ego'));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->extrait());
        self::assertSame('mère', $this->charge()['parente']);

        $this->requete('/api/arbre/personnes/'.$this->idDe('aieul').'?depuis='.$this->idDe('ego'));
        self::assertSame('père', $this->charge()['parente']);
    }

    /** @return iterable<string, array{string}> */
    public static function fournirLesRoutes(): iterable
    {
        yield 'graphe' => ['/api/arbre/graphe'];
        yield 'fiche' => ['/api/arbre/personnes/{ego}'];
    }

    /** Un membre ne choisit pas le point de vue : c'est une fonction d'administration. */
    #[DataProvider('fournirLesRoutes')]
    public function testUnMembreNeChoisitPasLePointDeVue(string $route): void
    {
        $this->rattacher(self::MEMBRE, 'ego');
        $this->connexionDe(self::MEMBRE);

        $this->requete($this->chemin($route).'?depuis='.$this->idDe('aieule'));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), $this->extrait());
        self::assertSame('acces_refuse', $this->charge()['erreur']['code']);
    }

    /** @return iterable<string, array{string}> */
    public static function fournirLesIdentifiantsInvalides(): iterable
    {
        yield 'texte' => ['abc'];
        yield 'zéro' => ['0'];
        yield 'négatif' => ['-3'];
        yield 'vide' => [''];
        yield 'nœud de personne' => ['p12'];
        yield 'au-delà d’un entier SQL' => ['99999999999999999999'];
    }

    #[DataProvider('fournirLesIdentifiantsInvalides')]
    public function testUnIdentifiantInvalideEstRefuse(string $depuis): void
    {
        $this->connexionAdministrateur();
        $this->requete('/api/arbre/graphe?depuis='.rawurlencode($depuis));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode(), $this->extrait());
        self::assertArrayHasKey('depuis', $this->charge()['erreur']['champs']);
    }

    /** `depuis[]=…` : la même `422`, pas la `400` que lèverait `getString()`. */
    public function testUnTableauEstRefuseCommeUnIdentifiantInvalide(): void
    {
        $this->connexionAdministrateur();
        $this->requete('/api/arbre/graphe?depuis[]='.$this->idDe('aieule'));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode(), $this->extrait());
        self::assertArrayHasKey('depuis', $this->charge()['erreur']['champs']);
    }

    /**
     * Une personne inconnue rend `404` : retomber en silence sur la racine afficherait un
     * arbre sous le nom de quelqu'un d'autre.
     */
    public function testUnePersonneInconnueEstIntrouvable(): void
    {
        $this->connexionAdministrateur();
        $this->requete('/api/arbre/graphe?depuis='.self::FICHE_HORS_DE_L_ARBRE);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode(), $this->extrait());
    }

    /**
     * Sous la bascule, l'administrateur lit avec le cercle d'un membre : y ajouter le point
     * de vue d'une troisième personne composerait un écran que personne ne verra jamais.
     */
    public function testSousLaBasculeLePointDeVueEstRefuse(): void
    {
        $membre = $this->rattacher(self::MEMBRE, 'ego');
        $this->connexionAdministrateur();

        $this->client->request('POST', '/api/administration/voir-comme', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['memberId' => $membre->getId()], \JSON_THROW_ON_ERROR));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->extrait());

        $this->requete('/api/arbre/graphe?depuis='.$this->idDe('aieule'));

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode(), $this->extrait());
        self::assertSame('vue_comme_membre_active', $this->charge()['erreur']['code']);
    }

    private function connexionAdministrateur(): void
    {
        $this->creerUtilisateur(self::ADMIN, ['ROLE_ADMIN']);
        $this->connexionDe(self::ADMIN);
    }

    private function connexionDe(string $courriel): void
    {
        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }

    private function rattacher(string $courriel, string $personne): Member
    {
        $identifiant = $this->creerUtilisateur($courriel)->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $courriel, $this->idDe($personne), $this->personnes[$personne]->getDisplayName(), MatchKind::PreLie);
        $membre->activer();

        $this->em()->persist($membre);
        $this->em()->flush();

        return $membre;
    }

    private function revalider(string $chemin, string $etag): void
    {
        $this->client->request('GET', $chemin, server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);
    }

    private function chemin(string $route): string
    {
        return str_replace('{ego}', (string) $this->idDe('ego'), $route);
    }

    private function extrait(): string
    {
        return mb_substr((string) $this->client->getResponse()->getContent(), 0, 300);
    }

    /**
     * @param array<string, mixed> $charge
     *
     * @return array<string, float>
     */
    private function rangees(array $charge): array
    {
        $rangees = [];
        self::assertIsArray($charge['noeuds']);

        foreach ($charge['noeuds'] as $noeud) {
            self::assertIsArray($noeud);
            if ('personne' === ($noeud['type'] ?? null)) {
                self::assertIsString($noeud['id']);
                self::assertIsNumeric($noeud['y']);
                $rangees[$noeud['id']] = (float) $noeud['y'];
            }
        }

        return $rangees;
    }
}
