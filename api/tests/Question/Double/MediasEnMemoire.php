<?php

declare(strict_types=1);

namespace App\Tests\Question\Double;

use App\Module\Question\Contract\PortMediasPourQuestions;

/**
 * Le compte des médias, joué par un tableau.
 *
 * **Ce double n'est pas le repli**, et la distinction porte du sens : le repli
 * ({@see \App\Module\Question\Service\Port\MediasNonBranchesPourQuestions}) fait taire la
 * nature `aucune_photo`, parce qu'un tableau vide rendu par un port non câblé ouvrirait
 * la lacune sur toutes les fiches. Ce double, lui, est **branché** : un tableau vide y
 * veut dire « aucune de ces personnes n'a de photo », et la question se pose.
 *
 * C'est exactement le cas que `ServiceDesQuestions::photosDemandables()` distingue par le
 * type plutôt que par le contenu, et c'est ce qui rend ce double indispensable pour
 * l'éprouver.
 */
final class MediasEnMemoire implements PortMediasPourQuestions
{
    /** @param array<int, int> $comptes */
    public function __construct(private array $comptes = [])
    {
    }

    public function comptesParPersonne(): array
    {
        return $this->comptes;
    }
}
