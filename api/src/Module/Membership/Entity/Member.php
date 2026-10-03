<?php

declare(strict_types=1);

namespace App\Module\Membership\Entity;

use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\MemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le rattachement compte ↔ personne (§ 4.2 du modèle de données).
 *
 * Le § 2 du dossier de mission appelle cette entité `Membership\Account` ; elle s'appelle
 * `Member` parce que **`Core\User` est déjà le compte** — il porte le courriel, le mot
 * de passe, les rôles et implémente `UserInterface`. Deux « comptes » dans deux modules
 * garantiraient qu'un agent aille chercher le mot de passe dans `mbr_account`. Ce que
 * cette table apporte est le *rattachement*. L'écart est argumenté dans
 * `docs/arbitrages.md` (point H).
 *
 * C'est le point d'ancrage du cercle de visibilité (§ 9.3) : `person_id` est la
 * personne `u` de la formule.
 */
#[ORM\Entity(repositoryClass: MemberRepository::class)]
#[ORM\Table(name: 'mbr_member')]
// « Un compte est rattaché à **une** personne » (§ 6.1 du dossier). La base le garantit,
// pas le code.
#[ORM\UniqueConstraint(name: 'uniq_mbr_member_user', columns: ['user_id'])]
// « Une personne ne peut être réclamée que par **un** compte ». Sans cette contrainte,
// deux invitations envoyées par erreur à la même personne créent deux comptes qui se
// voient l'un l'autre comme soi-même.
#[ORM\UniqueConstraint(name: 'uniq_mbr_member_person', columns: ['person_id'])]
#[ORM\Index(
    name: 'idx_mbr_member_status',
    columns: ['status'],
    options: ['where' => "((status)::text = 'en_attente'::text)"],
)]
class Member
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column]
    private int $userId;

    /** Libellé dénormalisé : affiche la liste des membres sans charger `Core`. */
    #[ORM\Column(length: 180)]
    private string $userEmail;

    /**
     * ⇢ inter-module (`Tree\Person`). La personne de l'arbre que ce compte **est**.
     *
     * **Nulle pour un compte `a_rattacher`, et pour lui seul** (`ck_mbr_member_fiche`,
     * ADR 0013) : le candidat ne s'est pas trouvé dans l'arbre, et c'est un administrateur
     * qui posera la fiche. Un compte sans fiche n'est jamais actif
     * (`ck_mbr_member_sans_fiche_inactif`) : il n'a pas de cercle, donc rien à ancrer.
     */
    #[ORM\Column(nullable: true)]
    private ?int $personId;

    /**
     * Libellé dénormalisé, rafraîchi par le service `Tree` quand le nom d'affichage
     * change. Sortie de données personnelles : filtrée comme la personne (§ 0.4).
     *
     * Nul exactement quand `personId` l'est (`ck_mbr_member_libelle`).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $personLabel;

    #[ORM\Column(length: 12, enumType: MemberStatus::class, options: ['default' => 'en_attente'])]
    private MemberStatus $status = MemberStatus::EnAttente;

    /** FK **intra-module**. `null` pour le compte d'amorçage créé en console. */
    #[ORM\ManyToOne(targetEntity: Invitation::class)]
    #[ORM\JoinColumn(name: 'invitation_id', nullable: true, onDelete: 'SET NULL')]
    private ?Invitation $invitation = null;

    // 24 et non 16 : `recherche_confirmee` fait dix-neuf signes. C'est le cas du
    // rattachement sans pré-lien — celui qui laisse le compte en attente d'activation,
    // donc précisément celui qu'on n'exerce pas en développement.
    #[ORM\Column(length: 24, enumType: MatchKind::class)]
    private MatchKind $matchKind;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $linkedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    /** ⇢ inter-module (`Core\User`). `null` si l'activation était automatique. */
    #[ORM\Column(nullable: true)]
    private ?int $activatedByUserId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    // ── La déclaration d'un compte « à rattacher » (ADR 0013) ─────────────────────
    //
    // Ce que le candidat a saisi faute de s'être trouvé : son identité et un texte libre
    // qui décrit sa famille. **Des données sur des vivants**, en clair, et c'est pourquoi
    // elles obéissent à trois règles que ce fichier et la base tiennent ensemble :
    //
    // 1. elles n'existent **que** sur un compte `a_rattacher` (`ck_mbr_member_declaration`)
    //    **en attente** (`ck_mbr_member_declaration_en_attente`) ;
    // 2. elles sont **purgées** dès que le compte est rattaché ou refusé
    //    (`rattacherA()`, `purgerLaDeclaration()`) — on ne les garde que le temps de la
    //    décision ;
    // 3. elles ne sortent que vers un administrateur : jamais dans une notification, la
    //    charge de session ou le journal d'audit. `Core\EventSubscriber\AuditDoctrineSubscriber`
    //    les compte parmi ses champs sensibles — le diff d'une purge y vaut `***`.

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $declaredSurname = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $declaredGivenNames = null;

    /** Une date ferme : celle que le candidat a tapée, pas une date de l'arbre. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $declaredBirthDate = null;

    /**
     * Le texte libre. **Facultatif depuis le message vocal** : un candidat peut dire sa
     * famille au lieu de l'écrire, et la déclaration tient alors par le vocal seul
     * (`ck_mbr_member_declaration_texte_ou_voix`).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $familyDescription = null;

    // ── Le message vocal de la déclaration (addendum du 2 octobre 2026) ───────────
    //
    // Le fichier vit hors racine web (`StockageDesMessagesVocaux`), sous un nom aléatoire ;
    // la ligne n'en garde que de quoi le servir à un administrateur. Mêmes règles que la
    // déclaration — c'en est une — : `a_rattacher` en attente seulement, purgé au
    // rattachement comme au refus. **La purge de la ligne n'efface pas le fichier** : une
    // entité ne touche pas au disque. C'est `ServiceDesMembres` qui le fait, après coup,
    // à partir du nom lu **avant** {@see purgerLaDeclaration()}.

    /** `[0-9a-f]{32}.(webm|ogg|m4a)` — revalidé par le stockage à chaque accès. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $voiceMessageName = null;

    /** Le type **reniflé** : `audio/webm`, `audio/ogg` ou `audio/mp4`. */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $voiceMessageMime = null;

    #[ORM\Column(nullable: true)]
    private ?int $voiceMessageSize = null;

    /** Mesurée par le chrono du navigateur : indicative, et nulle si elle n'a pas été donnée. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $voiceMessageSeconds = null;

    // ── Sa transcription automatique (addendum du 2 octobre 2026, soir) ──────────
    //
    // Le module `Assistant` envoie le son à Gemini, après coup, à la demande de l'écran
    // « À rattacher » ; ce qu'il en rapporte est rangé ici, par le contrat
    // `ComptesARattacher`. Un texte **ou** un échec, jamais les deux
    // (`ck_mbr_member_transcription_ou_echec`), daté ; rien sans vocal
    // (`ck_mbr_member_transcription_avec_vocal`) : la transcription part avec lui.

    /**
     * Le texte transcrit, verbatim. **Des données de vivants**, au même titre que la
     * description : colonne sensible du journal mécanique, purgée avec la déclaration.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $voiceMessageTranscript = null;

    /** Le motif du dernier échec, écrit par le serveur — jamais une réponse du modèle. */
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $voiceMessageTranscriptError = null;

    /** La dernière tentative, réussie ou non. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $voiceMessageTranscribedAt = null;

    /**
     * La dernière **correction à la main** du texte par un administrateur (addendum du
     * 3 octobre 2026) ; `null` tant que le texte est celui du modèle. Elle dit à l'analyse
     * que les noms propres ont été relus. Pas de date sans texte
     * (`ck_mbr_member_transcription_corrigee`).
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $voiceMessageTranscriptEditedAt = null;

    /**
     * La suggestion de rattachement calculée par le module `Assistant`, **mise en cache
     * ici** pour ne pas rappeler le modèle de langage à chaque ouverture de l'écran.
     *
     * `Membership` ne lit pas ce blob : il le stocke et le purge. Son schéma appartient au
     * seul module qui l'écrit (contrat `Membership\Contract\ComptesARattacher`).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $attachmentSuggestion = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $attachmentSuggestionAt = null;

    // ── Le partage de la date de naissance (ADR 0016, réciprocité des vivants) ─────
    //
    // Ce que le titulaire consent à montrer de sa naissance aux autres membres —
    // l'année, le mois, le jour, indépendamment. C'est aussi ce qu'il verra des autres :
    // `Service\Reciprocite` prend l'intersection. Tout à `false` par défaut, y compris pour
    // les comptes antérieurs à la fonctionnalité : on ne présume pas un consentement.
    //
    // `birthShareUpdatedAt` et `birthShareSource` sont la **trace** du dernier choix
    // (`ck_mbr_member_partage_trace`) ; le détail de chaque changement est au journal
    // d'audit, avec son auteur.

    #[ORM\Column(options: ['default' => false])]
    private bool $birthShareYear = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $birthShareMonth = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $birthShareDay = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthShareUpdatedAt = null;

    /** `inscription` ou `profil` — d'où vient le dernier choix. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $birthShareSource = null;

    // ── Le verrou après un partage (ADR 0016, équité) ───────────────────────────
    //
    // Partager une composante est **immédiat**. Mais une composante partagée reste
    // visible des autres **au moins {@see self::DUREE_MINIMALE_JOURS} jours** : sans quoi
    // « je partage, je regarde, je retire » rendrait la réciprocité bidon pour ceux qui
    // partagent vraiment. Décision du commanditaire, contraire à la lettre de l'art. 7.3 du
    // RGPD et prise en connaissance de cause ; la soupape est la **demande RGPD**, que
    // l'administrateur applique sans délai (source `administration`). Une date par
    // composante : la date à laquelle elle a été partagée, `null` quand elle ne l'est pas.

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthShareYearSince = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthShareMonthSince = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthShareDaySince = null;

    public function __construct(
        int $userId,
        string $userEmail,
        int $personId,
        string $personLabel,
        MatchKind $matchKind,
    ) {
        $this->userId = $userId;
        $this->userEmail = $userEmail;
        $this->personId = $personId;
        $this->personLabel = $personLabel;
        $this->matchKind = $matchKind;
        $this->linkedAt = new \DateTimeImmutable();
        $this->createdAt = $this->linkedAt;
        $this->updatedAt = $this->linkedAt;
    }

    /**
     * Un compte **sans fiche** : le candidat ne s'est pas trouvé dans l'arbre et a décrit
     * sa famille (ADR 0013, `POST /api/inscription/compte` avec `declaration`).
     *
     * Une fabrique plutôt qu'un second constructeur : celui d'origine reste la seule façon
     * de créer un rattachement **à une fiche**, et ses appelants — fixtures comprises — ne
     * changent pas.
     */
    public static function aRattacher(
        int $userId,
        string $userEmail,
        string $surname,
        string $givenNames,
        \DateTimeImmutable $birthDate,
        ?string $description,
    ): self {
        $membre = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();

        $membre->userId = $userId;
        $membre->userEmail = $userEmail;
        $membre->personId = null;
        $membre->personLabel = null;
        $membre->matchKind = MatchKind::ARattacher;
        $membre->declaredSurname = $surname;
        $membre->declaredGivenNames = $givenNames;
        $membre->declaredBirthDate = $birthDate;
        $membre->familyDescription = $description;
        $membre->linkedAt = new \DateTimeImmutable();
        $membre->createdAt = $membre->linkedAt;
        $membre->updatedAt = $membre->linkedAt;

        return $membre;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUserEmail(): string
    {
        return $this->userEmail;
    }

    public function setUserEmail(string $userEmail): self
    {
        $this->userEmail = $userEmail;

        return $this;
    }

    /** `null` pour un compte `a_rattacher` — et pour lui seul. */
    public function getPersonId(): ?int
    {
        return $this->personId;
    }

    public function getPersonLabel(): ?string
    {
        return $this->personLabel;
    }

    /**
     * **Pose — ou corrige — la fiche du compte.** Le seul mutateur de `person_id`.
     *
     * Il remplace la réflexion qu'utilisait `ServiceDesMembres::rattacherAutrement()` : le
     * rattachement ne se pose que sur décision explicite d'un administrateur, et un
     * mutateur nommé se retrouve à la recherche plus sûrement qu'un `ReflectionProperty`.
     *
     * La déclaration et la suggestion sont **purgées au passage** : elles n'existaient que
     * pour trouver cette fiche, et `ck_mbr_member_declaration` les refuse d'ailleurs sur
     * toute autre nature que `a_rattacher`.
     */
    public function rattacherA(int $personId, string $personLabel, MatchKind $nature): self
    {
        if (!$nature->aUneFiche()) {
            throw new \LogicException('Un rattachement à une fiche ne peut pas être de nature « à rattacher ».');
        }

        $this->personId = $personId;
        $this->personLabel = $personLabel;
        $this->matchKind = $nature;
        $this->linkedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->linkedAt;

        return $this->purgerLaDeclaration();
    }

    /**
     * Joint le message vocal de la déclaration — à la création du compte seulement, dans
     * la transaction qui la pose. Sans effet hors d'un compte à rattacher.
     */
    public function joindreUnMessageVocal(string $nom, string $mime, int $taille, ?int $secondes): self
    {
        if (!$this->estARattacher()) {
            return $this;
        }

        $this->voiceMessageName = $nom;
        $this->voiceMessageMime = $mime;
        $this->voiceMessageSize = $taille;
        $this->voiceMessageSeconds = $secondes;

        return $this;
    }

    /**
     * Efface la déclaration et la suggestion — au rattachement comme au refus.
     *
     * La **nature**, elle, reste : un compte refusé demeure `a_rattacher`, sans fiche et
     * suspendu. On peut donc relire plus tard qu'il s'était inscrit sans se trouver, sans
     * rien garder de ce qu'il avait écrit — ni dit : le message vocal part aussi. Son
     * **fichier**, en revanche, est l'affaire de l'appelant : lire
     * {@see getVoiceMessageName()} avant la purge, effacer après le commit.
     */
    public function purgerLaDeclaration(): self
    {
        $this->voiceMessageTranscript = null;
        $this->voiceMessageTranscriptError = null;
        $this->voiceMessageTranscribedAt = null;
        $this->voiceMessageTranscriptEditedAt = null;
        $this->voiceMessageName = null;
        $this->voiceMessageMime = null;
        $this->voiceMessageSize = null;
        $this->voiceMessageSeconds = null;
        $this->declaredSurname = null;
        $this->declaredGivenNames = null;
        $this->declaredBirthDate = null;
        $this->familyDescription = null;
        $this->attachmentSuggestion = null;
        $this->attachmentSuggestionAt = null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDeclaredSurname(): ?string
    {
        return $this->declaredSurname;
    }

    public function getDeclaredGivenNames(): ?string
    {
        return $this->declaredGivenNames;
    }

    public function getDeclaredBirthDate(): ?\DateTimeImmutable
    {
        return $this->declaredBirthDate;
    }

    public function getFamilyDescription(): ?string
    {
        return $this->familyDescription;
    }

    public function getVoiceMessageName(): ?string
    {
        return $this->voiceMessageName;
    }

    public function getVoiceMessageMime(): ?string
    {
        return $this->voiceMessageMime;
    }

    public function getVoiceMessageSize(): ?int
    {
        return $this->voiceMessageSize;
    }

    public function getVoiceMessageSeconds(): ?int
    {
        return $this->voiceMessageSeconds;
    }

    public function getVoiceMessageTranscript(): ?string
    {
        return $this->voiceMessageTranscript;
    }

    public function getVoiceMessageTranscriptError(): ?string
    {
        return $this->voiceMessageTranscriptError;
    }

    public function getVoiceMessageTranscribedAt(): ?\DateTimeImmutable
    {
        return $this->voiceMessageTranscribedAt;
    }

    /**
     * Range la transcription du message vocal — **ou** le motif de son échec, quand
     * `$texte` est nul. Une tentative remplace la précédente : un échec efface un texte
     * plus ancien (on ne garde pas une transcription qu'on a voulu refaire), un succès
     * efface l'échec.
     *
     * Sans effet hors d'un compte à rattacher **qui a un vocal** : la purge a pu passer
     * pendant que le modèle travaillait, et la base le refuserait de toute façon.
     */
    public function enregistrerLaTranscription(?string $texte, ?string $erreur, \DateTimeImmutable $le): self
    {
        if (!$this->estARattacher() || null === $this->voiceMessageName) {
            return $this;
        }

        if (null === $texte && null === $erreur) {
            throw new \LogicException('Une transcription est un texte ou un échec.');
        }

        $this->voiceMessageTranscript = $texte;
        $this->voiceMessageTranscriptError = null === $texte ? mb_substr((string) $erreur, 0, 300) : null;
        $this->voiceMessageTranscribedAt = $le;
        // Le texte est de nouveau celui du modèle : la relecture ne vaut plus.
        $this->voiceMessageTranscriptEditedAt = null;

        return $this;
    }

    public function getVoiceMessageTranscriptEditedAt(): ?\DateTimeImmutable
    {
        return $this->voiceMessageTranscriptEditedAt;
    }

    /**
     * **Corrige à la main** la transcription — ou l'écrit, quand le modèle n'en a pas
     * rendu (échec, pas de clé) : l'administrateur a écouté, il sait ce qui est dit.
     *
     * Le texte remplace le précédent, l'échec éventuel s'efface, et la date de correction
     * est posée. `voiceMessageTranscribedAt` garde la date de la dernière tentative du
     * modèle s'il y en a eu une ; sinon elle prend `$le`, parce qu'un texte porte sa date
     * (`ck_mbr_member_transcription_datee`).
     *
     * Sans effet hors d'un compte à rattacher **qui a un vocal** — même garde que
     * {@see enregistrerLaTranscription()}. Le texte arrive déjà validé (non vide, borné) :
     * c'est l'affaire de la route.
     */
    public function corrigerLaTranscription(string $texte, \DateTimeImmutable $le): self
    {
        if (!$this->estARattacher() || null === $this->voiceMessageName) {
            return $this;
        }

        $this->voiceMessageTranscript = $texte;
        $this->voiceMessageTranscriptError = null;
        $this->voiceMessageTranscribedAt ??= $le;
        $this->voiceMessageTranscriptEditedAt = $le;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getAttachmentSuggestion(): ?array
    {
        return $this->attachmentSuggestion;
    }

    public function getAttachmentSuggestionAt(): ?\DateTimeImmutable
    {
        return $this->attachmentSuggestionAt;
    }

    /**
     * Range la suggestion calculée par l'assistant. Sans effet hors d'un compte **à
     * rattacher** — nature `a_rattacher` **et** statut `en_attente` ({@see estARattacher()}).
     *
     * La nature seule ne suffit pas : un compte **refusé** reste `a_rattacher` (suspendu,
     * déclaration purgée). Y ranger une suggestion défairait la purge du refus.
     * `ck_mbr_member_declaration` et `ck_mbr_member_declaration_en_attente` la refuseraient
     * de toute façon.
     *
     * @param array<string, mixed> $suggestion
     */
    public function enregistrerLaSuggestion(array $suggestion, \DateTimeImmutable $le): self
    {
        if (!$this->estARattacher()) {
            return $this;
        }

        $this->attachmentSuggestion = $suggestion;
        $this->attachmentSuggestionAt = $le;

        return $this;
    }

    /** Le compte attend-il qu'on lui trouve une fiche ? `a_rattacher` **et** `en_attente`. */
    public function estARattacher(): bool
    {
        return MatchKind::ARattacher === $this->matchKind && MemberStatus::EnAttente === $this->status;
    }

    public function setPersonLabel(string $personLabel): self
    {
        $this->personLabel = $personLabel;

        return $this;
    }

    public function getStatus(): MemberStatus
    {
        return $this->status;
    }

    public function setStatus(MemberStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getInvitation(): ?Invitation
    {
        return $this->invitation;
    }

    public function setInvitation(?Invitation $invitation): self
    {
        $this->invitation = $invitation;

        return $this;
    }

    public function getMatchKind(): MatchKind
    {
        return $this->matchKind;
    }

    public function setMatchKind(MatchKind $matchKind): self
    {
        $this->matchKind = $matchKind;

        return $this;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getActivatedByUserId(): ?int
    {
        return $this->activatedByUserId;
    }

    /** `$parUserId` reste nul quand l'activation est automatique (§ 4.5, `MatchKind`). */
    public function activer(?int $parUserId = null, ?\DateTimeImmutable $le = null): self
    {
        // `ck_mbr_member_sans_fiche_inactif` le refuserait en base ; on le refuse ici
        // d'abord, pour que l'erreur dise ce qui s'est passé plutôt qu'un nom de contrainte.
        if (null === $this->personId) {
            throw new \LogicException('Un compte sans fiche ne s\'active pas : rattachez-le d\'abord.');
        }

        $this->status = MemberStatus::Actif;
        $this->activatedAt = $le ?? new \DateTimeImmutable();
        $this->activatedByUserId = $parUserId;
        $this->updatedAt = $this->activatedAt;

        return $this;
    }

    public function getPartageDeNaissance(): ComposantesDeDate
    {
        return new ComposantesDeDate($this->birthShareYear, $this->birthShareMonth, $this->birthShareDay);
    }

    public function getPartageModifieLe(): ?\DateTimeImmutable
    {
        return $this->birthShareUpdatedAt;
    }

    public function getPartageSource(): ?string
    {
        return $this->birthShareSource;
    }

    /** Combien de jours une composante partagée reste visible, au moins. */
    public const int DUREE_MINIMALE_JOURS = 30;

    /**
     * Jusqu'à quand chaque composante **partagée** ne peut pas être retirée par le titulaire
     * — seules celles dont le verrou court encore à `$maintenant` figurent.
     *
     * @return array{annee?: \DateTimeImmutable, mois?: \DateTimeImmutable, jour?: \DateTimeImmutable}
     */
    public function verrouDuPartage(\DateTimeImmutable $maintenant = new \DateTimeImmutable()): array
    {
        $verrous = [];

        foreach (['annee' => $this->birthShareYearSince, 'mois' => $this->birthShareMonthSince, 'jour' => $this->birthShareDaySince] as $cle => $depuis) {
            $fin = $depuis?->modify(\sprintf('+%d days', self::DUREE_MINIMALE_JOURS));
            if (null !== $fin && $fin > $maintenant) {
                $verrous[$cle] = $fin;
            }
        }

        return $verrous;
    }

    /** @param 'inscription'|'profil'|'administration' $source */
    public function partagerLaNaissance(ComposantesDeDate $partage, string $source, ?\DateTimeImmutable $le = null): self
    {
        $le ??= new \DateTimeImmutable();

        // Chaque composante qui devient partagée pose sa date ; une composante retirée l'oublie.
        $depuis = static fn (bool $avant, bool $apres, ?\DateTimeImmutable $date): ?\DateTimeImmutable => $apres ? ($avant ? $date : $le) : null;
        $this->birthShareYearSince = $depuis($this->birthShareYear, $partage->annee, $this->birthShareYearSince);
        $this->birthShareMonthSince = $depuis($this->birthShareMonth, $partage->mois, $this->birthShareMonthSince);
        $this->birthShareDaySince = $depuis($this->birthShareDay, $partage->jour, $this->birthShareDaySince);

        $this->birthShareYear = $partage->annee;
        $this->birthShareMonth = $partage->mois;
        $this->birthShareDay = $partage->jour;
        $this->birthShareUpdatedAt = $le;
        $this->birthShareSource = $source;
        $this->updatedAt = $this->birthShareUpdatedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toucher(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
