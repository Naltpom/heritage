<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\PushSubscription;
use App\Module\Core\Enum\ResultatDEnvoiPush;

/**
 * Le port de sortie vers les services push (Google, Mozilla, Apple).
 *
 * Une interface, pour une raison : les tests ne doivent ni sortir sur le réseau ni
 * dépendre d'un service tiers. L'implémentation réelle est {@see EnvoyeurWebPush} ; en
 * environnement de test, `config/services/core.yaml` branche à la place un double en
 * mémoire (`tests/Core/Double/EnvoyeurPushEnMemoire`) qui enregistre les envois et rend
 * les réponses qu'un test lui dicte — c'est ce qui rend écrivable « un `410` supprime
 * l'abonnement ».
 *
 * Le port ne décide de rien : il envoie et rapporte. Supprimer un abonnement expiré,
 * écarter un compte suspendu, composer la charge — tout cela est dans
 * {@see DiffusionPush}, donc éprouvé par les tests quel que soit l'envoyeur branché.
 */
interface EnvoyeurDeNotificationsPush
{
    /**
     * Envoie **en une seule salve** chaque message (un appareil, une charge JSON déjà
     * composée). Une seule salve et non un appel par notification : c'est ce qui borne la
     * durée totale d'une requête qui notifie vingt comptes au délai d'**un** envoi
     * (voir l'en-tête de {@see DiffusionPush}).
     *
     * Peut lever : l'appelant ({@see DiffusionPush}) est le seul à le faire, et il
     * rattrape tout. Un appareil absent du résultat est compté comme un échec.
     *
     * Un même appareil peut recevoir plusieurs messages dans la salve ; son issue est
     * alors la plus décisive des siennes : `Expire` l'emporte (l'appareil n'existe plus,
     * quel que soit le message), puis `Remis`, puis `Echec`.
     *
     * @param list<array{abonnement: PushSubscription, charge: string}> $messages
     *
     * @return array<string, ResultatDEnvoiPush> endpoint → issue
     */
    public function envoyer(array $messages, int $dureeDeVieSecondes): array;
}
