<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * L'**identité d'authentification** : courriel, mot de passe, rôles (§ 2.1 du modèle).
 *
 * Elle ne sait rien de l'arbre. Le rattachement compte ↔ personne est porté par
 * `Membership\Member`, qui référence `core_user.id` par un entier nu. Un module qui a
 * besoin de « qui a fait ça » lit ici ; un module qui a besoin de « quelle personne de
 * l'arbre est-ce » lit `mbr_member`. Aucun des deux ne dépend de l'autre.
 *
 * Les comptes naissent **exclusivement** du parcours d'invitation ou de la commande
 * console d'amorçage : il n'existe aucune route publique d'inscription (§ 6.1 du
 * dossier de mission).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'core_user')]
#[ORM\UniqueConstraint(name: 'uniq_core_user_email', columns: ['email'])]
// Index partiel : la table est presque intégralement `actif`, indexer les deux ou trois
// lignes utiles suffit à servir la pastille « comptes à activer » du tableau de bord.
#[ORM\Index(
    name: 'idx_core_user_status',
    columns: ['status'],
    options: ['where' => "((status)::text = 'en_attente'::text)"],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /** Empreinte du mot de passe, jamais le mot de passe. 255 : les algorithmes de demain sont plus longs. */
    #[ORM\Column(length: 255)]
    private string $password = '';

    /**
     * `ROLE_USER` est ajouté à la lecture, jamais stocké.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSONB, options: ['default' => '[]'])]
    private array $roles = [];

    /** Nom affiché dans les files d'administration, sans aller chercher la personne rattachée. */
    #[ORM\Column(length: 120, options: ['default' => ''])]
    private string $displayName = '';

    #[ORM\Column(length: 16, enumType: UserStatus::class, options: ['default' => 'en_attente'])]
    private UserStatus $status = UserStatus::EnAttente;

    /**
     * Secret TOTP chiffré, pour la MFA administrateur. Colonne prévue dès maintenant :
     * ajouter une colonne plus tard est trivial, migrer des comptes existants vers un
     * second facteur ne l'est pas.
     */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $displayName = '')
    {
        $this->email = $email;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): self
    {
        $this->password = $hashedPassword;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    /**
     * Le secret et sa date d'activation se posent ensemble : la contrainte
     * `ck_core_user_totp` refuse un secret sans date.
     */
    public function activerTotp(string $secretChiffre, ?\DateTimeImmutable $le = null): self
    {
        $this->totpSecret = $secretChiffre;
        $this->totpEnabledAt = $le ?? new \DateTimeImmutable();

        return $this;
    }

    public function desactiverTotp(): self
    {
        $this->totpSecret = null;
        $this->totpEnabledAt = null;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;

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

    /**
     * Ce qui **invalide les sessions déjà ouvertes**.
     *
     * À chaque requête, `ContextListener` recharge l'utilisateur depuis la base et
     * compare le résultat à celui que porte la session. Sans cette méthode, la
     * comparaison de Symfony ne regarde que l'identifiant et les rôles : **changer le mot
     * de passe ne ferme aucune session**, et l'ancien détenteur reste connecté avec un
     * mot de passe qui n'existe plus. C'est le genre de détail qu'on ne voit qu'en le
     * cherchant, et il vide de son sens une réinitialisation faite en urgence.
     *
     * On compare donc l'empreinte, **et rien d'autre que l'identifiant**.
     *
     * > **Le statut n'est délibérément pas comparé ici**, et c'est un arbitrage, pas un
     * > oubli. L'y ajouter marche — les sessions d'un compte suspendu tombent — mais elles
     * > tombent *par le framework*, avant que
     * > {@see \App\Module\Core\EventSubscriber\AccountStatusSubscriber} n'ait pu répondre.
     * > Le titulaire reçoit alors `authentification_requise` au lieu de `compte_suspendu`,
     * > et n'apprend pas pourquoi il est dehors. Le code précis du § 15.2 vaut mieux qu'un
     * > refus muet : la suspension reste donc traitée par le souscripteur, qui la nomme.
     *
     * `hash_equals` plutôt que `===` : la comparaison porte sur des empreintes, et prendre
     * l'habitude du temps constant coûte ici exactement zéro.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        return $user->getUserIdentifier() === $this->getUserIdentifier()
            && hash_equals($user->getPassword(), $this->password);
    }
}
