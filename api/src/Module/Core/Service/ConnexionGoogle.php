<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * La connexion avec Google (Google Identity Services, flux par **ID token**).
 *
 * Le front reçoit de Google un ID token signé (`credential`) et nous le passe. On ne le
 * décode pas nous-mêmes : le point `tokeninfo` de Google vérifie la signature et
 * l'expiration, et l'on contrôle ensuite ce qui nous regarde — l'audience (notre client
 * OAuth, `GOOGLE_CLIENT_ID`), l'émetteur, et un courriel **vérifié**. Sans ce dernier, un
 * compte Google créé sur l'adresse d'un autre ouvrirait la session de cet autre.
 *
 * Deux portes, un même contrôle ({@see self::profilDe()}) :
 *
 * - **le bouton Google** (noel2026) : Google Identity Services rend l'ID token au
 *   navigateur ; il faut l'origine du site dans les « origines JavaScript autorisées » ;
 * - **la redirection** (heritage) : heritage ne charge **aucun script tiers** (CSP
 *   `script-src 'self'`), donc on passe par le flux « code d'autorisation » — un lien vers
 *   Google, un retour sur `/api/connexion/google/retour`, et l'échange du code contre
 *   l'ID token côté serveur, avec le secret du client. Il faut cette URI de retour dans
 *   les « URI de redirection autorisées ». L'ID token vient alors directement de Google,
 *   par TLS : sa signature n'a pas à être revérifiée (OpenID Connect, § 3.1.3.7).
 *
 * ## Le jeton d'inscription
 *
 * Un profil Google sans compte ne se connecte pas : le site de Noël propose alors de
 * s'inscrire, pré-rempli. Le profil vérifié repart vers le front dans un **jeton signé**
 * (HMAC du secret de l'application, 30 minutes) que l'inscription rend ensuite : le
 * courriel et le `sub` ne sont jamais repris tels que le navigateur les renverrait.
 */
final class ConnexionGoogle
{
    private const string TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';

    private const string AUTORISATION = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string JETON = 'https://oauth2.googleapis.com/token';

    public const string CHEMIN_DE_RETOUR = '/api/connexion/google/retour';

    private const array EMETTEURS = ['accounts.google.com', 'https://accounts.google.com'];

    private const int DUREE_DU_JETON = 1800;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'default::GOOGLE_CLIENT_ID')]
        private readonly ?string $clientId,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire(env: 'default::GOOGLE_CLIENT_SECRET')]
        private readonly ?string $clientSecret = null,
        private readonly ?UrlPublique $url = null,
    ) {
    }

    /** La redirection n'est possible qu'avec le secret du client et l'adresse publique. */
    public function redirectionPossible(): bool
    {
        return null !== $this->clientId() && null !== $this->url && '' !== trim((string) $this->clientSecret);
    }

    /** L'adresse Google où envoyer le navigateur, pour l'état `$etat` (anti-CSRF). */
    public function adresseDAutorisation(string $etat): string
    {
        return self::AUTORISATION.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->adresseDeRetour(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $etat,
            'prompt' => 'select_account',
        ]);
    }

    /** Le code d'autorisation échangé contre le profil, ou `null`. */
    public function echanger(string $code): ?ProfilGoogle
    {
        $clientId = $this->clientId();

        if (!$this->redirectionPossible() || null === $clientId || '' === $code || \strlen($code) > 2048) {
            return null;
        }

        try {
            $reponse = $this->http->request('POST', self::JETON, ['body' => [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => trim((string) $this->clientSecret),
                'redirect_uri' => $this->adresseDeRetour(),
                'grant_type' => 'authorization_code',
            ], 'timeout' => 8]);

            if (200 !== $reponse->getStatusCode()) {
                return null;
            }

            $idToken = $reponse->toArray(false)['id_token'] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }

        $parties = \is_string($idToken) ? explode('.', $idToken) : [];
        $revendications = 3 === \count($parties) ? json_decode((string) base64_decode(strtr($parties[1], '-_', '+/'), true), true) : null;

        return \is_array($revendications) ? $this->profilDe($revendications, $clientId) : null;
    }

    private function adresseDeRetour(): string
    {
        \assert(null !== $this->url);

        return $this->url->base().self::CHEMIN_DE_RETOUR;
    }

    public function clientId(): ?string
    {
        return null !== $this->clientId && '' !== trim($this->clientId) ? trim($this->clientId) : null;
    }

    /** Le profil du titulaire d'un ID token valide pour notre client, ou `null`. */
    public function verifier(string $credential): ?ProfilGoogle
    {
        $clientId = $this->clientId();

        if (null === $clientId || '' === $credential || \strlen($credential) > 4096) {
            return null;
        }

        try {
            $reponse = $this->http->request('GET', self::TOKENINFO, ['query' => ['id_token' => $credential], 'timeout' => 8]);

            if (200 !== $reponse->getStatusCode()) {
                return null;
            }

            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface) {
            return null;
        }

        return $this->profilDe($donnees, $clientId);
    }

    /**
     * @param array<mixed> $donnees les revendications de l'ID token
     */
    public function profilDe(array $donnees, string $clientId): ?ProfilGoogle
    {
        $verifie = $donnees['email_verified'] ?? false;
        $sub = $donnees['sub'] ?? null;
        $email = $donnees['email'] ?? null;

        if (
            ($donnees['aud'] ?? null) !== $clientId
            || !\in_array($donnees['iss'] ?? null, self::EMETTEURS, true)
            || (true !== $verifie && 'true' !== $verifie)
            || (int) ($donnees['exp'] ?? 0) < time()
            || !\is_string($sub) || '' === $sub || \strlen($sub) > 64
            || !\is_string($email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)
        ) {
            return null;
        }

        $texte = static fn (mixed $v, int $max): ?string => \is_string($v) && '' !== trim($v) ? mb_substr(trim($v), 0, $max) : null;
        $photo = $texte($donnees['picture'] ?? null, 512);

        return new ProfilGoogle(
            $sub,
            mb_strtolower($email),
            $texte($donnees['given_name'] ?? null, 120),
            $texte($donnees['family_name'] ?? null, 120),
            null !== $photo && str_starts_with($photo, 'https://') ? $photo : null,
        );
    }

    public function jetonDInscription(ProfilGoogle $profil, ?int $maintenant = null): string
    {
        $charge = self::base64url((string) json_encode(
            [...$profil->enTableau(), 'exp' => ($maintenant ?? time()) + self::DUREE_DU_JETON],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        ));

        return $charge.'.'.self::base64url(hash_hmac('sha256', $charge, $this->cle(), true));
    }

    /** Le profil d'un jeton d'inscription intact et non expiré, ou `null`. */
    public function lireLeJeton(string $jeton, ?int $maintenant = null): ?ProfilGoogle
    {
        $parties = explode('.', $jeton);

        if (2 !== \count($parties)) {
            return null;
        }

        [$charge, $signature] = $parties;
        $attendue = self::base64url(hash_hmac('sha256', $charge, $this->cle(), true));

        if (!hash_equals($attendue, $signature)) {
            return null;
        }

        $donnees = json_decode((string) base64_decode(strtr($charge, '-_', '+/'), true), true);

        if (!\is_array($donnees) || (int) ($donnees['exp'] ?? 0) < ($maintenant ?? time())
            || !\is_string($donnees['sub'] ?? null) || !\is_string($donnees['email'] ?? null)) {
            return null;
        }

        $chaine = static fn (mixed $v): ?string => \is_string($v) ? $v : null;

        return new ProfilGoogle($donnees['sub'], $donnees['email'], $chaine($donnees['prenom'] ?? null), $chaine($donnees['nom'] ?? null), $chaine($donnees['photo'] ?? null));
    }

    private function cle(): string
    {
        return hash('sha256', 'connexion-google|'.$this->secret, true);
    }

    private static function base64url(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }
}
