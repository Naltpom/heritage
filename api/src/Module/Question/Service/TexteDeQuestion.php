<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Question\Enum\RedacteurDeQuestion;

/**
 * Une question rédigée : la phrase, et **qui l'a écrite**.
 *
 * Les deux voyagent ensemble parce qu'elles se posent ensemble en base
 * (`qst_question.texte` et `qst_question.redacteur`). Un rédacteur qui rendrait la phrase
 * seule obligerait l'appelant à deviner l'origine — et il devinerait « gemini » pour tout
 * ce qui sort du service Gemini, y compris les lignes que ce service aura, en réalité,
 * fait écrire au repli parce que le tiers n'a pas répondu.
 *
 * C'est exactement ce qu'on ne veut pas : `redacteur` existe pour que
 * `SELECT redacteur, count(*) FROM qst_question GROUP BY 1` réponde à « le repli
 * a-t-il servi ? ». Une panne de tiers ne se manifeste par **aucun symptôme visible** —
 * la page continue de servir des questions, simplement plus raides.
 */
final readonly class TexteDeQuestion
{
    public function __construct(
        public string $texte,
        public RedacteurDeQuestion $redacteur,
    ) {
    }
}
