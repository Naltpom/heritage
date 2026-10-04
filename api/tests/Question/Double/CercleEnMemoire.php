<?php

declare(strict_types=1);

namespace App\Tests\Question\Double;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Security\CercleDeVisibiliteInterface;

/**
 * Le contrat de confidentialité du noyau, joué par un ensemble **réglable après coup**.
 *
 * Ce n'est pas un double de la formule de l'ADR 0003 — elle appartient à `Membership`,
 * n'a qu'une implémentation dans le produit, et est éprouvée chez elle. Ici on ne veut du
 * cercle qu'une chose : **qu'il change entre deux lectures**, parce que c'est précisément
 * le cas que `LacunesVisibles::filtrerLesQuestions()` existe pour attraper — un cercle
 * rétréci par un changement de `cercle.generations`, alors que les questions d'hier sont
 * déjà en base.
 *
 * {@see retirer()} est donc la méthode qui compte ; les autres ne servent qu'à la poser.
 *
 * > **Une différence avec l'implémentation réelle, à connaître avant de s'en servir
 * > ailleurs.** La vraie `filtrerPersonnes()` laisse passer les personnes **décédées**
 * > avant même de regarder le cercle (règle 1 du contrat). Ce double n'a aucune idée de
 * > qui est vivant et rend le cercle **nu** : plus fermé que la réalité. C'est le bon
 * > sens pour un test de confidentialité — il ne peut pas faire passer ce que la
 * > production retiendrait.
 */
final class CercleEnMemoire implements CercleDeVisibiliteInterface
{
    /** @var array<int, true> */
    private array $personnes = [];

    /** @param list<int> $personnes */
    public function __construct(private Regime $regime = Regime::Cercle, array $personnes = [])
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

    /** Le cercle rétrécit — le cas que le contrôle à la lecture existe pour attraper. */
    public function retirer(int $personId): self
    {
        unset($this->personnes[$personId]);

        return $this;
    }

    public function regime(): Regime
    {
        return $this->regime;
    }

    /** Un seul arbre dans ce double : tout est dans le périmètre. */
    public function dansLePerimetre(int $personId): bool
    {
        return true;
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
