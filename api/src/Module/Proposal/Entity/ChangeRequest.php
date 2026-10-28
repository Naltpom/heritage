<?php

declare(strict_types=1);

namespace App\Module\Proposal\Entity;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'en-tête d'une demande : une ligne = une décision de l'administrateur (§ 5.1).
 *
 * **Un seul mécanisme de validation pour tout** (§ 6.4 du dossier de mission) :
 * modifier un champ, ajouter une personne, ajouter un lien, joindre un média passent par
 * la même `ChangeRequest`, la même file d'attente, le même message obligatoire au refus.
 *
 * Une édition directe d'administrateur n'y échappe pas : elle crée une demande
 * auto-validée dans la même transaction (ADR 0004). **Aucun chemin d'écriture applicatif
 * ne contourne le mécanisme** — sans quoi le calque « en attente » aurait un cas
 * particulier à gérer et l'historique d'une fiche des trous que rien ne signale.
 */
#[ORM\Entity(repositoryClass: ChangeRequestRepository::class)]
#[ORM\Table(name: 'prp_change_request')]
// **La file d'attente de l'administrateur**, dans l'ordre de soumission : c'est l'écran
// d'accueil de l'administration, et l'index partiel le sert en balayage d'index seul.
#[ORM\Index(
    name: 'idx_prp_request_file',
    columns: ['status', 'submitted_at'],
    options: ['where' => "((status)::text = 'en_attente'::text)"],
)]
// « Mes demandes » — y compris les refusées, qui restent consultables par leur auteur.
#[ORM\Index(name: 'idx_prp_request_auteur', columns: ['author_user_id', 'created_at'])]
// Deux usages, tous deux chauds : le calque « en attente » d'une fiche, et la détection
// de demandes concurrentes avant validation.
#[ORM\Index(name: 'idx_prp_request_cible', columns: ['target_kind', 'target_id', 'status'])]
// Rapprochement des doublons du § 6.4 : deux cousins peuvent proposer le même
// arrière-grand-oncle, et l'administrateur doit le voir avant de trancher.
#[ORM\Index(
    name: 'idx_prp_request_dedup',
    columns: ['dedup_key'],
    options: ['where' => "((dedup_key IS NOT NULL) AND ((status)::text = 'en_attente'::text))"],
)]
// « Quels compléments attendent sur cette proposition ? » — la vue d'administration de
// l'origine, et le contrôle préalable qui en bloque la validation (ADR 0017). Nommé ici
// plutôt que laissé à l'index que Doctrine pose de lui-même sur une colonne de jointure :
// un `IDX_` haché ne dit rien à qui lit un plan d'exécution.
#[ORM\Index(name: 'idx_prp_request_complement', columns: ['complement_de_id'])]
// **Le rejeu d'un dépôt** (ADR 0022) : un compte ne dépose qu'une demande par clé
// `Idempotency-Key`. Partiel, parce que la plupart des demandes n'ont pas de clé. Le
// prédicat est écrit sans transtypage `::` pour rester valable sur les deux moteurs.
// SQLite, qui ne pose pas d'index partiel, en fait un index unique complet, où les `NULL`
// restent distincts : même effet.
#[ORM\UniqueConstraint(
    name: 'uniq_prp_request_idempotence',
    columns: ['author_user_id', 'idempotency_key'],
    options: ['where' => '(idempotency_key IS NOT NULL)'],
)]
class ChangeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, enumType: RequestKind::class)]
    private RequestKind $kind;

    #[ORM\Column(length: 12, enumType: RequestStatus::class, options: ['default' => 'brouillon'])]
    private RequestStatus $status = RequestStatus::Brouillon;

    /**
     * ⇢ inter-module (`Core\User`). C'est lui, et lui seul avec les administrateurs, qui
     * voit le calque « en attente » (§ 6.3 du dossier).
     */
    #[ORM\Column]
    private int $authorUserId;

    #[ORM\Column(length: 120)]
    private string $authorLabel;

    /**
     * `null` pour une création pure, qui ne vise rien d'existant.
     *
     * N'accepte que quatre des six valeurs de {@see SubjectKind} — voir
     * {@see SubjectKind::ciblesDeDemande()}.
     */
    #[ORM\Column(length: 12, nullable: true, enumType: SubjectKind::class)]
    private ?SubjectKind $targetKind = null;

    /**
     * ⇢ inter-module (`Tree\Person`, `Tree\Family`, `Tree\Citation` ou `Media\Media`).
     * Dénormalisé depuis les ops : sert à afficher et filtrer la file sans jointure, et
     * à détecter les demandes concurrentes sur la même fiche.
     */
    #[ORM\Column(nullable: true)]
    private ?int $targetId = null;

    /** Libellé dénormalisé de la cible. Sortie de données personnelles : filtrée comme la personne (§ 0.4). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetLabel = null;

    /** Le mot du demandeur (« c'est ma grand-tante, j'ai l'acte »). Facultatif. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /**
     * Le mot de l'administrateur. **Obligatoire au refus** — et c'est une contrainte
     * `CHECK`, pas seulement une validation de formulaire, pour qu'aucun chemin de code
     * (console, test, correctif à chaud) ne puisse la contourner.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionMessage = null;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column(nullable: true)]
    private ?int $decidedByUserId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $decidedByLabel = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /**
     * `true` quand le validateur est aussi l'auteur — l'administrateur qui valide sa
     * propre demande, et le cas particulier de l'édition directe.
     *
     * Autorisé : il n'y a qu'un administrateur, l'interdire bloquerait le produit. Mais
     * **rendu visible**, et en **colonne** plutôt qu'enfoui dans le `JSONB` d'audit, pour
     * que « montre-moi toutes les auto-validations » soit une requête et non une fouille.
     * Une décision d'administration indistinguable d'une contribution ordinaire est
     * exactement ce qu'un journal d'audit doit éviter (ADR 0004).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $autoValidated = false;

    /** C'est cet horodatage qui ordonne la file d'attente, pas `createdAt`. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    /**
     * FK **intra-module**, auto-référence. Renseignée quand l'administrateur refuse une
     * demande **en renvoyant à une autre** : l'auteur voit alors « votre proposition
     * rejoint la demande n° 12 », pas un refus sec.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'duplicate_of_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $duplicateOf = null;

    /**
     * La demande dont celle-ci est la **reprise**, après un refus ou une caducité.
     *
     * **À ne pas confondre avec `duplicateOf`, et la distinction n'est pas subtile** :
     *
     * - `duplicateOf` — **deux auteurs différents**, une même personne proposée. Deux
     *   cousins proposent le même arrière-grand-oncle ; l'administrateur refuse l'une en
     *   renvoyant à l'autre (§ 6.4 du dossier de mission).
     * - `repriseDe` — **le même auteur**, sa propre demande refaite. Le bouton « refaire
     *   cette demande » du § 9.6, pré-rempli sur les valeurs courantes.
     *
     * Deux relations, deux colonnes : donner un second sens à la première les rendrait
     * illisibles toutes les deux, et c'est le genre de surcharge qu'on ne défait jamais.
     *
     * **Pourquoi la colonne existe, alors que le service pourrait s'en passer.** Une
     * reprise emporte avec elle les médias déjà téléversés — le service repointe les
     * pièces de l'origine vers la reprise, parce qu'un acte photographié est le travail le
     * plus coûteux d'une contribution et que faire retéléverser perdrait entièrement ce
     * qu'une caducité n'avait perdu qu'à moitié. Sans cette colonne, la demande d'origine
     * affiche donc un dossier **amputé de ses pièces, sans explication** : qui la rouvre
     * conclut à une perte de données. Un enregistrement faux qui ne dit pas pourquoi coûte
     * plus cher que la ligne qui l'explique.
     *
     * Elle sert aussi le retour à l'auteur : « votre demande n° 12 a été refaite en
     * n° 21 », qui est la moitié de la valeur du circuit de refus motivé.
     *
     * `INT` et non `BIGINT` : la clé primaire de cette table est un `INT` (§ 0.2 du modèle
     * — seules `core_audit_log` et `mbr_claim_attempt` croissent sans borne).
     * Doctrine dérive d'ailleurs le type de la colonne de jointure de celui de la cible.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reprise_de_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $repriseDe = null;

    /**
     * La **fiche proposée** que cette demande complète — le troisième pointeur de la
     * table, et le seul qui vise une fiche plutôt qu'une demande (ADR 0017).
     *
     * Un membre qui reconnaît, dans la proposition d'un **autre**, la personne qu'il
     * s'apprêtait à saisir ne peut pas la modifier : elle n'est pas à lui. Il dépose donc
     * une `creation_personne` ordinaire — une fiche, au moins un lien — qui porte ce
     * pointeur, et l'administrateur la **fusionne** dans l'origine en un clic
     * ({@see \App\Module\Proposal\Service\FusionDeComplement}).
     *
     * ── Pourquoi une fiche et non une demande ───────────────────────────────────────
     *
     * Une création peut porter plusieurs fiches — une branche de vingt-deux personnes en
     * une demande. « Complète la demande n° 12 » ne dirait pas laquelle ; « complète la
     * fiche 7 » ne laisse aucun doute, et la demande s'en lit par la jointure.
     *
     * ── Trois pointeurs, trois sens ─────────────────────────────────────────────────
     *
     * - `duplicateOf` : deux auteurs, une même personne, et l'une des deux demandes
     *   **refusée** en renvoyant à l'autre ;
     * - `repriseDe` : le même auteur, sa propre demande **refaite** ;
     * - `complementDe` : deux auteurs, une même personne, et la seconde demande
     *   **versée** dans la première. Après la fusion, le complément porte aussi
     *   `duplicateOf` — c'est ce que son auteur lit : « fusionné dans la demande n° 12 ».
     *
     * `ON DELETE SET NULL` : une fiche ne disparaît qu'avec sa demande, ce qui n'arrive
     * jamais (ADR 0004) ; si cela arrivait, le complément redeviendrait une création
     * autonome — ce qu'il est déjà dès que son origine est refusée, retirée ou caduque.
     * `ck_prp_request_complement` réserve la colonne aux `creation_personne`.
     */
    #[ORM\ManyToOne(targetEntity: DraftPerson::class)]
    #[ORM\JoinColumn(name: 'complement_de_id', nullable: true, onDelete: 'SET NULL')]
    private ?DraftPerson $complementDe = null;

    /**
     * Clé de rapprochement des doublons probables : `search_key` de la personne proposée
     * + année de naissance (ou fourchette). Calculée à la soumission, pour les seules
     * demandes de création.
     */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $dedupKey = null;

    /**
     * La clé `Idempotency-Key` sous laquelle la demande a été déposée (ADR 0022), choisie par
     * le client : 16 à 128 caractères `[A-Za-z0-9_-]`. `null` pour un dépôt sans l'en-tête,
     * ce que fait le front.
     *
     * **À ne pas confondre avec `dedupKey`.** Celle-ci rapproche deux propositions
     * *semblables*, souvent de deux auteurs, pour que l'administrateur tranche. La clé
     * d'idempotence reconnaît *la même requête* rejouée par son auteur, et c'est le serveur
     * qui tranche. Posée à la création, jamais modifiée.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $idempotencyKey = null;

    /**
     * L'empreinte sha256 (hexadécimal) du corps déposé sous cette clé. Elle permet de refuser
     * un rejeu au corps différent (`422 idempotence_corps_different`) au lieu de le confondre
     * avec le premier. Présente si et seulement si la clé l'est
     * (`ck_prp_request_idempotence`, en migration).
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $idempotencyFingerprint = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(RequestKind $kind, int $authorUserId, string $authorLabel)
    {
        $this->kind = $kind;
        $this->authorUserId = $authorUserId;
        $this->authorLabel = $authorLabel;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): RequestKind
    {
        return $this->kind;
    }

    public function getStatus(): RequestStatus
    {
        return $this->status;
    }

    public function setStatus(RequestStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getAuthorUserId(): int
    {
        return $this->authorUserId;
    }

    public function getAuthorLabel(): string
    {
        return $this->authorLabel;
    }

    public function setAuthorLabel(string $authorLabel): self
    {
        $this->authorLabel = $authorLabel;

        return $this;
    }

    public function getTargetKind(): ?SubjectKind
    {
        return $this->targetKind;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function getTargetLabel(): ?string
    {
        return $this->targetLabel;
    }

    /**
     * Les trois colonnes de cible se posent ensemble : `ck_prp_request_cible` impose que
     * `target_kind` et `target_id` soient nuls ou renseignés tous les deux.
     */
    public function setTarget(?SubjectKind $kind, ?int $id, ?string $label): self
    {
        $this->targetKind = $kind;
        $this->targetId = $id;
        $this->targetLabel = $label;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getDecisionMessage(): ?string
    {
        return $this->decisionMessage;
    }

    public function getDecidedByUserId(): ?int
    {
        return $this->decidedByUserId;
    }

    public function getDecidedByLabel(): ?string
    {
        return $this->decidedByLabel;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /**
     * Enregistre la décision. `ck_prp_request_decision` impose un décideur et une date
     * dès que le statut est `validee` ou `refusee`, et `ck_prp_request_motif_refus`
     * impose un motif non vide au refus.
     */
    public function decider(
        RequestStatus $statut,
        int $decidedByUserId,
        string $decidedByLabel,
        ?string $decisionMessage = null,
        ?\DateTimeImmutable $le = null,
    ): self {
        $this->status = $statut;
        $this->decidedByUserId = $decidedByUserId;
        $this->decidedByLabel = $decidedByLabel;
        $this->decisionMessage = $decisionMessage;
        $this->decidedAt = $le ?? new \DateTimeImmutable();
        $this->autoValidated = RequestStatus::Validee === $statut && $decidedByUserId === $this->authorUserId;
        $this->updatedAt = $this->decidedAt;

        return $this;
    }

    public function isAutoValidated(): bool
    {
        return $this->autoValidated;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function soumettre(?\DateTimeImmutable $le = null): self
    {
        $this->status = RequestStatus::EnAttente;
        $this->submittedAt = $le ?? new \DateTimeImmutable();
        $this->updatedAt = $this->submittedAt;

        return $this;
    }

    public function getDuplicateOf(): ?self
    {
        return $this->duplicateOf;
    }

    public function setDuplicateOf(?self $duplicateOf): self
    {
        $this->duplicateOf = $duplicateOf;

        return $this;
    }

    public function getRepriseDe(): ?self
    {
        return $this->repriseDe;
    }

    public function setRepriseDe(?self $repriseDe): self
    {
        $this->repriseDe = $repriseDe;

        return $this;
    }

    public function getComplementDe(): ?DraftPerson
    {
        return $this->complementDe;
    }

    public function setComplementDe(?DraftPerson $complementDe): self
    {
        $this->complementDe = $complementDe;

        return $this;
    }

    public function getDedupKey(): ?string
    {
        return $this->dedupKey;
    }

    public function setDedupKey(?string $dedupKey): self
    {
        $this->dedupKey = $dedupKey;

        return $this;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getIdempotencyFingerprint(): ?string
    {
        return $this->idempotencyFingerprint;
    }

    /**
     * Une seule fois, avant le premier `flush()` : une clé qui changerait après coup
     * rendrait au rejeu une autre demande que celle déposée.
     */
    public function poserLaCleDIdempotence(string $cle, string $empreinte): self
    {
        if (null !== $this->idempotencyKey) {
            throw new \LogicException('La clé d\'idempotence d\'une demande ne se pose qu\'une fois.');
        }

        $this->idempotencyKey = $cle;
        $this->idempotencyFingerprint = $empreinte;

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
