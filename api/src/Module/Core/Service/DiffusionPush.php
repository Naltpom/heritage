<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Entity\PushSubscription;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\ResultatDEnvoiPush;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Repository\PushSubscriptionRepository;
use App\Module\Core\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Fait sonner le téléphone : une notification déposée en base part aussi, en push, vers
 * chaque appareil abonné de son destinataire.
 *
 * ## La notification en base reste la source de vérité
 *
 * Le push n'est qu'un **réveil** : il dit « il y a du nouveau » et mène à la
 * notification, qui est lue et marquée depuis la cloche comme avant. Un push perdu ne
 * perd donc rien — la cloche l'affiche au prochain passage. C'est ce qui autorise les
 * deux règles suivantes.
 *
 * ## Programmé pendant la requête, envoyé après la réponse
 *
 * `NotificationService::notifier()` ne fait que **programmer** l'envoi
 * ({@see self::programmer()}). L'envoi réel part en **une seule salve**, sur
 * `kernel.terminate` — c'est-à-dire une fois la réponse rendue au navigateur
 * (`Response::send()` appelle `fastcgi_finish_request()` quand PHP-FPM ou FrankenPHP le
 * fournissent) — ou sur `console.terminate` pour une commande.
 *
 * Pourquoi : les appelants bouclent. Un tirage notifie chaque participant, une demande
 * notifie chaque administrateur. Envoyé à chaque `notifier()`, un service push lent ou
 * injoignable (DNS, FCM dégradé) coûtait son délai **par destinataire** : vingt comptes
 * abonnés, jusqu'à ~100 s, bien au-delà du `proxy_read_timeout` de nginx (60 s) — et
 * l'administrateur recevait une erreur sur un tirage pourtant validé (relevé en relecture
 * le 01/10/2026). Désormais tous les messages de la requête partent ensemble, en
 * parallèle : le processus paie au plus **un** délai d'envoi
 * ({@see EnvoyeurWebPush::DELAI_SECONDES}), quel que soit le nombre de destinataires — et
 * même si la réponse n'a pas pu partir avant, la requête reste sous ce plafond.
 *
 * Le service est remis à zéro entre deux requêtes (`ResetInterface`) : en mode worker, un
 * envoi programmé ne doit jamais survivre à sa requête ni partir avec la suivante.
 *
 * ## Ni `programmer()` ni l'envoi ne lèvent jamais
 *
 * Même contrat que `NotificationService::notifier()`, et pour la même raison : on est
 * **après** le `commit` du travail qui a causé la notification. Un service push en panne
 * ne doit pas transformer en erreur une validation qui a bel et bien eu lieu.
 *
 * ## Ce qu'on pousse — et ce qu'on ne pousse pas
 *
 * Le titre et le corps **déjà composés** par l'appelant, dans le cercle du destinataire
 * (voir l'en-tête de `NotificationService`) : ce service ne compose rien et ne nomme
 * personne. Le corps est tronqué ({@see self::CORPS_MAX} signes) — un écran verrouillé
 * n'en affiche pas plus, et la charge d'un push est plafonnée à 4 Ko.
 *
 * **Un compte suspendu ne reçoit rien** : la suspension ferme la session à la requête
 * suivante (`AccountStatusSubscriber`), elle doit aussi faire taire le téléphone. Ses
 * abonnements sont gardés — une réactivation les retrouve.
 */
final class DiffusionPush implements ResetInterface
{
    /** 24 h : au-delà, un « votre demande a été validée » n'est plus une nouvelle. */
    public const int DUREE_DE_VIE_SECONDES = 86_400;

    public const int CORPS_MAX = 180;

    /**
     * Les notifications à pousser en fin de requête, indexées par objet : programmer
     * deux fois la même n'envoie qu'une fois.
     *
     * @var array<int, Notification>
     */
    private array $programmees = [];

    public function __construct(
        private readonly PushSubscriptionRepository $abonnements,
        private readonly UserRepository $utilisateurs,
        private readonly EnvoyeurDeNotificationsPush $envoyeur,
        private readonly LoggerInterface $journal,
    ) {
    }

    /**
     * Retient la notification pour la salve de fin de requête. Instantané : ne touche ni
     * à la base ni au réseau, donc ne peut rien ralentir.
     */
    public function programmer(Notification $notification): void
    {
        $this->programmees[spl_object_id($notification)] = $notification;
    }

    /**
     * Envoie en une salve tout ce qui a été programmé, puis l'oublie. **Ne lève jamais.**
     *
     * Appelée par le noyau après la réponse ; publique pour les tests, qui notifient hors
     * de toute requête.
     *
     * @return int le nombre d'appareils qui ont accepté leurs notifications
     */
    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function envoyerLesProgrammees(): int
    {
        // Vidée AVANT l'envoi : une salve qui lève ne doit pas repartir à l'événement
        // suivant.
        $notifications = array_values($this->programmees);
        $this->programmees = [];

        if ([] === $notifications) {
            return 0;
        }

        try {
            return $this->envoyerSansGarde($notifications);
        } catch (\Throwable $echec) {
            $this->journal->warning('Notifications push non envoyées.', [
                'notifications' => array_map(static fn (Notification $n): ?int => $n->getId(), $notifications),
                'exception' => $echec,
            ]);

            return 0;
        }
    }

    public function reset(): void
    {
        $this->programmees = [];
    }

    /**
     * La charge que le service worker du front lit (`app/public/sw.js`). Contrat commun :
     * `{id, titre, corps, linkRoute, linkParams, kind}`.
     */
    public function charge(Notification $notification): string
    {
        $corps = $notification->getBody();
        if (null !== $corps && mb_strlen($corps) > self::CORPS_MAX) {
            $corps = rtrim(mb_substr($corps, 0, self::CORPS_MAX - 1)).'…';
        }

        $parametres = $notification->getLinkParams();

        return json_encode(
            [
                'id' => $notification->getId(),
                'titre' => $notification->getTitle(),
                'corps' => $corps,
                'linkRoute' => $notification->getLinkRoute(),
                // Un objet JSON, jamais `[]` : le front fait `JSON.stringify(linkParams)`
                // et attend des paramètres de route nommés.
                'linkParams' => null === $parametres ? null : (object) $parametres,
                'kind' => $notification->getKind()->value,
            ],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );
    }

    /** @param non-empty-list<Notification> $notifications */
    private function envoyerSansGarde(array $notifications): int
    {
        $messages = [];
        /** @var array<int, list<PushSubscription>> $appareilsParCompte */
        $appareilsParCompte = [];

        foreach ($notifications as $notification) {
            $compte = $notification->getRecipientUserId();
            $appareilsParCompte[$compte] ??= $this->appareilsDe($compte);

            foreach ($appareilsParCompte[$compte] as $appareil) {
                // Un appareil abonné depuis noel2026 ne reçoit que les notifications de
                // Noël : son service worker n'ouvre que les liens de ce site-là.
                if (PushSubscription::ORIGINE_NOEL === $appareil->getOrigine() && !$notification->getKind()->estDeNoel()) {
                    continue;
                }

                $messages[] = ['abonnement' => $appareil, 'charge' => $this->charge($notification)];
            }
        }

        // Le cas de loin le plus fréquent : aucun appareil. On sort avant de toucher aux
        // clés VAPID, au client HTTP ou à quoi que ce soit d'autre.
        if ([] === $messages) {
            return 0;
        }

        $resultats = $this->envoyeur->envoyer($messages, self::DUREE_DE_VIE_SECONDES);

        $remis = array_keys(array_filter($resultats, static fn (ResultatDEnvoiPush $r): bool => ResultatDEnvoiPush::Remis === $r));
        $expires = array_keys(array_filter($resultats, static fn (ResultatDEnvoiPush $r): bool => ResultatDEnvoiPush::Expire === $r));

        $this->abonnements->marquerUtilises(array_map('strval', $remis), new \DateTimeImmutable());

        if ([] !== $expires) {
            $supprimes = $this->abonnements->supprimerExpires(array_map('strval', $expires));
            $this->journal->info('Abonnements push expirés supprimés.', ['nombre' => $supprimes]);
        }

        return \count($remis);
    }

    /**
     * Les appareils d'un compte — aucun s'il est suspendu (voir l'en-tête).
     *
     * @return list<PushSubscription>
     */
    private function appareilsDe(int $compte): array
    {
        $destinataire = $this->utilisateurs->find($compte);

        if (!$destinataire instanceof User || UserStatus::Suspendu === $destinataire->getStatus()) {
            return [];
        }

        return $this->abonnements->duCompte($compte);
    }
}
