<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une personne de l'arbre que le lecteur **voit**, telle que le rapprochement des
 * doublons la compare et la montre ({@see ArbrePourLesDoublons}).
 *
 * Deux formes de la même date, et elles ne font pas double emploi :
 *
 * - `naissance` / `deces` sont les **valeurs comparées** — la naissance d'un vivant y
 *   est déjà réduite aux composantes que la réciprocité accorde (ADR 0016), inconnue
 *   s'il n'en reste rien ;
 * - `blocNaissance` / `blocDeces` sont les **blocs servis**, tels que la fiche les sert
 *   (§ 5.3) : la naissance d'un vivant y est un bloc `masquee` quand elle n'est pas vue
 *   entière, pour qu'« inconnue » et « non partagée » ne se distinguent pas.
 *
 * Les proches sont des références courtes `{personId, displayName}` dont le nom suit
 * déjà le cercle — le masque pour une fiche confidentielle hors du cercle.
 */
final readonly class PersonneCandidate
{
    /**
     * @param array<string, mixed>                            $blocNaissance
     * @param array<string, mixed>                            $blocDeces
     * @param list<array{personId: int, displayName: string}> $parents
     * @param list<array{personId: int, displayName: string}> $conjoints
     * @param list<array{personId: int, displayName: string}> $enfants
     */
    public function __construct(
        public int $personId,
        public string $displayName,
        public string $givenNames,
        public string $surname,
        public ?string $marriedName,
        public ?string $nickname,
        public string $sex,
        public bool $deceased,
        public bool $vivant,
        public UncertainDate $naissance,
        public UncertainDate $deces,
        public array $blocNaissance,
        public array $blocDeces,
        public string $ligneDates,
        public array $parents,
        public array $conjoints,
        public array $enfants,
    ) {
    }
}
