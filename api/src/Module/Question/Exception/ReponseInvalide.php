<?php

declare(strict_types=1);

namespace App\Module\Question\Exception;

/**
 * Le corps de la réponse ne dit rien d'exploitable.
 *
 * Le cas courant est **la réponse vide** : ni texte, ni photo. Ce n'est pas « je ne sais
 * pas » — il y a un bouton pour ça, et les deux ne veulent pas dire la même chose. Une
 * réponse vide acceptée en silence marquerait la question `repondue` sans rien déposer :
 * le membre croirait avoir contribué, l'administrateur ne verrait rien, et la question ne
 * reviendrait jamais. **C'est la seule perte irréparable de ce circuit.**
 */
final class ReponseInvalide extends ErreurDeQuestion
{
    public static function pourChamp(string $champ, string $message): self
    {
        return new self('reponse_invalide', $message, 422, [$champ => $message]);
    }

    public static function vide(): self
    {
        return self::pourChamp(
            'reponse',
            'Écrivez une réponse ou joignez une photo. Si vous ne savez pas, choisissez « Je ne sais pas ».',
        );
    }
}
