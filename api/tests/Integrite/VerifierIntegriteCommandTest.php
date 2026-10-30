<?php

declare(strict_types=1);

namespace App\Tests\Integrite;

use App\Integrite\ControleDIntegrite;
use App\Integrite\VerificationDIntegrite;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `heritage:verifier-integrite` — LE FILET QUE LA BASE NE TEND PLUS (ADR 0002).
 *
 * ## Ce que ce fichier prouve
 *
 * 1. **Sur une base saine, la commande sort en `0`** — et la base de ce test n'est pas
 *    vide : elle porte au moins une ligne pour **chacune** des références déclarées. Un
 *    contrôle qui ne relève rien ne prouve rien ; l'assiette est donc affirmée, référence
 *    par référence.
 * 2. **Le recensement est figé.** La liste exacte des références contrôlées est écrite
 *    ici, et c'est elle que `docs/modele-donnees.md` § 10.8 recopie. Ajouter un `person_id`
 *    quelque part sans le déclarer ne fait rien rougir — mais le déclarer sans mettre à
 *    jour ce test et la doc, si.
 * 3. **Chaque référence pendante est trouvée.** Le scénario de l'incident réel — un
 *    `TRUNCATE` des tables cibles sous des modules qui les désignent toujours — fait pendre
 *    tout à la fois, et chaque contrôle doit nommer exactement les identifiants qu'il
 *    relevait. Puis, une à une, les références de personne que la suppression de fiche ne
 *    consultait pas : chacune seule, détectée seule.
 * 4. **La tolérance ne déborde pas** : un historique pendant (événement clos, autorisation
 *    archivée, invitation close) est montré et ne change pas le code de sortie.
 *
 * ## La base est SQLite
 *
 * Les requêtes émises sont un `GROUP BY` et un `IN` par module, sans rien de propre à un
 * moteur. PostgreSQL les rejoue en CI après les migrations (voir le rapport de la tâche et
 * `README.md`, « Vérifier »).
 */
final class VerifierIntegriteCommandTest extends KernelTestCase
{
    private const string BASE = 'sqlite:///:memory:';

    /** Une date à venir et une date passée, loin de « aujourd'hui » pour ne jamais basculer. */
    private const string AVENIR = '2999-12-24';
    private const string PASSE = '2000-12-24';

    /**
     * Le recensement : toutes les références contrôlées, triées. C'est la liste que le
     * § 10.8 de `docs/modele-donnees.md` reprend, ligne pour ligne.
     */
    private const array RECENSEMENT = [
        'ast_conversation.user_id',
        'dmr_demarche.auteur_user_id',
        'dmr_echange.saisi_par_user_id',
        'dmr_personne.person_id',
        'evt_evenement.organisateur_user_id',
        'evt_invitation.invite_par_user_id',
        'evt_invitation.person_id [événements passés ou annulés]',
        'evt_invitation.person_id [événements à venir ou en cours, non annulés]',
        'evt_invitation.reponse_notee_par_user_id',
        'mbr_autorisation_naissance.archived_by_user_id',
        'mbr_autorisation_naissance.granted_by_user_id',
        'mbr_autorisation_naissance.person_id [autorisations actives]',
        'mbr_autorisation_naissance.person_id [autorisations archivées]',
        'mbr_demande_rgpd.traitee_par_user_id',
        'mbr_demande_rgpd.user_id',
        'mbr_invitation.created_by_user_id',
        'mbr_invitation.person_id [invitations actives]',
        'mbr_invitation.person_id [invitations utilisées, expirées ou révoquées]',
        'mbr_invitation.revoked_by_user_id',
        'mbr_invitation.used_by_user_id',
        'mbr_member.activated_by_user_id',
        'mbr_member.person_id',
        'mbr_member.user_id',
        'med_media.change_request_id',
        'med_media.subject_id [subject_kind = citation]',
        'med_media.subject_id [subject_kind = famille]',
        'med_media.subject_id [subject_kind = personne]',
        'med_media.subject_request_id',
        'med_media.subject_user_id',
        'med_media.uploaded_by_user_id',
        'prp_change_op.subject_id [subject_kind = citation]',
        'prp_change_op.subject_id [subject_kind = famille]',
        'prp_change_op.subject_id [subject_kind = personne]',
        'prp_change_request.author_user_id',
        'prp_change_request.decided_by_user_id',
        'prp_change_request.target_id [target_kind = citation]',
        'prp_change_request.target_id [target_kind = famille]',
        'prp_change_request.target_id [target_kind = personne]',
        'prp_draft_link.family_id',
        'prp_draft_link.other2_person_id',
        'prp_draft_link.other_person_id',
        'prp_draft_link.resolved_family_id',
        'prp_draft_link.subject_person_id',
        'prp_draft_person.resolved_person_id',
        'prp_revision.actor_user_id',
        'prp_revision.subject_id [subject_kind = citation]',
        'prp_revision.subject_id [subject_kind = famille]',
        'prp_revision.subject_id [subject_kind = personne]',
        'qst_question.destinataire_user_id',
        'qst_question.sujet_id [sujet_kind = famille]',
        'qst_question.sujet_id [sujet_kind = personne]',
        'qst_question_demande.demande_id',
        'tml_preference.user_id',
        'tree_note.author_user_id',
        'tree_research_closure.author_user_id',
    ];

