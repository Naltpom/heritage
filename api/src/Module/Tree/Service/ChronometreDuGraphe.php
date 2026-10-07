<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Le temps passé à servir `GET /api/arbre/graphe`, étape par étape, rendu dans un
 * en-tête `Server-Timing` (onglet Réseau → Minutage du navigateur).
 *
 * ## Pourquoi il existe
 *
 * À 3 000 personnes, un F5 sur l'arbre coûtait 7 s, sans que rien dise si c'était le
 * serveur, le réseau, le placement refait par le navigateur ou le rendu. Ce chronomètre
 * répond à la première question. Il ne change rien au calcul : il l'entoure.
 *
 * ## Les étapes s'emboîtent
 *
 * `etag` contient `empreinte` et, au premier calcul, `ascendance` et `placement` : les
 * services sont mémorisés pour la requête, et c'est le premier appelant qui paie. Les
 * durées ne s'additionnent donc pas — `total` est la seule qui compte pour la somme.
 * Une étape appelée plusieurs fois cumule sa durée et dit combien de fois (`xN`).
 *
 * ## Une requête, pas un processus
 *
 * FrankenPHP garde les services d'une requête à l'autre en mode worker : `reset()`
 * (balise `kernel.reset`, posée par l'autoconfiguration) remet le chronomètre à zéro.
 */
final class ChronometreDuGraphe implements ResetInterface
{
    /** @var array<string, array{ms: float, fois: int, note: string|null}> */
    private array $etapes = [];

    /**
     * @template T
     *
     * @param callable(): T $calcul
     *
     * @return T
     */
    public function mesure(string $etape, callable $calcul): mixed
    {
        // Inscrite dès le départ : l'en-tête suit l'ordre des débuts, chaque étape avant
        // celles qu'elle contient.
        $this->etapes[$etape] ??= ['ms' => 0.0, 'fois' => 0, 'note' => null];
        $debut = hrtime(true);

        try {
            return $calcul();
        } finally {
            $ms = (hrtime(true) - $debut) / 1e6;
            $deja = $this->etapes[$etape];
            $this->etapes[$etape] = ['ms' => $deja['ms'] + $ms, 'fois' => $deja['fois'] + 1, 'note' => $deja['note']];
        }
    }

    /**
     * Une précision sans durée — `cache=hit`, un effectif, la mémoire. Sans `remplace`,
     * une note déjà posée l'emporte : un `cache=miss` ne s'efface pas sous le `cache=hit`
     * de l'appel suivant.
     */
    public function note(string $etape, string $note, bool $remplace = true): void
    {
        $deja = $this->etapes[$etape] ?? ['ms' => 0.0, 'fois' => 0, 'note' => null];
        $this->etapes[$etape] = ['ms' => $deja['ms'], 'fois' => $deja['fois'], 'note' => $remplace || null === $deja['note'] ? $note : $deja['note']];
    }

    /** La valeur de l'en-tête `Server-Timing`, chaîne vide si rien n'a été mesuré. */
    public function enTete(): string
    {
        $parties = [];

        foreach ($this->etapes as $etape => ['ms' => $ms, 'fois' => $fois, 'note' => $note]) {
            $partie = preg_replace('/[^A-Za-z0-9_-]/', '_', $etape) ?? 'etape';

            if ($fois > 0) {
                $partie .= \sprintf(';dur=%.1f', $ms);
            }

            $description = array_filter([$fois > 1 ? 'x'.$fois : null, $note]);

            if ([] !== $description) {
                $partie .= ';desc="'.addcslashes(implode(' ', $description), '"\\').'"';
            }

            $parties[] = $partie;
        }

        return implode(', ', $parties);
    }

    public function reset(): void
    {
        $this->etapes = [];
    }
}
