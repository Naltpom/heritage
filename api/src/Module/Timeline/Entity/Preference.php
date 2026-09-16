<?php

declare(strict_types=1);

namespace App\Module\Timeline\Entity;

use App\Module\Core\Enum\Branch;
use App\Module\Timeline\Enum\TimelineOrientation;
use App\Module\Timeline\Repository\PreferenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La préférence de lecture de la vue chronologique (§ 7.1 du modèle de données).
 *
 * **La seule table de `Timeline`**, et le § 5 du dossier de mission prévoyait qu'il
 * puisse n'en avoir aucune. La vue ne stocke rien d'autre : elle lit `tree_person`,
 * `tree_family` et `tree_fact`, applique le cercle de visibilité et dessine ; les bandes
 * se calculent à partir de `N` et des bornes de l'arbre, en deux lignes.
 *
 * Le réglage, lui, mérite d'être en base plutôt qu'en `localStorage` : le § 7.4 du
 * dossier fait de la largeur de bande et de l'orientation des choix structurants de
 * lecture, et un membre qui ouvre l'application sur son téléphone après l'avoir réglée
 * sur son ordinateur doit retrouver sa vue. Un `localStorage` ne traverse pas les
 * appareils.
 */
#[ORM\Entity(repositoryClass: PreferenceRepository::class)]
#[ORM\Table(name: 'tml_preference')]
#[ORM\UniqueConstraint(name: 'uniq_tml_preference_user', columns: ['user_id'])]
class Preference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ⇢ inter-module (`Core\User`). */
    #[ORM\Column]
    private int $userId;

    /** La tranche de `N` années : 1, 5, 10, 25, 50 ou saisie libre (§ 7.4 du dossier). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 10])]
    private int $bandYears = 10;

    #[ORM\Column(length: 10, enumType: TimelineOrientation::class, options: ['default' => 'lignes'])]
    private TimelineOrientation $orientation = TimelineOrientation::Lignes;

    /**
     * Branches affichées. Permet de n'afficher qu'un quart d'ascendance — utile quand
     * 134 personnes sur 274 sont sur `pm`.
     *
     * Le défaut SQL est écrit **espacé** (`["pp", "pm", …]`) et non compact : PostgreSQL
     * renormalise tout littéral `jsonb` à l'enregistrement, et c'est cette forme-là que
     * l'introspection rend. Un défaut compact ici et espacé en base, ce sont deux
     * chaînes différentes pour le comparateur de schéma — donc un `schema:validate`
     * rouge en permanence, pour un espace.
     *
     * @var list<string> valeurs de {@see Branch}
     */
    #[ORM\Column(type: Types::JSONB, options: ['default' => '["pp", "pm", "mp", "mm", "tr"]'])]
    private array $branches = ['pp', 'pm', 'mp', 'mm', 'tr'];

    /**
     * Affiche ou non les hachures aux extrémités des barres, sur l'intervalle
     * `birth_min` → `birth_max`.
     *
     * Le défaut est `true` parce que **masquer l'incertitude est précisément ce que le
     * § 4 du dossier interdit** : c'est un réglage de confort, pas une permission de
     * transformer une fourchette en date ferme à l'écran.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $showUncertainty = true;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getBandYears(): int
    {
        return $this->bandYears;
    }

    /** `ck_tml_preference_bande` borne la valeur entre 1 et 500 côté base. */
    public function setBandYears(int $bandYears): self
    {
        $this->bandYears = $bandYears;

        return $this;
    }

    public function getOrientation(): TimelineOrientation
    {
        return $this->orientation;
    }

    public function setOrientation(TimelineOrientation $orientation): self
    {
        $this->orientation = $orientation;

        return $this;
    }

    /** @return list<Branch> */
    public function getBranches(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $valeur): ?Branch => Branch::tryFrom($valeur),
            $this->branches,
        )));
    }

    /** @param list<Branch> $branches */
    public function setBranches(array $branches): self
    {
        $this->branches = array_values(array_map(
            static fn (Branch $branche): string => $branche->value,
            $branches,
        ));

        return $this;
    }

    public function isShowUncertainty(): bool
    {
        return $this->showUncertainty;
    }

    public function setShowUncertainty(bool $showUncertainty): self
    {
        $this->showUncertainty = $showUncertainty;

        return $this;
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
