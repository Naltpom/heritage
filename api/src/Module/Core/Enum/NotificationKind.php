<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * La nature d'une notification en base (§ 2.7 du modèle de données).
 *
 * `DemandeCaduque` porte le diff de ce qui a changé et le lien « refaire cette
 * demande » (§ 9.6) : sans eux, une caducité est une contribution perdue, et un membre
 * dont la première contribution se perd n'en dépose pas de seconde.
 */
enum NotificationKind: string
{
    case DemandeSoumise = 'demande_soumise';
    case DemandeValidee = 'demande_validee';
    case DemandeRefusee = 'demande_refusee';
    case DemandeCaduque = 'demande_caduque';
    case DoublonProbable = 'doublon_probable';
    case InvitationUtilisee = 'invitation_utilisee';
    case CompteEnAttente = 'compte_en_attente';
    /**
     * Annoncée **au compte lui-même** : son inscription est validée, l'arbre lui est
     * ouvert. Elle a sa propre nature depuis le 01/10/2026 — elle partait sous
     * `compte_en_attente`, que le front affiche « Compte à valider ».
     */
    case CompteActive = 'compte_active';
    case MediaValide = 'media_valide';
    case MediaRefuse = 'media_refuse';
    /** Le tirage au sort d'une année est fait (module `Tirage`). Elle ne nomme personne. */
    case TirageEffectue = 'tirage_effectue';
    /**
     * Les trois notifications du module `Evenement` (2026-10-02) : l'invitation, la relance
     * de qui n'a pas répondu, et l'annulation. Elles nomment l'organisateur et l'événement,
     * jamais un autre invité.
     */
    case EvenementInvitation = 'evenement_invitation';
    case EvenementRelance = 'evenement_relance';
    case EvenementAnnule = 'evenement_annule';
    /**
     * L'organisateur a **déplacé** l'événement (début ou fin changés) — à tous les invités qui
     * ont un compte, sauf l'auteur du geste (ADR 0014, addendum du 2026-10-02). Les réponses
     * déjà données restent, mais deviennent « à confirmer ».
     */
    case EvenementDatesModifiees = 'evenement_dates_modifiees';
}
