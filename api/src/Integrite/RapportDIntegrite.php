<?php

declare(strict_types=1);

namespace App\Integrite;

/**
 * Le résultat d'un passage de `heritage:verifier-integrite`.
 *
 * `anomalies` porte ce qui empêche de conclure — une cible sans propriétaire, deux
 * propriétaires pour la même. Ce ne sont pas des références pendantes, mais un contrôle qui
 * n'a pas pu se faire ne doit pas pouvoir passer pour un contrôle réussi : elles font
 * échouer la commande au même titre qu'une violation.
 */
final readonly class RapportDIntegrite
{
    /**
     * @param list<ControleDIntegrite> $controles
     * @param list<string>             $anomalies
     */
    public function __construct(
        public array $controles,
        public array $anomalies = [],
    ) {
    }

    /** @return list<ControleDIntegrite> */
    public function violations(): array
    {
        return array_values(array_filter($this->controles, static fn (ControleDIntegrite $c): bool => $c->estUneViolation()));
    }

    /** @return list<ControleDIntegrite> */
    public function tolerees(): array
    {
        return array_values(array_filter($this->controles, static fn (ControleDIntegrite $c): bool => 'toleree' === $c->etat()));
    }

    public function estSain(): bool
    {
        return [] === $this->violations() && [] === $this->anomalies;
    }

    /** @return array<string, mixed> */
    public function versJson(): array
    {
        return [
            'sain' => $this->estSain(),
            'controles' => \count($this->controles),
            'violations' => \count($this->violations()),
            'tolerees' => \count($this->tolerees()),
            'anomalies' => $this->anomalies,
            'details' => array_map(static fn (ControleDIntegrite $c): array => $c->versJson(), $this->controles),
        ];
    }
}
