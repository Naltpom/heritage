<?php

declare(strict_types=1);

namespace App\Module\Proposal\Enum;

/**
 * La nature d'une demande — elle dit **quelles tables filles sont peuplées** (§ 5.7).
 *
 * Six valeurs, dont trois `modification_*` distinctes là où l'ADR 0004 n'en proposait
 * qu'une générique. C'est le modèle qui l'emporte (arbitrage I) : `kind` sert à filtrer
 * et à grouper la file d'administration, et un générique obligerait à lire `target_kind`
 * pour savoir ce qui est modifié. Ce sont des chaînes qui partent en base — à acter
 * avant la première validation, pas après.
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

    public function label(): string
    {
        return match ($this) {
            self::ModificationPersonne => "Modification d'une fiche",
            self::ModificationFamille => "Modification d'une union",
            self::ModificationCitation => "Modification d'une citation",
            self::CreationPersonne => "Ajout d'une personne",
            self::AjoutLien => "Ajout d'un lien",
            self::AjoutMedia => "Ajout d'un média",
        };
    }
}
