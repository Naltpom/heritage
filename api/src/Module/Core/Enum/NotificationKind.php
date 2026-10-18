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
    /**
     * Une proposition en attente a été **complétée** par quelqu'un d'autre que le
     * destinataire (ADR 0017) : un administrateur l'a complétée sur place, ou y a fusionné
     * un complément — l'auteur l'apprend, avec le nom des champs touchés, jamais celui du
     * membre qui a fourni le complément. Dans l'autre sens, l'auteur qui complète sa
     * demande en attente la fait savoir aux administrateurs.
     */
    case DemandeCompletee = 'demande_completee';
    /**
     * Le complément du destinataire a été **fusionné** dans la proposition qu'il complétait
     * (ADR 0017). Ce n'est pas un refus, et il ne part pas comme tel : son travail est
     * repris, pas écarté.
     */
    case ComplementFusionne = 'complement_fusionne';
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
    /** Une demande RGPD d'un membre attend un administrateur (ADR 0016). */
    case DemandeRgpd = 'demande_rgpd';
    /** La demande RGPD du membre a été traitée — appliquée ou refusée. */
    case DemandeRgpdTraitee = 'demande_rgpd_traitee';
    /**
     * Le partage de la date de naissance passe à trois niveaux, `proches` par défaut
     * (ADR 0016, révision du 2026-10-07) — posée une fois, par la migration, à chaque
     * compte rattaché.
     */
    case PartageNaissanceRevu = 'partage_naissance_revu';
    /**
     * Un tirage de Noël du site noel2026 (module `Noel`) vient d'être fait : à chaque
     * participant, sans nommer personne. Lien `noel.tirage` `{code}`.
     */
    case NoelTirageEffectue = 'noel_tirage_effectue';
    /** L'organisateur d'un tirage de Noël en a changé la date ou le budget. */
    case NoelTirageModifie = 'noel_tirage_modifie';

    /** Les types propres au site noel2026 : ses abonnements push ne reçoivent que ceux-là. */
    public function estDeNoel(): bool
    {
        return self::NoelTirageEffectue === $this || self::NoelTirageModifie === $this;
    }
}
