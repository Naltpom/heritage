<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Entity\PushSubscription;
use App\Module\Core\Entity\User;
use App\Module\Core\Http\ErrorEnvelope;
use App\Module\Core\Service\AbonnementsPush;
use App\Module\Core\Service\ClesVapid;
use App\Module\Core\Service\Incarnation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `/api/push` — les notifications sur le téléphone, application fermée (Web Push).
 *
 * Trois routes, et le front n'a besoin de rien d'autre :
 *
 *  - `GET /api/push/cle` : la clé publique VAPID, que `PushManager.subscribe()` exige.
 *    **Elle ne change jamais d'un déploiement à l'autre** (voir {@see ClesVapid}) — c'est
 *    toute la différence avec la version qui « cassait à chaque mise en prod » ;
 *  - `PUT /api/push/abonnement` : inscrire (ou réinscrire) cet appareil ;
 *  - `DELETE /api/push/abonnement` : le retirer.
 *
 * Accès `ATTENTE`, comme `/api/notifications` et pour la même raison : un compte en
 * attente reçoit la notification de son activation, et c'est justement celle qu'il a le
 * plus de chances de manquer application fermée. Aucune règle dédiée dans
 * `security.yaml` : le filet `^/api → ROLE_USER` exige la session, et
 * `AccountStatusSubscriber` ferme celle d'un compte suspendu.
 *
 * ## `400`, et non `422`, sur un corps invalide
 *
 * Le reste de l'API rend `422 donnees_invalides` (§ 15.2). Ces routes rendent **`400`**
 * avec la même enveloppe et le même code, parce que le contrat Web Push passé au front
 * le fixe ainsi : le corps n'est pas une saisie humaine à corriger champ par champ, c'est
 * l'objet que le navigateur a produit — s'il est invalide, c'est la requête qui l'est.
 */
final class PushController extends AbstractController
{
    /** Clé publique P-256 non compressée : 0x04 ‖ X ‖ Y. */
    private const int P256DH_OCTETS = 65;

    /** Le secret `auth` de la RFC 8291 fait 16 octets. */
    private const int AUTH_OCTETS = 16;

