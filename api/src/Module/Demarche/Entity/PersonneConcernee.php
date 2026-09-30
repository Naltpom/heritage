<?php

declare(strict_types=1);

namespace App\Module\Demarche\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une personne de l'arbre que la démarche concerne — un acte de mariage en concerne deux.
 *
 * `person_id` est un identifiant nu vers `tree_person`, avec son libellé dénormalisé au
 * moment de l'ajout (ADR 0002, règle 2). C'est aussi lui qui décide **qui voit la
 * démarche** : le texte d'une demande d'acte nomme ces personnes, donne leurs dates et
 * leurs lieux ; il ne se montre qu'à qui voit **toutes** les personnes concernées.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dmr_personne')]
#[ORM\UniqueConstraint(name: 'uniq_dmr_personne', columns: ['demarche_id', 'person_id'])]
#[ORM\Index(name: 'idx_dmr_personne_person', columns: ['person_id'])]
class PersonneConcernee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Demarche::class, inversedBy: 'personnes')]
    #[ORM\JoinColumn(name: 'demarche_id', nullable: false, onDelete: 'CASCADE')]
    private Demarche $demarche;

    #[ORM\Column]
    private int $personId;

    #[ORM\Column(length: 255)]
    private string $personLabel;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Demarche $demarche, int $personId, string $personLabel)
    {
        $this->demarche = $demarche;
        $this->personId = $personId;
        $this->personLabel = $personLabel;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemarche(): Demarche
    {
        return $this->demarche;
    }

    public function getPersonId(): int
    {
        return $this->personId;
    }

    public function getPersonLabel(): string
    {
        return $this->personLabel;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
