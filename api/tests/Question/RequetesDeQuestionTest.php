<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Question\Entity\Question;
use App\Module\Question\Enum\NatureDeLacune;
use App\Module\Question\Enum\RedacteurDeQuestion;
use App\Module\Question\Repository\QuestionRepository;
use App\Tests\ReinitialisationDuSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les requêtes du dépôt de questions, sur une **vraie base** (SQLite, comme le reste de
 * la suite).
 *
 * ## Pourquoi ces quatre cas et pas des tests d'entité de plus
 *
 * Parce que ce sont les seuls endroits du module où une erreur ne se voit pas à la
 * lecture du code, et où elle coûte cher :
 *
 * 1. **« un compte ne voit que ses questions »** — `servablesPour()` compose un `AND` et
 *    un `OR`. Doctrine parenthèse aujourd'hui le membre `OR` passé en chaîne ; il
 *    suffirait qu'un remaniement scinde la clause en deux `andWhere()` pour que la
 *    condition devienne `compte = :compte AND etat = 'posee' OR (…)`, c'est-à-dire
 *    **les questions différées de tout le monde**. Le SQL resterait valide, la page
 *    resterait pleine, et ce sont les noms des autres familles qui s'y afficheraient ;
 * 2. **les états définitifs ne reviennent jamais** — c'est la mémoire du « je ne sais
 *    pas », et une page qui redemande ce à quoi on a répondu cesse d'être ouverte ;
 * 3. **une question différée revient à l'heure dite**, et pas avant ;
 * 4. **`connuesPour()` charge tous les états** — en omettre un ferait recréer une ligne
 *    que l'index unique refuserait, ou, pire, reposerait la question.
 *
 * Aucune personne réelle : noms inventés (ADR 0006).
 */
final class RequetesDeQuestionTest extends KernelTestCase
{
    private const int OPALE = 11;
    private const int TUF = 12;

    private EntityManagerInterface $em;
    private QuestionRepository $questions;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        ReinitialisationDuSchema::reinitialiser($this->em);

        $depot = $this->em->getRepository(Question::class);
        \assert($depot instanceof QuestionRepository);
        $this->questions = $depot;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    /**
     * **Le cas qui compte.** Une question posée à Opale ne doit atteindre Tuf sous aucun
     * état, et surtout pas par le membre `OR` de la clause.
     */
    public function testUnCompteNeVoitQueSesQuestions(): void
    {
        $this->poser(self::OPALE, NatureDeLacune::AucunePhoto, 101);
        $laSienne = $this->poser(self::TUF, NatureDeLacune::MereInconnue, 202);

        // Une question différée chez Opale, déjà réveillée : c'est elle que la mauvaise
        // parenthèse ferait fuir chez Tuf.
        $this->poser(self::OPALE, NatureDeLacune::PereInconnu, 303)
            ->differer(new \DateTimeImmutable('2026-09-01 00:00:00'));
        $this->em->flush();

        $servables = $this->questions->servablesPour(self::TUF, new \DateTimeImmutable('2026-09-23 12:00:00'));

        self::assertSame(
            [$laSienne->getId()],
            array_map(static fn (Question $q): ?int => $q->getId(), $servables),
            'La page de Tuf ne doit contenir que les questions de Tuf.',
        );
    }

    /** « Je ne sais pas » et « répondue » ne reviennent jamais. */
    public function testLesEtatsDefinitifsNeSontPlusServis(): void
    {
        $ouverte = $this->poser(self::OPALE, NatureDeLacune::AucunePhoto, 101);
        $this->poser(self::OPALE, NatureDeLacune::MereInconnue, 101)->fermerFauteDeSavoir();
        $this->poser(self::OPALE, NatureDeLacune::PereInconnu, 101)->marquerRepondue('Il s\'appelait Thym.');
        $this->em->flush();

        $servables = $this->questions->servablesPour(self::OPALE, new \DateTimeImmutable('2026-09-23 12:00:00'));

        self::assertSame(
            [$ouverte->getId()],
            array_map(static fn (Question $q): ?int => $q->getId(), $servables),
        );
    }

    /** « Je vais me renseigner » revient **à l'heure dite**, et pas avant. */
    public function testUneQuestionDiffereeRevientApresSaDate(): void
    {
        $differee = $this->poser(self::OPALE, NatureDeLacune::LieuDeDecesInconnu, 101)
            ->differer(new \DateTimeImmutable('2026-10-23 08:00:00'));
        $this->em->flush();

        self::assertSame(
            [],
            $this->questions->servablesPour(self::OPALE, new \DateTimeImmutable('2026-10-22 23:59:00')),
            'Une question repoussée ne revient pas la veille.',
        );

        self::assertSame(
            [$differee->getId()],
            array_map(
                static fn (Question $q): ?int => $q->getId(),
                $this->questions->servablesPour(self::OPALE, new \DateTimeImmutable('2026-10-23 09:00:00')),
            ),
        );
    }

    /**
     * `connuesPour()` charge **tous** les états — c'est là qu'est la mémoire.
     *
     * En omettre un ferait recalculer une signature déjà connue, donc tenter une
     * insertion que `uniq_qst_question_lacune` refuserait — ou, si l'index venait à
     * manquer, reposer une question fermée.
     */
    public function testLesQuestionsConnuesPortentTousLesEtats(): void
    {
        $this->poser(self::OPALE, NatureDeLacune::AucunePhoto, 101);
        $this->poser(self::OPALE, NatureDeLacune::MereInconnue, 101)->fermerFauteDeSavoir();
        $this->poser(self::OPALE, NatureDeLacune::PereInconnu, 101)->differer(new \DateTimeImmutable('2027-01-01 00:00:00'));
        $this->poser(self::TUF, NatureDeLacune::NomInconnu, 101);
        $this->em->flush();

        $connues = $this->questions->connuesPour(self::OPALE);

        self::assertCount(3, $connues, 'Les trois états d\'Opale, et aucune question de Tuf.');
        self::assertArrayHasKey(
            Question::signatureDe(NatureDeLacune::MereInconnue, SubjectKind::Personne, 101),
            $connues,
            'La question fermée est la plus importante des trois : c\'est elle qu\'on ne doit pas reposer.',
        );
    }

    /**
     * La même lacune chez plusieurs destinataires se retrouve par sa signature.
     *
     * C'est la requête que le destinataire dans le haché aurait rendue impossible.
     */
    public function testLaMemeLacuneSeRetrouveChezTousSesDestinataires(): void
    {
        $this->poser(self::OPALE, NatureDeLacune::LieuDeDecesInconnu, 101)->fermerFauteDeSavoir();
        $this->poser(self::TUF, NatureDeLacune::LieuDeDecesInconnu, 101);
        $this->poser(self::TUF, NatureDeLacune::LieuDeDecesInconnu, 999);
        $this->em->flush();

        $memeLacune = $this->questions->memeLacuneChezLesAutres(
            NatureDeLacune::LieuDeDecesInconnu,
            SubjectKind::Personne,
            101,
        );

        self::assertCount(2, $memeLacune);
    }

    private function poser(int $destinataire, NatureDeLacune $nature, int $sujetId): Question
    {
        $question = new Question(
            $destinataire,
            $nature,
            SubjectKind::Personne,
            $sujetId,
            'Prune Bréval',
            \sprintf($nature->gabarit(), 'Prune Bréval'),
            RedacteurDeQuestion::Regles,
        );

        $this->em->persist($question);
        $this->em->flush();

        return $question;
    }
}
