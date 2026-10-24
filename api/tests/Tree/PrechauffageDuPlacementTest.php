<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Tree\Service\PlacementPartage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le placement du lecteur est prêt **avant** qu'il ouvre l'arbre : la connexion et la
 * session relue au démarrage le calculent une fois la réponse partie
 * ({@see \App\Module\Tree\Service\PrechauffageDuPlacement}).
 *
 * On le lit dans le `Server-Timing` du graphe, `placement … cache=hit`, après avoir vidé le
 * cache juste avant le geste qui doit le remplir. Le témoin
 * {@see testSansPrechauffageLeGrapheCalculeLePlacement()} prouve que la mesure distingue
 * les deux cas : sans lui, un `cache=hit` venu d'ailleurs ferait passer le test pour rien.
 */
final class PrechauffageDuPlacementTest extends ArbreWebTestCase
{
    public function testLaConnexionPrepareLePlacementDuLecteur(): void
    {
        $this->rattacherA('membre@heritage.test', 'ego');
        $this->viderLeCache();

        $this->connexionDe('membre@heritage.test');

        self::assertMatchesRegularExpression('/placement;dur=[\d.]+;desc="(x\d+ )?cache=hit"/', $this->minutageDuGraphe());
    }

    public function testLaSessionRelueAuDemarrageLePrepareAussi(): void
    {
        $this->rattacherA('membre@heritage.test', 'ego');
        $this->connexionDe('membre@heritage.test');
        $this->viderLeCache();

        $this->requete('/api/session');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        self::assertMatchesRegularExpression('/placement;dur=[\d.]+;desc="(x\d+ )?cache=hit"/', $this->minutageDuGraphe());
    }

    public function testSansPrechauffageLeGrapheCalculeLePlacement(): void
    {
        $this->rattacherA('membre@heritage.test', 'ego');
        $this->connexionDe('membre@heritage.test');
        $this->viderLeCache();

        self::assertMatchesRegularExpression('/placement;dur=[\d.]+;desc="(x\d+ )?cache=miss"/', $this->minutageDuGraphe());
    }

    /** Un compte en attente ne lit pas le graphe : rien à préparer pour lui. */
    public function testUnCompteEnAttenteNeDeclencheRien(): void
    {
        $this->creerUtilisateur('attente@heritage.test');
        $this->viderLeCache();

        $this->connexionDe('attente@heritage.test');

        $partage = self::getContainer()->get(PlacementPartage::class);
        \assert($partage instanceof PlacementPartage);
        self::assertFalse($this->cache()->hasItem($partage->cle()), 'Aucun placement calculé pour un compte sans accès au graphe.');
    }

    private function minutageDuGraphe(): string
    {
        $this->requete('/api/arbre/graphe');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        return (string) $this->client->getResponse()->headers->get('Server-Timing');
    }

    private function cache(): CacheItemPoolInterface
    {
        $cache = self::getContainer()->get('cache.arbre');
        \assert($cache instanceof CacheItemPoolInterface);

        return $cache;
    }

    private function viderLeCache(): void
    {
        self::assertTrue($this->cache()->clear());
    }

    private function rattacherA(string $courriel, string $personne): void
    {
        $identifiant = $this->creerUtilisateur($courriel)->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $courriel, $this->idDe($personne), $this->personnes[$personne]->getDisplayName(), MatchKind::PreLie);
        $membre->activer();

        $this->em()->persist($membre);
        $this->em()->flush();
    }

    private function connexionDe(string $courriel): void
    {
        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }
}
