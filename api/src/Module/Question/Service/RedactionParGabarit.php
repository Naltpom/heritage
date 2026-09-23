<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Question\Enum\RedacteurDeQuestion;

/**
 * Le repli : la phrase de {@see \App\Module\Question\Enum\NatureDeLacune::gabarit()},
 * remplie avec le nom de la personne. **Aucun appel sortant, aucune dépendance.**.
 *
 * ## Ce fichier est la raison pour laquelle la page ne peut pas être vide
 *
 * C'est le dernier maillon de la chaîne de rédaction (ADR 0008, § 4), et son absence de
 * dépendances **est** sa fonction : il doit répondre quand tout le reste est tombé. Lui
 * injecter quoi que ce soit — un client HTTP, un cache, un réglage, une base — rouvrirait
 * la porte qu'il existe pour fermer. Si l'on se surprend à vouloir ajouter un argument à
 * son constructeur, c'est qu'on s'est trompé de classe.
 *
 * Une panne du tiers ne se voit donc **nulle part à l'écran** : la page sert les mêmes
 * questions, moins bien tournées. C'est voulu, et c'est aussi pourquoi
 * `qst_question.redacteur` existe — sans cette colonne, « le repli a-t-il servi ? » se
 * répondrait en relisant des journaux, c'est-à-dire ne se répondrait pas.
 *
 * ## Il n'émet pas d'alerte, et c'est une décision
 *
 * Un déploiement sans `HERITAGE_GEMINI_CLE_API` sert **toutes** ses questions d'ici. Ce
 * n'est pas une panne : c'est l'état normal d'une installation qui n'a pas souscrit, et
 * celui de l'intégration continue. Un rouge permanent sur un état normal détruit l'alarme
 * là où elle sert.
 */
final class RedactionParGabarit implements RedacteurDeQuestions
{
    public function rediger(array $lacunes): array
    {
        $textes = [];

        foreach ($lacunes as $lacune) {
            // Indexé par signature : deux lacunes de même signature dans un même lot
            // seraient la même lacune, et la seconde écraserait la première par la même
            // valeur. L'appelant n'en tire qu'une question, ce qui est juste.
            $textes[$lacune->signature()] = new TexteDeQuestion(
                $lacune->texteParDefaut(),
                RedacteurDeQuestion::Regles,
            );
        }

        return $textes;
    }
}
