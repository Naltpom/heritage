<?php

declare(strict_types=1);

namespace App\Module\Demarche\Entity;

use App\Module\Demarche\Enum\FormeDActe;
use App\Module\Demarche\Enum\NatureDActe;
use App\Module\Demarche\Enum\StatutDeDemarche;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une pièce demandée à un service — une mairie, des archives, un notaire — et la suite
 * qu'on lui a donnée.
 *
 * **Pourquoi elle existe.** Une demande d'acte met des semaines à revenir, et elle part
 * de la boîte de quelqu'un. Sans trace dans l'arbre, personne d'autre ne sait qu'elle a
 * été faite : on la refait, ou l'on attend une pièce que personne n'a demandée. Le
 * 30 septembre 2026, une recherche a failli faire redemander un acte de mariage envoyé le
 * matin même.
 *
 * **Ce n'est pas une donnée de l'arbre.** Une démarche ne dit rien de vrai sur une
 * personne : elle dit ce qu'on a fait pour le savoir. Elle ne passe donc pas par le
 * circuit des propositions (ADR 0004) et n'écrit que dans ses tables, comme le module
 * `Question`. Quand la pièce arrive, ce qu'elle établit entre dans l'arbre par une
 * demande ordinaire ; la démarche, elle, passe à « reçue » et garde la trace du chemin.
 *
 * Le texte de chaque message est gardé **en entier** dans {@see Echange} : celui qui
 * reprend une démarche n'a pas accès à la boîte de celui qui l'a lancée, et c'est ce
 * texte qui lui dit ce qui a été demandé, à qui, et ce qu'on peut encore compléter.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dmr_demarche')]
#[ORM\Index(name: 'idx_dmr_demarche_statut', columns: ['statut'])]
class Demarche
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12, enumType: NatureDActe::class)]
    private NatureDActe $nature;

    #[ORM\Column(length: 24, enumType: FormeDActe::class)]
    private FormeDActe $forme;

    /** « Mariage PROVOST × MAGNOL, Rampieux, 1945 » : ce qu'on lit dans une liste. */
    #[ORM\Column(length: 255)]
    private string $intitule;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $commune = null;

    /**
     * La date de l'acte **telle qu'on la connaît au moment de demander** : « 1er septembre
     * 1945 », « vers 1945 ». Du texte et non une date, parce qu'une démarche se lance
     * justement quand la date n'est pas sûre.
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $dateDeLActe = null;

    /** « Mairie de Rampieux », « Archives départementales du Gers ». */
    #[ORM\Column(length: 160)]
    private string $destinataire;

    #[ORM\Column(length: 12, enumType: StatutDeDemarche::class, options: ['default' => 'envoyee'])]
    private StatutDeDemarche $statut = StatutDeDemarche::Envoyee;

    /** Ce que la démarche a donné, ou pourquoi elle s'est arrêtée. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultat = null;

    #[ORM\Column]
    private int $auteurUserId;

    #[ORM\Column(length: 180)]
    private string $auteurLibelle;

    /** @var Collection<int, PersonneConcernee> */
    #[ORM\OneToMany(targetEntity: PersonneConcernee::class, mappedBy: 'demarche', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $personnes;

    /** @var Collection<int, Echange> */
    #[ORM\OneToMany(targetEntity: Echange::class, mappedBy: 'demarche', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $echanges;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        NatureDActe $nature,
        FormeDActe $forme,
        string $intitule,
        string $destinataire,
        int $auteurUserId,
        string $auteurLibelle,
    ) {
        $this->nature = $nature;
        $this->forme = $forme;
        $this->intitule = $intitule;
        $this->destinataire = $destinataire;
        $this->auteurUserId = $auteurUserId;
        $this->auteurLibelle = $auteurLibelle;
        $this->personnes = new ArrayCollection();
        $this->echanges = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNature(): NatureDActe
    {
        return $this->nature;
    }

    public function getForme(): FormeDActe
    {
        return $this->forme;
    }

    public function getIntitule(): string
    {
        return $this->intitule;
    }

    public function getCommune(): ?string
    {
        return $this->commune;
    }

    public function getDateDeLActe(): ?string
    {
        return $this->dateDeLActe;
    }

    public function getDestinataire(): string
    {
        return $this->destinataire;
    }

    public function getStatut(): StatutDeDemarche
    {
        return $this->statut;
    }

    public function getResultat(): ?string
    {
        return $this->resultat;
    }

    public function getAuteurUserId(): int
    {
        return $this->auteurUserId;
    }

    public function getAuteurLibelle(): string
    {
        return $this->auteurLibelle;
    }

    /** @return list<PersonneConcernee> */
    public function getPersonnes(): array
    {
        return array_values($this->personnes->toArray());
    }

    /** @return list<int> */
    public function getPersonIds(): array
    {
        return array_map(static fn (PersonneConcernee $p): int => $p->getPersonId(), $this->getPersonnes());
    }

    /** @return list<Echange> */
    public function getEchanges(): array
    {
        $echanges = array_values($this->echanges->toArray());

        // L'`OrderBy` ne vaut qu'au chargement : un échange ajouté dans la requête en cours
        // se range ici, pour que la réponse relue et la page rechargée disent la même chose.
        usort($echanges, static fn (Echange $a, Echange $b): int => [$a->getDate(), $a->getId() ?? \PHP_INT_MAX] <=> [$b->getDate(), $b->getId() ?? \PHP_INT_MAX]);

        return $echanges;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function decrire(
        NatureDActe $nature,
        FormeDActe $forme,
        string $intitule,
        ?string $commune,
        ?string $dateDeLActe,
        string $destinataire,
    ): void {
        $this->nature = $nature;
        $this->forme = $forme;
        $this->intitule = $intitule;
        $this->commune = $commune;
        $this->dateDeLActe = $dateDeLActe;
        $this->destinataire = $destinataire;
        $this->toucher();
    }

    public function suivre(StatutDeDemarche $statut, ?string $resultat): void
    {
        $this->statut = $statut;
        $this->resultat = $resultat;
        $this->toucher();
    }

    /**
     * Remplace les personnes concernées par celles données, en gardant les lignes de
     * celles qui restent.
     *
     * @param array<int, string> $libellesParPersonne
     */
    public function concerner(array $libellesParPersonne): void
    {
        foreach ($this->getPersonnes() as $personne) {
            if (!\array_key_exists($personne->getPersonId(), $libellesParPersonne)) {
                $this->personnes->removeElement($personne);
            }
        }

        $deja = array_flip($this->getPersonIds());

        foreach ($libellesParPersonne as $personId => $libelle) {
            if (!isset($deja[$personId])) {
                $this->personnes->add(new PersonneConcernee($this, $personId, $libelle));
            }
        }

        $this->toucher();
    }

    public function ajouterEchange(Echange $echange): void
    {
        $this->echanges->add($echange);
        $this->toucher();
    }

    public function retirerEchange(Echange $echange): void
    {
        $this->echanges->removeElement($echange);
        $this->toucher();
    }

    /** Le dernier message parti vers le service : c'est de lui que l'attente se compte. */
    public function getDernierEnvoiOuNull(): ?Echange
    {
        $envois = array_values(array_filter($this->getEchanges(), static fn (Echange $e): bool => $e->estEnvoye()));

        return [] === $envois ? null : $envois[\count($envois) - 1];
    }

    private function toucher(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
