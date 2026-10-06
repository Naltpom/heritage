<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Service\Port\PersonneAcreer;
use App\Module\Proposal\Service\ValidationDeDemande;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Service\RecalculDesColonnesDerivees;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `PortArbre::enUnLot()` — UN RECALCUL PAR VALIDATION, ET JAMAIS AUCUN D'OUBLIÉ.
 *
 * Le lot diffère le recalcul de `generation`, `branch` et `rank` jusqu'à sa fermeture :
 * c'est ce qui a ramené la validation de la demande 4178 de plus de 30 s à quelques
 * secondes (voir {@see ValidationDUneGrosseCreationTest}). Ces tests tiennent les trois
 * façons dont un report peut mal tourner : ne jamais avoir lieu, avoir lieu trop tôt
 * (un lot imbriqué qui referme l'englobant), ou rester dû après une exception et
 * contaminer la requête suivante d'un processus de longue durée.
 */
final class LotDEcrituresDeLArbreTest extends BaseDeDemandeSurArbreReel
{
    private int $famille = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Une racine mariée : son enfant sera de génération 1.
        $racine = $this->poserUnePersonne('Racine', 'Lot', Sex::Masculin);
        $epouse = $this->poserUnePersonne('Epouse', 'Lot');

        $famille = new Family($this->personne($racine), $this->personne($epouse));
        $this->em()->persist($famille);

        $reglage = $this->em()->getRepository(Setting::class)->findOneBy(['settingKey' => 'tree.personne_racine_id']);
        self::assertInstanceOf(Setting::class, $reglage);
        $reglage->setValue((string) $racine);

        $this->em()->flush();
        $this->famille = (int) $famille->getId();
    }

    public function testDansUnLotLeRecalculAttendLaFermetureEtALieuAlors(): void
    {
        $port = $this->portReel();

        $enfant = $port->enUnLot(function (): int {
            $enfant = $this->creerUnEnfant();

            self::assertNull($this->personne($enfant)->getGeneration(), 'Dans le lot, le recalcul est différé.');

            return $enfant;
        });

        self::assertSame(1, $this->personne($enfant)->getGeneration(), 'À la fermeture du lot, le recalcul a eu lieu.');
    }

    public function testUnLotImbriqueNeRefermePasCeluiQuiLEnglobe(): void
    {
        $port = $this->portReel();

        $port->enUnLot(function () use ($port): void {
            $enfant = $port->enUnLot(fn (): int => $this->creerUnEnfant());

            self::assertNull($this->personne($enfant)->getGeneration(), 'Le lot intérieur ne déclenche pas le recalcul de l’englobant.');
        });

        $this->em()->clear();
        self::assertSame(0, $this->recalculerAPart(), 'Le lot extérieur a recalculé en se refermant.');
    }

    public function testUneExceptionDansLeLotNeLaissePasDeLotOuvert(): void
    {
        $port = $this->portReel();

        $levee = null;

        try {
            $port->enUnLot(function (): int {
                $enfant = $this->creerUnEnfant();
                $this->echouer();

                return $enfant;
            });
        } catch (\RuntimeException $erreur) {
            $levee = $erreur;
        }

        self::assertSame('Annulé.', $levee?->getMessage(), 'L’exception doit remonter telle quelle.');

        // Hors lot, le geste suivant recalcule aussitôt : le compteur est revenu à zéro.
        $enfant = $this->creerUnEnfant();
        self::assertSame(1, $this->personne($enfant)->getGeneration());
    }

    /**
     * La validation relève la limite de PHP à 55 s — et ne l'abaisse jamais : la valeur
     * `0` des tests et de la ligne de commande reste illimitée.
     */
    public function testLaValidationReleveLaLimiteDeTempsSansJamaisLAbaisser(): void
    {
        $avant = \ini_get('max_execution_time');

        try {
            ini_set('max_execution_time', '30');
            $this->validation()->valider($this->deposerUneCreation('Trente'), $this->admin());
            self::assertSame((string) ValidationDeDemande::DUREE_PHP_SECONDES, \ini_get('max_execution_time'));

            ini_set('max_execution_time', '0');
            $this->validation()->valider($this->deposerUneCreation('Illimitee'), $this->admin());
            self::assertSame('0', \ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', false === $avant ? '0' : $avant);
        }
    }

    /** Ce qu'un applicateur fait quand il refuse : il lève, au milieu du lot. */
    private function echouer(): void
    {
        throw new \RuntimeException('Annulé.');
    }

    private function creerUnEnfant(): int
    {
        $port = $this->portReel();

        $enfant = $port->creerPersonne(new PersonneAcreer(
            'Enfant',
            'Lot',
            null,
            null,
            Sex::Feminin->value,
            false,
            UncertainDate::inconnue(),
            UncertainDate::inconnue(),
        ))->id;

        $port->rattacherEnfant($this->famille, $enfant, null);

        return $enfant;
    }

    private function deposerUneCreation(string $prenom): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'creation_personne',
            'message' => 'Livret de famille.',
            'soumettre' => true,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => $prenom,
                'surname' => 'Lot',
                'sex' => 'feminin',
                'deceased' => false,
            ]],
            'liens' => [['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'familyId' => $this->famille]],
        ]);
    }

    private function recalculerAPart(): int
    {
        $recalcul = self::getContainer()->get(RecalculDesColonnesDerivees::class);
        self::assertInstanceOf(RecalculDesColonnesDerivees::class, $recalcul);

        return $recalcul->recalculer();
    }
}
