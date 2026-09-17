<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Tests\Core\CoreWebTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE PLAFOND DE DÉPÔT : 20 PAR HEURE POUR UN MEMBRE, AUCUN POUR L'ADMINISTRATEUR
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Deux tests qui ne se lisent pas séparément. Le premier vérifie l'exemption décidée par
 * le commanditaire le 17 septembre 2026 ({@see \App\Module\Media\Controller\MediaController::limiter()}) ;
 * **le second vérifie qu'elle n'a pas emporté le plafond avec elle**, et c'est celui-là
 * qui compte le plus. Une exemption trop large ne casse rien de visible : le membre
 * ordinaire se met simplement à pouvoir déposer sans fin, et personne ne s'en aperçoit
 * avant le jour où ça sert.
 *
 * ── Pourquoi ces requêtes n'ont pas de fichier ──────────────────────────────────
 *
 * `televerser()` consomme son jeton **avant** de lire `$requete->files` : la limitation
 * précède la validation, comme il se doit — on ne fait pas travailler le serveur pour
 * décider ensuite qu'on n'avait pas le droit de le lui demander. Un `POST /api/medias`
 * sans fichier consomme donc un jeton exactement comme un vrai dépôt, puis échoue en
 * `422 donnees_invalides`. C'est ce qui rend ce fichier rapide : vingt-cinq dépôts
 * d'images réelles coûteraient vingt-cinq réencodages GD pour mesurer un compteur.
 *
 * Et cet appui n'est pas un pari : si quelqu'un déplaçait `limiter()` après la lecture du
 * fichier, les requêtes à vide cesseraient de consommer un jeton et
 * {@see self::testUnMembreOrdinaireEstToujoursBloqueAuVingtEtUnieme()} passerait au rouge
 * immédiatement. Le test du membre est le garde-fou de celui de l'administrateur.
 *
 * Ce que ces deux tests ne prouvent pas, et qui est prouvé ailleurs : qu'un dépôt complet
 * aboutit — c'est `Tree\TeleversementDUnePreuveHttpTest`, de bout en bout.
 *
 * ── Le compteur doit être vidé ici, et pas seulement par le socle ───────────────
 *
 * `CoreWebTestCase` vide `cache.rate_limiter` ; le limiteur de ce module vit dans
 * `cache.app` (voir `config/services/media.yaml`, `media.stockage_limiteur`). Et la clé
 * du compteur est l'identifiant du compte — or la base repart vierge à chaque test, donc
 * les identifiants repartent de 1. Sans le vidage ci-dessous, le premier test léguerait
 * ses vingt et un jetons au second sous la même clé, et l'ordre d'exécution déciderait du
 * résultat.
 */
final class PlafondDeDepotTest extends CoreWebTestCase
{
    /** § 1.9 du contrat. */
    private const int PLAFOND = 20;

    protected function setUp(): void
    {
        parent::setUp();

        $pool = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    /**
     * L'exemption, sur le cas qui l'a motivée : l'import d'un lot d'actes notariés par le
     * compte d'administration. On dépasse largement le plafond — et rien ne tombe.
     *
     * 26 requêtes et non 21 : à 21, un test resterait vert si l'exemption était en fait
     * un plafond relevé d'un cran quelque part. On veut la preuve qu'il n'y a plus de
     * plafond du tout, pas qu'il est plus haut.
     */
    public function testUnAdministrateurNEstPasCompteParLeLimiteur(): void
    {
        $this->creerUtilisateur('administration@example.net', roles: ['ROLE_ADMIN']);
        $this->connexion('administration@example.net');
        self::assertResponseIsSuccessful('La connexion de l’administrateur doit réussir.');

        for ($rang = 1; $rang <= self::PLAFOND + 6; ++$rang) {
            $this->tenterUnDepot();

            self::assertSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $this->client->getResponse()->getStatusCode(),
                \sprintf('Dépôt n° %d : un administrateur n’est jamais limité en nombre depuis le 17 septembre 2026.', $rang),
            );
        }

        // La preuve directe, à côté des codes de retour : son compteur n'a jamais bougé.
        // Sans elle, un test vert dirait seulement « aucun 429 en 26 coups » — ce qui
        // serait aussi vrai d'un plafond monté à 30.
        self::assertSame(
            self::PLAFOND,
            $this->jetonsRestantsDe('administration@example.net'),
            'Aucun jeton n’a été consommé : l’administrateur ne passe pas par le compteur, il en sort avant.',
        );
    }

    /**
     * **Le test qui compte le plus.** Le plafond protège les comptes de la famille : un
     * membre dont la session serait reprise ne doit pas pouvoir remplir le disque, et
     * c'est le seul frein au nombre qui existe dans ce module.
     *
     * On vérifie les trois choses qu'un front doit pouvoir lire : le rang exact du refus,
     * le code d'erreur nommé du § 15.2, et le `Retry-After` du § 1.8.
     */
    public function testUnMembreOrdinaireEstToujoursBloqueAuVingtEtUnieme(): void
    {
        $this->creerUtilisateur('cousine@example.net');
        $this->connexion('cousine@example.net');
        self::assertResponseIsSuccessful('La connexion du membre doit réussir.');

        for ($rang = 1; $rang <= self::PLAFOND; ++$rang) {
            $this->tenterUnDepot();

            self::assertSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $this->client->getResponse()->getStatusCode(),
                \sprintf('Dépôt n° %d : les vingt premiers passent le limiteur.', $rang),
            );
        }

        $this->tenterUnDepot();
        $reponse = $this->client->getResponse();

        self::assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $reponse->getStatusCode(),
            'Le vingt et unième dépôt d’un membre ordinaire est refusé (§ 1.9).',
        );

        $charge = $this->charge();
        self::assertIsArray($charge['erreur']);
        self::assertSame('trop_de_tentatives', $charge['erreur']['code']);
        self::assertSame(['retryAfter' => $charge['erreur']['details']['retryAfter'] ?? null], $charge['erreur']['details']);
        self::assertNotEmpty($reponse->headers->get('Retry-After'), 'Le délai ressort aussi en en-tête (§ 1.8).');

        self::assertSame(0, $this->jetonsRestantsDe('cousine@example.net'), 'Le compteur du membre est bien à sec.');
    }

    /**
     * Un dépôt sans fichier : il consomme son jeton puis échoue en `422`. Voir l'en-tête
     * de cette classe — c'est l'ordre des opérations dans `televerser()` qui le permet,
     * et le test du membre qui le garde.
     */
    private function tenterUnDepot(): void
    {
        $this->client->request('POST', '/api/medias', server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * L'état du compteur, lu sans le consommer (`consume(0)`).
     *
     * Le pool vit sur le système de fichiers : le noyau relancé entre deux requêtes du
     * client n'y change rien, et c'est bien le compteur qu'ont vu les requêtes ci-dessus.
     */
    private function jetonsRestantsDe(string $courriel): int
    {
        $utilisateur = $this->em()->getRepository(\App\Module\Core\Entity\User::class)->findOneBy(['email' => $courriel]);
        self::assertNotNull($utilisateur);
        $identifiant = $utilisateur->getId();
        self::assertNotNull($identifiant);

        $limiteur = self::getContainer()->get('media.limiteur_televersement');
        self::assertInstanceOf(\Symfony\Component\RateLimiter\RateLimiterFactoryInterface::class, $limiteur);

        return $limiteur->create((string) $identifiant)->consume(0)->getRemainingTokens();
    }
}