    /**
     * Les services push des navigateurs, et eux seuls (suffixes d'hôte).
     *
     * Sans cette liste, `https://` ne suffisait pas : un compte connecté — même en
     * attente — pouvait enregistrer `https://127.0.0.1/…` ou `https://api/…`, et le
     * serveur y aurait POSTé à chaque notification (SSRF aveugle). Chrome, Edge
     * Android, Opera, Brave et Samsung passent par FCM ; Firefox par Mozilla ;
     * Safari (macOS et iOS 16.4+) par Apple ; Edge bureau par WNS.
     */
    private const array HOTES_PUSH = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ];

    public function __construct(
        private readonly ClesVapid $cles,
        private readonly AbonnementsPush $abonnements,
        private readonly Incarnation $incarnation,
    ) {
    }

    #[Route('/api/push/cle', name: 'core_push_cle', methods: ['GET'])]
    public function cle(): JsonResponse
    {
        return new JsonResponse(['clePublique' => $this->cles->clePublique()]);
    }

    #[Route('/api/push/abonnement', name: 'core_push_inscrire', methods: ['PUT'])]
    public function inscrire(Request $requete, #[CurrentUser] User $compte): Response
    {
        $corps = $this->corps($requete);
        $champs = [];

        $endpoint = $this->endpointValide($corps['endpoint'] ?? null, $champs);

        $cles = $corps['keys'] ?? null;
        $p256dh = \is_array($cles) ? ($cles['p256dh'] ?? null) : null;
        $auth = \is_array($cles) ? ($cles['auth'] ?? null) : null;

        if (!$this->cleValide($p256dh, self::P256DH_OCTETS, "\x04")) {
            $champs['keys.p256dh'] = 'Clé publique P-256 attendue (base64url, 65 octets).';
        }
        if (!$this->cleValide($auth, self::AUTH_OCTETS)) {
            $champs['keys.auth'] = 'Secret d’authentification attendu (base64url, 16 octets).';
        }

        // `origine` : le site qui s'abonne (`noel` pour noel2026). Absente : heritage.
        $origine = $corps['origine'] ?? PushSubscription::ORIGINE_HERITAGE;
        if (!\in_array($origine, PushSubscription::ORIGINES, true)) {
            $champs['origine'] = 'Origine attendue : heritage ou noel.';
        }

        if ([] !== $champs || null === $endpoint || !\is_string($p256dh) || !\is_string($auth)) {
            return $this->refus($champs);
        }

        // **Pendant une incarnation, rien n'est écrit.** Le front s'en garde déjà, mais le
        // service worker rejoue ce PUT seul (`pushsubscriptionchange`) : sans ce refus
        // côté serveur, le téléphone de l'administrateur passerait au compte incarné et
        // recevrait ses notifications. 204 plutôt qu'une erreur : le service worker n'a
        // rien à corriger, et le prochain démarrage hors incarnation remettra l'appareil
        // à son vrai propriétaire.
        if (null !== $this->incarnation->incarnant()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        \assert(\is_string($origine));
        $this->abonnements->inscrire($compte, $endpoint, $p256dh, $auth, $origine);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/push/abonnement', name: 'core_push_retirer', methods: ['DELETE'])]
    public function retirer(Request $requete, #[CurrentUser] User $compte): Response
    {
        $champs = [];
        $endpoint = $this->corps($requete)['endpoint'] ?? null;

        if (!\is_string($endpoint) || '' === trim($endpoint)) {
            $champs['endpoint'] = 'L’endpoint de l’abonnement est requis.';

            return $this->refus($champs);
        }

        $this->abonnements->retirer($compte, $endpoint);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<array-key, mixed> */
    private function corps(Request $requete): array
    {
        try {
            $corps = json_decode($requete->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($corps) ? $corps : [];
    }

    /**
     * `https://`, sans port ni identifiants, vers un service push connu
     * ({@see self::HOTES_PUSH}) — sinon ce serveur deviendrait un client HTTP à la
     * disposition de n'importe quel compte connecté.
     *
     * L'endpoint rendu est **normalisé** (hôte en minuscules) : c'est sous cette forme
     * que la bibliothèque d'envoi rapporte chaque résultat, et un endpoint stocké sous une
     * autre casse ne serait jamais retrouvé — un 410 ne supprimerait pas l'abonnement mort.
     *
     * @param array<string, string> $champs
     */
    private function endpointValide(mixed $endpoint, array &$champs): ?string
    {
        $parties = \is_string($endpoint) ? parse_url($endpoint) : false;
        $hote = \is_array($parties) ? strtolower((string) ($parties['host'] ?? '')) : '';

        if (
            !\is_string($endpoint)
            || !\is_array($parties)
            || \strlen($endpoint) > PushSubscription::ENDPOINT_MAX
            || 'https' !== strtolower((string) ($parties['scheme'] ?? ''))
            || false === filter_var($endpoint, \FILTER_VALIDATE_URL)
            || isset($parties['port']) || isset($parties['user']) || isset($parties['pass'])
            || !$this->hotePush($hote)
        ) {
            $champs['endpoint'] = 'URL https d’un service push de navigateur attendue.';

            return null;
        }

        return 'https://'.$hote
            .($parties['path'] ?? '')
            .(isset($parties['query']) ? '?'.$parties['query'] : '');
    }

    private function hotePush(string $hote): bool
    {
        foreach (self::HOTES_PUSH as $suffixe) {
            if ($hote === $suffixe || str_ends_with($hote, '.'.$suffixe)) {
                return true;
            }
        }

        return false;
    }

    private function cleValide(mixed $valeur, int $octets, ?string $prefixe = null): bool
    {
        if (!\is_string($valeur) || \strlen($valeur) > 255) {
            return false;
        }

        $decode = ClesVapid::depuisBase64Url($valeur);

        return null !== $decode
            && \strlen($decode) === $octets
            && (null === $prefixe || str_starts_with($decode, $prefixe));
    }

    /** @param array<string, string> $champs */
    private function refus(array $champs): JsonResponse
    {
        return ErrorEnvelope::response(
            Response::HTTP_BAD_REQUEST,
            'donnees_invalides',
            'L’abonnement envoyé par le navigateur est invalide.',
            [] === $champs ? ['corps' => 'JSON attendu : {"endpoint", "keys": {"p256dh", "auth"}}.'] : $champs,
        );
    }
}
