<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\User;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\SourceKind;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les sources gérées par l'administration — ADR 0012, § 10.7 du contrat.
 *
 * L'arbre semé par `ArbreWebTestCase` porte **une** source, « Archives départementales
 * de la Fougerie » (`@S500011@`), citée une fois : c'est elle qui donne un
 * `citationCount` non nul.
 */
final class AdministrationDesSourcesTest extends ArbreWebTestCase
{
    private const string ADMIN = 'admin@example.invalid';
    private const string MEMBRE = 'membre@example.invalid';

    /** L'ensemble exact des clés d'une source : une clé de plus fait échouer (§ 11.4). */
    private const array CLES = [
        'id', 'gedcomXref', 'title', 'author', 'publication', 'text', 'note', 'mediaType',
        'sourceKind', 'sourceKindLabel', 'citationCount', 'createdAt', 'updatedAt',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pool = self::getContainer()->get('cache.rate_limiter');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }

        $this->creerUtilisateur(self::ADMIN, ['ROLE_ADMIN']);
        $this->creerUtilisateur(self::MEMBRE);
    }

    /* ───────────────── Les droits ───────────────── */

    public function testSansSessionLaRouteRend401(): void
    {
        $this->requete('/api/administration/sources');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statut());
    }

    public function testUnMembreNeLitNiNEcritLesSources(): void
    {
        $this->connexionDe(self::MEMBRE);

        $this->requete('/api/administration/sources');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());

        $this->envoyer('POST', '/api/administration/sources', ['title' => 'État civil de Paris', 'sourceKind' => 'acte_etat_civil']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());

        $id = $this->idDeLaSourceSemee();
        $this->envoyer('PUT', "/api/administration/sources/{$id}", ['title' => 'Détourné', 'sourceKind' => 'autre']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());

        self::assertSame(1, $this->em()->getRepository(Source::class)->count([]), 'Rien n’a été écrit.');
    }

    /* ───────────────── Le parcours ───────────────── */

    public function testLaListeCompteLesCitationsEtChercheSansAccents(): void
    {
        $this->connexionDe(self::ADMIN);
        $this->creerSource('État civil de Paris', 'acte_etat_civil');

        $this->requete('/api/administration/sources');
        self::assertSame(Response::HTTP_OK, $this->statut());
        $charge = $this->charge();
        self::assertSame(2, $charge['total']);
        self::assertSame(1, $charge['page']);

        $parTitre = array_column($charge['elements'], null, 'title');
        self::assertSame(1, $parTitre['Archives départementales de la Fougerie']['citationCount']);
        self::assertSame(0, $parTitre['État civil de Paris']['citationCount']);
        self::assertSame(self::CLES, array_keys($parTitre['État civil de Paris']));

        // « etat » trouve « État » : le repliement d'accents est celui du projet.
        $this->requete('/api/administration/sources?q=etat%20CIVIL');
        $charge = $this->charge();
        self::assertSame(1, $charge['total']);
        self::assertSame('État civil de Paris', $charge['elements'][0]['title']);

        $this->requete('/api/administration/sources?q=introuvable');
        self::assertSame(0, $this->charge()['total']);
    }

    public function testCreerUneSourceLaRendCitableEtSansXref(): void
    {
        $this->connexionDe(self::ADMIN);

        $this->envoyer('POST', '/api/administration/sources', [
            'title' => '  État civil du Nord  ',
            'author' => 'Archives départementales du Nord',
            'publication' => 'archivesdepartementales.lenord.fr — registres paroissiaux et d’état civil',
            'note' => '',
            'sourceKind' => 'acte_etat_civil',
            // Ignoré : la référence GEDCOM n'est pas saisie à l'écran.
            'gedcomXref' => '@S500916@',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        $charge = $this->charge();
        self::assertSame(self::CLES, array_keys($charge));
        self::assertSame('État civil du Nord', $charge['title'], 'Le titre est rogné.');
        self::assertNull($charge['gedcomXref'], 'Une source créée à l’écran ne porte pas de xref.');
        self::assertNull($charge['note'], 'Un champ blanc vaut null.');
        self::assertSame('acte_etat_civil', $charge['sourceKind']);
        self::assertSame("Acte d'état civil", $charge['sourceKindLabel']);
        self::assertSame(0, $charge['citationCount']);

        $source = $this->em()->find(Source::class, $charge['id']);
        self::assertInstanceOf(Source::class, $source);
        self::assertSame(SourceKind::ActeEtatCivil, $source->getSourceKind());

        $this->requete("/api/administration/sources/{$charge['id']}");
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame('Archives départementales du Nord', $this->charge()['author']);
    }

    public function testModifierRemplaceLaNoticeSansToucherAuXref(): void
    {
        $this->connexionDe(self::ADMIN);
        $id = $this->idDeLaSourceSemee();

        $this->envoyer('PUT', "/api/administration/sources/{$id}", [
            'title' => 'Archives départementales de la Fougerie — état civil',
            'publication' => 'Cotes 4 E (1802-1892)',
            'sourceKind' => 'registre_officiel',
        ]);
        self::assertSame(Response::HTTP_OK, $this->statut(), (string) $this->client->getResponse()->getContent());

        $charge = $this->charge();
        self::assertSame('Archives départementales de la Fougerie — état civil', $charge['title']);
        self::assertSame('Cotes 4 E (1802-1892)', $charge['publication']);
        self::assertSame('registre_officiel', $charge['sourceKind']);
        self::assertSame('@S500011@', $charge['gedcomXref'], 'La référence GEDCOM ne bouge pas.');
        self::assertSame(1, $charge['citationCount'], 'La citation suit la source.');
    }

    public function testUneSourceInconnueRend404(): void
    {
        $this->connexionDe(self::ADMIN);

        $this->requete('/api/administration/sources/999999');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());

        $this->envoyer('PUT', '/api/administration/sources/999999', ['title' => 'x', 'sourceKind' => 'autre']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->statut());
    }

    /* ───────────────── Ce qui est refusé ───────────────── */

    public function testUnCorpsInvalideNommeChaqueChampFautif(): void
    {
        $this->connexionDe(self::ADMIN);

        $this->envoyer('POST', '/api/administration/sources', [
            'title' => '   ',
            'author' => str_repeat('a', 256),
            'mediaType' => 12,
            'sourceKind' => 'parchemin',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        $erreur = $this->charge()['erreur'];
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertEqualsCanonicalizing(['title', 'author', 'mediaType', 'sourceKind'], array_keys($erreur['champs']));

        $this->envoyer('POST', '/api/administration/sources', ['title' => str_repeat('é', 256), 'sourceKind' => 'autre']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertArrayHasKey('title', $this->charge()['erreur']['champs']);

        // 255 caractères multioctets passent : la borne est en caractères, pas en octets.
        $this->envoyer('POST', '/api/administration/sources', ['title' => str_repeat('é', 255), 'sourceKind' => 'autre']);
        self::assertSame(Response::HTTP_CREATED, $this->statut());

        $id = $this->idDeLaSourceSemee();
        $this->envoyer('PUT', "/api/administration/sources/{$id}", ['title' => 'Sans type']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
        self::assertArrayHasKey('sourceKind', $this->charge()['erreur']['champs']);

        $this->client->request('POST', '/api/administration/sources', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: 'pas du json');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statut());
    }

    public function testSousLaBasculeLAdministrateurLitSansEcrire(): void
    {
        $identifiant = $this->em()->getRepository(User::class)->findOneBy(['email' => self::MEMBRE])?->getId();
        self::assertNotNull($identifiant);
        $membre = new Member($identifiant, self::MEMBRE, $this->idDe('ego'), 'Ego', MatchKind::PreLie);
        $membre->activer();
        $this->em()->persist($membre);
        $this->em()->flush();

        $this->connexionDe(self::ADMIN);
        $this->envoyer('POST', '/api/administration/voir-comme', ['memberId' => $membre->getId()]);
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->requete('/api/administration/sources');
        self::assertSame(Response::HTTP_OK, $this->statut());

        $this->envoyer('POST', '/api/administration/sources', ['title' => 'Sous la bascule', 'sourceKind' => 'autre']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statut());
    }

    /* ───────────────── Outils ───────────────── */

    private function creerSource(string $titre, string $genre): int
    {
        $this->envoyer('POST', '/api/administration/sources', ['title' => $titre, 'sourceKind' => $genre]);
        self::assertSame(Response::HTTP_CREATED, $this->statut());
        $id = $this->charge()['id'];
        self::assertIsInt($id);

        return $id;
    }

    private function idDeLaSourceSemee(): int
    {
        $source = $this->em()->getRepository(Source::class)->findOneBy(['gedcomXref' => '@S500011@']);
        self::assertNotNull($source);

        return (int) $source->getId();
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

    private function connexionDe(string $courriel): void
    {
        $this->envoyer('POST', '/api/connexion', ['email' => $courriel, 'password' => 'motdepasse-de-test']);
        self::assertSame(Response::HTTP_OK, $this->statut(), 'La connexion de test doit réussir.');
    }
}
