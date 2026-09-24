<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\Service\UrlPublique;
use App\Module\Tree\Command\ResoudreLesLieuxCommand;
use App\Module\Tree\Entity\Place;
use App\Module\Tree\Service\AppariementDesLieux;
use App\Module\Tree\Service\GeocodeurNominatim;
use App\Module\Tree\Service\RecoursEnLigne;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `heritage:resoudre-les-lieux --en-ligne`, de bout en bout sur une vraie base — et
 * **sans jamais toucher le réseau** : le géocodeur réel reçoit un `MockHttpClient` qui
 * note chaque requête.
 *
 * ## L'arbre de ces cas
 *
 * Celui d'{@see ArbreWebTestCase}, auquel `setUp()` ajoute trois chaînes choisies pour
 * couvrir chacune une règle du recours :
 *
 * | chaîne | passe locale | demandée en ligne ? |
 * |---|---|---|
 * | `Saule, Ombrage, Sylvanie` | C (résolue) | non — déjà située |
 * | `Charmille, Ombrage, Sylvanie` | C (résolue) | non — déjà située |
 * | `Sorbière, Fougerie, Florie` | F | **oui** — le tiers rend un pays, refusé |
 * | `Liège, Belgique` | F | **oui** — le tiers rend la ville |
 * | `Tilleul, Florie` | **D** (deux Tilleul) | **non** — on ne laisse pas choisir un tiers |
 * | `Castelbrun` | F | **non** — un seul morceau, trop ambigu |
 *
 * Tous les noms sont inventés (ADR 0006), sauf Liège, qui est une ville et non une
 * personne.
 */
final class ResolutionDesLieuxEnLigneTest extends ArbreWebTestCase
{
    private string $dossier;

