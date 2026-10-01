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
    /**
     * **Pas encore de fiche.** Le candidat ne s'est pas trouvé dans l'arbre — ou ne s'y
     * est pas reconnu — et a décrit sa famille en texte libre (ADR 0013). Le compte existe,
     * `person_id` est **nul**, et il reste `en_attente` jusqu'à ce qu'un administrateur le
     * rattache à une fiche existante ou à une fiche qu'il crée pour lui.
     *
     * C'est la seule nature où `person_id` est nul, et la base le garantit
     * (`ck_mbr_member_fiche`) : un rattachement sans fiche qui annoncerait un pré-lien
     * serait un compte dont le cercle n'a pas d'ancre.
     */
    case ARattacher = 'a_rattacher';

    public function label(): string
    {
        return match ($this) {
            self::PreLie => 'Pré-lié par un administrateur',
            self::RechercheConfirmee => 'Recherche confirmée par le candidat',
            self::ManuelAdmin => 'Rattachement manuel',
            self::ARattacher => 'Fiche à identifier',
        };
    }

    /**
     * Le rattachement active-t-il le compte sans intervention d'un administrateur ?
     *
     * Non pour une recherche confirmée — le candidat s'est reconnu, rien ne le prouve —,
     * et **a fortiori** non pour un compte sans fiche : il n'y a rien à activer, pas même
     * une ancre de cercle.
     */
    public function activeLeCompte(): bool
    {
        return self::RechercheConfirmee !== $this && self::ARattacher !== $this;
    }

    /** La nature exige-t-elle une fiche ? Toutes, sauf le compte « à rattacher ». */
    public function aUneFiche(): bool
    {
        return self::ARattacher !== $this;
    }
}
