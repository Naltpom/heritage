<?php

declare(strict_types=1);

namespace App\Module\Core\ValueObject;

use App\Module\Core\Enum\MotifDeFinDeRecherche;

/**
 * La valeur d'une fin de recherche : `{reason, beforeYear, body}` (ADR 0023, § 6.2 bis du
 * contrat d'API). Les trois clés sont solidaires, comme celles d'un bloc date.
 */
final readonly class FinDeRecherche
{
    public function __construct(
        public MotifDeFinDeRecherche $reason,
        public ?int $beforeYear,
        public ?string $body,
    ) {
    }

    /**
     * La forme JSON canonique, **dans cet ordre** (la comparaison se fait sur le tableau
     * entier et `JSONB` ne préserve pas l'ordre d'écriture).
     *
     * @return array{reason: string, beforeYear: int|null, body: string|null}
     */
    public function versJson(): array
    {
        return [
            'reason' => $this->reason->value,
            'beforeYear' => $this->beforeYear,
            'body' => $this->body,
        ];
    }

    /**
     * Relit une valeur **déjà validée** (venue de `JSONB`) : tolérant, un motif inconnu rend
     * `null`, jamais d'exception.
     *
     * @param array<array-key, mixed> $json
     */
    public static function depuisJson(array $json): ?self
    {
        $motif = $json['reason'] ?? null;
        $motif = \is_string($motif) ? MotifDeFinDeRecherche::tryFrom($motif) : null;
        if (null === $motif) {
            return null;
        }
        $annee = $json['beforeYear'] ?? null;
        $texte = $json['body'] ?? null;

        return new self(
            $motif,
            \is_int($annee) ? $annee : null,
            \is_string($texte) ? $texte : null,
        );
    }

    /**
     * La phrase en clair — la même que `phraseDeFinDeRecherche()` du front :
     * « Registres inexistants avant 1668 — Saint-Martin commence en 1668 ».
     */
    public function enClair(): string
    {
        if ($this->reason->avecAnnee()) {
            $phrase = null !== $this->beforeYear
                ? \sprintf('Registres inexistants avant %d', $this->beforeYear)
                : 'Registres inexistants';
        } else {
            $phrase = $this->reason->label();
        }

        return null !== $this->body && '' !== $this->body ? $phrase.' — '.$this->body : $phrase;
    }
}
