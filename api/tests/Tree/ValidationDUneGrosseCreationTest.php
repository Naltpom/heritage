<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\Sex;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Service\RecalculDesColonnesDerivees;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UNE GROSSE CRÉATION SE VALIDE EN UNE FOIS, PAS EN QUARANTE RELECTURES DE L'ARBRE.
 *
 * ## Le défaut, tel qu'il a eu lieu en production
 *
 * La demande 4178 (04/10/2026) crée 22 fiches, 11 unions et 11 filiations. Sa validation
 * tombait en `500` après 30 s (`MaxExecutionTimeError`, incidents 87f129028ebd61d2 et
 * 5e9dbdf822ce98e6) ; la 4206 la veille, de taille comparable, aussi.
 *
 * La cause : chaque geste du port — une personne créée, une famille nouée, un enfant
 * rattaché — **relisait l'arbre entier** (2 373 fiches, 811 familles) pour recalculer
 * `generation`, `branch` et `rank`, puis `flush()`ait une unité de travail qui contenait
 * désormais toutes ces lignes. Une quarantaine de fois par demande : le temps croissait
 * comme *gestes × taille de l'arbre*.
 *
 * ## Ce que ces tests tiennent
 *
 * - le recalcul se fait **une fois par validation**, après toutes les écritures
 *   (le comptage lui-même est éprouvé par {@see LotDEcrituresDeLArbreTest}) ;
 * - le résultat est **le même** qu'un recalcul complet fait après coup — rien n'est
 *   oublié parce qu'on a différé ;
 * - et la validation d'une demande de cette forme, sur un arbre de cette taille, tient
 *   largement sous la limite (le temps mesuré est affiché pour le suivi).
 */
final class ValidationDUneGrosseCreationTest extends BaseDeDemandeSurArbreReel
{
    /** Taille de l'arbre de fond : de l'ordre de celui de la production. */
    private const int FAMILLES_DE_FOND = 800;

    private int $racine = 0;

    /** @var list<int> */
    private array $personnesDeFond = [];

