<?php

declare(strict_types=1);

namespace App\Module\Membership\Enum;

/**
 * L'issue d'une tentative de rattachement (§ 4.5 du modèle de données).
 *
 * `AucunCandidat` et `LimiteAtteinte` doivent rester **indistinguables côté client** :
 * les séparer dirait au porteur du jeton qu'il a atteint un plafond, donc que ses
 * requêtes précédentes portaient, donc que le lien est un oracle exploitable.
 */
enum ClaimResult: string
{
    case Confirme = 'confirme';
    case EchecDate = 'echec_date';
    case CandidatsProposes = 'candidats_proposes';
    case AucunCandidat = 'aucun_candidat';
    case LimiteAtteinte = 'limite_atteinte';

    public function label(): string
    {
        return match ($this) {
            self::Confirme => 'Date de naissance confirmée',
            self::EchecDate => 'Date de naissance non confirmée',
            self::CandidatsProposes => 'Candidats proposés',
            self::AucunCandidat => 'Aucun candidat',
            self::LimiteAtteinte => 'Plafond horaire atteint',
        };
    }
}
