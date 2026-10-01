<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les comptes vus par l'administrateur — § 10.1 du contrat d'API.
 *
 * Trois champs modifiables, tous facultatifs, appliqués ensemble : `roles`, `status`,
 * `displayName`.
 */
final class UserAdminService
{
    public const int PAR_PAGE_MAX = 100;

    public const int PAR_PAGE_DEFAUT = 25;

    /** `ROLE_USER` est accepté en entrée et ignoré : il est ajouté à la lecture, jamais stocké. */
    private const array ROLES_ACCEPTES = ['ROLE_USER', 'ROLE_ADMIN'];

    /**
     * « **Pas `en_attente`** — on ne remet pas un compte en attente, on le suspend »
     * (§ 10.1). Le régime « en attente » est celui d'un rattachement jamais établi ; y
     * revenir après coup serait une troisième histoire, avec un `mbr_member` déjà activé.
     */
    private const array STATUTS_ACCEPTES = ['actif', 'suspendu'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $utilisateurs,
        private readonly AnnuaireDesMembresInterface $annuaire,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
    }

    /**
     * @return array{elements: list<array<string, mixed>>, total: int, page: int, parPage: int}
     */
    public function lister(?string $statut, int $page, int $parPage): array
    {
        $page = max(1, $page);
        $parPage = min(self::PAR_PAGE_MAX, max(1, $parPage));

        $qb = $this->utilisateurs->createQueryBuilder('u');

        // Un statut inconnu n'est pas une erreur : c'est un filtre qui ne ramène rien.
        if (null !== $statut && '' !== $statut) {
            $qb->andWhere('u.status = :statut')->setParameter('statut', UserStatus::tryFrom($statut) ?? $statut);
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        /** @var list<User> $lignes */
        $lignes = $qb
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery()
            ->getResult();

        return [
            'elements' => array_map($this->normaliser(...), $lignes),
            'total' => $total,
            'page' => $page,
            'parPage' => $parPage,
        ];
    }

    /** @throws NotFoundException */
    public function trouver(int $id): User
    {
        return $this->utilisateurs->find($id) ?? throw new NotFoundException('Ce compte n’existe pas.');
    }

    /**
     * `PATCH /api/administration/utilisateurs/{id}`.
     *
     * @param array<string, mixed> $corps
     *
     * @throws NotFoundException|ValidationException|ConflictException
     */
    public function modifier(int $id, array $corps, User $administrateur): User
    {
        $compte = $this->trouver($id);

        $roles = \array_key_exists('roles', $corps) ? $this->rolesValides($corps['roles']) : null;
        $statut = \array_key_exists('status', $corps) ? $this->statutValide($corps['status']) : null;
        $nom = \array_key_exists('displayName', $corps) ? $this->nomValide($corps['displayName']) : null;

        // **Avant** toute écriture : `appliquerLesRoles()` journalise, donc vide l'unité de
        // travail — un `409` levé après lui laisserait le changement de rôle écrit.
        if (UserStatus::Actif === $statut && $statut !== $compte->getStatus()) {
            $this->refuserUnActifSansRattachementEtabli($compte);
        }

        if (null !== $roles) {
            $this->appliquerLesRoles($compte, $roles, $administrateur);
        }
        if (null !== $statut) {
            $this->appliquerLeStatut($compte, $statut, $administrateur);
        }
        if (null !== $nom) {
            $compte->setDisplayName($nom);
        }

        $compte->toucher();
        $this->em->flush();

        return $compte;
    }

    /**
     * `POST /api/administration/utilisateurs/{id}/mot-de-passe`.
     *
     * ## Pourquoi cette route existe
     *
     * Parce que son absence rendait le journal aveugle. Tant qu'aucune route ne savait
     * réinitialiser un mot de passe, toute réinitialisation passait **par du SQL** — donc
     * hors audit et sans toucher `updated_at`. Deux écrasements ont ainsi été invisibles.
     * *Quand la seule façon de faire une chose légitime est de contourner l'application,
     * les gens contournent l'application, et l'audit devient aveugle sur l'opération la
     * plus sensible du système.*
     *
     * ## Le mot de passe est rendu **une fois**, et n'est écrit nulle part
     *
     * Ni en base — seule l'empreinte l'est —, ni au journal d'audit, ni dans les traces
     * du serveur. Il ne sort que dans le corps de cette réponse, et il n'existe plus
     * ensuite : l'administrateur le transmet par un canal de son choix, comme le lien
     * d'invitation du § 3.2, qui suit exactement la même règle — « le seul moment où le
     * jeton existe ».
     *
     * L'autre option était un lien de réinitialisation par courriel. Écartée pour cette
     * livraison : elle demande une entité de jeton, un TTL, une révocation et un SMTP —
     * or « aucun SMTP n'est configuré » est un état normal du produit (§ 6.1), et une
     * réinitialisation qui dépend du courriel échoue précisément quand on en a besoin.
     *
     * ## Les sessions ouvertes tombent
     *
     * Par {@see User::isEqualTo()}, comparée à chaque requête. Sans cela, l'ancien
     * détenteur resterait connecté avec un mot de passe qui n'existe plus — une
     * réinitialisation faite en urgence ne servirait à rien.
     *
     * @return string le mot de passe en clair, à ne journaliser nulle part
     *
     * @throws NotFoundException|ConflictException
     */
    public function reinitialiserLeMotDePasse(int $id, User $administrateur): string
    {
        $compte = $this->trouver($id);
        $enClair = $this->motDePasseAleatoire();

        $compte->setPassword($this->hacheur->hashPassword($compte, $enClair))->toucher();
        $this->em->flush();

        // `password` n'apparaît pas dans `changes` : ni l'ancienne empreinte, ni la
        // nouvelle, ni a fortiori le clair. Ce qui compte au journal est **qui** a
        // réinitialisé **quoi**, et c'est tout ce qui y figure.
        $this->audit->enregistrer(
            AuditAction::MotDePasseReinitialise,
            'Core\\User',
            (string) $compte->getId(),
            $compte->getEmail(),
            // Une réinitialisation de son propre compte est licite — mais elle doit se
            // lire d'un coup d'œil dans le journal, sans recouper deux colonnes.
            ['soiMeme' => $compte->getId() === $administrateur->getId()],
            $administrateur,
        );

        return $enClair;
    }

    /**
     * Un mot de passe que personne n'a choisi.
     *
     * `random_bytes` et non `rand()` : c'est un secret. Base64 URL-safe pour qu'il se
     * transmette et se retape sans ambiguïté sur les caractères `+` et `/`.
     */
    private function motDePasseAleatoire(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    public function normaliser(User $compte): array
    {
        $id = $compte->getId();
        $displayName = $compte->getDisplayName();

        return [
            'id' => $id,
            'email' => $compte->getEmail(),
            // Comme dans la charge de session : sans rattachement validé, le nom affiché
            // retombe sur le courriel et n'est **jamais** renseigné depuis
            // `mbr_member.person_label` (§ 2.2).
            'displayName' => '' !== $displayName ? $displayName : $compte->getEmail(),
            'roles' => $compte->getRoles(),
            'status' => $compte->getStatus()->value,
            'totpEnabledAt' => $compte->getTotpEnabledAt()?->format(\DateTimeInterface::ATOM),
            'lastLoginAt' => $compte->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $compte->getCreatedAt()->format(\DateTimeInterface::ATOM),
            // Rendu tel quel par `Membership` ; `null` tant qu'il n'est pas branché.
            'membre' => null !== $id ? $this->annuaire->membreDuCompte($id) : null,
        ];
    }

    /**
     * @param list<string> $roles
     *
     * @throws ConflictException `409 dernier_administrateur`
     */
    private function appliquerLesRoles(User $compte, array $roles, User $administrateur): void
    {
        $avant = $compte->getRoles();
        $etaitAdmin = \in_array('ROLE_ADMIN', $avant, true);
        $devientAdmin = \in_array('ROLE_ADMIN', $roles, true);

        if ($etaitAdmin && !$devientAdmin && $this->estLeDernierAdministrateur($compte)) {
            // « Il n'y a qu'un administrateur (ADR 0004) ; se verrouiller dehors est un
            // incident sans porte de secours autre que la console. »
            $motif = 'Ce compte est le dernier administrateur : lui retirer le rôle fermerait l’administration à tout le monde.';

            throw new ConflictException('dernier_administrateur', $motif);
        }

        // `ROLE_USER` n'est jamais stocké : il est ajouté à la lecture par l'entité.
        $compte->setRoles($devientAdmin ? ['ROLE_ADMIN'] : []);

        if ($etaitAdmin === $devientAdmin) {
            return;
        }

        $this->audit->enregistrer(
            AuditAction::RoleModifie,
            'Core\\User',
            (string) $compte->getId(),
            $compte->getEmail(),
            ['roles' => ['avant' => $avant, 'apres' => $compte->getRoles()]],
            $administrateur,
        );
    }

    /**
     * **On ne rend pas `actif` un compte dont le rattachement n'est pas établi.**
     *
     * `RegimeVoter` décide du régime « membre » sur `core_user.status` **seul** — c'est
     * `Membership\Service\ServiceDesMembres` qui tient les deux statuts ensemble, et c'est
     * le seul endroit où ils passent `actif` (ADR 0002 : le voteur n'interroge pas
     * `Membership`). Ce `PATCH` était la brèche : « Réactiver » un compte **refusé** (membre
     * `suspendu`, sans fiche, nature `a_rattacher`) ou un compte en attente qu'on avait
     * suspendu le rendait `actif` côté `core_user` seulement. Le résultat : un compte au
     * cercle **vide** (la fabrique du lecteur ne voit pas de membre actif) mais admis comme
     * membre pour écrire — démarches, tirages.
     *
     * La règle : si le compte a un membre, ce membre doit être `actif` **et** avoir une
     * fiche. Sans membre du tout — l'administrateur d'amorçage —, rien ne change. Pour un
     * compte en attente, le bon geste est l'activation (`POST /api/administration/membres/
     * {id}/activer`, ou l'onglet « À rattacher ») : elle passe les deux statuts ensemble.
     * Pour un compte refusé, il n'y a pas de retour : sa déclaration est purgée, la personne
     * se réinscrit.
     *
     * @throws ConflictException `409 rattachement_non_etabli`
     */
    private function refuserUnActifSansRattachementEtabli(User $compte): void
    {
        $id = $compte->getId();
        $membre = null !== $id ? $this->annuaire->membreDuCompte($id) : null;

        if (null === $membre) {
            return;
        }

        $statutDuMembre = $membre['status'] ?? null;

        if ('actif' === $statutDuMembre && \is_int($membre['personId'] ?? null)) {
            return;
        }

        $motif = 'en_attente' === $statutDuMembre
            ? 'Ce compte attend la validation de son rattachement : activez-le depuis la liste des comptes ou l’onglet « À rattacher », qui rendent les deux statuts actifs ensemble.'
            : 'L’inscription de ce compte a été refusée : il n’a pas de fiche validée et ne peut pas redevenir actif. La personne peut se réinscrire.';

        throw new ConflictException('rattachement_non_etabli', $motif, ['memberId' => $membre['id'] ?? null]);
    }

    private function appliquerLeStatut(User $compte, UserStatus $statut, User $administrateur): void
    {
        $avant = $compte->getStatus();

        if ($avant === $statut) {
            return;
        }

        $compte->setStatus($statut);

        // Une **suspension** est une décision d'administration et se journalise comme
        // telle. Une réactivation n'a pas d'action dédiée dans `AuditAction` : elle reste
        // couverte par la ligne `modification` automatique, qui porte le diff
        // `status: {avant, apres}`. La voie mécanique n'a donc pas de trou.
        if (UserStatus::Suspendu === $statut) {
            $this->audit->enregistrer(
                AuditAction::CompteSuspendu,
                'Core\\User',
                (string) $compte->getId(),
                $compte->getEmail(),
                ['status' => ['avant' => $avant->value, 'apres' => $statut->value]],
                $administrateur,
            );
        }

        if (UserStatus::Actif === $statut && UserStatus::Suspendu === $avant) {
            $id = $compte->getId();
            if (null !== $id) {
                $this->notifications->notifier(
                    $id,
                    NotificationKind::CompteEnAttente,
                    'Votre accès a été rétabli.',
                    'Un administrateur a rétabli votre accès à l’arbre. Rechargez la page pour en profiter.',
                );
            }
        }
    }

    /**
     * Le dernier administrateur **actif**. Un compte suspendu qui porte `ROLE_ADMIN` ne
     * compte pas : il ne s'authentifie pas, donc il n'ouvre aucune porte.
     */
    private function estLeDernierAdministrateur(User $compte): bool
    {
        foreach ($this->notifications->administrateurs() as $administrateur) {
            if ($administrateur->getId() !== $compte->getId()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    private function rolesValides(mixed $valeur): array
    {
        if (!\is_array($valeur)) {
            throw new ValidationException(['roles' => 'Un tableau de rôles est attendu.']);
        }

        $roles = [];
        foreach ($valeur as $role) {
            if (!\is_string($role) || !\in_array($role, self::ROLES_ACCEPTES, true)) {
                throw new ValidationException(['roles' => 'Rôles acceptés : ROLE_USER, ROLE_ADMIN.']);
            }
            $roles[] = $role;
        }

        return $roles;
    }

    /** @throws ValidationException */
    private function statutValide(mixed $valeur): UserStatus
    {
        if (!\is_string($valeur) || !\in_array($valeur, self::STATUTS_ACCEPTES, true)) {
            throw new ValidationException(['status' => 'Statuts acceptés : actif, suspendu.']);
        }

        return UserStatus::from($valeur);
    }

    /** @throws ValidationException */
    private function nomValide(mixed $valeur): string
    {
        if (!\is_string($valeur) || '' === trim($valeur) || mb_strlen($valeur) > 120) {
            throw new ValidationException(['displayName' => 'De 1 à 120 caractères.']);
        }

        return trim($valeur);
    }
}
