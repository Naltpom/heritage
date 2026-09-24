<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Service\UrlPublique;
use App\Module\Tree\Entity\Place;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE SEUL ENDROIT OÙ UN NOM DE LIEU DE L'ARBRE SORT DE LA MACHINE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * OpenStreetMap Nominatim, interrogé pour une chaîne `PLAC` que les référentiels locaux
 * n'ont pas su situer. Tout ce que la résolution des lieux faisait jusqu'ici restait sur
 * le serveur (`ressources/gazetteer/LISEZMOI.md`) ; ce fichier est l'exception, décidée
 * par le commanditaire pour les lieux hors de France et du Canada, et elle est **bornée
 * ici et nulle part ailleurs**.
 *
 * ## Ce qui sort, exactement
 *
 * **La chaîne du lieu, et rien d'autre** : `q=Liège, Belgique`, plus quatre paramètres
 * fixes (`format`, `limit`, `accept-language`, `addressdetails`). Aucun nom de personne,
 * aucune date, aucun identifiant, aucun lien entre ce lieu et qui que ce soit : le tiers
 * voit qu'une instance de Heritage cherche « Liège, Belgique », pas qui y est né.
 * `ResoudreLesLieuxCommand` ne passe que `tree_place.raw`, et
 * `GeocodeurNominatimTest::testLaRequeteNePorteQueLaChaineDuLieu()` le vérifie paramètre
 * par paramètre.
 *
 * L'en-tête `User-Agent` porte l'adresse publique de l'instance et l'adresse
 * d'expédition des courriels : c'est **exigé** par la politique d'usage de Nominatim
 * (« identifier l'application », un contact en cas d'abus), et ce sont des données de
 * l'instance, pas de la famille.
 *
 * ## La politique d'usage, tenue par construction
 *
 * - **une requête par seconde au plus** : {@see patienter()} attend, avant chaque
 *   appel, qu'une seconde entière se soit écoulée depuis la **fin** du précédent. Par
 *   l'horloge injectée, pour qu'un test le vérifie sans dormir ;
 * - **un délai** de {@see self::DELAI_SECONDES} secondes, total compris : un tiers lent ne
 *   tient pas la commande de nuit indéfiniment ;
 * - **pas de réessai** : une panne lève {@see GeocodageIndisponible}, et la commande cesse
 *   d'interroger pour le reste de l'exécution ;
 * - **pas de redemande** d'un lieu introuvable avant trente jours — tenu par la commande,
 *   qui a la mémoire (`tree_place`).
 *
 * ## Ce qu'on accepte d'une réponse
 *
 * Un lieu **au niveau d'une commune, ou plus fin** — voir {@see acceptable()}. Une
 * chaîne `PLAC` nomme une commune dans l'immense majorité des cas ; quand Nominatim ne
 * trouve que le pays ou la région qui la suit, il rend quelque chose de **vrai et
 * inutile**, et une bulle au centre de la Belgique pour un ancêtre de Liège est
 * exactement la carte « plausible et fausse » que la passe D existe pour éviter. On
 * préfère ne rien poser.
 */
final class GeocodeurNominatim implements GeocodeurEnLigne
{
    /** Le point d'accès public. Pas de clé, pas de compte : c'est un service bénévole. */
    public const string URL = 'https://nominatim.openstreetmap.org/search';

    /** Au plus une requête par seconde — la politique d'usage, pas une préférence. */
    private const float INTERVALLE_SECONDES = 1.0;

    /**
     * Le délai, en secondes, établissement de la connexion compris.
     *
     * Rien n'attend derrière — ni lecteur, ni requête HTTP : la commande tourne la nuit ou
     * après un déploiement. Dix secondes laissent sa chance à un serveur chargé sans
     * laisser un serveur muet bloquer l'exécution.
     */
    private const float DELAI_SECONDES = 10.0;

    /**
     * Les `addresstype` d'une localité : de la ville au hameau et au quartier.
     *
     * `addresstype` est le **rôle** que Nominatim prête à l'objet dans une adresse ; c'est
     * lui qui dit « commune » ou « pays », mieux que `type`, qui décrit l'objet
     * cartographique (`administrative` pour une commune comme pour un pays).
     */
    private const array LOCALITES = [
        'city', 'town', 'village', 'hamlet', 'municipality', 'suburb', 'locality',
        'neighbourhood', 'quarter', 'city_district', 'borough', 'isolated_dwelling', 'croft',
        'farm',
    ];

