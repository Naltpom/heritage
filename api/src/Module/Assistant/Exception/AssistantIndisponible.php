<?php

declare(strict_types=1);

namespace App\Module\Assistant\Exception;

use App\Module\Core\Exception\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `503 assistant_indisponible` — aucune clé n'est configurée, ou le fournisseur est coupé.
 *
 * **Ce n'est pas une panne du produit** : c'est l'état d'une installation qui n'a pas
 * souscrit, et celui de l'intégration continue (même règle que la page « Questions »). Le
 * formulaire reste là, et le front le dit — d'où un code distinct de `assistant_erreur`,
 * qui, lui, invite à réessayer.
 */
final class AssistantIndisponible extends DomainException
{
    public function __construct(string $message = 'L’assistant n’est pas disponible sur cette installation. Le formulaire reste à votre disposition.')
    {
        parent::__construct($message);
    }

    public function statutHttp(): int
    {
        return Response::HTTP_SERVICE_UNAVAILABLE;
    }

    public function codeErreur(): string
    {
        return 'assistant_indisponible';
    }
}
