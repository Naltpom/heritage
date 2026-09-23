<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Service\SettingService;
use App\Module\Question\Contract\PortMediasPourQuestions;
use App\Module\Question\Entity\Question;
use App\Module\Question\Enum\EtatDeQuestion;
use App\Module\Question\Enum\NatureDeLacune;
use App\Module\Question\Enum\RedacteurDeQuestion;
use App\Module\Question\Repository\QuestionRepository;
use App\Module\Question\Service\DetecteurDeLacunes;
use App\Module\Question\Service\LacunesVisibles;
use App\Module\Question\Service\Port\MediasNonBranchesPourQuestions;
use App\Module\Question\Service\RedactionParGabarit;
use App\Module\Question\Service\ReglagesDesQuestions;
use App\Module\Question\Service\ServiceDesQuestions;
use App\Tests\Question\Double\ArbreEnMemoire;
use App\Tests\Question\Double\CercleEnMemoire;
use App\Tests\Question\Double\MediasEnMemoire;
use App\Tests\ReinitialisationDuSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La page « Questions » de bout en bout — **sans Gemini, et c'est tout l'objet du jalon**.
 *
 * Tout ce que ce fichier vérifie fonctionne avec le tiers coupé, hors quota ou jamais
 * configuré : la rédaction est assurée par {@see RedactionParGabarit}, qui n'a aucune
 * dépendance. **C'est la garantie de l'ADR 0008** — *« une page vide parce qu'un tiers ne
 * répond pas est un défaut de conception, pas un incident »* —, et elle se mesure ici.
 *
 * ## Le montage : une vraie base, des ports doublés
 *
 * `qst_question` est réelle (SQLite, comme le reste de la suite) parce que la mémoire des
 * réponses **est** le sujet : c'est l'index unique qui rend la détection idempotente, et
 * un dépôt en mémoire l'aurait simulé plutôt qu'exercé. L'arbre, les médias et le cercle
 * sont doublés : leur traduction depuis les vraies tables est éprouvée chez eux.
 *
 * `ServiceDesQuestions` est **monté à la main** plutôt que tiré du conteneur, pour une
 * raison de portée : aliaser `CercleDeVisibiliteInterface` dans
 * `config/services/question.yaml` sous `when@test` l'emporterait sur l'alias de
 * `membership.yaml` — le glob des fichiers de services est trié, et « question » passe
 * après « membership ». On casserait les tests du cercle d'un autre module depuis ce
 * fichier-ci, ce qui est exactement le genre de dégât qu'on ne rattache jamais à sa cause.
 *
 * Aucune personne réelle : noms inventés (ADR 0006).
 */
final class PageDesQuestionsTest extends KernelTestCase
{
    private const int OPALE = 11;
    private const int TUF = 12;

    private EntityManagerInterface $em;
    private QuestionRepository $questions;
    private ArbreEnMemoire $arbre;
    private CercleEnMemoire $cercle;

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

        $this->arbre = new ArbreEnMemoire();
        $this->cercle = new CercleEnMemoire();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    /* ───────────────── Ce que la page sert ───────────────── */

    /**
     * **Le cas qui définit le jalon.** Aucun appel sortant, et la page sert des questions
     * lisibles, nommant la personne.
     */
    public function testLaPageSertDesQuestionsSansAucunAppelSortant(): void
    {
        $this->arbre->ajouter(1, 'Prune Bréval');

        $servies = $this->page(self::OPALE, [1]);

        self::assertNotCount(0, $servies);

        foreach ($servies as $question) {
            self::assertSame(RedacteurDeQuestion::Regles, $question->getRedacteur());
            self::assertStringContainsString('Prune Bréval', $question->getTexte());
            self::assertSame(EtatDeQuestion::Posee, $question->getEtat());
        }
    }

    /**
     * La photo passe en premier : une page qui s'ouvre sur « auriez-vous une photo ? »
     * obtient une première contribution, là où « quand est-il né ? » fait refermer.
     */
    public function testLesQuestionsSortentDansLOrdreDAffichage(): void
    {
        $this->arbre->ajouter(1);

        $rangs = array_map(
            static fn (Question $q): int => $q->getNature()->rang(),
            $this->page(self::OPALE, [1], medias: new MediasEnMemoire()),
        );

        self::assertSame(NatureDeLacune::AucunePhoto->rang(), $rangs[0]);

        $tries = $rangs;
        sort($tries);
        self::assertSame($tries, $rangs, 'Les rangs doivent être croissants.');
    }

    /** La borne existe parce qu'une liste sans fin n'est pas une liste. */
    public function testLaPageEstBornee(): void
    {
        for ($id = 1; $id <= 12; ++$id) {
            $this->arbre->ajouter($id);
        }

        $maximum = 5;
        $servies = $this->page(self::OPALE, range(1, 12), maximum: $maximum);

        self::assertCount($maximum, $servies);
    }

