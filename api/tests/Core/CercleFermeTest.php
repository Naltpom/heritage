<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Security\CercleFerme;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le test qui prouve que **le défaut est fermé**.
 *
 * Il est écrit contre la classe réelle et contre le conteneur réel, jamais contre un
 * double : ce qu'on vérifie n'est pas qu'une fonction rend `false`, c'est qu'une
 * application dont le cercle de visibilité n'est pas branché **ne publie rien**. C'est le
 * test qui tiendra quand quelqu'un remaniera la configuration des services dans deux ans,
 * et c'est le seul moment où la règle sera relue.
 */
final class CercleFermeTest extends KernelTestCase
{
    public function testLeDefautRefuseToutePersonne(): void
    {
        $cercle = new CercleFerme(new NullLogger());

        // Les identifiants ne veulent rien dire ici, et c'est le point : sans adaptateur,
        // aucune question sur aucune personne n'obtient « oui ».
        foreach ([1, 42, 129, \PHP_INT_MAX] as $personId) {
            self::assertFalse(
                $cercle->voitPersonne($personId),
                \sprintf('La personne %d ne doit pas être visible sans adaptateur branché.', $personId),
            );
        }

        self::assertSame([], $cercle->filtrerPersonnes([1, 42, 129]));
    }

    /**
     * Le corollaire de l'ADR 0003, dans les mots de l'agent des médias : *« on ne sait
     * pas si elle est morte » n'est pas une autorisation de publier ses dates*.
     *
     * Une personne décédée est pourtant visible de tout compte connecté — c'est la
     * première règle du contrat. `CercleFerme` la masque quand même, parce qu'elle n'a
     * aucun moyen d'établir le décès. Masquer un fait historique est un désagrément ;
     * publier la fiche d'une personne vivante parce qu'on a supposé son décès est une
     * fuite. Le test fige le bon côté de l'arbitrage.
     */
    public function testUnDecesInconnuVautMasque(): void
    {
        $cercle = new CercleFerme(new NullLogger());

        self::assertFalse($cercle->voitPersonne(1));
    }

    public function testLeRegimeRenduEstLePlusFermeDesDeux(): void
    {
        self::assertSame(Regime::Aucun, (new CercleFerme(new NullLogger()))->regime());
    }

    /**
     * L'avertissement est émis **une fois**, pas à chaque question : le graphe interroge
     * le cercle pour 274 personnes, et 274 lignes identiques noieraient le message.
     */
    public function testLAbsenceDAdaptateurEstSignaleeUneFoisDansLesJournaux(): void
    {
        $journal = new class extends NullLogger {
            /** @var list<string> */
            public array $avertissements = [];

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->avertissements[] = (string) $message;
            }
        };

        $cercle = new CercleFerme($journal);
        $cercle->regime();
        $cercle->voitPersonne(1);
        $cercle->filtrerPersonnes([1, 2, 3]);

        self::assertCount(1, $journal->avertissements);
        self::assertStringContainsString('CercleFerme', $journal->avertissements[0]);
    }

    /**
     * Le câblage, et non plus la classe : c'est ce que reçoivent réellement `Media`,
     * `Tree`, `Timeline` et `Proposal` quand ils demandent le contrat.
     *
     * **Ce test a d'abord été un fil-piège**, et il a servi : il assertait
     * `CercleFerme`, et il est tombé le jour où `Membership` a branché son adaptateur —
     * ce qui était exactement le but, un changement d'implémentation de la règle de
     * confidentialité ne devant pas passer inaperçu. Le fil-piège a rempli son office
     * une fois ; le ré-armer sur l'implémentation réelle appartient à `Membership`, qui
     * seul sait ce que la sienne doit garantir.
     *
     * Ce qui reste ici est ce que `Core` peut garantir **pour toujours** : le contrat est
     * lié et injectable. Un alias non résolu casserait quatre modules d'un coup, et rien
     * d'autre dans cette suite ne le verrait. La preuve du défaut fermé, elle, ne dépend
     * d'aucun câblage — elle est dans les cas unitaires ci-dessus, écrits contre
     * {@see CercleFerme} directement.
     */
    public function testLeContratEstBrancheEtInjectable(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            CercleDeVisibiliteInterface::class,
            self::getContainer()->get(CercleDeVisibiliteInterface::class),
            'Le contrat doit être injectable : quatre modules le typent.',
        );
    }

    /**
     * Le contrat ne doit exposer **aucune profondeur** : « un cercle avec `N = 0` » ne
     * doit pas être exprimable, sans quoi la nuance entre « régime sans cercle » et
     * « cercle vide » disparaît au premier remaniement (ADR 0003).
     */
    public function testLeContratNExposeAucuneProfondeur(): void
    {
        $reflexion = new \ReflectionClass(CercleDeVisibiliteInterface::class);

        foreach ($reflexion->getMethods() as $methode) {
            foreach ($methode->getParameters() as $parametre) {
                self::assertStringNotContainsStringIgnoringCase(
                    'generation',
                    $parametre->getName(),
                    'Aucune méthode du contrat ne prend de profondeur de cercle.',
                );
                self::assertNotSame('n', strtolower($parametre->getName()));
            }
        }

        self::assertSame(
            ['aucun', 'cercle'],
            array_column(Regime::cases(), 'value'),
            'Le régime a deux valeurs et aucune n’est un nombre de générations.',
        );
    }

    /** Garde-fou de signature : les quatre modules consommateurs codent contre elle. */
    public function testLaSignatureDuContratEstCelleAnnonceeAuxAutresModules(): void
    {
        $reflexion = new \ReflectionClass(CercleDeVisibiliteInterface::class);

        // `dansLePerimetre` : révision du 2026-10-04 de l'ADR 0003, « un arbre par famille ».
        self::assertSame(
            ['regime', 'voitPersonne', 'dansLePerimetre', 'filtrerPersonnes'],
            array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $reflexion->getMethods()),
        );

        $perimetre = $reflexion->getMethod('dansLePerimetre');
        self::assertSame('int', (string) $perimetre->getParameters()[0]->getType());
        self::assertSame('bool', (string) $perimetre->getReturnType());

        $voitPersonne = $reflexion->getMethod('voitPersonne');
        // Un identifiant, jamais une entité de `Tree` : `Core` ne référence aucun module.
        self::assertSame('int', (string) $voitPersonne->getParameters()[0]->getType());
        self::assertSame('bool', (string) $voitPersonne->getReturnType());
        self::assertSame(Regime::class, (string) $reflexion->getMethod('regime')->getReturnType());
    }

    public function testLeJournalAcceptePsrLogger(): void
    {
        // Garde-fou de câblage : le constructeur doit rester autowirable sur l'interface
        // PSR, sinon l'alias de `config/services/core.yaml` casse à la compilation.
        $constructeur = (new \ReflectionClass(CercleFerme::class))->getConstructor();

        self::assertNotNull($constructeur);
        self::assertSame(LoggerInterface::class, (string) $constructeur->getParameters()[0]->getType());
    }
}
