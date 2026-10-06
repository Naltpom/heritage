<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le compte administrateur du commanditaire, **recréé depuis l'environnement**.
 *
 * ## Pourquoi il ne suffit pas de le créer une fois à la main
 *
 * `doctrine:fixtures:load` **purge la base** (voir l'avertissement de
 * `config/bundles.php`), et un réimport GEDCOM — supprimé depuis, ADR 0017 — remettait
 * `core_user` à zéro. Un compte créé à la main n'a survécu ni à l'un ni à l'autre — il a
 * déjà été perdu deux fois. Un compte dont la
 * définition vit dans l'environnement se repose en une commande, sans que personne ait à
 * se souvenir du mot de passe choisi ce jour-là.
 *
 * ## Les trois règles de ce service
 *
 * 1. **Idempotent.** Si le compte existe, on ne le duplique pas.
 * 2. **On n'écrase jamais un mot de passe existant.** C'est la règle qui a coûté cher :
 *    un amorçage qui réécrit le mot de passe à chaque exécution invalide celui que le
 *    titulaire a pu changer entre-temps, et le lui reprend sans le dire. On complète un
 *    compte incomplet — un compte sans empreinte, laissé par une création interrompue —
 *    mais on ne touche pas à un compte en état de marche.
 * 3. **Sans les variables, on ne crée rien, et on le dit.** Pas de compte par défaut :
 *    un administrateur par convention est un administrateur que personne n'a décidé, et
 *    un mot de passe par convention est un mot de passe public.
 *
 * Le mot de passe vit dans `api/.env.local`, **ignoré par Git**. Surtout pas dans
 * `api/.env`, qui est suivi : un secret y entrerait dans l'historique du dépôt, d'où il
 * ne sort plus.
 */
final class AmorcageDuCompteAdministrateur
{
    /** § 6.1 du dossier de mission — la même borne que le parcours d'inscription. */
    public const int LONGUEUR_MINIMALE = 12;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hacheur,
        #[Autowire(env: 'default::HERITAGE_ADMIN_EMAIL')]
        private readonly ?string $courriel = null,
        #[Autowire(env: 'default::HERITAGE_ADMIN_PASSWORD')]
        private readonly ?string $motDePasse = null,
        #[Autowire(env: 'default::HERITAGE_ADMIN_NOM')]
        private readonly ?string $nomAffiche = null,
    ) {
    }

    /**
     * Pose le compte si l'environnement le décrit, et rend ce qui a été fait — en clair,
     * pour que l'appelant l'affiche plutôt que de laisser croire à un succès muet.
     *
     * @return array{fait: bool, motif: string, utilisateur: User|null}
     */
    public function amorcer(): array
    {
        if ('' === trim((string) $this->courriel) || '' === (string) $this->motDePasse) {
            return [
                'fait' => false,
                'motif' => 'HERITAGE_ADMIN_EMAIL ou HERITAGE_ADMIN_PASSWORD absent : aucun compte créé. Renseigner api/.env.local.',
                'utilisateur' => null,
            ];
        }

        if (mb_strlen((string) $this->motDePasse) < self::LONGUEUR_MINIMALE) {
            return [
                'fait' => false,
                'motif' => \sprintf('HERITAGE_ADMIN_PASSWORD fait moins de %d caractères : aucun compte créé.', self::LONGUEUR_MINIMALE),
                'utilisateur' => null,
            ];
        }

        $courriel = trim((string) $this->courriel);
        $existant = $this->utilisateurs->findOneBy(['email' => $courriel]);

        if (null !== $existant) {
            return $this->completerSiNecessaire($existant);
        }

        $administrateur = new User($courriel, trim((string) $this->nomAffiche));
        $administrateur
            ->setRoles(['ROLE_ADMIN'])
            ->setStatus(UserStatus::Actif)
            ->setPassword($this->hacheur->hashPassword($administrateur, (string) $this->motDePasse));

        $this->em->persist($administrateur);
        $this->em->flush();

        return ['fait' => true, 'motif' => 'Compte administrateur créé.', 'utilisateur' => $administrateur];
    }

    /**
     * Le compte est là : on le laisse tel quel, à deux exceptions près.
     *
     * Le **rôle** et le **statut** sont réalignés : un amorçage dont le compte a perdu
     * `ROLE_ADMIN` — suspendu par erreur, rétrogradé par un `PATCH` malheureux — ne
     * rendrait pas l'administration au commanditaire, et la porte de secours du
     * § 10.1 (`409 dernier_administrateur`) ne s'ouvre que depuis la console. Le **mot
     * de passe**, lui, n'est jamais retouché : il appartient au titulaire.
     *
     * @return array{fait: bool, motif: string, utilisateur: User}
     */
    private function completerSiNecessaire(User $existant): array
    {
        $corrections = [];

        if (!\in_array('ROLE_ADMIN', $existant->getRoles(), true)) {
            $existant->setRoles(['ROLE_ADMIN']);
            $corrections[] = 'rôle administrateur rétabli';
        }
        if (UserStatus::Actif !== $existant->getStatus()) {
            $existant->setStatus(UserStatus::Actif);
            $corrections[] = 'compte réactivé';
        }
        // Une empreinte vide n'est pas un mot de passe qu'on respecte : c'est une
        // création interrompue. On la complète, et c'est le seul cas.
        if ('' === $existant->getPassword()) {
            $existant->setPassword($this->hacheur->hashPassword($existant, (string) $this->motDePasse));
            $corrections[] = 'mot de passe posé (le compte n’en avait aucun)';
        }

        if ([] === $corrections) {
            return ['fait' => false, 'motif' => 'Compte déjà en place, rien à faire.', 'utilisateur' => $existant];
        }

        $existant->toucher();
        $this->em->flush();

        return ['fait' => true, 'motif' => 'Compte existant, '.implode(', ', $corrections).'.', 'utilisateur' => $existant];
    }
}
