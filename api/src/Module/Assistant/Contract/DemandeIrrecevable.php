<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * Le corps proposé par le modèle ne passerait pas le dépôt.
 *
 * **Ce n'est pas une erreur HTTP.** L'analyse ne rend jamais `422` pour une proposition
 * du modèle : elle la retire et le dit dans `avertissements`. Le message est donc écrit
 * pour le membre — c'est celui du constructeur de `Proposal`, qui nomme déjà le champ et
 * le geste attendu.
 */
final class DemandeIrrecevable extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $precedente = null)
    {
        parent::__construct($message, 0, $precedente);
    }
}
