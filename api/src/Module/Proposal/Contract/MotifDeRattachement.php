<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * **Pourquoi** une filiation atterrit dans telle famille — le résultat de la règle, dit
 * avec ses mots.
 *
 * Elle existe parce qu'une filiation à un seul parent n'a plus de destination évidente.
 * Avant le 01/10/2026, `enfant_de` + un parent P visait toujours le couple `(P, ∅)`, et
 * une famille monoparentale naissait à côté de celle que l'enfant avait déjà. C'est ce
 * qui a donné trois familles de parents à Véronique (469) — deux « fantômes » à un
 * parent, plus le vrai couple — corrigées à la main dans la base. Le détail des règles
 * est en tête de {@see \App\Module\Proposal\Service\Port\PortArbre::etablirFiliation()}.
 *
 * Elle traverse le port en énumération de `Proposal` et non de `Tree` : `Proposal` doit
 * pouvoir la nommer (pour l'annoncer et la tracer), et la règle 3 de l'ADR 0002 lui
 * interdit de nommer quoi que ce soit de `Tree`.
 */
enum MotifDeRattachement: string
{
    /** L'enfant est déjà dans une famille dont ce parent est l'époux : rien à créer. */
    case DejaRattache = 'deja_rattache';

    /** Deux parents désignés : leur famille de couple, existante ou à créer. */
    case Couple = 'couple';

    /**
     * Règle 1 : l'enfant avait une famille à un seul parent Q, et la place libre reçoit
     * P. La famille garde son identifiant, ses autres données et sa révision.
     */
    case FamilleCompletee = 'famille_completee';

    /**
     * Règle 1, second cas : Q et P forment **déjà** un couple ailleurs. L'enfant y est
     * rattaché, et quitte la famille à un parent.
     */
    case CoupleExistant = 'couple_existant';

    /**
     * Règle 1, troisième cas : la famille de Q compte **d'autres** enfants. La compléter
     * donnerait P pour parent à des frères dont personne ne l'a dit ; le couple Q × P se
     * forme donc à côté, et seul cet enfant y passe.
     */
    case CoupleForme = 'couple_forme';

    /**
     * Règle 3 : l'enfant n'avait aucune famille de parents et P n'a qu'une union, à deux
     * époux. L'enfant y est rattaché plutôt que dans une famille à un parent.
     */
    case UnionUnique = 'union_unique';

    /** Le cas d'avant, devenu le dernier recours : la famille `(P, ∅)`. */
    case ParentSeul = 'parent_seul';

    /**
     * Le motif qui mérite d'être **annoncé** à l'administrateur avant qu'il valide : ceux
     * où la famille d'arrivée n'est pas celle que la lecture littérale du lien désigne.
     * « Enfant de Jean-Marie » qui atterrit chez « Jean-Marie × Hélène » doit se lire
     * avant le clic, pas se découvrir dans l'arbre après.
     */
    public function seDevineMal(): bool
    {
        return match ($this) {
            self::FamilleCompletee, self::CoupleExistant, self::CoupleForme, self::UnionUnique => true,
            self::DejaRattache, self::Couple, self::ParentSeul => false,
        };
    }
}
