<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Integrite\ControleDIntegrite;
use App\Integrite\VerificationDIntegrite;
use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Demarche\Entity\Demarche;
use App\Module\Demarche\Enum\FormeDActe;
use App\Module\Demarche\Enum\NatureDActe;
use App\Module\Evenement\Entity\Evenement;
use App\Module\Evenement\Entity\Invitation as InvitationAUnEvenement;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Membership\Entity\AutorisationDeNaissance;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Question\Entity\Question;
use App\Module\Question\Entity\QuestionDemande;
use App\Module\Question\Enum\NatureDeLacune;
use App\Module\Tirage\Entity\Attribution;
use App\Module\Tirage\Entity\Edition;
use App\Module\Tirage\Entity\Participant;
use App\Module\Tirage\Entity\PresenceAnnuelle;
use App\Module\Tirage\Entity\Tirage;
use App\Module\Tirage\Enum\Presence;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * CE QUE LES AUTRES MODULES ACCROCHENT À UNE FICHE QU'ON SUPPRIME — sur la vraie base.
 *
 * `Demarche`, `Evenement`, `Tirage`, `Membership` et `Question` tiennent un `person_id`
 * **sans clé étrangère**. Avant ce lot, aucun n'était consulté par la suppression : une
 * fiche supprimée y laissait des lignes pendantes, découvertes au mieux par
 * `heritage:verifier-integrite`, au pire par la famille.
 *
 * Chaque nature a son test, et chaque test dit **le sort décidé** :
 *
 * | Ce qui pointe sur la fiche | Sort |
 * |---|---|
 * | une démarche d'acte | bloque |
 * | une invitation à un événement à venir | bloque |
 * | une invitation à un événement passé ou annulé | reste (historique) |
 * | une participation à un tirage, ou un rôle de référent | bloque |
 * | les attributions et présences d'une édition passée | restent (historique) |
 * | une autorisation de naissance active | bloque |
 * | une autorisation de naissance archivée | reste (preuve) |
 * | une question posée à son sujet | nettoyée, tracée |
 * | une preuve sur une union ou une citation emportées | bloque |
 *
 * Le dernier test fait le tour : après une suppression qui laisse de l'historique,
 * `heritage:verifier-integrite` ne trouve **aucune violation** — ce qui reste est toléré, et
 * dit pourquoi.
 */
final class DependancesDUneFicheSupprimeeTest extends BaseDeDemandeSurArbreReel
{
    private const string MOTIF = 'Doublon de la fiche voisine, même acte de naissance : celle-ci est vide.';

    public function testUneDemarcheDActeBloqueEtDitOuLaCorriger(): void
    {
        $personne = $this->personneNeuve('Opale', 'Sureau');
        $demarche = new Demarche(NatureDActe::Naissance, FormeDActe::CopieIntegrale, 'Naissance d\'Opale', 'Mairie de Saule', self::OPALE, 'Compte contributeur');
        $demarche->concerner([(int) $personne->getId() => 'Opale Sureau']);
        $this->em()->persist($demarche);
        $this->em()->flush();

        $message = $this->refus((int) $personne->getId());

        self::assertStringContainsString('1 démarche d\'acte', $message);
        self::assertStringContainsString('« Corriger »', $message, 'Le message dit quel geste défait le blocage.');
        self::assertStringNotContainsString('Naissance d\'Opale', $message, 'L\'intitulé d\'une démarche ne sort pas sur une route ouverte aux membres.');
    }

    public function testUneInvitationAUnEvenementAVenirBloque(): void
    {
        $personne = $this->personneNeuve('Lave', 'Muguet');
        $this->inviter($personne, new \DateTimeImmutable('+10 days'));

        $message = $this->refus((int) $personne->getId());

        self::assertStringContainsString('invitée à 1 événement à venir', $message);
        self::assertStringContainsString('retirer des invités', $message);
    }

