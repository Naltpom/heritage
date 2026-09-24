<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Service\UrlPublique;
use App\Module\Tree\Entity\Place;
use App\Module\Tree\Service\GeocodageIndisponible;
use App\Module\Tree\Service\GeocodeurEnLigne;
use App\Module\Tree\Service\GeocodeurNominatim;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le géocodeur en ligne, **sans réseau** : `MockHttpClient` pour le tiers, `MockClock`
 * pour la politique d'une requête par seconde.
 *
 * Ce que ce fichier garde, et que le test de la commande ne peut pas garder au même
 * grain : **ce qui sort** (la chaîne du lieu, rien d'autre, avec un `User-Agent`), **ce
 * qu'on accepte** (une commune, jamais un pays), et **la différence entre « rien » et
 * « pas de réponse »** — la seconde ne doit jamais passer pour la première.
 *
 * Les réponses simulées reprennent la forme réelle de `format=jsonv2` (relevée sur le
 * service le 2026-09-24) : `addresstype`, `place_rank`, `address.country_code`, et des
 * coordonnées **en chaînes**, comme Nominatim les rend.
 */
final class GeocodeurNominatimTest extends TestCase
{
    /**
     * Un village situé : commune, subdivision, pays ISO2, coordonnées, et la provenance
     * `nominatim` à confiance 50 — sous toutes les passes locales.
     */
    public function testUnVillageEstSitue(): void
    {
        $geocodeur = $this->geocodeur([$this->reponse(self::village())]);

        $trouve = $geocodeur->chercher('Wilczyce, Pologne');

        self::assertNotNull($trouve);
        self::assertSame(51.0741, $trouve['lat']);
        self::assertSame(16.2297, $trouve['lon']);
        self::assertSame('Wilczyce', $trouve['commune']);
        self::assertSame('powiat legnicki', $trouve['subdivision'], 'Le comté d’abord : c’est l’équivalent du département.');
        self::assertSame('PL', $trouve['pays'], 'Le code pays en majuscules, depuis address.country_code.');
        self::assertSame('Wilczyce (PL)', $trouve['libelle']);
        self::assertSame(Place::SOURCE_NOMINATIM, $trouve['source']);
        self::assertSame(GeocodeurEnLigne::CONFIANCE, $trouve['confiance']);
        self::assertSame(50, $trouve['confiance']);
        self::assertSame(GeocodeurEnLigne::PASSE, $trouve['passe']);
    }

    /**
     * **Un pays n'est pas une réponse** pour une chaîne qui nomme une commune.
     *
     * C'est le cas type : Nominatim ne connaît pas « Zzyzx » mais connaît la Belgique, et
     * rend la Belgique. Une bulle au centre du pays serait vraie et inutile — la carte
     * « plausible et fausse » que la passe D existe pour éviter. On rend `null`, qui est
     * un verdict (« rien d'acceptable ») et non une panne.
     */
    public function testUnResultatAuNiveauDuPaysEstRefuse(): void
    {
        $pays = [
            'lat' => '50.6402809', 'lon' => '4.6667145', 'category' => 'boundary', 'type' => 'administrative',
            'place_rank' => 4, 'addresstype' => 'country', 'name' => 'Belgique',
            'address' => ['country' => 'Belgique', 'country_code' => 'be'],
        ];

        self::assertNull($this->geocodeur([$this->reponse($pays)])->chercher('Zzyzx, Belgique'));
    }

    /**
     * Une région, un comté : refusés aussi, **quel que soit leur rang**. Et une route —
     * plus fine, mais c'est un homonyme choisi à notre place (« rue de Liège »).
     */
    public function testUneRegionUnComteOuUneRouteSontRefuses(): void
    {
        $region = ['lat' => '50.5', 'lon' => '4.8', 'category' => 'boundary', 'type' => 'administrative', 'place_rank' => 8, 'addresstype' => 'state', 'name' => 'Wallonie', 'address' => ['country_code' => 'be']];
        $comte = ['lat' => '45.1', 'lon' => '0.7', 'category' => 'boundary', 'type' => 'administrative', 'place_rank' => 12, 'addresstype' => 'county', 'name' => 'Dordogne', 'address' => ['country_code' => 'fr']];
        $route = ['lat' => '50.84', 'lon' => '4.35', 'category' => 'highway', 'type' => 'residential', 'place_rank' => 26, 'addresstype' => 'road', 'name' => 'Rue de Liège', 'address' => ['country_code' => 'be']];

        foreach ([$region, $comte, $route] as $resultat) {
            self::assertNull($this->geocodeur([$this->reponse($resultat)])->chercher('Quelque part, Ailleurs'), $resultat['addresstype']);
        }
    }

    /**
     * Une entité administrative **au rang d'une commune** passe même sans rôle de
     * localité : c'est la commune que Nominatim n'étiquette pas `city` ou `village`.
     */
    public function testUneCommuneAdministrativeDeRangSuffisantEstAcceptee(): void
    {
        $commune = ['lat' => '50.6451', 'lon' => '5.5734', 'category' => 'boundary', 'type' => 'administrative', 'place_rank' => 16, 'addresstype' => 'administrative', 'name' => 'Liège', 'address' => ['state' => 'Wallonie', 'country_code' => 'be']];

        $trouve = $this->geocodeur([$this->reponse($commune)])->chercher('Liège, Belgique');

        self::assertNotNull($trouve);
        self::assertSame('Liège', $trouve['commune']);
        self::assertSame('Wallonie', $trouve['subdivision'], 'Sans comté, la région.');
    }

    /** Aucune réponse : `null`, le verdict « introuvable ». */
    public function testUneListeVideRendNull(): void
    {
        self::assertNull($this->geocodeur([new MockResponse('[]')])->chercher('Nullepart, Pologne'));
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  SEULE LA CHAÎNE DU LIEU SORT — ET L'AGENT EST IDENTIFIÉ.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * La requête est relue paramètre par paramètre : `q` porte la chaîne verbatim, les
     * quatre autres sont des constantes, et **il n'y en a pas d'autre**. Un sixième
     * paramètre ajouté un jour — un identifiant de personne « pour le journal » — ferait
     * rougir ce cas.
     *
     * Le `User-Agent` est exigé par la politique d'usage de Nominatim ; il porte l'adresse
     * de l'instance et son contact, **pas** l'expéditeur avec son libellé.
     */
    public function testLaRequeteNePorteQueLaChaineDuLieu(): void
    {
        $vues = [];
        $client = new MockHttpClient(function (string $methode, string $url, array $options) use (&$vues): MockResponse {
            $vues[] = ['methode' => $methode, 'url' => $url, 'entetes' => $options['normalized_headers'] ?? []];

            return $this->reponse(self::village());
        });

        $geocodeur = new GeocodeurNominatim($client, new MockClock(), new UrlPublique('https://heritage.exemple.test/'), 'Heritage <contact@exemple.test>');
        $geocodeur->chercher('Gdańsk, Pologne');

        self::assertCount(1, $vues);
        self::assertSame('GET', $vues[0]['methode']);

        $morceaux = parse_url($vues[0]['url']);
        self::assertIsArray($morceaux);
        self::assertSame('nominatim.openstreetmap.org', $morceaux['host'] ?? null);
        self::assertSame('/search', $morceaux['path'] ?? null);

        parse_str($morceaux['query'] ?? '', $parametres);
        self::assertSame(
            ['q' => 'Gdańsk, Pologne', 'format' => 'jsonv2', 'limit' => '1', 'accept-language' => 'fr', 'addressdetails' => '1'],
            $parametres,
            'La chaîne du lieu, quatre constantes, et RIEN d’autre.',
        );

        $agent = $vues[0]['entetes']['user-agent'][0] ?? '';
        self::assertSame('User-Agent: Heritage/1.0 (+https://heritage.exemple.test; contact@exemple.test)', $agent);
    }

    /** Sans configuration d'instance, l'agent se nomme quand même — sans rien inventer. */
    public function testSansConfigurationLAgentSeNommeSansContact(): void
    {
        $agent = null;
        $client = new MockHttpClient(function (string $methode, string $url, array $options) use (&$agent): MockResponse {
            $agent = $options['normalized_headers']['user-agent'][0] ?? null;

            return new MockResponse('[]');
        });

        (new GeocodeurNominatim($client, new MockClock(), new UrlPublique(null), null))->chercher('Liège, Belgique');

        self::assertSame('User-Agent: Heritage/1.0', $agent);
    }

    /**
     * **Une requête par seconde au plus** — mesuré sur l'horloge, sans dormir.
     *
     * Trois appels d'affilée : l'horloge doit avoir avancé d'au moins deux secondes, car
     * le géocodeur attend avant le deuxième et avant le troisième. Le premier, lui, part
     * sans attendre : une exécution qui n'a qu'un lieu à demander ne perd pas de seconde.
     */
    public function testUneRequeteParSecondeAuPlus(): void
    {
        $horloge = new MockClock('2026-09-24 03:30:00');
        $client = new MockHttpClient([new MockResponse('[]'), new MockResponse('[]'), new MockResponse('[]')]);
        $geocodeur = new GeocodeurNominatim($client, $horloge, new UrlPublique('https://heritage.exemple.test'), null);
        $depart = (float) $horloge->now()->format('U.u');

        $geocodeur->chercher('Un, Deux');
        self::assertSame($depart, (float) $horloge->now()->format('U.u'), 'Le premier appel part sans attendre.');

        $geocodeur->chercher('Trois, Quatre');
        $geocodeur->chercher('Cinq, Six');

        self::assertGreaterThanOrEqual(2.0, (float) $horloge->now()->format('U.u') - $depart);
        self::assertSame(3, $client->getRequestsCount());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  UNE PANNE N'EST PAS UN « INTROUVABLE ».
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Réseau coupé, quota (`429`), maintenance (`503`), page d'erreur HTML : le géocodeur
     * **lève** `GeocodageIndisponible` au lieu de rendre `null`. La différence est toute la
     * mémoire des échecs — un `null` fait taire le lieu trente jours, une panne non.
     */
    public function testUnePanneLeveAuLieuDeRendreRien(): void
    {
        $pannes = [
            'réseau' => new MockResponse('', ['error' => 'Could not resolve host: nominatim.openstreetmap.org']),
            'quota' => new MockResponse('{"error":"Too many requests"}', ['http_code' => 429]),
            'maintenance' => new MockResponse('<html>Service Unavailable</html>', ['http_code' => 503]),
            'corps illisible' => new MockResponse('<html>pas du JSON</html>'),
            'forme inattendue' => new MockResponse('{"error":"quelque chose"}'),
        ];

        foreach ($pannes as $cas => $reponse) {
            try {
                $this->geocodeur([$reponse])->chercher('Liège, Belgique');
                self::fail('Une panne ('.$cas.') doit lever GeocodageIndisponible.');
            } catch (GeocodageIndisponible) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(GeocodageIndisponible::class);
        $this->geocodeur([static fn () => throw new TransportException('Connexion refusée')])->chercher('Liège, Belgique');
    }

    /** @param list<MockResponse|callable> $reponses */
    private function geocodeur(array $reponses): GeocodeurNominatim
    {
        $client = new MockHttpClient(array_map(
            static fn (MockResponse|callable $r): callable => $r instanceof MockResponse ? static fn (): MockResponse => $r : $r,
            $reponses,
        ));

        return new GeocodeurNominatim($client, new MockClock(), new UrlPublique('https://heritage.exemple.test'), 'contact@exemple.test');
    }

    /** @param array<string, mixed> $resultat */
    private function reponse(array $resultat): MockResponse
    {
        return new MockResponse((string) json_encode([$resultat]), ['response_headers' => ['content-type' => 'application/json']]);
    }

    /** @return array<string, mixed> un hameau polonais, forme réelle de `jsonv2` */
    private static function village(): array
    {
        return [
            'place_id' => 123, 'lat' => '51.0741', 'lon' => '16.2297',
            'category' => 'place', 'type' => 'village', 'place_rank' => 19, 'addresstype' => 'village',
            'name' => 'Wilczyce', 'display_name' => 'Wilczyce, gmina Kunice, powiat legnicki, Pologne',
            'address' => [
                'village' => 'Wilczyce', 'county' => 'powiat legnicki', 'state' => 'voïvodie de Basse-Silésie',
                'country' => 'Pologne', 'country_code' => 'pl',
            ],
        ];
    }
}
