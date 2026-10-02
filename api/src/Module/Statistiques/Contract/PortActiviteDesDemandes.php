<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/**
 * Le chantier (administration) : combien de demandes de modification ont été proposées et
 * validées, mois par mois. Implémenté par `Proposal`, qui possède `prp_change_request`.
 *
 * Aucun nom, aucun libellé : des comptes. Le port ne peut donc rien laisser fuir d'une
 * personne, et il n'a pas besoin du cercle.
 */
interface PortActiviteDesDemandes
{
    /**
     * Les mois de `$depuis` à `$jusqua` (inclus), du plus ancien au plus récent, **sans
     * trou** : un mois sans demande vaut zéro et figure quand même — une barre absente se
     * lirait comme une donnée manquante.
     *
     * @return list<DemandesDuMois>
     */
    public function parMois(\DateTimeImmutable $depuis, \DateTimeImmutable $jusqua): array;

    /** Les demandes déposées qui attendent une décision. */
    public function enAttente(): int;
}
