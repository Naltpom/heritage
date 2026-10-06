<?php

declare(strict_types=1);

namespace App\Module\Core\Controller;

use App\Module\Core\Http\ErrorEnvelope;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /api/sante` — la sonde **publique** de disponibilité (§ 26 du contrat).
 *
 * Elle répond à la question que pose une sonde extérieure : « le site sert-il des
 * requêtes, base comprise ? ». Elle amorce donc le noyau Symfony et lance un `SELECT 1` —
 * c'est ce qui la distingue de `public/sante.php`, servie sans noyau ni base, qui reste la
 * cible du `HEALTHCHECK` du conteneur : la santé du conteneur ne doit pas dépendre de la
 * base, sinon une base qui tousse fait redémarrer une API saine.
 *
 * **Ce qu'elle ne dit pas, délibérément.** Aucune version, aucune empreinte, aucun nom
 * d'hôte, aucun message d'erreur : la route est ouverte à tous, sans session. En panne,
 * elle rend `503` avec l'enveloppe du § 15.1 et un code générique ; la cause réelle part
 * au journal du serveur, jamais dans la réponse.
 *
 * **Elle n'ouvre aucune session.** `PUBLIC_ACCESS` dans `security.yaml`, et
 * `AccountStatusSubscriber` l'écarte comme les autres chemins publics : rien ne lit le
 * jeton de sécurité, donc rien ne charge ni ne démarre la session — même quand la requête
 * porte un cookie.
 */
final class SanteController extends AbstractController
{
    public function __construct(
        private readonly Connection $connexion,
        private readonly LoggerInterface $journal,
    ) {
    }

    #[Route('/api/sante', name: 'core_sante', methods: ['GET'])]
    public function sonder(): JsonResponse
    {
        try {
            $this->connexion->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $panne) {
            // `error` et non `critical` : la sonde peut être appelée chaque minute pendant
            // toute la panne, et c'est la base qui est en cause, pas ce code.
            $this->journal->error('Sonde /api/sante : la base de données ne répond pas.', ['exception' => $panne]);

            return ErrorEnvelope::response(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'service_indisponible',
                'Le service est momentanément indisponible.',
            );
        }

        return new JsonResponse(['statut' => 'ok']);
    }
}