    /** Trop larges, quel que soit le rang : ce n'est jamais le lieu qu'une chaîne `PLAC` nomme. */
    private const array TROP_LARGES = [
        'continent', 'country', 'state', 'region', 'province', 'state_district', 'county',
    ];

    /**
     * Le rang minimal d'une entité administrative sans rôle de localité.
     *
     * L'échelle de recherche de Nominatim : 4 pays, 5 à 9 état ou région, **10 à 12
     * comté** (le département français), **13 à 16 commune**, 17 et au-delà ville, village,
     * quartier. Treize est donc le premier rang d'une commune ; douze admettrait un
     * département.
     */
    private const int RANG_MINIMAL = 13;

    private ?float $dernierAppel = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ClockInterface $horloge,
        private readonly UrlPublique $urlPublique,
        #[Autowire(env: 'default::MAILER_EXPEDITEUR')]
        private readonly ?string $expediteur = null,
    ) {
    }

    public function chercher(string $lieu): ?array
    {
        $this->patienter();

        try {
            $reponse = $this->http->request('GET', self::URL, [
                // La chaîne du lieu, et quatre constantes. RIEN d'autre — voir l'en-tête.
                'query' => [
                    'q' => $lieu,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'accept-language' => 'fr',
                    'addressdetails' => 1,
                ],
                'headers' => [
                    'User-Agent' => $this->userAgent(),
                    'Accept' => 'application/json',
                ],
                'timeout' => self::DELAI_SECONDES,
                'max_duration' => self::DELAI_SECONDES,
            ]);

            // `toArray()` lève sur un 3xx/4xx/5xx comme sur un corps qui n'est pas du
            // JSON : `429` (quota), `503` (maintenance) et une page d'erreur HTML sont le
            // même cas pour nous — le tiers n'a pas répondu utilement.
            $resultats = $reponse->toArray();
        } catch (ExceptionInterface $e) {
            throw new GeocodageIndisponible(\sprintf('Nominatim n’a pas répondu utilement : %s', $e->getMessage()), 0, $e);
        } finally {
            $this->dernierAppel = $this->maintenant();
        }

        if (!array_is_list($resultats)) {
            throw new GeocodageIndisponible('Nominatim a rendu autre chose qu’une liste de résultats.');
        }

        $premier = $resultats[0] ?? null;

        return \is_array($premier) ? $this->lire($premier) : null;
    }

    /**
     * Un résultat de Nominatim, rendu dans la forme de {@see AppariementDesLieux::apparier()},
     * ou `null` s'il n'est pas **acceptable**.
     *
     * @param array<mixed> $resultat
     *
     * @return array{libelle: string, commune: string|null, subdivision: string|null, pays: string|null, lat: float|null, lon: float|null, source: string, confiance: int, passe: string, candidats: list<string>}|null
     */
    private function lire(array $resultat): ?array
    {
        if (!self::acceptable($resultat)) {
            return null;
        }

        $lat = is_numeric($resultat['lat'] ?? null) ? (float) $resultat['lat'] : null;
        $lon = is_numeric($resultat['lon'] ?? null) ? (float) $resultat['lon'] : null;

        // Les mêmes bornes que les `CHECK` de `tree_place`, qui n'existent pas sur SQLite :
        // une coordonnée hors bornes serait refusée par PostgreSQL au `flush`, et ferait
        // perdre toute l'exécution pour une ligne.
        if (null === $lat || null === $lon || abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }

        /** @var array<string, mixed> $adresse */
        $adresse = \is_array($resultat['address'] ?? null) ? $resultat['address'] : [];
        $role = self::chaine($resultat['addresstype'] ?? null);

        $commune = self::chaine($resultat['name'] ?? null) ?? self::chaine($adresse[$role ?? ''] ?? null);
        $subdivision = self::premiere($adresse, ['county', 'state_district', 'state', 'province', 'region']);
        $codePays = strtoupper((string) self::chaine($adresse['country_code'] ?? null));
        $pays = 1 === preg_match('/^[A-Z]{2}$/', $codePays) ? $codePays : null;

        $nom = $commune ?? self::chaine($resultat['display_name'] ?? null) ?? '?';

        return [
            'libelle' => mb_substr(null === $pays ? $nom : \sprintf('%s (%s)', $nom, $pays), 0, 160),
            'commune' => null === $commune ? null : mb_substr($commune, 0, 120),
            'subdivision' => null === $subdivision ? null : mb_substr($subdivision, 0, 120),
            'pays' => $pays,
            'lat' => $lat,
            'lon' => $lon,
            'source' => Place::SOURCE_NOMINATIM,
            'confiance' => self::CONFIANCE,
            'passe' => self::PASSE,
            'candidats' => [],
        ];
    }

    /**
     * **Au niveau d'une commune, ou plus fin** — jamais un pays ni une région.
     *
     * Deux portes :
     *
     * 1. le rôle d'adresse (`addresstype`) est celui d'une localité — ville, village,
     *    hameau, quartier ({@see self::LOCALITES}) ;
     * 2. à défaut, une entité administrative de rang {@see self::RANG_MINIMAL} ou plus
     *    fin — une commune que Nominatim n'étiquette pas autrement.
     *
     * Un rôle de {@see self::TROP_LARGES} est refusé **avant** tout le reste, rang compris :
     * un rang est une échelle de recherche, pas une promesse, et « Belgique » ne doit pas
     * passer parce qu'un jour son rang aurait changé.
     *
     * Les routes et les points d'intérêt sont refusés aussi, bien qu'ils soient « plus
     * fins » : une chaîne « Liège, Belgique » qui ressortirait en « rue de Liège » à
     * Bruxelles serait un homonyme choisi à notre place.
     *
     * @param array<mixed> $resultat
     */
    private static function acceptable(array $resultat): bool
    {
        $role = self::chaine($resultat['addresstype'] ?? null) ?? self::chaine($resultat['type'] ?? null) ?? '';
        $rang = is_numeric($resultat['place_rank'] ?? null) ? (int) $resultat['place_rank'] : 0;

        if (\in_array($role, self::TROP_LARGES, true)) {
            return false;
        }

        if (\in_array($role, self::LOCALITES, true)) {
            return true;
        }

        $administratif = 'administrative' === $role
            || ('boundary' === ($resultat['category'] ?? null) && 'administrative' === ($resultat['type'] ?? null));

        return $administratif && $rang >= self::RANG_MINIMAL && $rang <= 25;
    }

    /**
     * Attend qu'une seconde soit passée depuis la fin de l'appel précédent.
     *
     * Par l'horloge injectée : en production elle dort pour de bon, en test une
     * `MockClock` avance sans dormir — et le test peut **mesurer** l'attente.
     */
    private function patienter(): void
    {
        if (null === $this->dernierAppel) {
            return;
        }

        $reste = self::INTERVALLE_SECONDES - ($this->maintenant() - $this->dernierAppel);

        if ($reste > 0) {
            $this->horloge->sleep($reste);
        }
    }

    private function maintenant(): float
    {
        return (float) $this->horloge->now()->format('U.u');
    }

    /**
     * `Heritage/1.0 (+https://heritage.exemple.fr; contact@exemple.fr)`.
     *
     * Les deux morceaux viennent de la configuration de l'instance —
     * `HERITAGE_URL_PUBLIQUE` et `MAILER_EXPEDITEUR`, que le `garde-secrets` de
     * `compose.prod.yaml` exige déjà en production. Absents (développement), l'agent se
     * nomme sans contact plutôt que de lever : un géocodage n'est pas une invitation, et
     * rien de faux ne part.
     */
    private function userAgent(): string
    {
        $parties = [];

        try {
            $parties[] = '+'.$this->urlPublique->base();
        } catch (\LogicException) {
            // Pas d'adresse publique configurée : on n'en invente pas.
        }

        $expediteur = trim((string) $this->expediteur);

        if (1 === preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $expediteur, $trouve)) {
            $parties[] = $trouve[1];
        } elseif (false !== filter_var($expediteur, \FILTER_VALIDATE_EMAIL)) {
            $parties[] = $expediteur;
        }

        return 'Heritage/1.0'.([] === $parties ? '' : ' ('.implode('; ', $parties).')');
    }

    private static function chaine(mixed $valeur): ?string
    {
        if (!\is_string($valeur)) {
            return null;
        }

        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }

    /**
     * @param array<string, mixed> $adresse
     * @param list<string>         $cles
     */
    private static function premiere(array $adresse, array $cles): ?string
    {
        foreach ($cles as $cle) {
            $valeur = self::chaine($adresse[$cle] ?? null);

            if (null !== $valeur) {
                return $valeur;
            }
        }

        return null;
    }
}
