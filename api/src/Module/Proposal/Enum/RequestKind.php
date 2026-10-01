<?php

declare(strict_types=1);

namespace App\Module\Proposal\Enum;

/**
 * La nature d'une demande — elle dit **quelles tables filles sont peuplées** (§ 5.7).
 *
 * Neuf valeurs, dont trois `modification_*` distinctes là où l'ADR 0004 n'en proposait
 * qu'une générique. C'est le modèle qui l'emporte (arbitrage I) : `kind` sert à filtrer
 * et à grouper la file d'administration, et un générique obligerait à lire `target_kind`
 * pour savoir ce qui est modifié. Ce sont des chaînes qui partent en base — à acter
 * avant la première validation, pas après.
 *
 * ── `ajout_citation` est la septième, et elle n'a coûté aucune migration ─────────
 *
 * `prp_change_request.kind` est un `VARCHAR(24)` **sans contrainte `CHECK` sur ses
 * valeurs** (migration `Version20260915170602`, ligne 101 : seules `ck_prp_request_*`
 * existent, et aucune n'énumère les natures). Ajouter un cas ne demande donc qu'un
 * constructeur et un applicateur. Ce n'est pas une invitation à en ajouter à la
 * légère : la chaîne part en base, et une nature écrite une fois ne se renomme plus.
 */
enum RequestKind: string
{
    /** Un ou plusieurs champs d'une fiche existante → `prp_change_op`. */
    case ModificationPersonne = 'modification_personne';
    /** Dates ou lieu d'union, divorce → `prp_change_op`. */
    case ModificationFamille = 'modification_famille';
    /** Page, `QUAY`, transcription → `prp_change_op`. */
    case ModificationCitation = 'modification_citation';
    /** Le cas du § 6.4 du dossier → `prp_draft_person` + `prp_draft_link`. */
    case CreationPersonne = 'creation_personne';
    /** « Ils étaient frère et sœur », « elle s'est remariée avec lui » → `prp_draft_link` seul. */
    case AjoutLien = 'ajout_lien';
    /** Portrait ou preuve → `prp_change_op` avec `op_kind = 'ajout_media'`. */
    case AjoutMedia = 'ajout_media';
    /**
     * « Cette fiche repose sur cet acte » → `prp_change_op` avec
     * `op_kind = 'ajout_citation'`.
     *
     * Le pendant **création** de {@see self::ModificationCitation}, qui ne savait que
     * corriger une citation déjà là. Les deux parlent le même langage — page, `QUAY`,
     * transcription, la surface du § 5.7 — pour qu'un contributeur n'ait pas deux
     * vocabulaires à apprendre selon que la source est déjà citée ou non.
     */
    case AjoutCitation = 'ajout_citation';
    /**
     * « Voici ce que dit l'acte, et voici pourquoi j'ai corrigé » → `prp_change_op` avec
     * `op_kind = 'ajout_note'`.
     *
     * Le pendant, pour une fiche **existante**, de la note qu'un brouillon de création
     * portait déjà. Les notes sont « les lignes les plus précieuses du fichier »
     * (§ 3.5) : le raisonnement d'une correction, la décision consignée pour ne pas être
     * refaite, le faisceau d'indices non conclu. Les 337 fiches déjà en base n'avaient
     * aucun moyen d'en recevoir une.
     */
    case AjoutNote = 'ajout_note';
    /**
     * « Cette fiche est un doublon, effacez-la » → `prp_change_op` avec
     * `op_kind = 'suppression_personne'`.
     *
     * ## La neuvième, et la première qui retire au lieu d'ajouter
     *
     * Les huit autres ajoutent ou corrigent ; celle-ci **efface**, et c'est ce qui la rend
     * différente de bout en bout. Un doublon dans un arbre généalogique se réparait
     * jusqu'ici à la main en base — un `DELETE` écrit dans un client SQL, sans demande,
     * sans motif, sans révision et sans personne pour relire. C'est le seul chemin
     * d'écriture qui contournait le mécanisme, et l'ADR 0004 dit qu'il ne doit pas en
     * exister.
     *
     * ## Trois règles la distinguent des huit autres
     *
     * 1. **Le motif est obligatoire au dépôt.** `message` est facultatif partout ailleurs ;
     *    ici il ne l'est pas. `POST /api/demandes/{id}/valider` ne lit aucun corps de
     *    requête — seul un refus porte un motif —, si bien qu'une validation ne laisse
     *    aucune trace écrite de la raison. Le motif doit donc vivre sur la demande, déposé
     *    à la création, ou il n'existera nulle part.
     * 2. **Une suppression qui détacherait des descendants est refusée.** Deux fiches du
     *    même homme, l'une portant six générations et l'autre vide : supprimer la mauvaise
     *    est trivial, supprimer la bonne serait une catastrophe silencieuse. Le refus est
     *    prononcé **au dépôt**, pas à la validation.
     * 3. **Rien ne part en silence.** L'op porte l'inventaire de ce que la suppression
     *    emporte, l'administrateur le lit avant de cliquer, et `prp_revision.changes` le
     *    garde après.
     */
    case SuppressionPersonne = 'suppression_personne';

    /**
     * « Il n'est pas son enfant », « ils n'ont jamais été mariés » → `prp_change_op` avec
     * `op_kind = 'retrait_lien'`, **un op par lien**.
     *
     * ## La dixième, et le pendant de `ajout_lien`
     *
     * `OpKind::RetraitLien` était déclaré depuis le premier jour et rien ne l'appliquait :
     * un lien faux ne se défaisait qu'en base, à la main, exactement le chemin que l'en-tête
     * de `ChangeRequest` interdit. Elle porte des **ops** et non des `prp_draft_link` comme
     * son pendant : défaire un lien est une écriture **sur une famille qui existe**, qui
     * doit entrer en conflit avec les autres (`base_version`) et dont l'effet — l'union
     * conservée ou supprimée, les enfants qui ne gardent qu'un parent — se mesure au dépôt et
     * se remesure à la validation. C'est le modèle de `suppression_personne`, pas celui de
     * l'ajout.
     *
     * Plusieurs liens se retirent dans **une** demande — c'est le regroupement que le mode
     * édition de la fiche dépose : une décision pour l'administrateur, pas cinq.
     */
    case RetraitLien = 'retrait_lien';

    public function label(): string
    {
        return match ($this) {
            self::ModificationPersonne => "Modification d'une fiche",
            self::ModificationFamille => "Modification d'une union",
            self::ModificationCitation => "Modification d'une citation",
            self::CreationPersonne => "Ajout d'une personne",
            self::AjoutLien => "Ajout d'un lien",
            self::AjoutMedia => "Ajout d'un média",
            self::AjoutCitation => "Ajout d'une source",
            self::AjoutNote => "Ajout d'une note",
            self::SuppressionPersonne => "Suppression d'une fiche",
            self::RetraitLien => "Retrait d'un lien",
        };
    }
}
