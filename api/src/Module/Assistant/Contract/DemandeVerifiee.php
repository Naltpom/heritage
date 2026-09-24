<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * Un corps de demande **complet et vérifié** : il passerait `POST /api/demandes` tel quel,
 * à `soumettre` et `message` près.
 *
 * `changements` décrit les ops d'une modification en français, avec la valeur **réelle**
 * d'avant. Ce n'est pas une vue pour le lecteur : c'est l'assistant qui décide ensuite si
 * cette valeur peut lui être montrée (`Service\VueDuLecteur`). Une valeur lue ici ne part
 * jamais telle quelle vers le navigateur.
 */
final readonly class DemandeVerifiee
{
    /**
     * @param array<string, mixed>    $corps
     * @param list<ChangementVerifie> $changements un par op ; vide pour une création ou un
     *                                             ajout de lien, que l'assistant décrit
     *                                             lui-même
     */
    public function __construct(
        public array $corps,
        public array $changements,
    ) {
    }
}
