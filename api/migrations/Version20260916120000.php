<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Reprise de données, aucun changement de schéma.** Deux défauts corrigés dans le code
 * de cette livraison laissent derrière eux des lignes que le correctif seul ne répare
 * pas : il empêche de nouvelles, il ne touche pas aux anciennes.
 *
 * La règle posée par `Version20260915193611` s'applique ici : « une migration jouée
 * quelque part est figée ; ce qui change après elle est une migration de plus ».
 *
 * ── 1. `prp_change_request.author_label` vide ───────────────────────────────────
 *
 * `Proposal\Service\FabriqueDeLecteur` passait `core_user.display_name` brut. Or un
 * compte né du parcours d'invitation a `display_name = ''` **par construction** : il
 * n'est jamais renseigné depuis `mbr_member.person_label` avant activation, ce serait
 * donner le nom de la personne réclamée à un compte qui n'y a pas droit (§ 2.2). Le
 * libellé partait donc vide dans un **instantané** qui ne se recalcule jamais, d'où
 * « proposée par , le 16 septembre 2026 » dans la file d'administration.
 *
 * **Mesuré sur la base de travail avant reprise : 6 demandes sur 6, soit 100 %.** Pas un
 * cas de bord — toutes les demandes existantes, parce que leur auteur unique est un
 * compte d'invitation.
 *
 * **Pourquoi on reprend au lieu de laisser.** `author_label` est un instantané, et l'on
 * ne réécrit pas l'histoire à la légère. Mais une chaîne vide n'est l'instantané de
 * rien : au moment du dépôt, ce compte n'avait pas davantage de `display_name`, et son
 * libellé d'alors **était** son courriel — c'est la règle du § 2.2, appliquée à
 * l'identique par `SessionPayloadBuilder`, `UserAdminService` et
 * `Media\Security\FabriqueDeLecteur`. On ne fabrique donc pas une valeur : on pose celle
 * que l'instantané aurait dû prendre, à partir d'une donnée qui n'a pas bougé.
 *
 * L'exposition ne change pas : `author_label` n'est rendu qu'à **l'auteur de la demande**
 * (`pourAuteur()`) et aux **administrateurs** (`pourAdministration()`, qui porte seul
 * `demandesConcurrentes` et `doublonsProbables`). Un administrateur lit déjà tous les
 * courriels sur `GET /api/administration/utilisateurs`.
 *
 * Les lignes dont le compte a disparu sont laissées telles quelles : on n'invente pas un
 * libellé pour un auteur qu'on ne peut plus nommer.
 *
 * ── 2. `med_media` orphelins d'une création validée ─────────────────────────────
 *
 * La passe 6 du § 9.5 n'était branchée nulle part : `resoudreLeBrouillon()` avait **0
 * appelant en production**. Une preuve téléversée sur un brouillon restait donc pointée
 * sur `#<demande>/<ref>` après validation. Elle n'était pas perdue — la ligne et les
 * octets survivaient — mais plus aucun écran n'y menait, le nœud en attente ayant quitté
 * le graphe.
 *
 * La correspondance est **retrouvable sans ambiguïté**, parce que
 * `ApplicateurDeCreation` a persisté `prp_draft_person.resolved_person_id` à la
 * validation. Le dièse est la seule subtilité : `prp_draft_person.local_ref` s'écrit
 * `#p1`, `med_media.subject_draft_ref` s'écrit `p1`.
 *
 * `resolved_person_id IS NOT NULL` **est** la condition de validation : cette colonne
 * n'est posée qu'à la passe 2. Une demande refusée ou en attente n'est donc jamais
 * touchée ici, et sa preuve reste sur son brouillon, où elle doit rester.
 *
 * **`is_active_portrait` n'est pas basculé**, volontairement. Un portrait orphelin
 * deviendrait visible dans la galerie sans devenir *le* portrait de la fiche. C'est le
 * choix prudent : `uniq_med_portrait_actif` est un index unique partiel, et promouvoir en
 * masse des portraits rétroactifs pourrait déloger un portrait choisi depuis. Le cas est
 * rare et se règle fiche par fiche.
 *
 * ── 3. Ce qui n'est PAS ici, et pourquoi ────────────────────────────────────────
 *
 * `tree_person.search_key` doit être recalculée pour les fiches dont le surnom manquait
 * (3 lignes mesurées). **Cela ne peut pas se faire en SQL**, et l'écrire ici serait le
 * défaut que `Core\Service\CleDeRecherche` existe pour rendre impossible : il faudrait
 * réimplémenter le repliement d'accents une seconde fois, en PL/pgSQL cette fois, et les
 * deux définitions divergeraient un jour sans que rien ne rougisse. La reprise passe donc
 * par la commande `heritage:recalculer-les-cles-de-recherche`, qui appelle **la** classe
 * de repliement.
 */
final class Version20260916120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reprise : libellés d’auteur vides, et preuves restées attachées à un brouillon déjà résolu.';
    }

    public function up(Schema $schema): void
    {
        // 1. Le libellé d'auteur retombe sur le courriel, comme le fait désormais le code.
        $this->addSql(<<<'SQL'
            UPDATE prp_change_request AS d
               SET author_label = u.email
              FROM core_user AS u
             WHERE u.id = d.author_user_id
               AND btrim(d.author_label) = ''
            SQL);

        // Le libellé du décideur souffre du même défaut, par la même fabrique. Aucune
        // ligne concernée sur la base de travail — l'administrateur a un nom d'affichage —
        // mais la reprise doit couvrir le défaut, pas l'échantillon qui l'a révélé.
        $this->addSql(<<<'SQL'
            UPDATE prp_change_request AS d
               SET decided_by_label = u.email
              FROM core_user AS u
             WHERE u.id = d.decided_by_user_id
               AND d.decided_by_label IS NOT NULL
               AND btrim(d.decided_by_label) = ''
            SQL);

        // 2. Les preuves orphelines rejoignent la personne que leur demande a créée.
        $this->addSql(<<<'SQL'
            UPDATE med_media AS m
               SET subject_id         = b.resolved_person_id,
                   subject_label      = left(p.display_name, 255),
                   subject_draft_ref  = NULL,
                   subject_request_id = NULL,
                   status             = 'actif',
                   updated_at         = now()
              FROM prp_draft_person AS b
              JOIN tree_person      AS p ON p.id = b.resolved_person_id
             WHERE b.request_id = m.subject_request_id
               AND ltrim(b.local_ref, '#') = m.subject_draft_ref
               AND m.subject_draft_ref IS NOT NULL
               AND b.resolved_person_id IS NOT NULL
            SQL);
    }

    /**
     * **Irréversible, et il faut le dire plutôt que de faire semblant.**
     *
     * Un `down()` devrait remettre `author_label` à `''` et rendre les médias à leur
     * brouillon. Le premier restaurerait un défaut ; le second est impossible sans avoir
     * mémorisé quelles lignes ont été touchées — après coup, plus rien ne distingue une
     * preuve reprise par cette migration d'une preuve résolue normalement par la passe 6.
     *
     * Une reprise de données qui ment sur sa réversibilité est pire qu'une reprise qui
     * l'assume : elle laisse croire qu'un retour arrière est sûr.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Reprise de données : les libellés d’auteur et les preuves rattachées ne sont pas distinguables après coup.',
        );
    }
}
