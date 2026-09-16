<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Enum\SettingType;
use App\Module\Core\Repository\SettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un réglage d'administration modifiable **sans redéploiement** (§ 2.2 du modèle).
 *
 * Le § 6.2 du dossier de mission l'impose pour `N` (« le commanditaire a formulé ce
 * chiffre avec hésitation ») ; la même logique vaut pour les autres seuils.
 *
 * Le jeu initial est posé par la **migration**, pas par une fixture : l'application ne
 * démarre pas sans lui.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\Table(name: 'core_setting')]
// `fields:` et non `columns:` : la colonne est un identifiant cité (`"key"`), et c'est à
// Doctrine de résoudre le nom réel plutôt qu'à nous de le réécrire avec ses guillemets.
#[ORM\UniqueConstraint(name: 'uniq_core_setting_key', fields: ['settingKey'])]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Clé pointée, par domaine : `cercle.*`, `tree.*`, `invitation.*`, `media.*`,
     * `timeline.*`.
     *
     * `key` est un mot réservé SQL : les accents graves disent à Doctrine de citer
     * l'identifiant (`"key"` sur PostgreSQL), et la propriété s'appelle `settingKey`
     * pour ne pas se heurter au vocabulaire du langage.
     */
    #[ORM\Column(name: '`key`', length: 80)]
    private string $settingKey;

    /** Valeur sérialisée en texte. Le type est porté par `valueType`, pas par la colonne. */
    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    #[ORM\Column(length: 10, enumType: SettingType::class, options: ['default' => 'texte'])]
    private SettingType $valueType = SettingType::Texte;

    /** Libellé français affiché dans l'écran d'administration. */
    #[ORM\Column(length: 160)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Borne basse des réglages entiers. Empêche `cercle.generations = 0` par inadvertance. */
    #[ORM\Column(nullable: true)]
    private ?int $minValue = null;

    /** Borne haute. Au-delà de 6, le cercle couvre l'arbre entier et ne protège plus personne. */
    #[ORM\Column(nullable: true)]
    private ?int $maxValue = null;

    /** ⇢ inter-module (`core_user`) : entier nu, même à l'intérieur de `Core` (§ 0.4). */
    #[ORM\Column(nullable: true)]
    private ?int $updatedByUserId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $updatedByEmail = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $settingKey,
        string $value,
        SettingType $valueType,
        string $label,
        ?string $description = null,
        ?int $minValue = null,
        ?int $maxValue = null,
    ) {
        $this->settingKey = $settingKey;
        $this->value = $value;
        $this->valueType = $valueType;
        $this->label = $label;
        $this->description = $description;
        $this->minValue = $minValue;
        $this->maxValue = $maxValue;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getValueType(): SettingType
    {
        return $this->valueType;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getMinValue(): ?int
    {
        return $this->minValue;
    }

    public function getMaxValue(): ?int
    {
        return $this->maxValue;
    }

    public function getUpdatedByUserId(): ?int
    {
        return $this->updatedByUserId;
    }

    public function getUpdatedByEmail(): ?string
    {
        return $this->updatedByEmail;
    }

    public function setUpdatedBy(?int $userId, ?string $email): self
    {
        $this->updatedByUserId = $userId;
        $this->updatedByEmail = $email;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
