<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Proposal\Enum\ValueKind;

/**
 * Une demande peut ne changer que le **lieu** d'une date : la ligne du diff le dit
 * (demande 5335 : « Naissance : (inconnue) → (inconnue) » pour « né à Montréal »).
 */
final class LieuDansLeDiffTest extends BaseDeDemandes
{
    public function testLeLieuSeDitDansLaLigneDuDiff(): void
    {
        $inconnue = ['raw' => null, 'qualifier' => 'inconnue', 'min' => null, 'max' => null, 'place' => null];

        self::assertSame('(inconnue)', $this->normaliseur->enUneLigne(ValueKind::DateIncertaine, $inconnue));
        self::assertSame(
            '(inconnue), à Montréal, Canada',
            $this->normaliseur->enUneLigne(ValueKind::DateIncertaine, ['place' => 'Montréal, Canada'] + $inconnue),
        );
        self::assertSame(
            '1987, à Saule',
            $this->normaliseur->enUneLigne(ValueKind::DateIncertaine, ['place' => 'Saule'] + $this->blocDate($this->annee(1987))),
        );
    }
}
