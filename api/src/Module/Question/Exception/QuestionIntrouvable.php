<?php

declare(strict_types=1);

namespace App\Module\Question\Exception;

/**
 * La question n'existe pas, **ou elle n'est pas posée à ce compte**.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LES DEUX CAS RENDENT LA MÊME CHOSE, ET C'EST LA DÉCISION QUI COMPTE ICI.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un `403` sur la question d'autrui apprendrait que la ligne existe. Or une question
 * **nomme une personne** : « n° 412 existe mais n'est pas à vous » se recoupe avec le
 * reste pour reconstituer à qui l'on demande quoi, donc qui connaît qui. C'est le § 15.3
 * du contrat d'API — *« la réponse ne dit pas à un lecteur hors cercle quelles personnes
 * existent »* — appliqué à une porte de sortie nouvelle.
 *
 * Le dépôt porte déjà la règle dans sa clause `WHERE` plutôt qu'en vérification après
 * coup : on ne peut pas oublier de la faire.
 */
final class QuestionIntrouvable extends ErreurDeQuestion
{
    public static function pourLeCompte(): self
    {
        return new self(
            'question_introuvable',
            'Cette question n’existe pas.',
            404,
        );
    }
}
