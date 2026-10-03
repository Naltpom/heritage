<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Repository\NoteAdministrateurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une note du **carnet de l'administrateur** — idées, chantiers, choses à ne pas
 * oublier, écrites depuis l'application plutôt que dans un fichier du dépôt.
 *
 * Le carnet est **personnel** : une note appartient au compte qui l'a écrite, et aucun
 * autre administrateur ne la lit. `authorUserId` est donc la clé de toute lecture, pas
 * un simple renseignement.
 *
 * Rien ici ne touche l'arbre : une note n'est pas une donnée familiale, elle ne passe
 * pas par la file de validation et ne connaît pas le cercle de visibilité.
 */
#[ORM\Entity(repositoryClass: NoteAdministrateurRepository::class)]
#[ORM\Table(name: 'core_note')]
// Le carnet se lit toujours par auteur.
#[ORM\Index(name: 'idx_core_note_auteur', columns: ['author_user_id'])]
class NoteAdministrateur
{
    public const int LONGUEUR_TITRE = 160;

    /** 100 000 caractères : un long plan de vidéo tient, un fichier collé par erreur non. */
    public const int LONGUEUR_TEXTE = 100_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ⇢ `core_user` : entier nu, même à l'intérieur de `Core` (§ 0.4). */
    #[ORM\Column]
    private int $authorUserId;

    #[ORM\Column(length: self::LONGUEUR_TITRE)]
    private string $noteTitle;

    #[ORM\Column(type: Types::TEXT)]
    private string $noteBody;

    /** Une note épinglée reste en tête du carnet, quelle que soit sa date. */
    #[ORM\Column(options: ['default' => false])]
    private bool $pinned = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $authorUserId, string $noteTitle, string $noteBody, bool $pinned = false)
    {
        $this->authorUserId = $authorUserId;
        $this->noteTitle = $noteTitle;
        $this->noteBody = $noteBody;
        $this->pinned = $pinned;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthorUserId(): int
    {
        return $this->authorUserId;
    }

    public function getNoteTitle(): string
    {
        return $this->noteTitle;
    }

    public function getNoteBody(): string
    {
        return $this->noteBody;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function reecrire(string $noteTitle, string $noteBody, bool $pinned): void
    {
        $this->noteTitle = $noteTitle;
        $this->noteBody = $noteBody;
        $this->pinned = $pinned;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
