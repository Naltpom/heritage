<?php

declare(strict_types=1);

namespace App\Module\Question\Enum;

/**
 * Ce qu'il est advenu d'une question posée.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  `Inconnue` ET `Differee` NE SONT PAS LE MÊME ÉTAT, ET LES FONDRE CASSE LA PAGE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * C'est le point de conception de l'ADR 0008 (§ 8), et il ne se rattrape pas après
 * coup — un seul bouton « masquer » perd l'une des deux informations, et on ne sait
 * plus laquelle :
 *
 * - **« Je ne sais pas » ferme la question.** Sans cette mémoire, la même question
 *   revient à chaque chargement. Au bout d'une semaine, la page est un mur de choses
 *   auxquelles on a déjà répondu, c'est-à-dire un écran qu'on n'ouvre plus. La page
 *   devient inutilisable **par accumulation**, et rien ne le signale : elle affiche
 *   toujours des questions, elles sont toujours justes, elles ont seulement déjà été
 *   écartées.
 * - **« Je vais me renseigner » la repousse.** C'est une promesse — « je demanderai à
 *   ma tante ». La refermer définitivement perd la contribution la plus probable de
 *   toutes ; ne rien en faire la noie dans la liste.
 *
 * ── Ce qu'aucun de ces états ne fait ────────────────────────────────────────────
 *
 * **Aucun ne dit que la lacune est comblée.** Une question comblée n'a pas d'état :
 * la détection cesse de la produire, et la page sert l'intersection de ce qui est
 * détecté maintenant et de ce qui n'est ni fermé ni différé (ADR 0008, § 9). Ajouter
 * ici un cas `SansObjet` obligerait à un balayage périodique pour le poser — « de la
 * mécanique pour rien » à ce volume, et une seconde source de vérité sur l'état de
 * l'arbre.
 */
enum EtatDeQuestion: string
{
    /** Posée, sans réponse. C'est l'état servi par la page. */
    case Posee = 'posee';

    /**
     * « Je ne sais pas ». **Définitif** : la question ne revient jamais pour ce
     * destinataire, même si la lacune persiste.
     *
     * Elle n'écrit rien dans l'arbre. C'est pourtant une réponse, et `repondu_le` est
     * renseigné : « personne ne sait » est un renseignement, et le savoir évite de
     * chercher deux fois.
     */
    case Inconnue = 'inconnue';

    /**
     * « Je vais me renseigner ». Revient après `relancer_apres`.
     *
     * Le compteur `reports` existe pour que la troisième relance ne soit pas la
     * dixième : une promesse qu'on rappelle indéfiniment est un harcèlement poli, et
     * le membre finit par répondre « je ne sais pas » pour s'en débarrasser — ce qui
     * détruit l'information au lieu de la recueillir.
     */
    case Differee = 'differee';

    /**
     * Une réponse a été déposée, et elle a produit **au moins une demande** dans la
     * file de validation (`qst_question_demande`).
     *
     * **Définitif, même si la demande est refusée ou devient caduque.** La reprise se
     * fait depuis « Mes demandes », avec le motif du refus et le bouton « refaire cette
     * demande » de l'ADR 0004 — rouvrir la question en plus donnerait deux endroits
     * pour la même reprise, donc deux endroits pour l'oublier.
     */
    case Repondue = 'repondue';

    /** Une question tranchée ne se répond plus ; seule sa trace se consulte. */
    public function estDefinitif(): bool
    {
        return \in_array($this, [self::Inconnue, self::Repondue], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Posee => 'Posée',
            self::Inconnue => 'Sans réponse connue',
            self::Differee => 'À revoir plus tard',
            self::Repondue => 'Répondue',
        };
    }
}