    /** @var list<array{url: string, q: string, parametres: array<string, mixed>, agent: string}> */
    private array $requetes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = \dirname(__DIR__, 2).'/var/gazetteer_en_ligne_'.getmypid();

        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0777, true) && !is_dir($this->dossier)) {
            self::fail('Impossible de créer le dossier de fixtures : '.$this->dossier);
        }

        file_put_contents($this->dossier.'/communes-fr.csv', "# nom;departement;region;lat;lon;population;rang\n"
            ."Saule;18;24;46.12;2.56;540;0\nCharmille;58;27;47.03;3.11;820;0\n"
            ."Tilleul;18;24;46.50;2.40;300;0\nTilleul;58;27;47.20;3.30;280;0\n");
        file_put_contents($this->dossier.'/villes-monde.csv', "# nom;pays_iso2;lat;lon;population\nOran;DZ;35.69;-0.64;850000\n");
        file_put_contents($this->dossier.'/villes-canada.csv', "# nom;pays_iso2;lat;lon;population\nTrochu;CA;51.83;-113.21;1000\n");
        file_put_contents($this->dossier.'/departements.csv', "# code;nom\n18;Cher\n58;Nièvre\n");
        file_put_contents($this->dossier.'/regions.csv', "# code;nom\n24;Centre-Val de Loire\n27;Bourgogne-Franche-Comté\n");

        // Trois lieux de plus, posés en SQL sur des personnes du socle : la commande lit
        // les colonnes `*_place`, elle ne passe pas par les entités.
        $connexion = $this->em()->getConnection();
        $connexion->executeStatement('UPDATE tree_person SET birth_place = ? WHERE id = ?', ['Liège, Belgique', $this->idDe('aieul')]);
        $connexion->executeStatement('UPDATE tree_person SET death_place = ? WHERE id = ?', ['Tilleul, Florie', $this->idDe('aieul')]);
        $connexion->executeStatement('UPDATE tree_person SET birth_place = ? WHERE id = ?', ['Castelbrun', $this->idDe('sansDate')]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }

        @rmdir($this->dossier);

        parent::tearDown();
    }

    /**
     * Le cas nominal, et toutes les règles de sélection d'un coup : **seules les passes F
     * de deux morceaux ou plus** partent, la ville est située, le pays est refusé.
     */
    public function testUnLieuEtrangerEstSitueEnLigneEtLuiSeul(): void
    {
        $testeur = $this->lancer(['--en-ligne' => true]);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());
        self::assertSame(
            ['Liège, Belgique', 'Sorbière, Fougerie, Florie'],
            $this->chainesDemandees(),
            'Ni la passe D (Tilleul), ni le mot seul (Castelbrun), ni les lieux déjà situés.',
        );

        $lignes = $this->lignesParCle();

        $liege = $lignes['LIEGE BELGIQUE'];
        self::assertSame(50.6451, $liege->getLat());
        self::assertSame(5.5734, $liege->getLon());
        self::assertSame(Place::SOURCE_NOMINATIM, $liege->getSource());
        self::assertSame(50, $liege->getConfiance());
        self::assertSame('BE', $liege->getPays());
        self::assertSame('Liège', $liege->getCommune());
        self::assertSame('Liège (BE)', $liege->getLibelle());

        // Le tiers a rendu un pays : refusé, et **mémorisé** comme introuvable.
        $sorbiere = $lignes['SORBIERE FOUGERIE FLORIE'];
        self::assertNull($sorbiere->getLat());
        self::assertSame(Place::SOURCE_NOMINATIM, $sorbiere->getSource(), 'Le verdict négatif est gardé : c’est la mémoire des trente jours.');
        self::assertSame(0, $sorbiere->getConfiance());

        self::assertSame(Place::SOURCE_AUCUNE, $lignes['TILLEUL FLORIE']->getSource(), 'Une passe D reste une passe D.');
        self::assertSame(Place::SOURCE_AUCUNE, $lignes['CASTELBRUN']->getSource());

        self::assertStringContainsString('Requêtes envoyées à Nominatim', $testeur->getDisplay());
        self::assertStringContainsString('homonymes', $testeur->getDisplay());
    }

    /**
     * **Chaque requête est identifiée et ne porte que la chaîne du lieu** — vérifié ici
     * sur les requêtes réellement émises par la commande, pas seulement par le géocodeur
     * isolé.
     */
    public function testLesRequetesNePortentQueLeLieuEtUnUserAgent(): void
    {
        $this->lancer(['--en-ligne' => true]);

        self::assertNotEmpty($this->requetes);

        foreach ($this->requetes as $requete) {
            self::assertSame(['q', 'format', 'limit', 'accept-language', 'addressdetails'], array_keys($requete['parametres']));
            self::assertStringStartsWith('User-Agent: Heritage/1.0 (+https://heritage.exemple.test; contact@exemple.test)', $requete['agent']);

            // Aucun nom de personne de l'arbre ne doit apparaître dans l'URL.
            foreach (['Sarriette', 'Émeri', 'Sureau', 'Tilleul', 'Pyroxène', 'Romarin'] as $nom) {
                self::assertStringNotContainsString(rawurlencode($nom), $requete['url']);
                self::assertStringNotContainsString($nom, urldecode($requete['url']));
            }
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  PAS DE SECONDE DEMANDE AVANT TRENTE JOURS.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * La commande tourne toutes les nuits. Sans mémoire, elle redemanderait chaque soir à
     * un service bénévole les mêmes lieux qu'il a déjà dit ne pas connaître. Relancée le
     * lendemain, elle n'envoie **rien** ; trente et un jours plus tard, elle redemande le
     * seul lieu introuvable — et date sa nouvelle demande, même si la réponse est la même.
     */
    public function testUnLieuIntrouvableNEstPasRedemandeAvantTrenteJours(): void
    {
        $this->lancer(['--en-ligne' => true]);
        self::assertCount(2, $this->requetes);

        $this->requetes = [];
        $testeur = $this->lancer(['--en-ligne' => true]);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
        self::assertSame([], $this->chainesDemandees(), 'Le lendemain : aucune requête. Liège est situé, Sorbière est introuvable depuis hier.');
        self::assertStringContainsString('ni en ligne (Nominatim, le ', $testeur->getDisplay());

        $ilYA = (new \DateTimeImmutable('-'.(RecoursEnLigne::JOURS_AVANT_NOUVELLE_DEMANDE + 1).' days'))->format('Y-m-d H:i:s');
        $this->em()->getConnection()->executeStatement('UPDATE tree_place SET resolu_le = ? WHERE raw_key = ?', [$ilYA, 'SORBIERE FOUGERIE FLORIE']);
        $this->em()->clear();

        $this->lancer(['--en-ligne' => true]);

        self::assertSame(['Sorbière, Fougerie, Florie'], $this->chainesDemandees(), 'Trente et un jours après : redemandé, et lui seul.');

        $this->em()->clear();
        self::assertSame(
            (new \DateTimeImmutable())->format('Y-m-d'),
            $this->lignesParCle()['SORBIERE FOUGERIE FLORIE']->getResoluLe()->format('Y-m-d'),
            'La nouvelle demande est datée, même sans rien changer d’autre — sinon elle repartirait chaque nuit.',
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  UNE PANNE DU TIERS NE FAIT PAS TOMBER LA COMMANDE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Elle tourne au déploiement et la nuit : si Nominatim ne répond pas, la commande
     * sort **`SUCCESS`**, le dit, **cesse d'interroger** après la première panne — une
     * panne ne se sonde pas cent fois — et **ne mémorise rien** : la prochaine exécution
     * redemandera.
     */
    public function testUnePanneReseauLaisseLeLieuNonResoluEtLaCommandeVerte(): void
    {
        $testeur = $this->lancer(['--en-ligne' => true], static fn () => throw new TransportException('Could not resolve host: nominatim.openstreetmap.org'));

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());
        self::assertCount(1, $this->requetes, 'Le coupe-circuit : une seule tentative, puis plus rien pour cette exécution.');
        self::assertStringContainsString('Nominatim est tombé', $testeur->getDisplay());
        self::assertStringContainsString('Nominatim injoignable', $testeur->getDisplay());

        $liege = $this->lignesParCle()['LIEGE BELGIQUE'];
        self::assertNull($liege->getLat());
        self::assertSame(Place::SOURCE_AUCUNE, $liege->getSource(), 'Une panne n’est pas un verdict : rien n’est mémorisé.');

        // Et la nuit suivante, le tiers revenu, le lieu est bien redemandé.
        $this->requetes = [];
        $this->em()->clear();
        $this->lancer(['--en-ligne' => true]);

        self::assertContains('Liège, Belgique', $this->chainesDemandees());
    }

    /** Une correction manuelle n'est **jamais** envoyée au tiers — même sans coordonnées. */
    public function testUneCorrectionManuelleNEstJamaisDemandeeEnLigne(): void
    {
        $manuel = (new Place('Liège, Belgique', 'LIEGE BELGIQUE', 'À corriger — voir l’acte'))
            ->setSource(Place::SOURCE_MANUEL);
        $this->em()->persist($manuel);
        $this->em()->flush();
        $this->em()->clear();

        $this->lancer(['--en-ligne' => true, '--forcer' => true]);

        self::assertNotContains('Liège, Belgique', $this->chainesDemandees());

        $this->em()->clear();
        $apres = $this->lignesParCle()['LIEGE BELGIQUE'];
        self::assertSame(Place::SOURCE_MANUEL, $apres->getSource());
        self::assertSame('À corriger — voir l’acte', $apres->getLibelle());
        self::assertNull($apres->getLat());
    }

    /** Sans `--en-ligne`, et sous `--dry-run` : aucune requête. */
    public function testSansLOptionOuEnBlancRienNePart(): void
    {
        $this->lancer();
        self::assertSame([], $this->requetes, 'Sans --en-ligne.');

        $testeur = $this->lancer(['--en-ligne' => true, '--dry-run' => true]);
        self::assertSame([], $this->requetes, 'Sous --dry-run.');
        self::assertStringContainsString('--en-ligne ignoré sous --dry-run', $testeur->getDisplay());
    }

    /**
     * **L'interrupteur de l'instance l'emporte sur l'option.** `HERITAGE_GEOCODAGE_EN_LIGNE=0`
     * coupe tout : la commande de nuit peut garder son `--en-ligne`, rien ne part. Vide,
     * c'est l'option qui décide.
     */
    public function testLaVariableDEnvironnementCoupeLeRecours(): void
    {
        foreach (['0', 'false', 'OFF', 'non'] as $valeur) {
            $testeur = $this->lancer(['--en-ligne' => true], interrupteur: $valeur);

            self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
            self::assertSame([], $this->requetes, 'HERITAGE_GEOCODAGE_EN_LIGNE='.$valeur);
            self::assertStringContainsString('HERITAGE_GEOCODAGE_EN_LIGNE='.$valeur, $testeur->getDisplay());
        }

        $this->lancer(['--en-ligne' => true], interrupteur: '');
        self::assertNotSame([], $this->requetes, 'Vide : l’option décide, et elle a été donnée.');
    }

    /**
     * **Le câblage réel du conteneur de test ne joint pas le réseau.** La commande tirée du
     * conteneur, avec `--en-ligne`, reçoit un géocodeur branché sur un `MockHttpClient`
     * sans réponse (`config/services/tree.yaml`, `when@test`) : elle voit une panne, reste
     * verte, et rien ne sort de la machine.
     */
    public function testLeConteneurDeTestNeJointJamaisLeReseau(): void
    {
        self::assertNotNull(self::$kernel);

        $application = new Application(self::$kernel);
        $testeur = new CommandTester($application->find('heritage:resoudre-les-lieux'));
        $testeur->execute([
            '--fichier-communes' => $this->dossier.'/communes-fr.csv',
            '--fichier-villes' => $this->dossier.'/villes-monde.csv',
            '--en-ligne' => true,
        ]);

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());
        self::assertStringContainsString('Nominatim est tombé', $testeur->getDisplay());
    }

    /**
     * La commande, construite avec le **vrai** géocodeur et un transport simulé.
     *
     * @param array<string, bool> $options
     */
    private function lancer(array $options = [], ?callable $transport = null, ?string $interrupteur = null): CommandTester
    {
        $this->requetes = [];

        $client = new MockHttpClient(function (string $methode, string $url, array $opts) use ($transport): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $parametres);
            $q = \is_string($parametres['q'] ?? null) ? $parametres['q'] : '';
            $this->requetes[] = ['url' => $url, 'q' => $q, 'parametres' => $parametres, 'agent' => (string) ($opts['normalized_headers']['user-agent'][0] ?? '')];

            if (null !== $transport) {
                $transport();
            }

            return new MockResponse((string) json_encode(self::reponsePour($q)));
        });

        $commande = new ResoudreLesLieuxCommand(
            $this->em(),
            new AppariementDesLieux(),
            \dirname(__DIR__, 2),
            new CleDeRecherche(),
            new GeocodeurNominatim($client, new MockClock(), new UrlPublique('https://heritage.exemple.test'), 'Heritage <contact@exemple.test>'),
            $interrupteur,
        );

        $testeur = new CommandTester($commande);
        $testeur->execute([
            '--fichier-communes' => $this->dossier.'/communes-fr.csv',
            '--fichier-villes' => $this->dossier.'/villes-monde.csv',
            ...$options,
        ]);

        return $testeur;
    }

    /**
     * Ce que « Nominatim » répond : Liège au rang d'une ville, la Belgique pour tout le
     * reste — c'est-à-dire le résultat trop grossier que le géocodeur doit refuser.
     *
     * @return list<array<string, mixed>>
     */
    private static function reponsePour(string $q): array
    {
        if ('Liège, Belgique' === $q) {
            return [[
                'lat' => '50.6451', 'lon' => '5.5734', 'category' => 'boundary', 'type' => 'administrative',
                'place_rank' => 16, 'addresstype' => 'city', 'name' => 'Liège',
                'address' => ['city' => 'Liège', 'county' => 'Liège', 'state' => 'Wallonie', 'country' => 'Belgique', 'country_code' => 'be'],
            ]];
        }

        return [[
            'lat' => '50.6402809', 'lon' => '4.6667145', 'category' => 'boundary', 'type' => 'administrative',
            'place_rank' => 4, 'addresstype' => 'country', 'name' => 'Belgique',
            'address' => ['country' => 'Belgique', 'country_code' => 'be'],
        ]];
    }

    /** @return list<string> */
    private function chainesDemandees(): array
    {
        $chaines = array_map(static fn (array $r): string => $r['q'], $this->requetes);
        sort($chaines);

        return $chaines;
    }

    /** @return array<string, Place> */
    private function lignesParCle(): array
    {
        $lignes = [];

        foreach ($this->em()->getRepository(Place::class)->findAll() as $ligne) {
            $lignes[$ligne->getRawKey()] = $ligne;
        }

        return $lignes;
    }
}
