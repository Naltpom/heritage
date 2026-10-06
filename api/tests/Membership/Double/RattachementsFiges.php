<?php

declare(strict_types=1);

namespace App\Tests\Membership\Double;

use App\Module\Membership\Service\Port\RattachementsAuxArbres;

/** Les fiches réclamées par un compte, posées par le test. Vide : tous les arbres sont « sans lien ». */
final readonly class RattachementsFiges implements RattachementsAuxArbres
{
    /** @param list<int> $personnes */
    public function __construct(private array $personnes = [])
    {
    }

    public function personnes(): array
    {
        return $this->personnes;
    }
}
