<?php

declare(strict_types=1);

namespace App\Tests\Integrite;

use App\Integrite\VerificationDIntegrite;
use App\Module\Core\Contract\CiblesDeReferenceInterface;
use App\Module\Core\Contract\ReferenceInterModules;
use App\Module\Core\Contract\ReferencesInterModulesInterface;
use App\Module\Core\Enum\CibleDeReference;
use PHPUnit\Framework\TestCase;

/**
 * Ce qui empêche `heritage:verifier-integrite` de conclure — sans base.
 *
 * Un contrôle qui n'a pas pu se faire ne doit pas passer pour réussi. Trois façons de ne
 * pas pouvoir : aucune référence déclarée, une cible sans propriétaire, une cible
 * revendiquée deux fois. Chacune est une **anomalie**, et chacune fait échouer la commande.
 */
final class VerificationDIntegriteTest extends TestCase
{
    public function testUnePendanteEstTrouveeEtUneTolereeNeFaitPasEchouer(): void
    {
        $verification = new VerificationDIntegrite(
            [$this->declarant(
                new ReferenceInterModules('a.person_id', CibleDeReference::Personne, [1 => 2, 7 => 1]),
                new ReferenceInterModules('b.person_id', CibleDeReference::Personne, [8 => 1], null, 'historique'),
            )],
            [$this->proprietaire([CibleDeReference::Personne], [1])],
        );

        $rapport = $verification->verifier();

        self::assertSame([7 => 1], $rapport->controles[0]->pendants);
        self::assertSame('violation', $rapport->controles[0]->etat());
        self::assertSame('toleree', $rapport->controles[1]->etat());
        self::assertFalse($rapport->estSain());
        self::assertSame([], $rapport->anomalies);
    }

    public function testAucuneReferenceDeclareeEstUneAnomalie(): void
    {
        $rapport = (new VerificationDIntegrite([], [$this->proprietaire([CibleDeReference::Personne], [])]))->verifier();

        self::assertFalse($rapport->estSain(), 'Un balayage vide est le vert le plus trompeur qui soit.');
        self::assertCount(1, $rapport->anomalies);
    }

    public function testUneCibleSansProprietaireEstUneAnomalie(): void
    {
        $rapport = (new VerificationDIntegrite(
            [$this->declarant(new ReferenceInterModules('a.user_id', CibleDeReference::Compte, [1 => 1]))],
            [$this->proprietaire([CibleDeReference::Personne], [])],
        ))->verifier();

        self::assertFalse($rapport->estSain());
        self::assertStringContainsString('core_user', $rapport->anomalies[0]);
        self::assertSame([], $rapport->controles[0]->pendants, 'Sans répondant, on ne déclare rien pendant : l\'anomalie suffit.');
    }

    public function testUneCibleRevendiqueeDeuxFoisEstUneAnomalie(): void
    {
        $rapport = (new VerificationDIntegrite(
            [$this->declarant(new ReferenceInterModules('a.person_id', CibleDeReference::Personne, [1 => 1]))],
            [$this->proprietaire([CibleDeReference::Personne], [1]), $this->proprietaire([CibleDeReference::Personne], [1])],
        ))->verifier();

        self::assertFalse($rapport->estSain());
        self::assertStringContainsString('revendiquée par deux modules', $rapport->anomalies[0]);
    }

    private function declarant(ReferenceInterModules ...$references): ReferencesInterModulesInterface
    {
        return new readonly class(array_values($references)) implements ReferencesInterModulesInterface {
            /** @param list<ReferenceInterModules> $references */
            public function __construct(private array $references)
            {
            }

            public function references(): array
            {
                return $this->references;
            }
        };
    }

    /**
     * @param list<CibleDeReference> $cibles
     * @param list<int>              $existants
     */
    private function proprietaire(array $cibles, array $existants): CiblesDeReferenceInterface
    {
        return new readonly class($cibles, $existants) implements CiblesDeReferenceInterface {
            /**
             * @param list<CibleDeReference> $cibles
             * @param list<int>              $existants
             */
            public function __construct(private array $cibles, private array $existants)
            {
            }

            public function cibles(): array
            {
                return $this->cibles;
            }

            public function existants(CibleDeReference $cible, array $identifiants): array
            {
                return array_values(array_intersect($identifiants, $this->existants));
            }
        };
    }
}
