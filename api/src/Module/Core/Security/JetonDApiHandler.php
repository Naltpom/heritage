<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Entity\JetonDApi;
use App\Module\Core\Entity\User;
use App\Module\Core\Repository\JetonDApiRepository;
use App\Module\Core\Service\GenerateurDeJetonDApi;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * `Authorization: Bearer hrt_…` → le compte du jeton (ADR 0021 § 3 et § 6, § 1.6 du
 * contrat d'API). Le `token_handler` du pare-feu `api_jetons`.
 *
 * ## Un seul refus
 *
 * Mal formé, inconnu, expiré ou révoqué : la même `BadCredentialsException`, que
 * {@see RefusDuJetonDApi} rend en `401 authentification_requise`, le corps du point
 * d'entrée au caractère près. L'appelant n'apprend pas lequel des quatre — savoir qu'un
 * jeton a existé, ou qu'il vient d'expirer, c'est savoir qu'il vaut la peine d'en voler un
 * autre au même endroit. Le compte suspendu rejoint ce refus plus loin, par le
 * `user_checker` (voir {@see RefusDuJetonDApi}).
 *
 * Seule la forme est jugée avant la base, pour ne pas l'interroger sur n'importe quoi : la
 * forme d'un jeton est publique (`hrt_` et 43 caractères), un refus plus rapide n'apprend
 * rien que la documentation ne dise.
 *
 * ## Aucune comparaison de secret en PHP
 *
 * Le jeton est retrouvé par `WHERE token_hash = ?` sur l'index unique, avec l'empreinte
 * sha256 du jeton présenté : aucune chaîne secrète n'est comparée ici, donc rien n'est à
 * rendre à temps constant. Une mesure du temps de la recherche renseignerait au mieux sur
 * l'empreinte, et une empreinte ne se remonte pas jusqu'au jeton
 * ({@see GenerateurDeJetonDApi}, « Pourquoi `hash_equals` n'apparaît nulle part »).
 *
 * ## Le dernier usage, au plus toutes les cinq minutes
 *
 * `last_used_at` et `last_used_ip` disent d'où sert un jeton ; ils n'ont pas besoin de le
 * dire à la seconde. Les écrire à chaque requête ferait d'une lecture une écriture, et
 * d'un agent qui lit l'arbre deux fois par seconde un `UPDATE` deux fois par seconde. Le
 * journal d'audit, lui, ne voit pas ces écritures : ce sont des champs **techniques** de
 * `AuditDoctrineSubscriber`, dont un diff réduit à eux ne laisse aucune ligne.
 */
final readonly class JetonDApiHandler implements AccessTokenHandlerInterface
{
    /** L'écart minimal, en secondes, entre deux écritures du dernier usage. */
    public const int INTERVALLE_DU_DERNIER_USAGE = 300;

    public function __construct(
        private JetonDApiRepository $jetons,
        private GenerateurDeJetonDApi $generateur,
        private EntityManagerInterface $em,
        private RequestStack $requetes,
        private ClockInterface $horloge,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        if (!$this->generateur->formeValide($accessToken)) {
            throw self::refus();
        }

        $jeton = $this->jetons->parEmpreinte($this->generateur->empreinte($accessToken));
        $maintenant = $this->horloge->now();

        if (null === $jeton || !$jeton->estUtilisable($maintenant)) {
            throw self::refus();
        }

        $this->noterUsage($jeton, $maintenant);

        // Le compte vient de la ligne du jeton, pas d'une seconde recherche par courriel :
        // c'est la clé étrangère qui dit à qui est le jeton. Le fournisseur du pare-feu
        // reste celui de `main` (`entity_utilisateurs`), et le `user_checker` passe ensuite
        // sur ce compte comme sur tout autre.
        $utilisateur = $jeton->getUser();

        return new UserBadge(
            $utilisateur->getUserIdentifier(),
            static fn (): User => $utilisateur,
            JetonDApiAuthentifie::duJeton($jeton)->attributs(),
        );
    }

    /** Voir l'en-tête : « Le dernier usage, au plus toutes les cinq minutes ». */
    private function noterUsage(JetonDApi $jeton, \DateTimeImmutable $maintenant): void
    {
        $dernier = $jeton->getLastUsedAt();

        if (null !== $dernier && $maintenant->getTimestamp() - $dernier->getTimestamp() < self::INTERVALLE_DU_DERNIER_USAGE) {
            return;
        }

        $jeton->noterUsage($maintenant, $this->requetes->getMainRequest()?->getClientIp());
        // Rien d'autre n'est en attente dans l'unité de travail : on est dans le pare-feu,
        // avant tout contrôleur.
        $this->em->flush();
    }

    /**
     * Le message est celui de Symfony et ne sort jamais : {@see RefusDuJetonDApi} rend le
     * corps du point d'entrée, quel que soit le motif.
     */
    private static function refus(): BadCredentialsException
    {
        return new BadCredentialsException('Invalid credentials.');
    }
}
