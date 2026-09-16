<?php

declare(strict_types=1);

namespace App\Module\Membership\Enum;

/**
 * Comment le rattachement compte ↔ personne a été établi (§ 4.5 du modèle).
 *
 * C'est cette valeur qui détermine l'activation automatique : seul un pré-lien avec date
 * de naissance confirmée donne un compte `actif` sans intervention (§ 6.1 du dossier de
 * mission, étape 3).
 */
enum MatchKind: string
{
    /** L'administrateur avait désigné la personne sur l'invitation, le candidat a confirmé sa date de naissance. */
    case PreLie = 'pre_lie';
    /** Pas de pré-lien ; la recherche floue a renvoyé un candidat, le candidat l'a retenu. */
    case RechercheConfirmee = 'recherche_confirmee';
    /** Rattachement posé ou corrigé à la main par un administrateur. */
    case ManuelAdmin = 'manuel_admin';

    public function label(): string
    {
        return match ($this) {
            self::PreLie => 'Pré-lié par un administrateur',
            self::RechercheConfirmee => 'Recherche confirmée par le candidat',
            self::ManuelAdmin => 'Rattachement manuel',
        };
    }

    /** Le rattachement active-t-il le compte sans intervention d'un administrateur ? */
    public function activeLeCompte(): bool
    {
        return self::RechercheConfirmee !== $this;
    }
}
