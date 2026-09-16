<?php

declare(strict_types=1);

namespace App\Module\Core\Exception;

/**
 * Racine des erreurs métier de l'application, traduites en JSON par
 * {@see \App\Module\Core\EventSubscriber\ApiExceptionSubscriber}.
 *
 * C'est le seul mécanisme par lequel un service dit « non » : lever l'une de ces
 * exceptions suffit, aucun service ne construit de réponse HTTP. Le contrôleur ne connaît
 * donc jamais la correspondance erreur métier → code HTTP, et il n'y a qu'un endroit à
 * relire pour vérifier qu'elle respecte le § 15.2 du contrat d'API.
 *
 * `codeErreur()` et non `getCode()` : `\Exception::getCode()` est un entier, le contrat
 * veut une chaîne `snake_case` stable sur laquelle le front branche.
 */
abstract class DomainException extends \RuntimeException
{
    abstract public function statutHttp(): int;

    /** Code stable du § 15.2, en `snake_case`. Il ne change jamais ; le message, si. */
    abstract public function codeErreur(): string;

    /** @return array<string, string>|null Rempli sur les 422 uniquement */
    public function champs(): ?array
    {
        return null;
    }

    /** @return array<string, mixed>|null */
    public function details(): ?array
    {
        return null;
    }
}
