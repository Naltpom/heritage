<?php

declare(strict_types=1);

namespace App\Module\Core\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * `403` — l'action est refusée sur une ressource que le lecteur **peut voir**.
 *
 * La frontière avec {@see NotFoundException} est la règle générale du § 15.3 : on ne rend
 * `403` que si l'existence de la ressource n'est pas elle-même un secret. Deux codes
 * seulement dans le contrat : `acces_refuse` (voter) et `regime_en_attente` (écriture
 * demandée par un compte non encore validé, § 12.2).
 */
final class ForbiddenException extends DomainException
{
    public const REGIME_EN_ATTENTE_MESSAGE = 'Votre rattachement est en cours de vérification. Vous pourrez proposer des modifications une fois votre compte activé.';

    public function __construct(
        private readonly string $codeErreur = 'acces_refuse',
        string $message = 'Accès refusé.',
    ) {
        parent::__construct($message);
    }

    /** Le refus d'écriture opposé à un compte en attente de validation (§ 12.2). */
    public static function regimeEnAttente(): self
    {
        return new self('regime_en_attente', self::REGIME_EN_ATTENTE_MESSAGE);
    }

    public function statutHttp(): int
    {
        return Response::HTTP_FORBIDDEN;
    }

    public function codeErreur(): string
    {
        return $this->codeErreur;
    }
}
