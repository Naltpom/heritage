<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Les actions consignées au journal d'audit (§ 2.7 du modèle de données).
 *
 * Liste **exhaustive** : toute décision d'administration y figure (§ 8 du dossier de
 * mission). Ajouter une valeur ici est un geste délibéré, pas un effet de bord.
 */
enum AuditAction: string
{
    case Creation = 'creation';
    case Modification = 'modification';
    case Suppression = 'suppression';

    case Connexion = 'connexion';
    case ConnexionEchouee = 'connexion_echouee';
    case Deconnexion = 'deconnexion';

    case InvitationCreee = 'invitation_creee';
    case InvitationRevoquee = 'invitation_revoquee';
    case InvitationUtilisee = 'invitation_utilisee';
    /** Recherche de rattachement — tracée **même en échec** : c'est l'oracle à surveiller. */
    case RattachementTente = 'rattachement_tente';

    case DemandeSoumise = 'demande_soumise';
    /** Porte `changes.auto_validation = true` quand le validateur est aussi l'auteur. */
    case DemandeValidee = 'demande_validee';
    /** Le motif obligatoire est dans `changes.motif`. */
    case DemandeRefusee = 'demande_refusee';
    /** Rejeu automatique après verrou : une écriture qu'aucun humain n'a réexaminée doit se voir. */
    case DemandeRejouee = 'demande_rejouee';
    /** Caducité constatée à la validation. Rien n'a été écrit. */
    case DemandeCaduque = 'demande_caduque';

    case RoleModifie = 'role_modifie';
    case CompteSuspendu = 'compte_suspendu';
    /**
     * Un administrateur a réinitialisé le mot de passe d'un compte.
     *
     * **Ajoutée parce que son absence rendait le journal aveugle.** Tant qu'aucune route
     * ne savait le faire, toute réinitialisation passait par du SQL — donc hors audit et
     * sans toucher `updated_at`. Deux écrasements de mot de passe ont ainsi été
     * **invisibles** : le journal n'en portait aucune trace, et la colonne de date était
     * restée à l'heure de création. *Un journal ne peut pas enregistrer ce que
     * l'application ne sait pas faire.*
     */
    case MotDePasseReinitialise = 'mot_de_passe_reinitialise';
    case ReglageModifie = 'reglage_modifie';

    case Televersement = 'televersement';
    case MediaSupprime = 'media_supprime';

    case ImportGedcom = 'import_gedcom';
    case ExportGedcom = 'export_gedcom';

    /** Bascule « voir comme un membre » : tracée, parce que c'est une lecture de données masquées. */
    case VueCommeMembre = 'vue_comme_membre';

    /**
     * Un administrateur se connecte **en tant qu'un autre compte** (ADR 0010). La ligne
     * porte l'administrateur en acteur et le compte incarné en sujet.
     */
    case IncarnationDebut = 'incarnation_debut';

    /**
     * Retour au compte réel. Écrite **avant** le retour : son acteur est le compte incarné,
     * et son incarnant l'administrateur — comme toute ligne écrite pendant l'incarnation.
     */
    case IncarnationFin = 'incarnation_fin';

    /**
     * Un appel sortant au modèle de langage qui formule les questions (ADR 0008).
     *
     * **C'est le seul endroit du produit où des données de personnes vivantes quittent
     * l'application**, et la seule action de ce journal qui ne décrive pas une décision
     * humaine. Elle y figure quand même — et surtout — parce que « combien de vivants
     * sont partis chez le tiers ce mois-ci » est la première question qu'un membre
     * posera, et qu'une réponse qui suppose de relire des journaux applicatifs est une
     * réponse qu'on n'obtient pas.
     *
     * `changes` porte des **comptes**, jamais le corpus ni la réponse, et évidemment
     * jamais la clé.
     */
    case AppelModeleDeLangage = 'appel_modele_langage';
}
