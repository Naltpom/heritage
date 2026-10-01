<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Enum\ResultatDEnvoiPush;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttplugClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * L'envoi réel : VAPID (RFC 8292) + chiffrement `aes128gcm` (RFC 8291), par
 * `minishlink/web-push`.
 *
 * ## Borné dans le temps, et en parallèle
 *
 * L'envoi a lieu dans le processus PHP de la requête qui a produit la notification — la
 * validation d'une demande par l'administrateur, typiquement —, mais **après sa réponse**
 * (`kernel.terminate`, voir {@see DiffusionPush}). Il n'y a ni file ni Redis (§ 3 du
 * dossier). D'où :
 *
 *  - un délai de {@see self::DELAI_SECONDES} secondes, **durée totale** comprise
 *    (`max_duration`), pas seulement l'inactivité ;
 *  - **une seule salve par requête**, tous destinataires et toutes notifications
 *    confondus, envoyée **en parallèle** (`flushPooled` sur le client HTTPlug asynchrone
 *    de Symfony) : un tirage qui notifie vingt comptes coûte un délai, pas vingt. C'est
 *    ce qui fait du délai ci-dessus un plafond **par requête** et non par destinataire.
 *
 * Le client HTTP est celui du framework, reconfiguré ici : on ne dépend d'aucune
 * découverte automatique de client (`php-http/discovery`), dont le choix varierait avec
 * les paquets installés.
 */
final class EnvoyeurWebPush implements EnvoyeurDeNotificationsPush
{
    public const float DELAI_SECONDES = 5.0;

    /**
     * Le codage de contenu de la RFC 8291. `aesgcm` (le brouillon d'avant) n'est plus
     * utile : tous les navigateurs qui savent afficher une notification application
     * fermée — Chrome, Firefox, Safari 16.4+ sur iOS — annoncent `aes128gcm`.
     */
    private const string CODAGE = 'aes128gcm';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ClesVapid $cles,
        private readonly LoggerInterface $journal,
    ) {
    }

    public function envoyer(array $messages, int $dureeDeVieSecondes): array
    {
        if ([] === $messages) {
            return [];
        }

        $fabrique = new Psr17Factory();
        $client = new HttplugClient(
            $this->http->withOptions(['timeout' => self::DELAI_SECONDES, 'max_duration' => self::DELAI_SECONDES]),
            $fabrique,
            $fabrique,
        );

        $webPush = new WebPush(
            ['VAPID' => $this->cles->pourSigner()],
            ['TTL' => $dureeDeVieSecondes, 'urgency' => 'normal'],
            $client,
            $fabrique,
            $fabrique,
            $client,
            $this->journal,
        );
        // Un seul jeton VAPID par service push et par salve, au lieu d'une signature ECDSA
        // par appareil.
        $webPush->setReuseVAPIDHeaders(true);

        foreach ($messages as ['abonnement' => $abonnement, 'charge' => $charge]) {
            $webPush->queueNotification(
                new Subscription($abonnement->getEndpoint(), $abonnement->getP256dh(), $abonnement->getAuth(), self::CODAGE),
                $charge,
            );
        }

        $resultats = [];

        // Toute la salve d'un coup : `flushPooled` découpe par lots de 1 000 (défaut de la
        // bibliothèque), bien au-delà de ce qu'une requête produit ici.
        $webPush->flushPooled(function (MessageSentReport $rapport) use (&$resultats): void {
            $endpoint = $rapport->getEndpoint();
            $resultats[$endpoint] = ResultatDEnvoiPush::laPlusDecisive($resultats[$endpoint] ?? null, $this->issue($rapport));
        });

        return $resultats;
    }

    private function issue(MessageSentReport $rapport): ResultatDEnvoiPush
    {
        if ($rapport->isSuccess()) {
            return ResultatDEnvoiPush::Remis;
        }

        if ($rapport->isSubscriptionExpired()) {
            return ResultatDEnvoiPush::Expire;
        }

        // L'hôte seul : l'endpoint complet est une adresse de livraison, il n'a rien à
        // faire dans un journal que d'autres lisent.
        $this->journal->warning('Notification push refusée par le service push.', [
            'service' => parse_url($rapport->getEndpoint(), \PHP_URL_HOST),
            'statut' => $rapport->getResponse()?->getStatusCode(),
            'raison' => mb_substr($rapport->getReason(), 0, 300),
        ]);

        return ResultatDEnvoiPush::Echec;
    }
}
