<?php

declare(strict_types=1);

namespace App\Module\Noel\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\TooManyRequestsException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Service\ConnexionGoogle;
use App\Module\Core\Service\ProfilGoogle;
use App\Module\Noel\Contract\PortComptesPourNoel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * **L'inscription publique** du site de Noël — la seule d'heritage.
 *
 * Elle crée des comptes **en attente** : le compte se connecte, participe aux tirages de
 * Noël, et voit d'heritage ce que voit tout compte en attente (les défunts). L'identité
 * déclarée — nom de naissance, prénom, date de naissance, téléphone — part chez
 * `Membership` comme un membre « à rattacher » : l'administrateur le relie ensuite à sa
 * fiche, par le parcours habituel.
 *
 * **Sauf** quand cette identité désigne sans ambiguïté une fiche vivante et libre (même
 * nom, un prénom, la date au jour près) : `Membership` l'y rattache, et le compte naît
 * **actif**. C'est une décision du commanditaire, prise en connaissance de cause : qui
 * connaît le nom et la date de naissance d'un vivant peut ainsi prendre sa fiche. Les
 * administrateurs en sont prévenus et peuvent défaire le rattachement.
 *
 * Avec Google, le courriel et le `sub` viennent du **jeton d'inscription signé** rendu par
 * `POST /api/connexion/google`, jamais du corps de la requête.
 *
 * Cinq inscriptions par heure et par adresse IP, comme l'inscription sans fiche.
 */
final class InscriptionAuSiteDeNoel
{
    public const int LONGUEUR_MINIMALE_MOT_DE_PASSE = 12;

    public const int INSCRIPTIONS_PAR_IP = 5;

    private const int NOM_MAX = 120;

