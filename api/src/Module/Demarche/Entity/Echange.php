<?php

declare(strict_types=1);

namespace App\Module\Demarche\Entity;

use App\Module\Demarche\Enum\CanalDEchange;
use App\Module\Demarche\Enum\SensDEchange;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un message de la démarche — la demande, une relance, la réponse du service —, **avec
 * son texte entier**.
 *
 * Le texte n'est pas résumé, et c'est le point : celui qui reprend une démarche n'a pas la
 * boîte de celui qui l'a lancée. Relire mot pour mot ce qui a été demandé dit ce qu'on
 * peut compléter sans se contredire (« l'année est à confirmer, entre 1944 et 1946 »), et
 * ce qu'une réponse a vraiment promis.
 *
 * `de` et `a` sont ce que le message portait — une adresse, un service, un nom —, pas des
 * comptes de l'application : une mairie n'en a pas.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dmr_echange')]
class Echange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Demarche::class, inversedBy: 'echanges')]
    #[ORM\JoinColumn(name: 'demarche_id', nullable: false, onDelete: 'CASCADE')]
    private Demarche $demarche;

    #[ORM\Column(length: 8, enumType: SensDEchange::class)]
    private SensDEchange $sens;

    #[ORM\Column(length: 16, enumType: CanalDEchange::class)]
    private CanalDEchange $canal;

    /** Le jour du message — celui de l'envoi, de la réception. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $de = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $a = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $objet = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $texte;

    /** Un numéro de dossier service-public, une référence de courrier. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column]
    private int $saisiParUserId;

    #[ORM\Column(length: 180)]
    private string $saisiParLibelle;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Demarche $demarche,
        SensDEchange $sens,
        CanalDEchange $canal,
        \DateTimeImmutable $date,
        string $texte,
        int $saisiParUserId,
        string $saisiParLibelle,
    ) {
        $this->demarche = $demarche;
        $this->sens = $sens;
        $this->canal = $canal;
        $this->date = $date;
        $this->texte = $texte;
        $this->saisiParUserId = $saisiParUserId;
        $this->saisiParLibelle = $saisiParLibelle;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function adresser(?string $de, ?string $a, ?string $objet, ?string $reference): void
    {
        $this->de = $de;
        $this->a = $a;
        $this->objet = $objet;
        $this->reference = $reference;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemarche(): Demarche
    {
        return $this->demarche;
    }

    public function getSens(): SensDEchange
    {
        return $this->sens;
    }

    public function estEnvoye(): bool
    {
        return SensDEchange::Envoye === $this->sens;
    }

    public function getCanal(): CanalDEchange
    {
        return $this->canal;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getDe(): ?string
    {
        return $this->de;
    }

    public function getA(): ?string
    {
        return $this->a;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getSaisiParUserId(): int
    {
        return $this->saisiParUserId;
    }

    public function getSaisiParLibelle(): string
    {
        return $this->saisiParLibelle;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
