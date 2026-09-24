<?php

declare(strict_types=1);

namespace App\Module\Assistant\Exception;

use App\Module\Core\Exception\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `502 assistant_erreur` — le modèle a échoué, ou a rendu une réponse inexploitable.
 *
 * Délai dépassé, `429` ou `500` du fournisseur, filtre de sécurité, JSON tronqué, ou —
 * le cas le plus fréquent d'un modèle d'agent — **il n'a jamais appelé `proposer`** dans
 * le nombre de tours imparti. Le message dit au membre ce qu'il peut faire ; la cause
 * technique part au journal, jamais dans la réponse : elle pourrait citer un morceau de
 * la charge, et la charge contient ses documents.
 */
final class AssistantEnErreur extends DomainException
{
    public function __construct(
        string $message = 'L’assistant n’a pas pu terminer l’analyse. Réessayez dans un moment, ou utilisez le formulaire.',
        ?\Throwable $precedente = null,
    ) {
        parent::__construct($message, 0, $precedente);
    }

    public function statutHttp(): int
    {
        return Response::HTTP_BAD_GATEWAY;
    }

    public function codeErreur(): string
    {
        return 'assistant_erreur';
    }
}
