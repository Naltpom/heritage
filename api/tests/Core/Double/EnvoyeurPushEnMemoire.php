<?php

declare(strict_types=1);

namespace App\Tests\Core\Double;

use App\Module\Core\Enum\ResultatDEnvoiPush;
use App\Module\Core\Service\EnvoyeurDeNotificationsPush;

/**
 * Enregistre les envois push au lieu de les faire, et répond ce que le test lui dicte.
 *
 * Branché à la place de `EnvoyeurWebPush` par `config/services/core.yaml` (bloc
 * `when@test`). Il sert trois assertions que le contrat demande : la notification part
 * vers les appareils **du destinataire et d'eux seuls** ; un `410` du service push
 * **supprime** l'abonnement ; une panne de l'envoi **n'empêche pas** la notification en
 * base.
 *
 * État statique, pour la même raison que `Proposal\Double\NotificateurEnMemoire` : le
 * client de test relance le noyau entre deux requêtes, et un envoi déjà fait ne revient
 * pas pour autant. Remis à zéro par {@see self::oublierTout()} au début de chaque test.
 */
final class EnvoyeurPushEnMemoire implements EnvoyeurDeNotificationsPush
{
    /**
     * Un élément par charge distincte (donc par notification), dans l'ordre d'envoi, avec
     * les appareils qu'elle vise — la lecture naturelle d'une assertion de test.
     *
     * @var list<array{endpoints: list<string>, charge: string, ttl: int}>
     */
    public static array $envois = [];

    /** Le nombre d'appels à `envoyer()` : une salve par requête, pas une par notification. */
    public static int $salves = 0;

    /** @var array<string, ResultatDEnvoiPush> endpoint → réponse imposée (défaut : `Remis`) */
    public static array $reponses = [];

    /** Si posé, `envoyer()` lève — une panne réseau, un service push injoignable. */
    public static ?\Throwable $panne = null;

    public static function oublierTout(): void
    {
        self::$envois = [];
        self::$salves = 0;
        self::$reponses = [];
        self::$panne = null;
    }

    public function envoyer(array $messages, int $dureeDeVieSecondes): array
    {
        if (null !== self::$panne) {
            throw self::$panne;
        }

        ++self::$salves;

        /** @var array<string, list<string>> $parCharge */
        $parCharge = [];
        $resultats = [];

        foreach ($messages as ['abonnement' => $abonnement, 'charge' => $charge]) {
            $endpoint = $abonnement->getEndpoint();
            $parCharge[$charge][] = $endpoint;
            $resultats[$endpoint] = self::$reponses[$endpoint] ?? ResultatDEnvoiPush::Remis;
        }

        foreach ($parCharge as $charge => $endpoints) {
            self::$envois[] = ['endpoints' => $endpoints, 'charge' => (string) $charge, 'ttl' => $dureeDeVieSecondes];
        }

        return $resultats;
    }

    /** @return list<string> tous les endpoints visés, tous envois confondus */
    public static function endpointsVises(): array
    {
        return array_merge(...array_map(static fn (array $e): array => $e['endpoints'], self::$envois));
    }
}