    private RateLimiterFactory $limiteur;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hacheur,
        private readonly ConnexionGoogle $google,
        private readonly PortComptesPourNoel $comptes,
        #[Autowire(service: 'cache.rate_limiter')]
        CacheItemPoolInterface $pool,
    ) {
        $this->limiteur = new RateLimiterFactory([
            'id' => 'noel_inscription_ip',
            'policy' => 'sliding_window',
            'limit' => self::INSCRIPTIONS_PAR_IP,
            'interval' => '1 hour',
        ], new CacheStorage($pool));
    }

    /** @param array<string, mixed> $corps */
    public function inscrire(array $corps, Request $requete, \DateTimeImmutable $aujourdhui = new \DateTimeImmutable('today')): User
    {
        $champs = [];
        $profil = null;

        if (\is_string($corps['googleJeton'] ?? null) && '' !== $corps['googleJeton']) {
            $profil = $this->google->lireLeJeton($corps['googleJeton']);
            if (null === $profil) {
                $champs['googleJeton'] = 'La connexion Google a expiré : recommence avec le bouton Google.';
            }
            $email = $profil?->email;
            $motDePasse = null;
        } else {
            $email = \is_string($corps['email'] ?? null) ? mb_strtolower(trim($corps['email'])) : '';
            if (false === filter_var($email, \FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
                $champs['email'] = 'Adresse e-mail invalide.';
            }
            $motDePasse = \is_string($corps['password'] ?? null) ? $corps['password'] : '';
            if (mb_strlen($motDePasse) < self::LONGUEUR_MINIMALE_MOT_DE_PASSE) {
                $champs['password'] = \sprintf('%d caractères au moins.', self::LONGUEUR_MINIMALE_MOT_DE_PASSE);
            } elseif (\strlen($motDePasse) > 4096) {
                $champs['password'] = 'Mot de passe trop long.';
            }
        }

        $nom = $this->nom($corps['nomDeNaissance'] ?? null, 'nomDeNaissance', $champs);
        $prenom = $this->nom($corps['prenom'] ?? null, 'prenom', $champs);
        $naissance = $this->naissance($corps['dateDeNaissance'] ?? null, $aujourdhui, $champs);
        [$telephone, $refus] = self::lireTelephone($corps['telephone'] ?? null);
        if (null !== $refus) {
            $champs['telephone'] = $refus;
        }

        if ([] !== $champs) {
            throw new ValidationException($champs);
        }
        \assert(\is_string($email) && null !== $nom && null !== $prenom && null !== $naissance);

        $limite = $this->limiteur->create($requete->getClientIp() ?? 'inconnue')->consume();
        if (!$limite->isAccepted()) {
            throw new TooManyRequestsException(max(1, $limite->getRetryAfter()->getTimestamp() - time()));
        }

        if (null !== $this->utilisateurs->findOneBy(['email' => $email])
            || (null !== $profil && null !== $this->utilisateurs->findOneBy(['googleSub' => $profil->sub]))) {
            throw new ConflictException('courriel_deja_pris', 'Un compte existe déjà pour cette adresse : connecte-toi.');
        }

        $compte = $this->nouveauCompte($email, $motDePasse, $profil, $telephone);

        $this->em->beginTransaction();
        try {
            $this->em->persist($compte);
            $this->em->flush();

            $id = $compte->getId();
            \assert(null !== $id);
            if ($this->comptes->declarerUnCompte($id, $email, $nom, $prenom, $naissance)) {
                $compte->setStatus(UserStatus::Actif);
            }

            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $echec) {
            $this->em->rollback();

            throw $echec;
        }

        return $compte;
    }

    /**
     * Le numéro tel que saisi, nettoyé : chiffres, `+`, espaces, points, tirets et
     * parenthèses, de 6 à 15 chiffres — la règle de l'inscription d'heritage
     * (`Membership\Service\ServiceDInscription::lireTelephone`).
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function lireTelephone(mixed $brut): array
    {
        if (null === $brut || (\is_string($brut) && '' === trim($brut))) {
            return [null, null];
        }
        if (!\is_string($brut) || 1 !== preg_match('/^[0-9+().\s-]+$/', trim($brut))) {
            return [null, 'Numéro de téléphone invalide.'];
        }
        $propre = trim((string) preg_replace('/\s+/', ' ', $brut));
        $chiffres = (string) preg_replace('/\D/', '', $propre);
        if (\strlen($chiffres) < 6 || \strlen($chiffres) > 15 || mb_strlen($propre) > 32) {
            return [null, 'Numéro de téléphone invalide.'];
        }

        return [$propre, null];
    }

    private function nouveauCompte(string $email, ?string $motDePasse, ?ProfilGoogle $profil, ?string $telephone): User
    {
        $compte = new User($email);
        $compte->setStatus(UserStatus::EnAttente)->setTelephone($telephone);

        if (null !== $profil) {
            // Pas de mot de passe : la chaîne vide n'est l'empreinte d'aucun mot de passe,
            // `json_login` refuse donc tout essai. Le compte se connecte avec Google.
            $compte->setGoogleSub($profil->sub)->setAvatarUrl($profil->photo);
        } else {
            \assert(null !== $motDePasse);
            $compte->setPassword($this->hacheur->hashPassword($compte, $motDePasse));
        }

        return $compte;
    }

    /** @param array<string, string> $champs */
    private function nom(mixed $brut, string $cle, array &$champs): ?string
    {
        $valeur = \is_string($brut) ? trim((string) preg_replace('/\s+/', ' ', $brut)) : '';

        if ('' === $valeur) {
            $champs[$cle] = 'Ce champ est requis.';

            return null;
        }
        if (mb_strlen($valeur) > self::NOM_MAX) {
            $champs[$cle] = \sprintf('%d caractères au plus.', self::NOM_MAX);

            return null;
        }

        return $valeur;
    }

    /** @param array<string, string> $champs */
    private function naissance(mixed $brut, \DateTimeImmutable $aujourdhui, array &$champs): ?\DateTimeImmutable
    {
        $date = \is_string($brut) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $brut) : false;

        if (false === $date || $date->format('Y-m-d') !== $brut || $date > $aujourdhui || (int) $date->format('Y') < 1900) {
            $champs['dateDeNaissance'] = 'Date de naissance attendue (AAAA-MM-JJ).';

            return null;
        }

        return $date;
    }
}