    /**
     * Un événement **passé** ou **annulé** ne bloque pas : on ne peut plus en retirer
     * personne (`exigerModifiable()`), et une invitation qu'on ne peut pas défaire ne doit
     * pas pouvoir bloquer — la fiche ne se supprimerait jamais. La ligne reste, sous son
     * libellé : c'est l'historique de la fête.
     */
    public function testUneInvitationAUnEvenementClosResteEtNeBloquePas(): void
    {
        $personne = $this->personneNeuve('Natron', 'Muguet', Sex::Masculin);
        $this->inviter($personne, new \DateTimeImmutable('-30 days'));
        $annule = $this->inviter($personne, new \DateTimeImmutable('+30 days'));
        $annule->annuler(new \DateTimeImmutable());
        $this->em()->flush();

        $this->validation()->valider($this->deposer((int) $personne->getId()), $this->admin());
        $this->em()->clear();

        self::assertNull($this->em()->find(Person::class, (int) $personne->getId()));
        self::assertSame(2, $this->compter(InvitationAUnEvenement::class), 'Le suivi des événements clos garde ses lignes.');
    }

    public function testUneParticipationAUnTirageBloque(): void
    {
        $personne = $this->personneNeuve('Cobalt', 'Romarin', Sex::Masculin);
        $tirage = new Tirage('Noël côté père', 24, 12, self::OPALE, 'Compte contributeur');
        $tirage->ajouterParticipant(new Participant($tirage, (int) $personne->getId(), 'Cobalt Romarin', self::OPALE));
        $this->em()->persist($tirage);
        $this->em()->flush();

        $message = $this->refus((int) $personne->getId());

        self::assertStringContainsString('participe à 1 tirage au sort', $message);
        self::assertStringNotContainsString('Noël côté père', $message, 'Le nom d\'un tirage fermé ne sort pas.');
    }

    /** Le référent est un participant du même tirage : il bloque au même titre. */
    public function testUnReferentDeTirageBloque(): void
    {
        $enfant = $this->personneNeuve('Marne', 'Romarin');
        $referent = $this->personneNeuve('Jargon', 'Romarin', Sex::Masculin);

        $tirage = new Tirage('Noël côté mère', 25, 12, self::OPALE, 'Compte contributeur');
        $participant = new Participant($tirage, (int) $enfant->getId(), 'Marne Romarin', self::OPALE);
        $participant->setReferent((int) $referent->getId(), 'Jargon Romarin');
        $tirage->ajouterParticipant($participant);
        $this->em()->persist($tirage);
        $this->em()->flush();

        self::assertStringContainsString('tirage au sort', $this->refus((int) $referent->getId()));
    }

    /**
     * Retirée du tirage, la fiche se supprime, et **l'historique des éditions passées
     * reste** : « les éditions passées gardent leurs attributions » (ADR 0009). Ni la
     * présence ni l'attribution ne sont effacées, et aucune trace n'a à nommer qui offrait à
     * qui.
     */
    public function testLHistoriqueDUnTiragePasseResteEtNeBloquePas(): void
    {
        $personne = $this->personneNeuve('Gentiane', 'Romarin');
        $autre = $this->personneNeuve('Saule', 'Romarin', Sex::Masculin);

        $tirage = new Tirage('Noël 2025', 24, 12, self::OPALE, 'Compte contributeur');
        $edition = new Edition($tirage, 2025, new \DateTimeImmutable('2025-12-24'));
        $edition->definirPresence((int) $personne->getId(), Presence::ADistance);
        $edition->marquerTiree([new Attribution($edition, (int) $personne->getId(), 'Gentiane Romarin', (int) $autre->getId(), 'Saule Romarin')], 2, new \DateTimeImmutable('2025-12-01'));
        $tirage->ajouterEdition($edition);
        $this->em()->persist($tirage);
        $this->em()->flush();

        $this->validation()->valider($this->deposer((int) $personne->getId()), $this->admin());
        $this->em()->clear();

        self::assertSame(1, $this->compter(Attribution::class));
        self::assertSame(1, $this->compter(PresenceAnnuelle::class));
    }

    public function testUneAutorisationDeNaissanceActiveBloque(): void
    {
        $personne = $this->personneNeuve('Opale', 'Dolomite');
        $this->em()->persist($this->autorisation((int) $personne->getId()));
        $this->em()->flush();

        $message = $this->refus((int) $personne->getId());

        self::assertStringContainsString('autorisation signée', $message);
        self::assertStringContainsString('administrateur doit d\'abord la retirer', $message);
    }

