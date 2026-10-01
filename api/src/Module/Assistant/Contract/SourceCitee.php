<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * Une citation de la fiche, telle que l'assistant la transmet au modèle.
 *
 * **`appui` est ce qui compte** : c'est le niveau que la fiche affiche à côté de chaque
 * citation (`Tree\Serializer\AppuiDUneCitation`) — le plus bas du fonds et de l'usage
 * (`QUAY`). C'est lui qui permet au modèle de dire « d'après l'acte de naissance » ou
 * « d'après un arbre en ligne, non vérifié », et de ne pas présenter le second comme le
 * premier. `appuiLibelle` est sa phrase française (« tenu par un acte »), reprise telle
 * quelle pour que l'assistant parle comme la fiche.
 *
 * Les adresses d'arbres en ligne sont déjà retirées de `page` et `texte`.
 */
final readonly class SourceCitee
{
    public function __construct(
        public string $appui,
        public string $appuiLibelle,
        public ?string $qualite,
        public string $titre,
        public string $fonds,
        public ?string $page,
        public ?string $contexte,
        public ?string $texte,
    ) {
    }
}
