<?php

declare(strict_types=1);

namespace App\Module\Core\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * `422` — la requête est bien formée mais la donnée soumise est refusée (§ 15.2).
 *
 * Le code par défaut est `donnees_invalides` ; il se surcharge pour les 422 nommées du
 * contrat, `valeur_hors_bornes` en particulier, dont les bornes voyagent dans `details`.
 */
class ValidationException extends DomainException
{
    /**
     * @param array<string, string>     $champs  `{"nouveauMotDePasse": "12 caractères minimum."}`
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        private readonly array $champs,
        string $message = 'Les données envoyées sont invalides.',
        private readonly string $codeErreur = 'donnees_invalides',
        private readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public function statutHttp(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    public function codeErreur(): string
    {
        return $this->codeErreur;
    }

    public function champs(): ?array
    {
        return $this->champs;
    }

    public function details(): ?array
    {
        return $this->details;
    }
}
