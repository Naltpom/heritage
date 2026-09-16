<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\EventSubscriber\ApiExceptionSubscriber;
use App\Module\Core\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Une `500` **laisse une trace**, et c'est un test parce que ce fut un défaut.
 *
 * `ApiExceptionSubscriber` appelle `setResponse()`, ce qui **arrête la propagation** de
 * `kernel.exception`. L'`ErrorListener` du framework, qui écoute en -128 et journalise
 * d'ordinaire les exceptions non rattrapées, ne s'exécute donc jamais derrière lui. Sans
 * le `critical` que ce test vérifie, une `500` en production rendait une enveloppe propre
 * et **aucune trace nulle part** — on l'a découvert en cherchant la cause de cinq `500`
 * dont il ne restait rien.
 *
 * C'est le genre de garantie qui se perd au premier remaniement du souscripteur, parce
 * qu'elle ne se voit dans aucune réponse HTTP. D'où un test unitaire, qui la regarde
 * directement.
 */
final class ErreurInterneJournaliseeTest extends TestCase
{
    public function testUneErreurInattendueEstJournaliseeEnCritical(): void
    {
        $journal = $this->journalEspion();
        $panne = new \RuntimeException('Connexion à la base perdue.');

        $evenement = $this->evenement('/api/arbre/graphe', $panne);
        // `debug: false` — en développement on laisse passer vers la page d'erreur de
        // Symfony ; c'est le comportement de production qu'on vérifie ici.
        (new ApiExceptionSubscriber(false, $journal))->surException($evenement);

        self::assertCount(1, $journal->lignes, 'Une 500 doit laisser exactement une trace.');
        self::assertSame('critical', $journal->lignes[0]['niveau']);
        self::assertSame($panne, $journal->lignes[0]['contexte']['exception'] ?? null);
    }

    /**
     * **Le message de l'exception ne sort pas dans la réponse.** Il peut nommer une
     * personne, un chemin de fichier ou une requête SQL (§ 16.1 du contrat d'API) : il va
     * au journal du serveur, et la réponse ne porte que le code stable.
     */
    public function testLeMessageDeLExceptionNeFuitPasDansLaReponse(): void
    {
        $evenement = $this->evenement('/api/arbre/personnes/17', new \RuntimeException('SELECT * FROM tree_person WHERE display_name = "Marne Sureau"'));

        (new ApiExceptionSubscriber(false, $this->journalEspion()))->surException($evenement);

        $reponse = $evenement->getResponse();
        self::assertNotNull($reponse);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $reponse->getStatusCode());

        $corps = (string) $reponse->getContent();
        self::assertStringNotContainsString('tree_person', $corps);
        self::assertStringNotContainsString('Marne Sureau', $corps);
        self::assertStringContainsString('erreur_interne', $corps);
    }

    /**
     * Une erreur **attendue** — une exception de domaine — est tracée, mais **jamais au
     * niveau des pannes**.
     *
     * Les deux moitiés comptent. Tracer : un `404` ou un `422` renseigne sur ce que le
     * front demande de travers, et le souscripteur coupe la propagation, donc personne
     * d'autre ne le dira. Ne pas tracer en `critical` : un journal de pannes où tombe
     * chaque saisie invalide devient illisible exactement là où il sert le plus, et c'est
     * ainsi qu'on cesse de le lire.
     */
    public function testUneErreurDeDomaineNEncombrePasLeJournalDesPannes(): void
    {
        $journal = $this->journalEspion();
        $evenement = $this->evenement('/api/arbre/personnes/9999', new NotFoundException('Cette personne n’existe pas.'));

        (new ApiExceptionSubscriber(false, $journal))->surException($evenement);

        self::assertSame(Response::HTTP_NOT_FOUND, $evenement->getResponse()?->getStatusCode());

        foreach ($journal->lignes as $ligne) {
            self::assertNotContains(
                $ligne['niveau'],
                ['critical', 'alert', 'emergency', 'error'],
                'Un 404 attendu n’est pas une panne : il ne monte pas au niveau des pannes.',
            );
        }
    }

    /** Hors `/api`, le souscripteur ne touche à rien — ni réponse, ni journal. */
    public function testHorsApiLeSouscripteurNeSeMelePasDeLErreur(): void
    {
        $journal = $this->journalEspion();
        $evenement = $this->evenement('/assets/app.js', new \RuntimeException('peu importe'));

        (new ApiExceptionSubscriber(false, $journal))->surException($evenement);

        self::assertNull($evenement->getResponse());
        self::assertSame([], $journal->lignes);
    }

    private function evenement(string $chemin, \Throwable $exception): ExceptionEvent
    {
        // `createStub` et non `createMock` : on ne vérifie aucun appel sur le noyau, il
        // n'est là que pour satisfaire la signature de l'événement. PHPUnit émet une
        // notice sur un mock sans attente, et `phpunit.dist.xml` a `failOnNotice`.
        $noyau = $this->createStub(KernelInterface::class);

        return new ExceptionEvent($noyau, Request::create($chemin), HttpKernelInterface::MAIN_REQUEST, $exception);
    }

    /** @return AbstractLogger&object{lignes: list<array{niveau: string, contexte: array<string, mixed>}>} */
    private function journalEspion(): object
    {
        return new class extends AbstractLogger {
            /** @var list<array{niveau: string, contexte: array<string, mixed>}> */
            public array $lignes = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lignes[] = ['niveau' => (string) $level, 'contexte' => $context];
            }
        };
    }
}
