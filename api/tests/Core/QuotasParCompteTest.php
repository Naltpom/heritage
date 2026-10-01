<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Contract\Quota;
use App\Module\Core\Entity\LimiteDeQuota;
use App\Module\Core\Service\QuotasDUsage;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les quotas d'usage par compte — `quotas` dans `GET /api/administration/utilisateurs`,
 * `PATCH /api/administration/utilisateurs/{id}/quotas`, et `/api/administration/quotas`
 * pour le compteur global du rattachement assisté.
 *
 * Ce que ces cas tiennent :
 *
 * - la **limite effective** est la limite personnelle si elle existe, le défaut sinon ;
 * - **0 bloque**, sans décompter ni lever l'exception que `SlidingWindowLimiter` réserve
 *   aux demandes plus grandes que sa taille ;
 * - la consommation se **lit sans se décompter** — deux lectures de la liste rendent le
 *   même chiffre, et le compteur n'a pas bougé ;
 * - la limite changée s'applique **aux coups déjà comptés** : le compteur ne garde pas la
 *   limite, seulement les coups ;
 * - les routes sont réservées à l'administrateur, et un lot invalide n'écrit **rien**.
 */
final class QuotasParCompteTest extends CoreWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Les compteurs des quotas vivent dans `cache.app` (`CoreWebTestCase` ne vide que
        // `cache.rate_limiter`) ; la base repart vierge, donc les identifiants repartent de
        // 1 : sans ce vidage, un test léguerait sa consommation au suivant.
        $pool = self::getContainer()->get('cache.app');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }
    }

    public function testLaListeRendLesQuotasParDefautEtLesLitSansLesDecompter(): void
    {
        $natron = $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $idCobalt = (int) $cobalt->getId();

        $limiteur = $this->quotas()->limiteur($this->quota('assistant.analyses'), $idCobalt);
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($limiteur->consume()->isAccepted());
        }

        $this->connexion('natron@example.net');

        foreach ([1, 2] as $lecture) {
            $this->requete('GET', '/api/administration/utilisateurs');
            self::assertResponseIsSuccessful();

            $quotas = $this->quotasDe($idCobalt);
            self::assertSame(['assistant.analyses', 'assistant.questions', 'media.televersements'], array_column($quotas, 'cle'), 'Le rattachement assisté est global : il n’apparaît pas sur un compte.');
            self::assertSame(
                ['cle' => 'assistant.analyses', 'libelle' => 'Analyses IA', 'fenetre' => 'par 24 h', 'consomme' => 3, 'limite' => 20, 'limiteParDefaut' => 20, 'personnalisee' => false, 'exempte' => false],
                $quotas[0],
                \sprintf('Lecture %d : la consommation se lit sans se décompter.', $lecture),
            );
            self::assertSame([0, 60], [$quotas[1]['consomme'], $quotas[1]['limite']]);
            self::assertSame([0, 20, false], [$quotas[2]['consomme'], $quotas[2]['limite'], $quotas[2]['exempte']]);
        }

        self::assertSame(17, $limiteur->consume(0)->getRemainingTokens(), 'Deux lectures de la liste n’ont rien décompté.');

        // L'administrateur n'est pas compté sur les téléversements : sa ligne le dit.
        $quotasDeNatron = $this->quotasDe((int) $natron->getId());
        self::assertTrue($quotasDeNatron[2]['exempte']);
        self::assertFalse($quotasDeNatron[0]['exempte'], 'Les analyses comptent l’administrateur : appel payant.');
    }

    public function testUneLimitePersonnelleDevientLaLimiteEffectivePuisSeRetire(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $idCobalt = (int) $cobalt->getId();
        $questions = $this->quota('assistant.questions');

        // Douze questions déjà posées sous le défaut de 60.
        $limiteur = $this->quotas()->limiteur($questions, $idCobalt);
        for ($i = 0; $i < 12; ++$i) {
            $limiteur->consume();
        }

        $this->connexion('natron@example.net');
        $this->requete('PATCH', "/api/administration/utilisateurs/$idCobalt/quotas", ['limites' => ['assistant.questions' => 15]]);
        self::assertResponseIsSuccessful();

        $ligne = $this->charge()['quotas'][1];
        self::assertSame(['assistant.questions', 12, 15, 60, true], [$ligne['cle'], $ligne['consomme'], $ligne['limite'], $ligne['limiteParDefaut'], $ligne['personnalisee']]);

        // La limite personnelle s'applique aux douze coups déjà comptés : trois de plus, puis non.
        $quotas = $this->quotas();
        self::assertSame(15, $quotas->limiteEffective($questions, $idCobalt));
        for ($i = 0; $i < 3; ++$i) {
            self::assertTrue($quotas->consommer($questions, $idCobalt)->isAccepted());
        }
        $refus = $quotas->consommer($questions, $idCobalt);
        self::assertFalse($refus->isAccepted());
        self::assertSame(15, $refus->getLimit(), 'Le 429 citera la limite du compte.');

        // `null` rend le défaut, et la ligne disparaît.
        $this->requete('PATCH', "/api/administration/utilisateurs/$idCobalt/quotas", ['limites' => ['assistant.questions' => null]]);
        self::assertResponseIsSuccessful();
        $ligne = $this->charge()['quotas'][1];
        self::assertSame([15, 60, false], [$ligne['consomme'], $ligne['limite'], $ligne['personnalisee']]);
        self::assertSame(0, $this->em()->getRepository(LimiteDeQuota::class)->count([]));
    }

    public function testUneLimiteAZeroBloqueSansDecompter(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $idCobalt = (int) $cobalt->getId();
        $televersements = $this->quota('media.televersements');

        $quotas = $this->quotas();
        $quotas->consommer($televersements, $idCobalt);
        $quotas->personnaliser($cobalt, ['media.televersements' => 0]);

        $refus = $quotas->consommer($televersements, $idCobalt);
        self::assertFalse($refus->isAccepted());
        self::assertSame(0, $refus->getLimit());
        self::assertGreaterThan(time(), $refus->getRetryAfter()->getTimestamp());
        self::assertSame(1, $quotas->consommation($televersements, $idCobalt), 'Le refus n’a rien compté, et le coup d’avant reste lisible.');
    }

    public function testLesRoutesSontReserveesALAdministrateur(): void
    {
        $cobalt = $this->creerUtilisateur('cobalt@example.net');

        $this->connexion('cobalt@example.net');
        $this->requete('PATCH', '/api/administration/utilisateurs/'.$cobalt->getId().'/quotas', ['limites' => ['assistant.analyses' => 1000]]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->requete('GET', '/api/administration/quotas');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->requete('PATCH', '/api/administration/quotas', ['limites' => ['assistant.rattachements' => 1000]]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame(0, $this->em()->getRepository(LimiteDeQuota::class)->count([]));
    }

    public function testLesValeursSontValideesEtUnLotInvalideNEcritRien(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $cobalt = $this->creerUtilisateur('cobalt@example.net');
        $chemin = '/api/administration/utilisateurs/'.$cobalt->getId().'/quotas';

        $this->connexion('natron@example.net');

        foreach ([
            'négatif' => ['assistant.analyses' => -1],
            'au-delà de la borne' => ['assistant.analyses' => QuotasDUsage::LIMITE_MAXIMALE + 1],
            'texte' => ['assistant.analyses' => '12'],
            'décimal' => ['assistant.analyses' => 1.5],
            'booléen' => ['assistant.analyses' => true],
            'clé inconnue' => ['assistant.inconnu' => 3],
            'quota global sur un compte' => ['assistant.rattachements' => 3],
            'un bon, un mauvais' => ['assistant.questions' => 30, 'media.televersements' => -4],
        ] as $cas => $limites) {
            $this->requete('PATCH', $chemin, ['limites' => $limites]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $cas);
            self::assertSame('donnees_invalides', $this->charge()['erreur']['code'] ?? null, $cas);
        }

        foreach ([['limite' => 3], ['limites' => [3, 4]], ['limites' => 'tout']] as $corps) {
            $this->requete('PATCH', $chemin, $corps);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) json_encode($corps));
        }

        self::assertSame(0, $this->em()->getRepository(LimiteDeQuota::class)->count([]), 'Aucun lot invalide n’a écrit, pas même sa moitié valide.');

        // Les deux bornes sont admises.
        $this->requete('PATCH', $chemin, ['limites' => ['assistant.analyses' => 0, 'assistant.questions' => QuotasDUsage::LIMITE_MAXIMALE]]);
        self::assertResponseIsSuccessful();
        self::assertSame([0, QuotasDUsage::LIMITE_MAXIMALE], array_column(\array_slice($this->charge()['quotas'], 0, 2), 'limite'));

        $this->requete('PATCH', '/api/administration/utilisateurs/999999/quotas', ['limites' => ['assistant.analyses' => 3]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testLeQuotaGlobalDuRattachementSeRegleSansSeRattacherAUnCompte(): void
    {
        $this->creerUtilisateur('natron@example.net', roles: ['ROLE_ADMIN']);
        $rattachements = $this->quota('assistant.rattachements');
        $this->quotas()->limiteur($rattachements, null)->consume();

        $this->connexion('natron@example.net');
        $this->requete('GET', '/api/administration/quotas');
        self::assertResponseIsSuccessful();
        self::assertSame(
            [['cle' => 'assistant.rattachements', 'libelle' => 'Rattachements assistés', 'fenetre' => 'par 24 h', 'consomme' => 1, 'limite' => 40, 'limiteParDefaut' => 40, 'personnalisee' => false]],
            $this->charge()['quotas'],
        );

        $this->requete('PATCH', '/api/administration/quotas', ['limites' => ['assistant.rattachements' => 5]]);
        self::assertResponseIsSuccessful();
        self::assertSame([1, 5, true], [$this->charge()['quotas'][0]['consomme'], $this->charge()['quotas'][0]['limite'], $this->charge()['quotas'][0]['personnalisee']]);
        self::assertSame(5, $this->quotas()->limiteEffective($rattachements, null));

        $ligne = $this->em()->getRepository(LimiteDeQuota::class)->findOneBy(['cle' => 'assistant.rattachements']);
        self::assertNotNull($ligne);
        self::assertNull($ligne->getUserId(), 'Une limite globale n’appartient à aucun compte.');

        // Un quota par compte ne se règle pas ici.
        $this->requete('PATCH', '/api/administration/quotas', ['limites' => ['assistant.analyses' => 5]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function quotas(): QuotasDUsage
    {
        $quotas = self::getContainer()->get(QuotasDUsage::class);
        \assert($quotas instanceof QuotasDUsage);

        return $quotas;
    }

    private function quota(string $cle): Quota
    {
        $quota = $this->quotas()->trouver($cle);
        self::assertNotNull($quota, \sprintf('Le quota « %s » doit être déclaré par son module.', $cle));

        return $quota;
    }

    /** @return list<array<string, mixed>> */
    private function quotasDe(int $userId): array
    {
        foreach ($this->charge()['elements'] as $element) {
            if ($element['id'] === $userId) {
                return $element['quotas'];
            }
        }

        self::fail("Le compte $userId n’est pas dans la liste.");
    }
}
