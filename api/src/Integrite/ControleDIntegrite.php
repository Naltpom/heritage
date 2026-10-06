<?php

declare(strict_types=1);

namespace App\Integrite;

use App\Module\Core\Contract\ReferenceInterModules;

/**
 * Le verdict sur une référence : combien de lignes relevées, lesquelles pendent.
 *
 * Trois états, et le troisième n'est pas une faiblesse du contrôle :
 *
 * - **sain** — aucune valeur pendante ;
 * - **violation** — des valeurs pendantes sur une référence stricte. Le code de sortie le dit ;
 * - **toléré** — des valeurs pendantes sur une référence faite pour survivre à sa cible
 *   (historique, preuve, caducité prévue). Montré, expliqué, sans effet sur le code de sortie.
 */
final readonly class ControleDIntegrite
{
    /**
     * @param string          $module   le module qui a déclaré la référence
     * @param array<int, int> $pendants identifiant introuvable => nombre de lignes qui le désignent
     */
    public function __construct(
        public string $module,
        public ReferenceInterModules $reference,
        public array $pendants,
    ) {
    }

    public function lignesRelevees(): int
    {
        return array_sum($this->reference->occurrences);
    }

    public function lignesPendantes(): int
    {
        return array_sum($this->pendants);
    }

    public function estSain(): bool
    {
        return [] === $this->pendants;
    }

    public function estUneViolation(): bool
    {
        return !$this->estSain() && null === $this->reference->tolerance;
    }

    public function etat(): string
    {
        return match (true) {
            $this->estSain() => 'sain',
            $this->estUneViolation() => 'violation',
            default => 'toleree',
        };
    }

    /** @return array<string, mixed> */
    public function versJson(): array
    {
        return [
            'module' => $this->module,
            'source' => $this->reference->source,
            'portee' => $this->reference->portee,
            'cible' => $this->reference->cible->value,
            'etat' => $this->etat(),
            'lignesRelevees' => $this->lignesRelevees(),
            'lignesPendantes' => $this->lignesPendantes(),
            'identifiantsPendants' => array_keys($this->pendants),
            'tolerance' => $this->reference->tolerance,
        ];
    }
}