    /** Archivée, elle est une preuve : « archivée, jamais supprimée » (ADR 0016). */
    public function testUneAutorisationArchiveeResteEtNeBloquePas(): void
    {
        $personne = $this->personneNeuve('Lave', 'Dolomite');
        $autorisation = $this->autorisation((int) $personne->getId());
        $autorisation->archiver(AutorisationDeNaissance::ARCHIVE_RETIREE, self::ADMIN);
        $this->em()->persist($autorisation);
        $this->em()->flush();

        $this->validation()->valider($this->deposer((int) $personne->getId()), $this->admin());
        $this->em()->clear();

        self::assertSame(1, $this->compter(AutorisationDeNaissance::class));
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LES QUESTIONS PARTENT AVEC LA FICHE** — annoncées, effacées dans la transaction,
     * tracées deux fois.
     *
     * Annoncées : l'inventaire du dépôt les compte et la phrase les nomme. Effacées : avec
     * leur lien vers la demande qu'une réponse avait produite — la demande, elle, reste.
     * Tracées : dans la révision avec le reste de l'inventaire, et au journal d'audit avec
     * leurs identifiants.
     */
    public function testLesQuestionsSontAnnonceesEffaceesEtTracees(): void
    {
        $personne = $this->personneNeuve('Marne', 'Sarriette');
        $personneId = (int) $personne->getId();

        $posee = new Question(self::OPALE, NatureDeLacune::DateDeNaissanceInconnue, SubjectKind::Personne, $personneId, 'Marne Sarriette', 'Quand est née Marne ?');
        $repondue = new Question(self::OPALE, NatureDeLacune::LieuDeNaissanceInconnu, SubjectKind::Personne, $personneId, 'Marne Sarriette', 'Où est née Marne ?');
        $repondue->attacherLaDemande(new QuestionDemande($repondue, 4242, 'ajout_note'));
        $this->em()->persist($posee);
        $this->em()->persist($repondue);
        $this->em()->flush();

        // Relevés avant : une entité retirée perd son identifiant au `flush`.
        $identifiants = [(int) $posee->getId(), (int) $repondue->getId()];

        $demande = $this->deposer($personneId);

        /** @var array<string, mixed> $inventaire */
        $inventaire = $this->opDe($demande)->getOldValue();
        self::assertSame([['nature' => 'question', 'nombre' => 2, 'bloquante' => false]], $inventaire['dependances']);

        $resultat = $this->validation()->valider($demande, $this->admin());

        $changes = $resultat->revisions[0]->getChanges();
        self::assertSame([['nature' => 'question', 'nombre' => 2, 'bloquante' => false]], $changes['dependances']['avant']);
        self::assertStringContainsString('2 questions posées à son sujet', (string) $changes['resume']['avant']);

        $this->em()->clear();

        self::assertSame(0, $this->compter(Question::class), 'Les questions sont parties avec la fiche.');
        self::assertSame(0, $this->compter(QuestionDemande::class), 'Leurs liens aussi.');

        $journal = $this->em()->getRepository(AuditLog::class)->findBy(['action' => AuditAction::Suppression, 'subjectType' => 'personne', 'subjectId' => (string) $personneId]);
        self::assertCount(1, $journal, 'L\'effacement est tracé au journal d\'audit.');
        self::assertSame(['avant' => $identifiants, 'apres' => null], $journal[0]->getChanges()['questions'] ?? null);
    }

    /** Une question d'une autre fiche n'est pas touchée — la condition ne déborde pas. */
    public function testLesQuestionsDUneAutreFicheRestent(): void
    {
        $personne = $this->personneNeuve('Natron', 'Sarriette', Sex::Masculin);
        $autre = $this->personneNeuve('Opale', 'Sarriette');
        $this->em()->persist(new Question(self::OPALE, NatureDeLacune::DateDeNaissanceInconnue, SubjectKind::Personne, (int) $autre->getId(), 'Opale Sarriette', 'Quand est née Opale ?'));
        $this->em()->flush();

        $this->validation()->valider($this->deposer((int) $personne->getId()), $this->admin());
        $this->em()->clear();

        self::assertSame(1, $this->compter(Question::class));
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **UNE PREUVE SUR UNE UNION QUI PART AVEC LA FICHE BLOQUE** — la preuve orpheline
     * qu'on ne voyait pas.
     *
     * L'union dont la fiche est le seul époux est effacée avec elle. Le compte de preuves ne
     * regardait que la fiche : une photo de l'acte de mariage, rattachée à l'union, serait
     * devenue orpheline sans que le refus l'ait annoncée.
     */
    public function testUnePreuveSurUneUnionEmporteeBloque(): void
    {
        $personne = $this->personneNeuve('Saule', 'Dolomite', Sex::Masculin);
        $famille = new Family($personne, null);
        $this->em()->persist($famille);
        $this->em()->flush();

        $this->preuve(MediaSubject::Famille, (int) $famille->getId());

        self::assertStringContainsString('preuve', $this->refus((int) $personne->getId()));
    }

    /** Une union qui **survit** à la fiche garde ses preuves, et ne bloque rien. */
    public function testUnePreuveSurUneUnionQuiSurvitNeBloquePas(): void
    {
        $mari = $this->personneNeuve('Saule', 'Dolomite', Sex::Masculin);
        $femme = $this->personneNeuve('Opale', 'Dolomite');
        $famille = new Family($mari, $femme);
        $this->em()->persist($famille);
        $this->em()->flush();

        $this->preuve(MediaSubject::Famille, (int) $famille->getId());

        $resultat = $this->validation()->valider($this->deposer((int) $mari->getId()), $this->admin());

        self::assertSame('validee', $resultat->resultat);
    }

    /** Même chose pour une citation de la fiche, que la suppression emporte. */
    public function testUnePreuveSurUneCitationEmporteeBloque(): void
    {
        $personne = $this->personneNeuve('Jargon', 'Dolomite', Sex::Masculin);
        $source = new Source('Registre paroissial de Saule');
        $citation = (new Citation($source))->setPerson($personne);
        $this->em()->persist($source);
        $this->em()->persist($citation);
        $this->em()->flush();

        $this->preuve(MediaSubject::Citation, (int) $citation->getId());

        self::assertStringContainsString('preuve', $this->refus((int) $personne->getId()));
    }

    /**
     * Une dépendance qui **bloque**, apparue entre le dépôt et la validation, rend la
     * demande caduque : l'inventaire se compare en entier, `dependances` comprises.
     */
    public function testUneDependanceApparueApresLeDepotRendLaDemandeCaduque(): void
    {
        $personne = $this->personneNeuve('Cobalt', 'Muguet', Sex::Masculin);
        $demande = $this->deposer((int) $personne->getId());

        $this->inviter($personne, new \DateTimeImmutable('+5 days'));

        try {
            $this->validation()->valider($demande, $this->admin());
            self::fail('Une invitation apparue depuis le dépôt doit rendre la demande caduque.');
        } catch (Conflit) {
        }

        self::assertSame(RequestStatus::Caduque, $demande->getStatus());
        self::assertInstanceOf(Person::class, $this->em()->find(Person::class, (int) $personne->getId()), 'Rien n\'a été effacé.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LE TOUR COMPLET** : une suppression qui laisse de l'historique, puis
     * `heritage:verifier-integrite`.
     *
     * Ce qui reste — un événement clos, un tirage passé, une autorisation archivée — pend
     * vers une fiche qui n'existe plus, et c'est voulu. Le contrôle le relève, le range
     * parmi les tolérés avec sa raison, et ne trouve **aucune violation**. Ce qui devait
     * partir est parti ; ce qui devait bloquer aurait bloqué.
     */
    public function testApresLaSuppressionLaVerificationNeTrouveAucuneViolation(): void
    {
        // Les deux comptes du harnais n'existent que par leur numéro : on leur donne une
        // ligne, sans quoi chaque auteur et chaque organisateur pendrait.
        foreach ([self::ADMIN => 'admin@example.invalid', self::OPALE => 'opale@example.invalid'] as $id => $courriel) {
            $this->em()->getConnection()->insert('core_user', [
                'id' => $id,
                'email' => $courriel,
                'password' => '!',
                'roles' => '[]',
                'display_name' => '',
                'status' => 'actif',
                'ignore_birth_restriction' => 0,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]);
        }

        $personne = $this->personneNeuve('Opale', 'Romarin');
        $personneId = (int) $personne->getId();
        $autre = $this->personneNeuve('Saule', 'Romarin', Sex::Masculin);

        $this->inviter($personne, new \DateTimeImmutable('-60 days'));

        $tirage = new Tirage('Noël 2024', 24, 12, self::OPALE, 'Compte contributeur');
        $edition = new Edition($tirage, 2024, new \DateTimeImmutable('2024-12-24'));
        $edition->marquerTiree([new Attribution($edition, (int) $autre->getId(), 'Saule Romarin', $personneId, 'Opale Romarin')], 2, new \DateTimeImmutable('2024-12-01'));
        $tirage->ajouterEdition($edition);
        $this->em()->persist($tirage);

        $archivee = $this->autorisation($personneId);
        $archivee->archiver(AutorisationDeNaissance::ARCHIVE_REMPLACEE, self::ADMIN);
        $this->em()->persist($archivee);
        $this->em()->persist(new Question(self::OPALE, NatureDeLacune::AucunePhoto, SubjectKind::Personne, $personneId, 'Opale Romarin', 'Auriez-vous une photo ?'));
        $this->em()->flush();

        $this->validation()->valider($this->deposer($personneId), $this->admin());
        $this->em()->clear();

        $verification = self::getContainer()->get(VerificationDIntegrite::class);
        self::assertInstanceOf(VerificationDIntegrite::class, $verification);
        $rapport = $verification->verifier();

        self::assertSame([], array_map(static fn (ControleDIntegrite $c): string => $c->reference->libelle(), $rapport->violations()));
        self::assertSame([], $rapport->anomalies);
        self::assertTrue($rapport->estSain());

        $tolerees = array_map(static fn (ControleDIntegrite $c): string => $c->reference->libelle(), $rapport->tolerees());
        sort($tolerees);

        self::assertSame([
            'evt_invitation.person_id [événements passés ou annulés]',
            'mbr_autorisation_naissance.person_id [autorisations archivées]',
            'prp_change_op.subject_id [subject_kind = personne]',
            'prp_change_request.target_id [target_kind = personne]',
            'prp_revision.subject_id [subject_kind = personne]',
            'trg_attribution.receveur_person_id [éditions passées]',
        ], $tolerees, 'Ce qui reste est l\'historique — et la demande de suppression elle-même, qui vise une fiche désormais absente.');
    }

    // ── Outils ──────────────────────────────────────────────────────────────────

    private function deposer(int $personneId): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'suppression_personne',
            'message' => self::MOTIF,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => $personneId,
        ]);
    }

    /** Le dépôt est refusé ; rend le message posé sur `targetId`, et vérifie que rien n'a bougé. */
    private function refus(int $personneId): string
    {
        $avant = $this->compter(Person::class);

        try {
            $this->deposer($personneId);
            self::fail('Ce dépôt devait être refusé.');
        } catch (DonneesInvalides $erreur) {
            $champs = (array) $erreur->champs();
            self::assertArrayHasKey('targetId', $champs);
            self::assertSame($avant, $this->compter(Person::class), 'Rien n\'a été touché.');

            return (string) $champs['targetId'];
        }
    }

    private function inviter(Person $personne, \DateTimeImmutable $le): Evenement
    {
        $evenement = new Evenement('Cousinade', $le, $le, self::OPALE, 'Compte contributeur');
        $evenement->ajouterInvitation(new InvitationAUnEvenement($evenement, (int) $personne->getId(), $personne->getDisplayName(), self::OPALE));
        $this->em()->persist($evenement);
        $this->em()->flush();

        return $evenement;
    }

    private function autorisation(int $personneId): AutorisationDeNaissance
    {
        return new AutorisationDeNaissance($personneId, new ComposantesDeDate(true, true, false), 'a1b2c3.pdf', 'application/pdf', 1_200, str_repeat('b', 64), 'Courrier signé', self::ADMIN);
    }

    private function preuve(MediaSubject $sujet, int $sujetId): void
    {
        $media = new Media(MediaKind::Preuve, $sujet, 'Acte', 'd4c3b2a1.jpg', 'image/jpeg', 90_000, str_repeat('c', 64), self::OPALE, 'Compte contributeur');
        $media->rattacherA($sujetId);
        $this->em()->persist($media);
        $this->em()->flush();
    }

    private function personneNeuve(string $prenoms, string $nom, Sex $sexe = Sex::Feminin): Person
    {
        $personne = new Person(trim($prenoms.' '.$nom));
        $personne->setGivenNames($prenoms)->setSurname($nom)->setSex($sexe);

        $this->em()->persist($personne);
        $this->em()->flush();

        return $personne;
    }

    /** @param class-string $entite */
    private function compter(string $entite): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(x.id) FROM '.$entite.' x')->getSingleScalarResult();
    }

    private function opDe(ChangeRequest $demande): ChangeRequestOp
    {
        $ops = $this->em()->getRepository(ChangeRequestOp::class)->findBy(['request' => $demande], ['seq' => 'ASC']);
        self::assertCount(1, $ops);

        return $ops[0];
    }
}