    /** @var list<int> */
    private array $famillesDeFond = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->poserUnArbreDeFond();
    }

    public function testUneCreationDeVingtDeuxFichesSeValideViteEtPoseLesColonnesDerivees(): void
    {
        $demande = $this->deposerCorps($this->corpsCommeLaDemande4178());

        $debut = hrtime(true);
        $resultat = $this->validation()->valider($demande, $this->admin());
        $duree = (hrtime(true) - $debut) / 1e9;

        fwrite(\STDERR, \sprintf("\n[mesure] validation d'une création de 22 fiches sur %d personnes : %.2f s\n", \count($this->personnesDeFond), $duree));

        self::assertSame('validee', $resultat->resultat);
        self::assertCount(22, $this->personnesCreees($demande));

        // La borne est large exprès — la machine de CI n'est pas celle de la production.
        // Avant la correction, cette validation prenait plusieurs dizaines de secondes.
        self::assertLessThan(15.0, $duree, 'La validation d’une grosse création doit tenir largement sous la limite d’exécution.');

        // Le recalcul différé produit exactement ce qu'un recalcul complet fait après coup
        // produirait : s'il restait une colonne à reposer, c'est qu'on en a oublié.
        $this->em()->clear();
        self::assertSame(0, $this->recalcul()->recalculer(), 'Les colonnes dérivées doivent être à jour à la fin de la validation.');

        // Et elles sont justes : les enfants rattachés à une famille de fond prennent la
        // génération suivante, leurs petits-enfants celle d'après.
        $creees = $this->personnesCreees($demande);
        $parents = $this->em()->find(Family::class, $this->famillesDeFond[10]);
        self::assertInstanceOf(Family::class, $parents);
        $generationDesParents = $parents->getGeneration();
        self::assertNotNull($generationDesParents);

        self::assertSame($generationDesParents + 1, $this->personne($creees['#p1'])->getGeneration());
        self::assertSame($generationDesParents + 1, $this->personne($creees['#p2'])->getGeneration());
        self::assertSame($generationDesParents + 2, $this->personne($creees['#p9'])->getGeneration());
        self::assertNotNull($this->personne($creees['#p9'])->getBranch());
    }

    /**
     * Un arbre en peigne : un couple racine, puis des familles dont le mari est un
     * enfant de la génération précédente et l'épouse une personne venue d'ailleurs, avec
     * deux enfants chacune. ~2 400 fiches, 800 familles, 1 600 filiations.
     */
    private function poserUnArbreDeFond(): void
    {
        $em = $this->em();

        $nouvelle = function (string $prenom, Sex $sexe) use ($em): Person {
            $personne = new Person($prenom.' Fond');
            $personne->setGivenNames($prenom)->setSurname('Fond')->setSex($sexe);
            $em->persist($personne);

            return $personne;
        };

        $racine = $nouvelle('Racine', Sex::Masculin);
        $file = [$racine];
        $familles = [];
        $personnes = [$racine];

        for ($i = 0; $i < self::FAMILLES_DE_FOND; ++$i) {
            $mari = array_shift($file);
            \assert($mari instanceof Person);
            $epouse = $nouvelle('Epouse'.$i, Sex::Feminin);
            $famille = new Family($mari, $epouse);
            $em->persist($famille);
            $familles[] = $famille;
            $personnes[] = $epouse;

            foreach ([1, 2] as $rang) {
                $enfant = $nouvelle('Enfant'.$i.'-'.$rang, Sex::Masculin);
                $em->persist(new Child($famille, $enfant, $rang));
                $file[] = $enfant;
                $personnes[] = $enfant;
            }
        }

        $em->flush();

        $this->racine = (int) $racine->getId();
        $this->personnesDeFond = array_map(static fn (Person $p): int => (int) $p->getId(), $personnes);
        $this->famillesDeFond = array_map(static fn (Family $f): int => (int) $f->getId(), $familles);

        $reglage = $em->getRepository(Setting::class)->findOneBy(['settingKey' => 'tree.personne_racine_id']);
        self::assertInstanceOf(Setting::class, $reglage);
        $reglage->setValue((string) $this->racine);
        $em->flush();

        // L'arbre de fond est placé avant la demande, comme l'est celui de la production.
        $this->recalcul()->recalculer();
        $em->flush();
        $em->clear();
    }

    /**
     * La forme exacte de la demande 4178 : 22 brouillons, 11 unions, 11 filiations, dont
     * deux vers une famille existante et deux vers une personne existante.
     *
     * @return array<string, mixed>
     */
    private function corpsCommeLaDemande4178(): array
    {
        $famille = $this->famillesDeFond[10];
        $existante = $this->personnesDeFond[1500];

        $brouillons = [];

        for ($i = 1; $i <= 22; ++$i) {
            $brouillons[] = [
                'localRef' => '#p'.$i,
                'givenNames' => 'Prenom'.$i,
                'surname' => 'Nouveau',
                'sex' => 0 === $i % 2 ? 'feminin' : 'masculin',
                'deceased' => true,
            ];
        }

        $enfant = static fn (int $seq, string $sujet, array $parents): array => ['seq' => $seq, 'kind' => 'enfant_de', 'subjectDraftRef' => $sujet] + $parents;
        $conjoint = static fn (int $seq, string $sujet, string $autre): array => ['seq' => $seq, 'kind' => 'conjoint_de', 'subjectDraftRef' => $sujet, 'otherDraftRef' => $autre];

        return [
            'kind' => 'creation_personne',
            'message' => 'Relevé de plusieurs générations.',
            'soumettre' => true,
            'brouillons' => $brouillons,
            'liens' => [
                $enfant(1, '#p1', ['familyId' => $famille]),
                $enfant(2, '#p2', ['familyId' => $famille]),
                $conjoint(3, '#p3', '#p1'),
                $conjoint(4, '#p4', '#p5'),
                $enfant(5, '#p3', ['otherDraftRef' => '#p4', 'other2DraftRef' => '#p5']),
                $conjoint(6, '#p6', '#p2'),
                $conjoint(7, '#p7', '#p8'),
                $enfant(8, '#p6', ['otherDraftRef' => '#p7', 'other2DraftRef' => '#p8']),
                $enfant(9, '#p9', ['otherDraftRef' => '#p3', 'other2DraftRef' => '#p1']),
                $enfant(10, '#p11', ['otherDraftRef' => '#p3', 'other2DraftRef' => '#p1']),
                $enfant(11, '#p13', ['otherDraftRef' => '#p3', 'other2DraftRef' => '#p1']),
                $conjoint(12, '#p10', '#p9'),
                $conjoint(13, '#p11', '#p12'),
                $enfant(14, '#p14', ['otherDraftRef' => '#p6', 'other2DraftRef' => '#p2']),
                $enfant(15, '#p16', ['otherDraftRef' => '#p6', 'other2DraftRef' => '#p2']),
                $enfant(16, '#p18', ['otherDraftRef' => '#p6', 'other2DraftRef' => '#p2']),
                $conjoint(17, '#p15', '#p14'),
                $conjoint(18, '#p17', '#p16'),
                $conjoint(19, '#p18', '#p19'),
                $conjoint(20, '#p20', '#p21'),
                ['seq' => 21, 'kind' => 'enfant_de', 'subjectPersonId' => $existante, 'otherDraftRef' => '#p20', 'other2DraftRef' => '#p21'],
                ['seq' => 22, 'kind' => 'conjoint_de', 'subjectDraftRef' => '#p22', 'otherPersonId' => $existante],
            ],
        ];
    }

    /** @return array<string, int> la référence provisoire → la fiche créée */
    private function personnesCreees(ChangeRequest $demande): array
    {
        $brouillons = self::getContainer()->get(DraftPersonRepository::class);
        self::assertInstanceOf(DraftPersonRepository::class, $brouillons);

        $carte = [];

        foreach ($brouillons->pourDemande($demande) as $brouillon) {
            $id = $brouillon->getResolvedPersonId();
            self::assertNotNull($id, 'Chaque brouillon doit être résolu en une fiche réelle.');
            $carte[$brouillon->getLocalRef()] = $id;
        }

        return $carte;
    }

    private function recalcul(): RecalculDesColonnesDerivees
    {
        $recalcul = self::getContainer()->get(RecalculDesColonnesDerivees::class);
        self::assertInstanceOf(RecalculDesColonnesDerivees::class, $recalcul);

        return $recalcul;
    }
}
