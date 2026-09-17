<?php

declare(strict_types=1);

namespace App\Module\Proposal\Enum;

/**
 * La nature d'une demande — elle dit **quelles tables filles sont peuplées** (§ 5.7).
 *
 * Huit valeurs, dont trois `modification_*` distinctes là où l'ADR 0004 n'en proposait
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
        };
    }
}
