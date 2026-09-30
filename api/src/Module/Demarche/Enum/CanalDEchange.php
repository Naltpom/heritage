<?php

declare(strict_types=1);

namespace App\Module\Demarche\Enum;

/**
 * Par où le message est passé. Il dit **où chercher la suite** : une demande faite sur
 * service-public se suit dans le compte de celui qui l'a déposée, un courriel dans sa
 * boîte, un courrier nulle part — d'où l'intérêt d'en garder le texte ici.
 */
enum CanalDEchange: string
{
    case Courriel = 'courriel';
    case ServicePublic = 'service_public';
    case Courrier = 'courrier';
    case Guichet = 'guichet';
    case Telephone = 'telephone';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Courriel => 'Courriel',
            self::ServicePublic => 'Service-public.gouv.fr',
            self::Courrier => 'Courrier postal',
            self::Guichet => 'Au guichet',
            self::Telephone => 'Téléphone',
            self::Autre => 'Autre',
        };
    }
}