    /* ───────────────── L'idempotence ───────────────── */

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  REJOUER LA DÉTECTION NE CRÉE RIEN DE PLUS.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * C'est ce qui rend acceptable que la page **écrive en se lisant** : ouvrir la page
     * mille fois laisse le même nombre de lignes. Sans cette propriété, l'effet de bord
     * serait un défaut ; avec elle, c'est une conséquence assumée de l'ADR 0008 § 3.
     */
    public function testRejouerLaDetectionNeCreeAucuneLigneDePlus(): void
    {
        $this->arbre->ajouter(1);

        $premier = \count($this->page(self::OPALE, [1]));
        $second = \count($this->page(self::OPALE, [1]));
        $troisieme = \count($this->page(self::OPALE, [1]));

        self::assertSame($premier, $second);
        self::assertSame($premier, $troisieme);
        self::assertCount($premier, $this->questions->connuesPour(self::OPALE));
    }

    /**
     * **« Je ne sais pas » survit à la détection rejouée.** C'est la mémoire, et sans
     * elle la page devient un mur de choses auxquelles on a déjà répondu.
     */
    public function testUneQuestionFermeeNeRevientJamais(): void
    {
        $this->arbre->ajouter(1);

        $premiere = $this->page(self::OPALE, [1])[0];
        $signature = $premiere->getSignature();

        $premiere->fermerFauteDeSavoir();
        $this->em->flush();

        $signatures = array_map(
            static fn (Question $q): string => $q->getSignature(),
            $this->page(self::OPALE, [1]),
        );

        self::assertNotContains($signature, $signatures);
    }

    /** « Je vais me renseigner » revient, mais plus tard. */
    public function testUneQuestionDiffereeRevientApresSaDate(): void
    {
        $this->arbre->ajouter(1);

        $question = $this->page(self::OPALE, [1])[0];
        $question->differer(new \DateTimeImmutable('2026-12-01 00:00:00'));
        $this->em->flush();

        $avant = array_map(
            static fn (Question $q): string => $q->getSignature(),
            $this->page(self::OPALE, [1], maintenant: new \DateTimeImmutable('2026-11-30 12:00:00')),
        );
        self::assertNotContains($question->getSignature(), $avant);

        $apres = array_map(
            static fn (Question $q): string => $q->getSignature(),
            $this->page(self::OPALE, [1], maintenant: new \DateTimeImmutable('2026-12-02 12:00:00')),
        );
        self::assertContains($question->getSignature(), $apres);
    }

    /**
     * **Une lacune comblée cesse d'être détectée ; elle ne se balaie pas.**.
     *
     * La ligne reste en base avec son état, et ne revient simplement plus. Même
     * discipline que la caducité de l'ADR 0004 : constatée au moment où l'on regarde,
     * jamais par une tâche de fond.
     */
    public function testUneLacuneCombleeDisparaitDeLaPageSansQuRienNeSoitEfface(): void
    {
        $this->arbre->ajouter(1);
        $avant = \count($this->page(self::OPALE, [1]));
        self::assertGreaterThan(0, $avant);

        // L'arbre a été corrigé : la fiche est désormais complète — et elle porte une
        // photo, sans quoi `aucune_photo` resterait, à juste titre, et le cas ne
        // mesurerait plus la disparition qu'il vise.
        $this->arbre->oublierTout()->ajouterComplete(1);

        self::assertCount(0, $this->page(self::OPALE, [1], medias: new MediasEnMemoire([1 => 1])));
        self::assertCount($avant, $this->questions->connuesPour(self::OPALE), 'Rien n’est effacé.');
    }

    /* ───────────────── Les deux gardes du cercle ───────────────── */

