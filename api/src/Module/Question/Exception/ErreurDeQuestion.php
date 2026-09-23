<?php

declare(strict_types=1);

namespace App\Module\Question\Exception;

use App\Module\Core\Exception\DomainException;

/**
 * Racine des erreurs métier de ce module.
 *
 * Elle n'ajoute rien à {@see DomainException} sinon un constructeur : le contrôleur ne
 * construit **jamais** de réponse HTTP, et la correspondance « erreur métier → code
 * HTTP » ne se lit qu'à un seul endroit du produit. Même geste que
 * `Proposal\Exception\ErreurDeDemande`.
 *
 * Le code d'erreur est une chaîne `snake_case` **stable** : le front branche dessus, et
 * le message, lui, peut être réécrit.
 */
abstract class ErreurDeQuestion extends DomainException
{
    /**
     * @param array<string, string>|null $champs  rempli sur les 422 seulement
     * @param array<string, mixed>|null  $details ce que le front peut lire sans connaître
     *                                            la liste des codes
     */
    public function __construct(
        private readonly string $codeErreur,
        string $message,
        private readonly int $statut,
        private readonly ?array $champs = null,
        private readonly ?array $details = null,
        ?\Throwable $precedente = null,
    ) {
        parent::__construct($message, 0, $precedente);
    }

    public function statutHttp(): int
    {
        return $this->statut;
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