    /** Celles dont une valeur pendante est attendue — historique, preuve, caducité prévue. */
    private const array TOLEREES = [
        'evt_invitation.person_id [événements passés ou annulés]',
        'mbr_autorisation_naissance.person_id [autorisations archivées]',
        'mbr_invitation.person_id [invitations utilisées, expirées ou révoquées]',
        'prp_change_op.subject_id [subject_kind = citation]',
        'prp_change_op.subject_id [subject_kind = famille]',
        'prp_change_op.subject_id [subject_kind = personne]',
        'prp_change_request.target_id [target_kind = citation]',
        'prp_change_request.target_id [target_kind = famille]',
        'prp_change_request.target_id [target_kind = personne]',
        'prp_draft_link.family_id',
        'prp_draft_link.other2_person_id',
        'prp_draft_link.other_person_id',
        'prp_draft_link.resolved_family_id',
        'prp_draft_link.subject_person_id',
        'prp_draft_person.resolved_person_id',
        'prp_revision.subject_id [subject_kind = citation]',
        'prp_revision.subject_id [subject_kind = famille]',
        'prp_revision.subject_id [subject_kind = personne]',
    ];

    private ?string $baseDOrigine = null;

    protected function setUp(): void
    {
        $this->baseDOrigine = \is_string($_ENV['DATABASE_URL'] ?? null) ? $_ENV['DATABASE_URL'] : null;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::BASE;

        self::bootKernel();

        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->connexion()->close();
        }

        self::ensureKernelShutdown();

        if (null === $this->baseDOrigine) {
            unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $this->baseDOrigine;
        }

