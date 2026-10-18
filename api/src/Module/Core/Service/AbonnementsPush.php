<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\PushSubscription;
use App\Module\Core\Entity\User;
use App\Module\Core\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Inscrire et retirer un appareil — `PUT` / `DELETE /api/push/abonnement`.
 *
 * ## L'inscription est un « upsert » par endpoint, et c'est ce qui la rend rejouable
 *
 * Le front la rejoue **à chaque ouverture de l'application**, abonnement existant ou
 * non : c'est la seule façon de rattraper un navigateur qui a renouvelé ses clés en
 * silence, une base restaurée, ou un abonnement créé avant le dernier déploiement. Il
 * faut donc qu'un second appel identique ne fasse rien de plus que le premier.
 *
 * Un endpoint déjà connu sous **un autre compte** lui est retiré et donné au compte
 * courant (voir l'en-tête de {@see PushSubscription}) : c'est le téléphone de quelqu'un
 * d'autre maintenant, et les notifications du compte précédent n'ont plus à y sonner.
 *
 * ## Le retrait ne touche que ses propres appareils
 *
 * Un endpoint inconnu, ou appartenant à un autre compte, rend le même `204` qu'un retrait
 * réussi : la route est idempotente, et elle ne dit pas si un endpoint existe chez
 * quelqu'un d'autre.
 */
final class AbonnementsPush
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushSubscriptionRepository $depot,
    ) {
    }

    public function inscrire(User $compte, string $endpoint, string $p256dh, string $auth, string $origine = PushSubscription::ORIGINE_HERITAGE): PushSubscription
    {
        $abonnement = $this->depot->parEndpoint($endpoint);

        if (null === $abonnement) {
            $abonnement = new PushSubscription($compte, $endpoint, $p256dh, $auth, $origine);
            $this->em->persist($abonnement);
        } else {
            $abonnement->reattribuer($compte, $p256dh, $auth, $origine);
        }

        // Deux inscriptions simultanées du même endpoint neuf buteraient sur l'index
        // unique ; le front rejoue à la prochaine ouverture, et la seconde passera en
        // mise à jour. On n'ajoute pas de verrou pour un cas qui se répare seul.
        $this->em->flush();

        return $abonnement;
    }

    public function retirer(User $compte, string $endpoint): void
    {
        $abonnement = $this->depot->parEndpoint($endpoint);

        if (null === $abonnement || $abonnement->getUser()->getId() !== $compte->getId()) {
            return;
        }

        $this->em->remove($abonnement);
        $this->em->flush();
    }
}
