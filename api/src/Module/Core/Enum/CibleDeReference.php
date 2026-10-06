<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Les tables qu'une référence inter-modules peut désigner — l'extrémité d'arrivée d'une
 * flèche du § 10.8 de `docs/modele-donnees.md`.
 *
 * ## Pourquoi un vocabulaire du noyau
 *
 * Neuf modules déclarent des références vers ces cibles, trois modules les possèdent, et
 * la commande `heritage:verifier-integrite` les rapproche. Une valeur qui traverse autant
 * de frontières est un **vocabulaire**, pas un contrat : elle monte dans `Core` dès deux
 * modules (ADR 0002, « Quand un contrat monte dans `Core` »). Passée en chaîne, elle serait
 * comparée à des littéraux dans chaque module — le vocabulaire déguisé que l'ADR interdit.
 *
 * La valeur est le **nom de la table**, et c'est délibéré : le rapport de la commande se lit
 * à côté du diagramme du § 10.8, qui nomme des tables. `Core` n'en tire aucun accès : il
 * ne lit jamais ces tables, c'est leur module propriétaire qui répond
 * ({@see \App\Module\Core\Contract\CiblesDeReferenceInterface}).
 *
 * Seules les cibles **effectivement référencées** figurent ici. `med_media`, par exemple,
 * n'est désignée que par un `JSONB` de `prp_change_op` (`value_kind = 'media'`), que la
 * commande ne lit pas : l'ajouter ferait croire à un contrôle qui n'existe pas.
 */
enum CibleDeReference: string
{
    case Compte = 'core_user';
    case Personne = 'tree_person';
    case Famille = 'tree_family';
    case Citation = 'tree_citation';
    case Demande = 'prp_change_request';
}