    /**
     * **Garde à la création.** On ne crée pas une question nommant quelqu'un que ce
     * compte n'a pas le droit de voir — la question porte le nom **dans son texte**.
     */
    public function testAucuneQuestionNEstCreeeSurUnePersonneHorsCercle(): void
    {
        $this->arbre->ajouter(1, 'Prune Bréval')->ajouter(2, 'Thym Bréval');

        // Le cercle d'Opale ne contient que la personne 1.
        $servies = $this->page(self::OPALE, [1]);

        self::assertNotCount(0, $servies);
        self::assertCount(0, $this->questions->memeLacuneChezLesAutres(
            NatureDeLacune::MereInconnue,
            SubjectKind::Personne,
            2,
        ), 'Aucune ligne ne doit exister pour la personne hors cercle.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  GARDE À LA LECTURE — LE CERCLE RÉTRÉCIT, LES LIGNES D'HIER NE BOUGENT PAS.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * `cercle.generations` est un réglage d'administration (ADR 0003). Le faire passer de
     * 4 à 3 retire des personnes de tous les cercles d'un coup ; les questions déjà en
     * base, elles, restent. Sans ce second contrôle, la page servirait le lendemain des
     * phrases nommant des vivants devenus hors cercle — et **aucun test de porte de
     * sortie ne sonnerait**, cette porte-là n'existant pas quand ils ont été écrits.
     */
    public function testUneQuestionCesseDEtreServieQuandLeCercleSeRetrecit(): void
    {
        $this->arbre->ajouter(1, 'Prune Bréval');

        self::assertNotCount(0, $this->page(self::OPALE, [1]));

        $this->cercle->retirer(1);

        self::assertCount(0, $this->page(self::OPALE, [1], cercleDejaPose: true));
        self::assertNotCount(
            0,
            $this->questions->connuesPour(self::OPALE),
            'Les lignes restent : c’est le service qui se tait, pas la base qui s’efface.',
        );
    }

    /** Un compte en attente de rattachement n'a **pas de cercle** : aucune question. */
    public function testUnCompteSansCercleNObtientAucuneQuestion(): void
    {
        $this->arbre->ajouter(1);
        $this->cercle = CercleEnMemoire::sansCercle();

        self::assertCount(0, $this->page(self::OPALE, [], cercleDejaPose: true));
        self::assertCount(0, $this->questions->connuesPour(self::OPALE));
    }

    /* ───────────────── La photo et le port non branché ───────────────── */

    /**
     * **Le repli des médias fait taire `aucune_photo`** — reconnu par son TYPE, pas par
     * un tableau vide. Sinon la page demanderait une photo de toutes les fiches de
     * l'arbre, y compris celles qui en ont déjà une.
     */
    public function testLeRepliDesMediasFaitTaireLaQuestionDePhoto(): void
    {
        $this->arbre->ajouter(1);

        $naturesAvecRepli = $this->natures($this->page(self::OPALE, [1], medias: new MediasNonBranchesPourQuestions()));
        self::assertNotContains(NatureDeLacune::AucunePhoto, $naturesAvecRepli);

        // La même situation, port branché sur un catalogue réellement vide : la question
        // se pose, et c'est juste.
        $naturesBranche = $this->natures($this->page(self::TUF, [1], medias: new MediasEnMemoire()));
        self::assertContains(NatureDeLacune::AucunePhoto, $naturesBranche);
    }

    /* ───────────────── Le cloisonnement entre comptes ───────────────── */

    public function testDeuxComptesOntChacunLeursQuestions(): void
    {
        $this->arbre->ajouter(1);

        $dOpale = $this->page(self::OPALE, [1]);
        $deTuf = $this->page(self::TUF, [1], cercleDejaPose: false);

        self::assertNotCount(0, $dOpale);
        self::assertNotCount(0, $deTuf);

        foreach ($dOpale as $question) {
            self::assertSame(self::OPALE, $question->getDestinataireUserId());
        }

        // La même lacune, posée aux deux : c'est exactement ce que la signature permet de
        // retrouver, et ce que le destinataire dans le haché aurait rendu impossible.
        self::assertCount(2, $this->questions->memeLacuneChezLesAutres(
            $dOpale[0]->getNature(),
            $dOpale[0]->getSujetKind(),
            $dOpale[0]->getSujetId(),
        ));
    }

    /* ───────────────── Montage ───────────────── */

    /**
     * Joue une ouverture de page.
     *
     * @param list<int> $cercle les personnes visibles de ce compte, posées avant l'appel
     *
     * @return list<Question>
     */
    private function page(
        int $userId,
        array $cercle,
        ?\DateTimeImmutable $maintenant = null,
        ?PortMediasPourQuestions $medias = null,
        ?int $maximum = null,
        bool $cercleDejaPose = false,
    ): array {
        if (!$cercleDejaPose) {
            $this->cercle = new CercleEnMemoire(personnes: $cercle);
        }

        $medias ??= new MediasEnMemoire();

        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);

        if (null !== $maximum) {
            // La ligne est POSÉE ICI, et non par une migration : `SettingService::entier()`
            // prend un défaut, et une clé absente de `core_setting` vaut ce défaut. Semer
            // la ligne à l'installation aurait figé la valeur du jour de l'installation,
            // ce qui est l'inverse du but d'un réglage. `modifier()` exige en revanche une
            // ligne existante — d'où cette création directe, propre au test.
            $this->em->persist(new Setting(
                ReglagesDesQuestions::CLE_MAXIMUM_PAR_PAGE,
                (string) $maximum,
                SettingType::Entier,
                'Questions servies par page',
            ));
            $this->em->flush();
        }

        $service = new ServiceDesQuestions(
            $this->arbre,
            $medias,
            new DetecteurDeLacunes(),
            new RedactionParGabarit(),
            new LacunesVisibles($this->cercle),
            $this->questions,
            new ReglagesDesQuestions($reglages),
            $this->em,
        );

        return $service->pour($userId, $maintenant ?? new \DateTimeImmutable('2026-09-23 12:00:00'));
    }

    /**
     * @param list<Question> $questions
     *
     * @return list<NatureDeLacune>
     */
    private function natures(array $questions): array
    {
        return array_values(array_map(static fn (Question $q): NatureDeLacune => $q->getNature(), $questions));
    }
}
