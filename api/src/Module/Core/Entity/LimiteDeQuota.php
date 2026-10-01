<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Repository\LimiteDeQuotaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une limite d'usage **posée à la main** par l'administrateur — l'exception au défaut de
 * configuration. Pas de ligne : le défaut s'applique. C'est le « `NULL` = défaut » de la
 * demande, tenu par l'absence de ligne plutôt que par une colonne nulle : retirer une
 * limite personnelle, c'est supprimer sa ligne, et le journal d'audit mécanique le dit tel
 * quel (`suppression`).
 *
 * ## Une table, et non des colonnes sur `core_user`
 *
 * La liste des quotas est **ouverte** : chaque module déclare les siens
 * (`Core\Contract\DeclarationDeQuotasInterface`). Des colonnes `limite_analyses`,
 * `limite_questions`… sur `core_user` écriraient le vocabulaire de l'assistant et des
 * médias dans le schéma du noyau, et demanderaient une migration de `core_user` à chaque
 * quota nouveau. Ici, un quota de plus est une clé de plus — aucune migration.
 *
 * ## `user` nul : la limite **globale** d'un compteur partagé
 *
 * Le rattachement assisté compte sur une seule clé pour toute l'installation (il borne une
 * dépense, pas l'activité d'une personne). Sa limite réglable vit dans la même table, sur
 * une ligne sans compte. L'unicité « un compte, une clé » est posée par la migration en
 * `NULLS NOT DISTINCT` — sans quoi deux lignes globales de même clé seraient admises, les
 * `NULL` étant distincts par défaut ; Doctrine ne sait pas l'exprimer, d'où son absence
 * du mapping.
 */
#[ORM\Entity(repositoryClass: LimiteDeQuotaRepository::class)]
#[ORM\Table(name: 'core_quota_limite')]
#[ORM\Index(name: 'idx_core_quota_limite_user', columns: ['user_id'])]
class LimiteDeQuota
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Le compte part, ses limites avec lui (`ON DELETE CASCADE`). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\Column(length: 64)]
    private string $cle;

    #[ORM\Column]
    private int $limite;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?User $user, string $cle, int $limite)
    {
        $this->user = $user;
        $this->cle = $cle;
        $this->limite = $limite;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): ?int
    {
        return $this->user?->getId();
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getLimite(): int
    {
        return $this->limite;
    }

    public function setLimite(int $limite): self
    {
        $this->limite = $limite;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
