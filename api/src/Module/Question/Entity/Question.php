<?php

declare(strict_types=1);

namespace App\Module\Question\Entity;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Question\Enum\EtatDeQuestion;
use App\Module\Question\Enum\NatureDeLacune;
use App\Module\Question\Enum\RedacteurDeQuestion;
use App\Module\Question\Repository\QuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une question posée à **un compte** au sujet d'**une personne** (ADR 0008).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LA LIGNE N'EST PAS LA QUESTION : C'EST LA MÉMOIRE DE CE QU'ON EN A FAIT.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * La question elle-même naît de la détection, qui relit l'arbre. Cette table existe
 * pour trois choses que la détection ne sait pas :
 *
 * 1. **ne pas reposer ce à quoi on a déjà répondu** — `etat`, et l'index unique
 *    ci-dessous ;
 * 2. **ne pas repayer une formulation déjà écrite** — `texte`, figé au premier appel.
 *    Le coût suit les lacunes nouvelles, jamais les rechargements de page ;
 * 3. **retrouver ce qu'une réponse a produit** — {@see $demandes}.
 *
 * ## L'index unique est le mécanisme central, pas une précaution
 *
 * `uniq_qst_question_lacune (destinataire_user_id, signature)`. La signature est
 * `sha256(nature : sujet_kind : sujet_id)` — elle désigne **la lacune**, pas la ligne.
 *
 * C'est ce qui rend la détection **idempotente sans qu'aucun service ait à s'en
 * souvenir** : on peut la rejouer à chaque chargement de page, elle retombe sur les
 * mêmes signatures, retrouve les lignes, et ne repose rien. Un « je ne sais pas »
 * répondu hier survit donc à une détection rejouée mille fois, et il y survit **par
 * construction** et non par la vigilance de l'appelant.
 *
 * Le corollaire est à connaître avant de toucher à {@see self::signatureDe()} : en
 * changer la formule **rouvre toutes les questions fermées**. Les anciennes lignes
 * gardent leur ancienne signature, la détection en calcule de nouvelles, et chaque
 * membre retrouve d'un coup tout ce qu'il avait écarté. Ce serait une migration, pas
 * une correction de détail.
 *
 * ## Deux colonnes sont des sorties de données personnelles
 *
 * `sujet_label` est un libellé dénormalisé au sens du § 0.4 du modèle de données :
 * **il est filtré comme la personne qu'il nomme** (ADR 0003, huitième porte).
 *
 * `texte` l'est aussi, et c'est un cas **nouveau** dans ce dépôt : jusqu'ici, une
 * sortie de données personnelles était un nom recopié dans une colonne prévue pour ça,
 * qu'une liste blanche champ par champ pouvait attraper. Ici, c'est une phrase en
 * français qui **contient** le nom — « Savez-vous où est née Alberte Bourrache ? ».
 * Aucune liste blanche ne l'aurait vue ; c'est la règle plus générale de l'ADR 0003 qui
 * l'attrape : *« tout champ dérivé d'une donnée masquée est lui-même une donnée
 * masquée »*.
 *
 * Le remède est le même que pour les huit autres portes : **la question entière n'est
 * pas servie quand son sujet ne l'est pas**, et la vérification se fait à la
 * sérialisation, sur le `CercleDeVisibiliteInterface` du noyau. Elle est refaite à la
 * lecture et pas seulement à la détection, parce qu'un cercle **rétrécit** — il suffit
 * que l'administrateur baisse `cercle.generations` (ADR 0008, § 3).
 *
 * ## Aucune relation ne franchit la frontière du module
 *
 * `destinataire_user_id` (⇢ `Core\User`) et `sujet_id` (⇢ `Tree\Person`) sont des
 * **identifiants nus** : ni `ManyToOne`, ni clé étrangère (ADR 0002, règle 2). La
 * seule relation Doctrine de cette entité est intra-module, vers
 * {@see QuestionDemande}.
 */
