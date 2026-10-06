<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

/**
 * Le résultat d'une comparaison qui conclut au doublon probable
 * ({@see RapprochementDeDoublons}).
 *
 * `criteres` dit **sur quoi** : `nom`, `prenom`, `naissance`, `deces`, `sexe`, `parents`,
 * `conjoints`, `enfants`. Le front n'en tire aucune décision — il compare lui-même champ
 * par champ ce qu'il affiche —, mais un rapprochement qu'on ne sait pas expliquer est un
 * rapprochement qu'on ne sait pas corriger.
 */
final readonly class Rapprochement
{
    /** @param list<string> $criteres */
    public function __construct(
        public int $score,
        public array $criteres,
    ) {
    }
}
