<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * CE QUE LES AUTRES MODULES ACCROCHENT À UNE FICHE — et ce qu'une suppression en fait.
 *
 * `Tree` connaît ses six clés étrangères, `Media` ses preuves, `Membership` le compte qui a
 * la fiche pour racine : l'inventaire de suppression les recolle depuis l'origine. Quatre
 * autres modules tiennent pourtant un `person_id` **sans clé étrangère** et n'étaient
 * consultés par personne — une fiche supprimée y laissait des lignes pendantes, et
 * `heritage:verifier-integrite` les aurait trouvées après coup. Chaque module mesure
 * désormais les siennes ({@see DependancesDUneFiche}), et cette énumération dit **pour
 * chacune** ce que la suppression en fait.
 *
 * ## Deux sorts, décidés ici et nulle part ailleurs
 *
 * - **Bloque** — la référence est vivante, quelqu'un agit dessus, et **un geste existant**
 *   permet de la rediriger vers l'autre fiche du doublon. La suppression est refusée au
 *   dépôt, avec la raison et le geste ; c'est le même traitement que la preuve ou le compte.
 * - **Nettoyée** — la ligne n'a de sens que par la fiche, ne témoigne d'aucune décision, et
 *   aucun écran ne permettrait de la rediriger. Elle part **dans la transaction de la
 *   validation**, annoncée d'avance par l'inventaire, et tracée deux fois : dans
 *   `prp_revision.changes` avec le reste de l'inventaire, et au journal d'audit par le
 *   module qui l'efface.
 *
 * Ce qui n'est **ni l'un ni l'autre** n'apparaît pas ici, et c'est délibéré : l'historique.
 * Le suivi d'un événement clos, une invitation close, une autorisation de naissance
 * archivée survivent à leur fiche sous leur libellé dénormalisé — ADR 0014 et 0016,
 * § 6.4 quater du contrat d'API.
 * `heritage:verifier-integrite` les relève comme **tolérées**.
 *
 * ## L'ordre des cas est un contrat
 *
 * C'est celui de `dependances` dans l'inventaire stocké, et l'inventaire se compare en
 * entier à la validation ({@see InventaireDeSuppression::versJson()}). Ajouter un cas se fait
 * à la fin.
 *
 * Retirer un cas est possible, et a été fait une fois : `tirage` (`trg_participant`) est
 * parti le 2026-10-20 avec le module `Tirage` (ADR 0009, remplacé). Un inventaire stocké
 * qui le nomme encore se relit par `tryFrom()`, qui l'écarte ; la comparaison à la
 * validation le voit alors changé, et l'administrateur relit l'aperçu — ce qui est juste,
 * puisque la dépendance a bel et bien disparu.
 */
enum DependanceDeFiche: string
{
    /** `dmr_personne` — une démarche d'acte la concerne (ADR 0011). */
    case Demarche = 'demarche';

    /** `evt_invitation` — elle est invitée à un événement à venir ou en cours, non annulé (ADR 0014). */
    case EvenementAVenir = 'evenement_a_venir';

    /** `mbr_autorisation_naissance` — une autorisation signée active est déposée sur elle (ADR 0016). */
    case AutorisationDeNaissance = 'autorisation_naissance';

    /** `qst_question` — des questions sont posées à son sujet (ADR 0008). */
    case Question = 'question';

    public function bloque(): bool
    {
        return self::Question !== $this;
    }

    /**
     * Pourquoi elle bloque, prêt à poser sur `targetId` — la même forme que les raisons de
     * {@see InventaireDeSuppression::raisonsDuBlocage()}.
     *
     * **Un compte, jamais un nom.** Ces raisons partent sur une route ouverte à tout membre :
     * l'intitulé d'une démarche masquée ou le nom d'un événement
     * où le lecteur n'est pas invité n'ont pas à en sortir. Le nombre suffit à dire qu'il
     * faut aller voir, et le geste à faire dit où.
     */
    public function raison(int $nombre): string
    {
        $pluriel = $nombre > 1;

        return match ($this) {
            self::Demarche => \sprintf(
                '%d démarche%s d\'acte (page « Actes demandés ») concerne%s cette fiche : une démarche dont une personne a disparu ne se montre plus à personne. '
                .'Désignez-y l\'autre fiche — « Corriger » sur la démarche — avant de demander la suppression de celle-ci.',
                $nombre,
                $pluriel ? 's' : '',
                $pluriel ? 'nt' : '',
            ),
            self::EvenementAVenir => \sprintf(
                'Cette fiche est invitée à %d événement%s à venir : l\'invitation la relancerait et attendrait sa réponse. '
                .'Son organisateur doit d\'abord la retirer des invités (ou inviter l\'autre fiche).',
                $nombre,
                $pluriel ? 's' : '',
            ),
            self::AutorisationDeNaissance => 'Une autorisation signée de partager sa date de naissance est déposée sur cette fiche : c\'est une preuve de consentement, elle ne part pas en effet de bord. '
                .'Un administrateur doit d\'abord la retirer — et la déposer sur l\'autre fiche s\'il s\'agit d\'un doublon.',
            self::Question => '',
        };
    }

    /** Ce que la suppression emporte, pour la phrase de {@see InventaireDeSuppression::enUneLigne()}. */
    public function enMorceau(int $nombre): string
    {
        $pluriel = $nombre > 1;

        return match ($this) {
            self::Demarche => \sprintf('%d démarche%s d\'acte', $nombre, $pluriel ? 's' : ''),
            self::EvenementAVenir => \sprintf('%d invitation%s à un événement à venir', $nombre, $pluriel ? 's' : ''),
            self::AutorisationDeNaissance => 'une autorisation de partage de sa date de naissance',
            self::Question => \sprintf('%d question%s posée%s à son sujet', $nombre, $pluriel ? 's' : '', $pluriel ? 's' : ''),
        };
    }
}
