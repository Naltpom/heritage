<?php

declare(strict_types=1);

namespace App\Tests\Tree\Double;

use App\Module\Tree\Service\EmpreinteDeLaMiseEnPage;

/**
 * L'empreinte de la mise en page, posée à la main.
 *
 * Le port existe exactement pour permettre ce double : la vraie implémentation est
 * {@see \App\Module\Tree\Service\PlacementPartage}, qui traîne le cache, Doctrine et le
 * chargement de l'arbre. La règle de clé qu'éprouve {@see \App\Tests\Tree\EtagDuGrapheTest}
 * n'a besoin d'aucun des trois — seulement d'une chaîne qui change, ou pas.
 */
final class MiseEnPageEnMemoire implements EmpreinteDeLaMiseEnPage
{
    /** @param array<int, string> $parPointDeVue une empreinte par personne regardée */
    public function __construct(
        private readonly string $racine = 'image-de-la-racine',
        private readonly array $parPointDeVue = [],
    ) {
    }

    public function empreinteDeLaMiseEnPage(?int $egoPersonneId): string
    {
        if (null === $egoPersonneId) {
            return $this->racine;
        }

        // Le repli dépend du point de vue : deux lecteurs rattachés à deux personnes
        // différentes reçoivent deux images, et le double ne doit pas laisser croire le
        // contraire au cas qui ne précise rien.
        return $this->parPointDeVue[$egoPersonneId] ?? 'image-de-p'.$egoPersonneId;
    }
}
