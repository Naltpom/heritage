<?php

declare(strict_types=1);

namespace App\Tests\Soutien;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\UserStatus;
use App\Module\Soutien\Entity\Releve;
use App\Module\Soutien\Service\ProfilLiberapay;
use App\Module\Soutien\Service\ReglagesDuSoutien;
use App\Tests\Tree\ArbreWebTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/soutien` — de bout en bout, Liberapay remplacé par
 * {@see SourceLiberapayProgrammable}.
 */
final class RoutesDuSoutienTest extends ArbreWebTestCase
{
    private const string MEMBRE = 'membre-soutien@example.invalid';

    protected function setUp(): void
    {
        parent::setUp();

        SourceLiberapayProgrammable::reinitialiser();
        // L'échec retenu vit dans un pool sur disque, partagé entre les cas : on l'oublie.
        $cache = self::getContainer()->get('cache.arbre');
        \assert($cache instanceof CacheItemPoolInterface);
        $cache->deleteItem('soutien.liberapay.echec');

        $this->creerLecteurHorsCercle(self::MEMBRE);
    }

    public function testSansPseudoLaPageDitQueLesDonsNeSontPasOuverts(): void
    {
        $this->reglages('', 45);
        $this->connexionDe(self::MEMBRE);

        $this->requete('/api/soutien');

        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame(['configure' => false, 'objectifMensuel' => 45], $this->charge());
        self::assertSame(0, SourceLiberapayProgrammable::$appels, 'Rien ne sort vers Liberapay sans pseudo.');
    }

    public function testUnPseudoMalformeNeSortPas(): void
    {
        $this->reglages('../admin', 45);
        $this->connexionDe(self::MEMBRE);

        $this->requete('/api/soutien');

        self::assertFalse($this->charge()['configure']);
        self::assertSame(0, SourceLiberapayProgrammable::$appels);
    }

    public function testLePremierAffichageDuJourReleveLiberapayUneSeuleFois(): void
    {
        $this->reglages('Naltpom', 45);
        SourceLiberapayProgrammable::$profil = new ProfilLiberapay(750, 'EUR', 3);
        $this->connexionDe(self::MEMBRE);

        $this->requete('/api/soutien');
        $this->requete('/api/soutien');

        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertSame(1, SourceLiberapayProgrammable::$appels, 'Le relevé du jour ne se reprend pas.');
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));

        $charge = $this->charge();
        self::assertTrue($charge['configure']);
        self::assertSame('https://liberapay.com/Naltpom/donate', $charge['lien']);
        self::assertSame(45, $charge['objectifMensuel']);
        self::assertIsArray($charge['actuel']);
        self::assertSame(750, $charge['actuel']['parSemaine']);
        self::assertSame(3250, $charge['actuel']['parMois']); // 7,50 € × 52 / 12
        self::assertSame(3, $charge['actuel']['donateurs']);
        self::assertSame(date('Y-m-d'), $charge['depuis']);
        self::assertIsArray($charge['mois']);
        self::assertCount(1, $charge['mois']);

        self::assertCount(1, $this->em()->getRepository(Releve::class)->findAll());
    }

    public function testLiberapayMuetLaPageRestDebout(): void
    {
        $this->reglages('Naltpom', 45);
        $this->connexionDe(self::MEMBRE);

        $this->requete('/api/soutien');
        self::assertSame(Response::HTTP_OK, $this->statut());
        self::assertNull($this->charge()['actuel']);

        // Une panne ne se repique pas à chaque affichage.
        SourceLiberapayProgrammable::$profil = new ProfilLiberapay(750, 'EUR', 3);
        $this->requete('/api/soutien');
        self::assertSame(1, SourceLiberapayProgrammable::$appels);
        self::assertNull($this->charge()['actuel']);
    }

    public function testUnCompteEnAttenteNeLitPasLaPage(): void
    {
        $this->reglages('Naltpom', 45);
        $this->creerUtilisateur('attente-soutien@example.invalid', statut: UserStatus::EnAttente);
        $this->connexionDe('attente-soutien@example.invalid');

        $this->requete('/api/soutien');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->statut());
    }

    public function testSansSessionLaRouteEstFermee(): void
    {
        $this->requete('/api/soutien');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statut());
    }

    private function connexionDe(string $courriel): void
    {
        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->statut(), 'La connexion de test doit réussir.');
    }

    private function statut(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function reglages(string $pseudo, int $objectif): void
    {
        $em = $this->em();
        $em->persist(new Setting(ReglagesDuSoutien::CLE_PSEUDO, $pseudo, SettingType::Texte, 'Pseudo Liberapay'));
        $em->persist(new Setting(ReglagesDuSoutien::CLE_OBJECTIF, (string) $objectif, SettingType::Entier, 'Objectif mensuel', null, 0, 10000));
        $em->flush();
    }
}
