<?php

declare(strict_types=1);

namespace App\Module\Core\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * `404 introuvable`.
 *
 * À lever aussi — et surtout — pour ce que le lecteur **n'a pas le droit de voir**
 * (§ 15.3) : un `403` confirmerait l'existence de la ressource, ce qui est exactement ce
 * qu'on refuse sur un jeton d'invitation ou sur le média d'une personne hors cercle.
 */
final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'Ressource introuvable.')
    {
        parent::__construct($message);
    }

    public function statutHttp(): int
    {
        return Response::HTTP_NOT_FOUND;
    }

    public function codeErreur(): string
    {
        return 'introuvable';
    }
}
