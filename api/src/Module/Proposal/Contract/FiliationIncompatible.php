<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * La règle 4 : **un enfant n'est jamais dans deux familles de parents qui partagent un
 * même parent.**.
 *
 * Levée par `Tree` quand une filiation y conduirait et qu'aucune des règles de fusion
 * ne sait la résoudre sans détruire de donnée — typiquement un enfant déjà rattaché au
 * couple `A × X`, qu'une demande dit enfant de `A × B`. Deux familles complètes, deux
 * mères possibles : ce n'est pas au validateur de choisir, c'est à un humain qui
 * corrige l'arbre d'abord.
 *
 * C'est le garde du défaut du 01/10/2026 (trois familles de parents pour Véronique,
 * dont deux partageaient Jean-Marie ou Hélène avec la troisième). Il est **dans la
 * transaction** : rien n'est écrit quand il lève. `Proposal` le traduit en `409
 * filiation_incompatible` — ni « réessayer », ni « refaire » : corriger l'arbre, puis
 * valider.
 */
final class FiliationIncompatible extends \RuntimeException
{
}
