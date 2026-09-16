<?php

declare(strict_types=1);

namespace App\Tests\Tree\Double;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\CercleDeVisibiliteInterface;

/**
 * Le contrat de confidentialité du noyau, joué par un ensemble réglable.
 *
 * Il existe pour une raison étroite : **éprouver la composition de l'`ETag`**, c'est-à-dire
 * vérifier que deux cercles différents en sortent différents. Ce n'est pas un double de la
 * formule de l'ADR 0003 — cette formule appartient à `Membership`, elle n'a qu'une
 * implémentation dans le produit, et elle est éprouvée chez elle. Ici on ne veut qu'une
 * chose du cercle : qu'il soit **celui-ci** plutôt que **celui-là**.
 *
 * > **Une différence à connaître avec l'implémentation réelle.** `filtrerPersonnes()` y
 * > laisse passer les personnes **décédées** avant même de regarder le cercle (règle 1 du
 * > contrat : un fait historique est visible de tout compte connecté). Ce double n'a
 * > aucune idée de qui est vivant, et rend donc le cercle **nu**. La nuance ne change rien
 * > à ce qui est éprouvé ici — deux ensembles différents restent deux ensembles
 * > différents — mais elle interdit de s'en servir pour éprouver la confidentialité
 * > elle-même, qui se mesure sur la charge servie ({@see \App\Tests\Tree\RoutesDeLArbreTest}).
 */
final class CercleEnMemoire implements CercleDeVisibiliteInterface
{
    /** @var array<int, true> */
    private array $personnes = [];

    /** @param list<int> $personnes */
    public function __construct(private readonly Regime $regime = Regime::Cercle, array $personnes = [])
    {
        foreach ($personnes as $id) {
            $this->personnes[$id] = true;
        }
    }

    /** Le régime distinct du compte en attente de validation : **pas de cercle du tout**. */
    public static function sansCercle(): self
    {
        return new self(Regime::Aucun);
    }

    public function regime(): Regime
    {
        return $this->regime;
    }

    public function voitPersonne(int $personId): bool
    {
        return Regime::Cercle === $this->regime && isset($this->personnes[$personId]);
    }

    public function filtrerPersonnes(array $personIds): array
    {
        $visibles = [];

        foreach ($personIds as $id) {
            if ($this->voitPersonne($id)) {
                $visibles[$id] = true;
            }
        }

        return array_keys($visibles);
    }
}
