<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Dto;

use App\Module\Gedcom\Service\CollecteurAnomalies;

/**
 * Ce qu'une exécution d'import a produit : les totaux obtenus, ceux qu'on attendait, les
 * écarts, et les anomalies.
 *
 * Deux jeux de totaux et non un : `attendus` vient du fichier de référence, `obtenus` est
 * **recompté depuis la base** après écriture — pas depuis le modèle en mémoire. C'est ce
 * qui fait la différence entre « j'ai lu 274 personnes » et « il y a 274 personnes en
 * base », et c'est la seconde phrase qu'on veut pouvoir dire.
 */
final class RapportImport
{
    /**
     * @param array<string, int>      $obtenus
     * @param array<string, int>|null $attendus `null` : aucun jeu de référence pour ce fichier
     */
    public function __construct(
        public readonly array $obtenus,
        public readonly ?array $attendus,
        public readonly CollecteurAnomalies $anomalies,
        public readonly string $fichier,
        public readonly string $sha256,
        public readonly int $octets,
        public readonly ?string $charset,
        public readonly string $ego,
        public readonly bool $aBlanc,
        public readonly float $secondes,
        public readonly ?int $runId = null,
    ) {
    }

    /**
     * Les clés dont le total obtenu diffère du total attendu.
     *
     * @return array<string, array{attendu: int, obtenu: int}>
     */
    public function ecarts(): array
    {
        if (null === $this->attendus) {
            return [];
        }

        $ecarts = [];

        foreach ($this->attendus as $cle => $attendu) {
            $obtenu = $this->obtenus[$cle] ?? 0;

            if ($obtenu !== $attendu) {
                $ecarts[$cle] = ['attendu' => $attendu, 'obtenu' => $obtenu];
            }
        }

        return $ecarts;
    }

    public function estConforme(): bool
    {
        return [] === $this->ecarts();
    }

    /** Le fichier a-t-il un jeu de totaux de référence ? */
    public function estControle(): bool
    {
        return null !== $this->attendus;
    }
}