#[ORM\Entity(repositoryClass: QuestionRepository::class)]
#[ORM\Table(name: 'qst_question')]
// **La mémoire de la lacune.** Une lacune, un destinataire, une ligne — quel que soit
// le nombre de fois où la détection est rejouée. Voir l'en-tête.
#[ORM\UniqueConstraint(name: 'uniq_qst_question_lacune', columns: ['destinataire_user_id', 'signature'])]
// La requête de la page : « les questions ouvertes de ce compte ». Index partiel, servi
// en balayage d'index seul — c'est la seule requête chaude du module.
#[ORM\Index(
    name: 'idx_qst_question_ouvertes',
    columns: ['destinataire_user_id', 'etat'],
    options: ['where' => "((etat)::text = 'posee'::text)"],
)]
// Le réveil des « je vais me renseigner ». Partiel sur le seul état concerné : les
// autres lignes portent `relancer_apres = NULL` et n'ont rien à faire dans cet index.
#[ORM\Index(
    name: 'idx_qst_question_relance',
    columns: ['relancer_apres'],
    options: ['where' => "((etat)::text = 'differee'::text)"],
)]
// « Toutes les questions portant sur cette fiche » — l'écran d'une personne, et le
// filtre du cercle appliqué en une passe plutôt qu'une par ligne.
#[ORM\Index(name: 'idx_qst_question_sujet', columns: ['sujet_kind', 'sujet_id'])]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ⇢ inter-module (`Core\User`). Le compte à qui **cette** question est posée. */
    #[ORM\Column]
    private int $destinataireUserId;

    /**
     * `sha256(nature : sujet_kind : sujet_id)` — voir {@see self::signatureDe()}.
     *
     * `CHAR(64)` comme `mbr_invitation.token_hash` et `core_email_change.token_hash` :
     * une empreinte hexadécimale a une longueur fixe, et la déclarer variable laisserait
     * croire qu'elle peut être autre chose.
     */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $signature;

    #[ORM\Column(length: 32, enumType: NatureDeLacune::class)]
    private NatureDeLacune $nature;

    /**
     * La portée du sujet. `Personne` dans toutes les natures livrées aujourd'hui, et la
     * colonne existe quand même : une lacune d'union — « en quelle année se sont-ils
     * mariés ? » — vise une `Tree\Family`, et la signature devrait alors distinguer la
     * famille n° 12 de la personne n° 12. Une signature qui confondrait deux portées
     * fermerait une question en répondant à une autre.
     */
    #[ORM\Column(length: 12, enumType: SubjectKind::class)]
    private SubjectKind $sujetKind;

    /** ⇢ inter-module (`Tree\Person` ou `Tree\Family`). */
    #[ORM\Column]
    private int $sujetId;

    /**
     * Libellé dénormalisé du sujet. **Sortie de données personnelles : filtrée comme
     * la personne** (§ 0.4 du modèle de données, ADR 0003).
     */
    #[ORM\Column(length: 255)]
    private string $sujetLabel;

    /**
     * La question, en français, telle qu'elle sera lue.
     *
     * **Écrite une fois et jamais réécrite.** Deux raisons, et la seconde est la plus
     * importante : le coût d'appel au modèle suit alors les lacunes nouvelles et non les
     * rechargements de page ; et une question dont le texte changerait entre deux visites
     * — y compris sous une réponse déjà rédigée par le membre — serait un écran dont on
     * se méfie.
     *
     * **Contient le nom de la personne**, donc relève du filtre du cercle au même titre
     * que `sujet_label`. Voir l'en-tête.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $texte;

    #[ORM\Column(length: 12, enumType: RedacteurDeQuestion::class, options: ['default' => 'regles'])]
    private RedacteurDeQuestion $redacteur = RedacteurDeQuestion::Regles;

    #[ORM\Column(length: 12, enumType: EtatDeQuestion::class, options: ['default' => 'posee'])]
    private EtatDeQuestion $etat = EtatDeQuestion::Posee;

    /**
     * Le texte libre tel que le membre l'a écrit, **verbatim**.
     *
     * Il est aussi recopié dans la demande produite — en corps de note, ou en message
     * de la demande quand la réponse alimente un champ. Le garder ici en plus n'est pas
     * une redondance : une demande refusée puis reprise change d'identifiant, une
     * demande peut être refusée et l'on veut toujours savoir ce qui avait été répondu,
     * et c'est ce qu'on relit le jour où l'on se demande si les questions servent à
     * quelque chose.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reponseTexte = null;

    /**
     * Quand reposer la question. Renseigné **si et seulement si** `etat = differee` —
     * contrainte `ck_qst_question_differee`, en base et non seulement dans le service.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $relancerApres = null;

    /**
     * Combien de fois le membre a répondu « je vais me renseigner ».
     *
     * Une promesse qu'on rappelle indéfiniment est un harcèlement poli : au bout de
     * quelques reports, le membre répond « je ne sais pas » pour s'en débarrasser, ce
     * qui **détruit** l'information au lieu de la recueillir. Le compteur existe pour que
     * la décision d'arrêter soit prise sur un chiffre, et pas au jugé.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $reports = 0;

    /**
     * Renseigné pour `inconnue` **et** pour `repondue` — contrainte
     * `ck_qst_question_repondue`.
     *
     * « Je ne sais pas » est une réponse. Elle n'écrit rien dans l'arbre, et c'est
     * précisément pourquoi il faut un horodatage : sans lui, rien ne distinguerait
     * « personne n'a jamais regardé cette question » de « quelqu'un a regardé et ne
     * savait pas », qui sont deux renseignements opposés pour qui cherche où chercher.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reponduLe = null;

    /**
     * Les demandes qu'une réponse a produites. **Relation intra-module**, la seule de
     * cette entité.
     *
     * @var Collection<int, QuestionDemande>
     */
    #[ORM\OneToMany(targetEntity: QuestionDemande::class, mappedBy: 'question', cascade: ['persist'], orphanRemoval: true)]
    private Collection $demandes;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $destinataireUserId,
        NatureDeLacune $nature,
        SubjectKind $sujetKind,
        int $sujetId,
        string $sujetLabel,
        string $texte,
        RedacteurDeQuestion $redacteur = RedacteurDeQuestion::Regles,
    ) {
        $this->destinataireUserId = $destinataireUserId;
        $this->nature = $nature;
        $this->sujetKind = $sujetKind;
        $this->sujetId = $sujetId;
        $this->sujetLabel = $sujetLabel;
        $this->texte = $texte;
        $this->redacteur = $redacteur;
        $this->signature = self::signatureDe($nature, $sujetKind, $sujetId);
        $this->demandes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * L'empreinte de la **lacune** — `sha256(nature : sujet_kind : sujet_id)`.
     *
     * ## Ce qui n'y entre pas, et pourquoi
     *
     * **Ni le destinataire** : il est la seconde colonne de l'index unique, séparément.
     * Le mêler à l'empreinte ferait perdre la capacité de répondre à « qui d'autre a été
     * interrogé sur cette lacune ? », qui est la question à poser avant d'en interroger
     * un de plus.
     *
     * **Ni le texte de la question** : deux formulations de la même lacune sont la même
     * lacune. L'y inclure ferait rouvrir toutes les questions au premier changement de
     * gabarit, ou pire, à chaque reformulation par le modèle — c'est-à-dire que la page
     * redemanderait indéfiniment ce à quoi on a déjà répondu, et que le défaut serait
     * attribué au modèle alors qu'il viendrait d'ici.
     *
     * ## Pourquoi un haché plutôt que la chaîne elle-même
     *
     * Pour la longueur fixe, et pour rien d'autre : `'date_naissance_inconnue:personne:214'`
     * conviendrait parfaitement à un index. Le haché garde la colonne à 64 signes quelles
     * que soient les natures ajoutées plus tard, et évite qu'un nom de nature long fasse
     * déborder un `VARCHAR` — le défaut exact que `LongueurDesEnumerationsTest` existe
     * pour attraper ailleurs.
     */
    public static function signatureDe(NatureDeLacune $nature, SubjectKind $sujetKind, int $sujetId): string
    {
        return hash('sha256', $nature->value.':'.$sujetKind->value.':'.$sujetId);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDestinataireUserId(): int
    {
        return $this->destinataireUserId;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getNature(): NatureDeLacune
    {
        return $this->nature;
    }

    public function getSujetKind(): SubjectKind
    {
        return $this->sujetKind;
    }

    public function getSujetId(): int
    {
        return $this->sujetId;
    }

    public function getSujetLabel(): string
    {
        return $this->sujetLabel;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function getRedacteur(): RedacteurDeQuestion
    {
        return $this->redacteur;
    }

    public function getEtat(): EtatDeQuestion
    {
        return $this->etat;
    }

    public function getReponseTexte(): ?string
    {
        return $this->reponseTexte;
    }

    public function getRelancerApres(): ?\DateTimeImmutable
    {
        return $this->relancerApres;
    }

    public function getReports(): int
    {
        return $this->reports;
    }

    public function getReponduLe(): ?\DateTimeImmutable
    {
        return $this->reponduLe;
    }

    /** @return Collection<int, QuestionDemande> */
    public function getDemandes(): Collection
    {
        return $this->demandes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Le libellé du sujet **a changé** dans l'arbre depuis que la question a été posée.
     *
     * La seule mise à jour admise sur une question déjà écrite, et elle ne touche pas
     * `texte` : une question reste telle qu'elle a été lue, même si la personne a été
     * renommée entre-temps. C'est le libellé de liste et de regroupement qui suit, parce
     * qu'un écran qui affiche deux orthographes pour la même fiche fait douter des deux.
     */
    public function rafraichirLeLibelle(string $sujetLabel): self
    {
        if ($sujetLabel !== $this->sujetLabel) {
            $this->sujetLabel = $sujetLabel;
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    /**
     * « Je ne sais pas » : la question est fermée, définitivement, et **rien n'est
     * écrit dans l'arbre**.
     */
    public function fermerFauteDeSavoir(): self
    {
        $this->etat = EtatDeQuestion::Inconnue;
        $this->relancerApres = null;
        $this->reponduLe = new \DateTimeImmutable();
        $this->updatedAt = $this->reponduLe;

        return $this;
    }

    /** « Je vais me renseigner » : elle revient après `$quand`. */
    public function differer(\DateTimeImmutable $quand): self
    {
        $this->etat = EtatDeQuestion::Differee;
        $this->relancerApres = $quand;
        $this->reponduLe = null;
        ++$this->reports;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Une réponse a été déposée, et elle a produit au moins une demande.
     *
     * **La règle « au moins une demande » ne peut pas être une contrainte `CHECK`** :
     * un `CHECK` ne voit qu'une ligne de sa propre table, et les demandes sont dans
     * `qst_question_demande`. Elle est donc portée par le service de réponse, qui
     * attache les liens **avant** d'appeler cette méthode, dans la même transaction.
     * Ce que la base garde, c'est ce qu'elle sait garder : `ck_qst_question_repondue`
     * exige `repondu_le`, et l'index unique de `qst_question_demande` empêche le même
     * lien deux fois.
     */
    public function marquerRepondue(?string $reponseTexte): self
    {
        $this->etat = EtatDeQuestion::Repondue;
        $this->reponseTexte = $reponseTexte;
        $this->relancerApres = null;
        $this->reponduLe = new \DateTimeImmutable();
        $this->updatedAt = $this->reponduLe;

        return $this;
    }

    public function attacherLaDemande(QuestionDemande $lien): self
    {
        if (!$this->demandes->contains($lien)) {
            $this->demandes->add($lien);
        }

        return $this;
    }
}
