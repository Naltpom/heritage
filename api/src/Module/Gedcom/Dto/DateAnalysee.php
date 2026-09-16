<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Le résultat de l'analyse d'une chaîne `DATE` : le bloc date, et ce qu'on n'a pas su lire.
 *
 * Deux valeurs plutôt qu'une parce que le convertisseur ne doit **pas** écrire dans le
 * journal : il est appelé 621 fois, il doit rester une fonction pure pour qu'un test
 * puisse le nourrir d'une chaîne et regarder ce qui sort. C'est l'appelant, qui connaît
 * le numéro de ligne et l'enregistrement, qui transforme `$anomalie` en `ged_issue`.
 */
final class DateAnalysee
{
    public function __construct(
        public readonly UncertainDate $date,
        /** Le message à journaliser, ou `null` si la chaîne a été comprise. */
        public readonly ?string $anomalie = null,
    ) {
    }
}