        parent::tearDown();
    }

    /**
     * Le recensement, et l'assiette : la base saine porte au moins une ligne pour chaque
     * référence. Sans la seconde moitié, un contrôle dont la requête ne relève plus rien —
     * une colonne renommée, une portée fausse — resterait vert sans rien vérifier.
     */
    public function testLeRecensementEstCeluiDuModeleEtChaqueReferenceEstExercee(): void
    {
        $this->peuplerUneBaseSaine();

        $rapport = $this->verification()->verifier();
        $libelles = array_map(static fn (ControleDIntegrite $c): string => $c->reference->libelle(), $rapport->controles);
        sort($libelles);

        // Pas de nombre magique : il diverge du § 10.8, qui compte aussi des références de
        // tables que ce schéma ne porte plus. La liste figée ci-dessus fait foi.
        self::assertCount(\count(self::RECENSEMENT), $libelles);
        self::assertContains('tree_research_closure.author_user_id', $libelles);
        self::assertSame(self::RECENSEMENT, $libelles, 'Une référence a été ajoutée ou retirée : mettez à jour ce recensement ET le § 10.8 de docs/modele-donnees.md.');

        foreach ($rapport->controles as $controle) {
            self::assertGreaterThan(0, $controle->lignesRelevees(), \sprintf('La base saine ne porte aucune ligne pour %s : ce contrôle n\'est pas exercé.', $controle->reference->libelle()));
            self::assertSame(
                \in_array($controle->reference->libelle(), self::TOLEREES, true),
                null !== $controle->reference->tolerance,
                \sprintf('%s : la tolérance déclarée n\'est pas celle que ce test attend.', $controle->reference->libelle()),
            );
        }
    }

    public function testUneBaseSaineSortEnZero(): void
    {
        $this->peuplerUneBaseSaine();

        $testeur = $this->executer();

        self::assertSame(Command::SUCCESS, $testeur->getStatusCode(), $testeur->getDisplay());
        self::assertStringContainsString(\sprintf('%d contrôle(s), aucune référence pendante hors tolérance (0 tolérée(s))', \count(self::RECENSEMENT)), $this->texte($testeur));
        self::assertStringNotContainsString('VIOLATION', $testeur->getDisplay());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **L'INCIDENT RÉEL, EN GRAND** : les tables cibles vidées sous les modules qui les
     * désignent.
     *
     * `CercleDeVisibilite` en garde la trace : un `TRUNCATE` a laissé `mbr_member.person_id`
     * pendant, et le compte s'est retrouvé sans racine. Ici, toutes les cibles disparaissent
     * d'un coup — comptes, personnes, familles, citations, demandes. **Chaque** contrôle doit
     * alors nommer exactement les identifiants qu'il relevait, ni plus ni moins ; les stricts
     * sont des violations, les tolérés restent tolérés.
     */
    public function testToutesLesCiblesVideesChaqueReferenceEstTrouvee(): void
    {
        $this->peuplerUneBaseSaine();

        $connexion = $this->connexion();
        $connexion->executeStatement('PRAGMA foreign_keys = OFF');
        foreach (['core_user', 'tree_person', 'tree_family', 'tree_citation'] as $cible) {
            $connexion->executeStatement('DELETE FROM '.$cible);
        }

        // `prp_change_request` est à la fois cible et source (son auteur, son
        // validateur) : on ne la vide pas, on **déplace** ses identifiants. Ses lignes
        // restent relevées, et plus aucune ne porte le numéro qu'on désigne.
        $connexion->executeStatement('UPDATE prp_change_request SET id = id + 500000');

        $rapport = $this->verification()->verifier();

        foreach ($rapport->controles as $controle) {
            self::assertSame(
                $controle->reference->occurrences,
                $controle->pendants,
                \sprintf('%s : chaque identifiant relevé devait être trouvé pendant.', $controle->reference->libelle()),
            );
        }

        $violations = array_map(static fn (ControleDIntegrite $c): string => $c->reference->libelle(), $rapport->violations());
        sort($violations);

        self::assertSame(array_values(array_diff(self::RECENSEMENT, self::TOLEREES)), $violations);
        self::assertFalse($rapport->estSain());

        $testeur = $this->executer();
        self::assertSame(Command::FAILURE, $testeur->getStatusCode());
        self::assertStringContainsString('VIOLATION', $testeur->getDisplay());
    }

    /**
     * Les références de personne que la suppression de fiche ne consultait pas, **une à
     * une** : chacune, rendue pendante seule, est la seule violation du rapport, avec son
     * identifiant.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function referencesDePersonne(): iterable
    {
        yield 'compte rattaché' => ['mbr_member', 'person_id', 'mbr_member.person_id'];
        yield 'démarche d\'acte' => ['dmr_personne', 'person_id', 'dmr_personne.person_id'];
        yield 'question posée' => ['qst_question', 'sujet_id', 'qst_question.sujet_id [sujet_kind = personne]'];
    }

    #[DataProvider('referencesDePersonne')]
    public function testUneReferenceDePersonnePendanteEstTrouveeSeule(string $table, string $colonne, string $attendue): void
    {
        $this->peuplerUneBaseSaine();

        $condition = 'qst_question' === $table ? " WHERE sujet_kind = 'personne'" : '';
        $this->connexion()->executeStatement(\sprintf('UPDATE %s SET %s = 990001%s', $table, $colonne, $condition));

        $this->exigerUneSeuleViolation($attendue, 990001);
    }

    /** Les portées strictes des colonnes déclarées deux fois : la bonne moitié est stricte. */
    public function testUneInvitationAUnEvenementAVenirPendanteEstUneViolation(): void
    {
        $this->peuplerUneBaseSaine();
        $this->connexion()->executeStatement('UPDATE evt_invitation SET person_id = 990002 WHERE evenement_id = 1');

        $this->exigerUneSeuleViolation('evt_invitation.person_id [événements à venir ou en cours, non annulés]', 990002);
    }

    public function testUneAutorisationActivePendanteEstUneViolation(): void
    {
        $this->peuplerUneBaseSaine();
        $this->connexion()->executeStatement('UPDATE mbr_autorisation_naissance SET person_id = 990004 WHERE archived_at IS NULL');

        $this->exigerUneSeuleViolation('mbr_autorisation_naissance.person_id [autorisations actives]', 990004);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LA TOLÉRANCE NE DÉBORDE PAS** — l'historique pend, la commande sort en `0` et le dit.
     */
    public function testUnHistoriquePendantEstMontreSansFaireEchouer(): void
    {
        $this->peuplerUneBaseSaine();
        $connexion = $this->connexion();
        $connexion->executeStatement('UPDATE evt_invitation SET person_id = 990010 WHERE evenement_id = 2');
        $connexion->executeStatement('UPDATE mbr_autorisation_naissance SET person_id = 990011 WHERE archived_at IS NOT NULL');
        $connexion->executeStatement("UPDATE mbr_invitation SET person_id = 990012 WHERE status <> 'active'");

        $rapport = $this->verification()->verifier();

        self::assertTrue($rapport->estSain());
        self::assertCount(3, $rapport->tolerees());

        $testeur = $this->executer();
        self::assertSame(Command::SUCCESS, $testeur->getStatusCode());
        self::assertStringContainsString('tolérée', $testeur->getDisplay());
        self::assertStringContainsString('(3 tolérée(s))', $this->texte($testeur));
        self::assertStringContainsString('jamais supprimée (ADR 0016)', $this->texte($testeur), 'La raison de la tolérance est écrite.');
    }

    /** `--format=json` : les clés sont un contrat, pour la CI et les scripts. */
    public function testLeFormatJsonPorteLeVerdictEtLeDetail(): void
    {
        $this->peuplerUneBaseSaine();
        $this->connexion()->executeStatement('UPDATE mbr_member SET person_id = 990020');

        $testeur = $this->executer(['--format' => 'json']);
        self::assertSame(Command::FAILURE, $testeur->getStatusCode());

        /** @var array{sain: bool, controles: int, violations: int, tolerees: int, anomalies: list<string>, details: list<array<string, mixed>>} $json */
        $json = json_decode($testeur->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertFalse($json['sain']);
        self::assertSame(\count(self::RECENSEMENT), $json['controles']);
        self::assertSame(1, $json['violations']);
        self::assertSame([], $json['anomalies']);

        $membre = array_values(array_filter($json['details'], static fn (array $d): bool => 'mbr_member.person_id' === $d['source']));
        self::assertSame([[
            'module' => 'Membership',
            'source' => 'mbr_member.person_id',
            'portee' => null,
            'cible' => 'tree_person',
            'etat' => 'violation',
            'lignesRelevees' => 1,
            'lignesPendantes' => 1,
            'identifiantsPendants' => [990020],
            'tolerance' => null,
        ]], $membre);
    }

    public function testUnFormatInconnuEstRefuse(): void
    {
        $testeur = $this->executer(['--format' => 'xml']);

        self::assertSame(Command::INVALID, $testeur->getStatusCode());
    }

    // ── Outils ──────────────────────────────────────────────────────────────────

    private function exigerUneSeuleViolation(string $attendue, int $identifiant): void
    {
        $rapport = $this->verification()->verifier();
        $violations = $rapport->violations();

        self::assertCount(1, $violations, implode(', ', array_map(static fn (ControleDIntegrite $c): string => $c->reference->libelle(), $violations)));
        self::assertSame($attendue, $violations[0]->reference->libelle());
        self::assertSame([$identifiant], array_keys($violations[0]->pendants));

        $testeur = $this->executer();
        self::assertSame(Command::FAILURE, $testeur->getStatusCode());
        self::assertStringContainsString((string) $identifiant, $testeur->getDisplay());
    }

    /** @param array<string, string> $options */
    private function executer(array $options = []): CommandTester
    {
        \assert(null !== self::$kernel);
        $application = new Application(self::$kernel);
        $testeur = new CommandTester($application->find('heritage:verifier-integrite'));
        $testeur->execute($options);

        return $testeur;
    }

    /** La sortie sans les retours à la ligne ni les marges de `SymfonyStyle`. */
    private function texte(CommandTester $testeur): string
    {
        return (string) preg_replace('/\s+/', ' ', $testeur->getDisplay());
    }

    private function verification(): VerificationDIntegrite
    {
        $verification = self::getContainer()->get(VerificationDIntegrite::class);
        self::assertInstanceOf(VerificationDIntegrite::class, $verification);

        return $verification;
    }

    private function connexion(): Connection
    {
        $connexion = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connexion);

        return $connexion;
    }

    /**
     * Une base où **chaque** référence déclarée a au moins une ligne, et où toutes
     * résolvent : un compte (1), une personne (1, et 2 pour l'autre extrémité des liens),
     * une famille (1), une citation (1), trois demandes (1 à 3, une par sorte de cible).
     *
     * Écrite en SQL et non par les entités : ce qu'on éprouve est la lecture des colonnes,
     * et des `INSERT` disent exactement quelle colonne porte quoi. Les deux portées des
     * colonnes déclarées deux fois sont garnies — à venir et passé, actif et archivé.
     */
    private function peuplerUneBaseSaine(): void
    {
        $quand = '2026-01-01 00:00:00';

        $lignes = [
            'core_user' => [
                ['id' => 1, 'email' => 'admin@example.invalid', 'password' => '!', 'roles' => '[]', 'display_name' => 'Admin', 'status' => 'actif', 'ignore_birth_restriction' => 0, 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'tree_person' => [
                ['id' => 1, 'display_name' => 'Opale Sureau', 'created_at' => $quand, 'updated_at' => $quand],
                ['id' => 2, 'display_name' => 'Cobalt Sureau', 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'tree_family' => [
                ['id' => 1, 'husband_id' => 2, 'wife_id' => 1, 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'tree_source' => [
                ['id' => 1, 'title' => 'Registre de Saule', 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'tree_citation' => [
                ['id' => 1, 'source_id' => 1, 'person_id' => 1, 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'tree_note' => [
                ['id' => 1, 'body' => 'Note', 'author_user_id' => 1, 'person_id' => 1, 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'tree_research_closure' => [
                ['id' => 1, 'person_id' => 1, 'side' => 'pere', 'reason' => 'registres_detruits', 'author_user_id' => 1, 'closed_at' => $quand, 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'mbr_invitation' => [
                ['id' => 1, 'token_hash' => str_repeat('a', 64), 'token_prefix' => 'aaaaaaaa', 'person_id' => 1, 'person_label' => 'Opale Sureau', 'status' => 'active', 'created_by_user_id' => 1, 'created_by_email' => 'admin@example.invalid', 'created_at' => $quand],
                ['id' => 2, 'token_hash' => str_repeat('b', 64), 'token_prefix' => 'bbbbbbbb', 'person_id' => 2, 'person_label' => 'Cobalt Sureau', 'status' => 'revoquee', 'used_by_user_id' => 1, 'revoked_by_user_id' => 1, 'created_by_user_id' => 1, 'created_by_email' => 'admin@example.invalid', 'created_at' => $quand],
            ],
            'mbr_member' => [
                ['id' => 1, 'user_id' => 1, 'user_email' => 'admin@example.invalid', 'person_id' => 1, 'person_label' => 'Opale Sureau', 'match_kind' => 'pre_lie', 'linked_at' => $quand, 'activated_by_user_id' => 1, 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'mbr_autorisation_naissance' => [
                ['id' => 1, 'person_id' => 2, 'share_year' => 1, 'share_month' => 1, 'share_day' => 0, 'document_name' => 'a.pdf', 'document_mime' => 'application/pdf', 'document_size' => 10, 'document_sha256' => str_repeat('c', 64), 'granted_by_user_id' => 1, 'granted_at' => $quand],
                ['id' => 2, 'person_id' => 1, 'share_year' => 1, 'share_month' => 0, 'share_day' => 0, 'document_name' => 'b.pdf', 'document_mime' => 'application/pdf', 'document_size' => 10, 'document_sha256' => str_repeat('d', 64), 'granted_by_user_id' => 1, 'granted_at' => $quand, 'archived_at' => $quand, 'archived_by_user_id' => 1, 'archive_reason' => 'remplacee'],
            ],
            'mbr_demande_rgpd' => [
                ['id' => 1, 'member_id' => 1, 'user_id' => 1, 'user_email' => 'admin@example.invalid', 'nature' => 'autre', 'retirer_annee' => 0, 'retirer_mois' => 0, 'retirer_jour' => 0, 'created_at' => $quand, 'traitee_par_user_id' => 1],
            ],
            'prp_change_request' => [
                ['id' => 1, 'kind' => 'modification', 'status' => 'en_attente', 'author_user_id' => 1, 'author_label' => 'Admin', 'target_kind' => 'personne', 'target_id' => 1, 'decided_by_user_id' => 1, 'created_at' => $quand, 'updated_at' => $quand],
                ['id' => 2, 'kind' => 'modification', 'status' => 'validee', 'author_user_id' => 1, 'author_label' => 'Admin', 'target_kind' => 'famille', 'target_id' => 1, 'created_at' => $quand, 'updated_at' => $quand],
                ['id' => 3, 'kind' => 'modification', 'status' => 'refusee', 'author_user_id' => 1, 'author_label' => 'Admin', 'target_kind' => 'citation', 'target_id' => 1, 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'prp_change_op' => [
                ['id' => 1, 'request_id' => 1, 'seq' => 1, 'op_kind' => 'champ', 'subject_kind' => 'personne', 'subject_id' => 1, 'value_kind' => 'texte'],
                ['id' => 2, 'request_id' => 2, 'seq' => 1, 'op_kind' => 'champ', 'subject_kind' => 'famille', 'subject_id' => 1, 'value_kind' => 'texte'],
                ['id' => 3, 'request_id' => 3, 'seq' => 1, 'op_kind' => 'champ', 'subject_kind' => 'citation', 'subject_id' => 1, 'value_kind' => 'texte'],
            ],
            'prp_draft_person' => [
                ['id' => 1, 'request_id' => 1, 'local_ref' => '#p1', 'resolved_person_id' => 2, 'created_at' => $quand],
            ],
            'prp_draft_link' => [
                ['id' => 1, 'request_id' => 1, 'seq' => 1, 'kind' => 'enfant_de', 'subject_person_id' => 1, 'subject_label' => 'Opale Sureau', 'other_person_id' => 2, 'other2_person_id' => 2, 'family_id' => 1, 'resolved_family_id' => 1, 'created_at' => $quand],
            ],
            'prp_revision' => [
                ['id' => 1, 'request_id' => 1, 'subject_kind' => 'personne', 'subject_id' => 1, 'subject_label' => 'Opale', 'operation' => 'modification', 'changes' => '{}', 'actor_user_id' => 1, 'actor_label' => 'Admin', 'tree_fingerprint_after' => str_repeat('e', 64), 'applied_at' => $quand],
                ['id' => 2, 'request_id' => 2, 'subject_kind' => 'famille', 'subject_id' => 1, 'subject_label' => 'Union', 'operation' => 'modification', 'changes' => '{}', 'actor_user_id' => 1, 'actor_label' => 'Admin', 'tree_fingerprint_after' => str_repeat('e', 64), 'applied_at' => $quand],
                ['id' => 3, 'request_id' => 3, 'subject_kind' => 'citation', 'subject_id' => 1, 'subject_label' => 'Citation', 'operation' => 'modification', 'changes' => '{}', 'actor_user_id' => 1, 'actor_label' => 'Admin', 'tree_fingerprint_after' => str_repeat('e', 64), 'applied_at' => $quand],
            ],
            'med_media' => [
                $this->media(1, 'personne', $quand) + ['subject_user_id' => 1, 'change_request_id' => 1],
                $this->media(2, 'famille', $quand) + ['subject_request_id' => 1],
                $this->media(3, 'citation', $quand),
            ],
            'dmr_demarche' => [
                ['id' => 1, 'nature' => 'naissance', 'forme' => 'copie_integrale', 'intitule' => 'Naissance', 'destinataire' => 'Mairie', 'auteur_user_id' => 1, 'auteur_libelle' => 'Admin', 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'dmr_echange' => [
                ['id' => 1, 'demarche_id' => 1, 'sens' => 'envoye', 'canal' => 'courriel', 'date' => '2026-01-01', 'texte' => 'Bonjour', 'saisi_par_user_id' => 1, 'saisi_par_libelle' => 'Admin', 'created_at' => $quand],
            ],
            'dmr_personne' => [
                ['id' => 1, 'demarche_id' => 1, 'person_id' => 1, 'person_label' => 'Opale Sureau', 'created_at' => $quand],
            ],
            'evt_evenement' => [
                ['id' => 1, 'titre' => 'Cousinade', 'debut' => self::AVENIR, 'fin' => self::AVENIR, 'organisateur_user_id' => 1, 'organisateur_libelle' => 'Admin', 'created_at' => $quand, 'updated_at' => $quand],
                ['id' => 2, 'titre' => 'Cousinade passée', 'debut' => self::PASSE, 'fin' => self::PASSE, 'organisateur_user_id' => 1, 'organisateur_libelle' => 'Admin', 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'evt_invitation' => [
                ['id' => 1, 'evenement_id' => 1, 'person_id' => 1, 'person_label' => 'Opale Sureau', 'invite_par_user_id' => 1, 'reponse' => 'present', 'reponse_notee_par_user_id' => 1, 'reponse_notee_par_libelle' => 'Admin', 'created_at' => $quand],
                ['id' => 2, 'evenement_id' => 2, 'person_id' => 2, 'person_label' => 'Cobalt Sureau', 'invite_par_user_id' => 1, 'created_at' => $quand],
            ],
            'qst_question' => [
                ['id' => 1, 'destinataire_user_id' => 1, 'signature' => str_repeat('f', 64), 'nature' => 'date_naissance_inconnue', 'sujet_kind' => 'personne', 'sujet_id' => 1, 'sujet_label' => 'Opale Sureau', 'texte' => 'Quand ?', 'created_at' => $quand, 'updated_at' => $quand],
                ['id' => 2, 'destinataire_user_id' => 1, 'signature' => str_repeat('9', 64), 'nature' => 'date_naissance_inconnue', 'sujet_kind' => 'famille', 'sujet_id' => 1, 'sujet_label' => 'Union', 'texte' => 'Quand ?', 'created_at' => $quand, 'updated_at' => $quand],
            ],
            'qst_question_demande' => [
                ['id' => 1, 'question_id' => 1, 'demande_id' => 1, 'nature' => 'ajout_note', 'created_at' => $quand],
            ],
            'tml_preference' => [
                ['id' => 1, 'user_id' => 1, 'updated_at' => $quand],
            ],
            'ast_conversation' => [
                ['id' => 1, 'user_id' => 1, 'titre' => 'Question', 'created_at' => $quand, 'updated_at' => $quand],
            ],
        ];

        $connexion = $this->connexion();

        foreach ($lignes as $table => $rangees) {
            foreach ($rangees as $rangee) {
                $connexion->insert($table, $rangee);
            }
        }
    }

    /** @return array<string, int|string> */
    private function media(int $id, string $sujet, string $quand): array
    {
        return [
            'id' => $id, 'kind' => 'preuve', 'subject_kind' => $sujet, 'subject_id' => 1, 'subject_label' => 'Sujet',
            'storage_name' => \sprintf('m%d.jpg', $id), 'mime_type' => 'image/jpeg', 'byte_size' => 10, 'sha256' => str_repeat('0', 64),
            'uploaded_by_user_id' => 1, 'uploaded_by_label' => 'Admin', 'created_at' => $quand, 'updated_at' => $quand,
        ];
    }
}
