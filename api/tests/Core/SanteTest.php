<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Controller\SanteController;
use Doctrine\DBAL\Connection;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * `GET /api/sante` — la sonde publique (§ 26 du contrat).
 *
 * Trois promesses, une par test : elle répond sans compte, elle ne touche à aucune session,
 * et sa panne ne dit rien de sa cause. La troisième est celle qui se perd au premier
 * « message d'erreur plus parlant » : la route est ouverte à tous.
 */
final class SanteTest extends CoreWebTestCase
{
    public function testLaSondeRepondSansCompteEtNeRendQueLeStatut(): void
    {
        $this->requete('GET', '/api/sante');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        // L'égalité stricte, et non « contient `statut` » : une clé ajoutée ici sortirait
        // vers n'importe qui.
        self::assertSame(['statut' => 'ok'], $this->charge());
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Aucun cookie ne doit être posé.');
    }

    /**
     * Le cas qui compte : un navigateur **connecté** qui l'appelle envoie son cookie. Lire
     * l'utilisateur chargerait alors la session ; rien ne doit le faire sur cette route.
     *
     * On l'observe sur `kernel.response`, avant que le listener de session ne la referme
     * (priorité -1000) : après, `isStarted()` rendrait `false` quoi qu'il soit arrivé.
     */
    public function testLaSondeNOuvrePasLaSessionMemeAvecUnCookie(): void
    {
        // Un seul noyau pour les deux requêtes : l'écouteur posé ci-dessous doit être
        // celui qui verra la seconde.
        $this->client->disableReboot();
        $this->creerUtilisateur('cobalt@example.net');
        $this->connexion('cobalt@example.net');
        self::assertResponseIsSuccessful();

        $sessionsOuvertes = [];
        $repartiteur = self::getContainer()->get('event_dispatcher');
        \assert($repartiteur instanceof EventDispatcherInterface);
        $repartiteur->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $evenement) use (&$sessionsOuvertes): void {
            $requete = $evenement->getRequest();
            $sessionsOuvertes[] = $requete->hasSession() && $requete->getSession()->isStarted();
        });

        $this->requete('GET', '/api/sante');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame([false], $sessionsOuvertes, 'La sonde ne doit ni charger ni démarrer la session.');
    }

    /**
     * En panne : `503`, l'enveloppe du § 15.1 avec un code générique, et la cause au
     * journal **seulement**. Le message de l'exception peut porter un nom d'hôte, un
     * utilisateur de base ou une requête SQL.
     */
    public function testEnPanneElleRend503SansRienDireDeLaCause(): void
    {
        $connexion = $this->createStub(Connection::class);
        $connexion->method('executeQuery')->willThrowException(
            new \RuntimeException('SQLSTATE[08006] could not connect to server db:5432 as user heritage'),
        );
        $journal = new class extends AbstractLogger {
            /** @var list<array{niveau: string, contexte: array<string, mixed>}> */
            public array $lignes = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lignes[] = ['niveau' => (string) $level, 'contexte' => $context];
            }
        };

        $reponse = (new SanteController($connexion, $journal))->sonder();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $reponse->getStatusCode());
        $corps = json_decode((string) $reponse->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($corps);
        self::assertSame('service_indisponible', $corps['erreur']['code'] ?? null);
        foreach (['SQLSTATE', 'db:5432', 'heritage', 'connect'] as $fuite) {
            self::assertStringNotContainsString($fuite, (string) $reponse->getContent());
        }

        self::assertCount(1, $journal->lignes, 'La panne doit laisser exactement une trace.');
        self::assertSame('error', $journal->lignes[0]['niveau']);
        self::assertInstanceOf(\RuntimeException::class, $journal->lignes[0]['contexte']['exception'] ?? null);
    }
}
