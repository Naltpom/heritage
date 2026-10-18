<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Http\ErrorEnvelope;
use App\Module\Core\Repository\UserRepository;
use App\Module\Core\Security\GoogleAuthenticator;
use App\Module\Core\Security\GoogleBadge;
use App\Module\Core\Service\ConnexionGoogle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La connexion avec Google.
 *
 * ```
 * GET  /api/connexion/google            configuration publique : {clientId, redirection}
 * POST /api/connexion/google            ID token du bouton Google ({@see GoogleAuthenticator})
 * GET  /api/connexion/google/debut      redirection vers Google (heritage, sans script tiers)
 * GET  /api/connexion/google/retour     retour de Google : session ouverte, renvoi au front
 * ```
 *
 * Le retour ne rend jamais de JSON : c'est une navigation. Il renvoie vers `/connexion`
 * avec `?google=inconnu` (aucun compte heritage pour ce compte Google) ou
 * `?google=echec`, sinon vers la suite demandée — un chemin interne seulement.
 */
final class GoogleController extends AbstractController
{
    private const string CLE_DE_SESSION = 'connexion_google';

    public function __construct(private readonly ConnexionGoogle $google)
    {
    }

    /** L'identifiant du client OAuth, que le bouton Google du front initialise. `null` : désactivé. */
    #[Route(GoogleAuthenticator::CHEMIN, name: 'core_connexion_google_config', methods: ['GET'])]
    public function configuration(): JsonResponse
    {
        return new JsonResponse(['clientId' => $this->google->clientId(), 'redirection' => $this->google->redirectionPossible()]);
    }

    /**
     * Jamais atteinte quand l'authentification aboutit ou échoue : l'authenticator répond
     * avant. Elle n'existe que pour que le routeur ne rende pas `404` avant le pare-feu.
     */
    #[Route(GoogleAuthenticator::CHEMIN, name: 'core_connexion_google', methods: ['POST'])]
    public function connexion(): JsonResponse
    {
        return ErrorEnvelope::response(Response::HTTP_UNAUTHORIZED, 'google_refuse', 'La connexion avec Google a échoué. Réessayez.');
    }

    #[Route(GoogleAuthenticator::CHEMIN.'/debut', name: 'core_connexion_google_debut', methods: ['GET'])]
    public function debut(Request $requete): Response
    {
        if (!$this->google->redirectionPossible()) {
            return new RedirectResponse('/connexion?google=echec');
        }

        $etat = bin2hex(random_bytes(24));
        $requete->getSession()->set(self::CLE_DE_SESSION, ['etat' => $etat, 'suite' => self::suiteInterne($requete->query->get('suite'))]);

        return new RedirectResponse($this->google->adresseDAutorisation($etat));
    }

    #[Route(ConnexionGoogle::CHEMIN_DE_RETOUR, name: 'core_connexion_google_retour', methods: ['GET'])]
    public function retour(Request $requete, UserRepository $utilisateurs, Security $securite, EntityManagerInterface $em): Response
    {
        $session = $requete->getSession();
        $attendu = $session->get(self::CLE_DE_SESSION);
        $session->remove(self::CLE_DE_SESSION);

        $etat = $requete->query->get('state');
        $code = $requete->query->get('code');

        if (!\is_array($attendu) || !\is_string($attendu['etat'] ?? null) || !\is_string($etat) || !hash_equals($attendu['etat'], $etat) || !\is_string($code)) {
            return new RedirectResponse('/connexion?google=echec');
        }

        $profil = $this->google->echanger($code);
        if (null === $profil) {
            return new RedirectResponse('/connexion?google=echec');
        }

        $compte = $utilisateurs->findOneBy(['googleSub' => $profil->sub]) ?? $utilisateurs->findOneBy(['email' => $profil->email]);
        if (!$compte instanceof User) {
            return new RedirectResponse('/connexion?google=inconnu');
        }
        if (UserStatus::Suspendu === $compte->getStatus()) {
            return new RedirectResponse('/connexion?google=suspendu');
        }

        $compte->setGoogleSub($compte->getGoogleSub() ?? $profil->sub);
        if (null === $compte->getAvatarUrl()) {
            $compte->setAvatarUrl($profil->photo);
        }
        $compte->setLastLoginAt(new \DateTimeImmutable());
        $em->flush();

        $securite->login($compte, 'json_login', badges: [new GoogleBadge()]);

        $suite = \is_string($attendu['suite'] ?? null) ? $attendu['suite'] : '/';

        return new RedirectResponse($suite);
    }

    /** Un chemin du site, jamais une autre origine : `/x`, mais ni `//x` ni `/\x`. */
    private static function suiteInterne(mixed $suite): string
    {
        return \is_string($suite) && 1 === preg_match('#^/(?![/\\\\])[^\s]*$#', $suite) && \strlen($suite) <= 512 ? $suite : '/';
    }
}
