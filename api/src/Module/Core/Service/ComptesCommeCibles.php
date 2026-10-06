<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Contract\CiblesDeReferenceInterface;
use App\Module\Core\Enum\CibleDeReference;

/**
 * `core_user`, la cible la plus désignée du dépôt : sept modules y renvoient — auteur d'une
 * demande, organisateur d'un tirage, destinataire d'une question, entre autres.
 *
 * `Core` n'a **aucune** référence sortante vers un module (la règle du noyau,
 * `tests/Frontieres`) ; ses colonnes `*_user_id` internes — `core_audit_log.actor_user_id`,
 * `core_notification.recipient_user_id` — restent dans le module et ne sont pas du ressort
 * de `heritage:verifier-integrite`, qui ne parcourt que les références **inter**-modules.
 */
final readonly class ComptesCommeCibles implements CiblesDeReferenceInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function cibles(): array
    {
        return [CibleDeReference::Compte];
    }

    public function existants(CibleDeReference $cible, array $identifiants): array
    {
        return $this->releveur->existants($cible->value, $identifiants);
    }
}
