<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

use App\Module\Core\Enum\CibleDeReference;

/**
 * Une flèche du § 10.8 de `docs/modele-donnees.md`, relevée dans la base : une colonne
 * d'un module qui porte l'identifiant d'une ligne d'un autre, **sans clé étrangère**.
 *
 * C'est le module **propriétaire de la colonne** qui la déclare et qui la lit — il ne lit
 * que ses propres tables. Il ne sait pas si les identifiants existent : c'est le
 * propriétaire de la cible qui le dira ({@see CiblesDeReferenceInterface}), et
 * `heritage:verifier-integrite` qui rapproche les deux. Personne ne joint deux modules.
 *
 * ## `tolerance` : pendante n'est pas toujours fautive
 *
 * Certaines colonnes sont faites pour **survivre** à leur cible — un historique, une preuve,
 * une demande qui deviendra caduque à son examen (§ 6.8 du contrat d'API). Une valeur
 * pendante y est l'état prévu, pas une anomalie. La référence est alors **quand même
 * relevée** — une table tronquée à la main se verrait aussi là —, mais le rapport la range
 * parmi les tolérées, avec la raison écrite ici, et le code de sortie ne change pas.
 *
 * Une même colonne peut être déclarée deux fois sous deux portées : `evt_invitation.person_id`
 * est stricte pour un événement à venir et tolérée pour un événement clos.
 */
final readonly class ReferenceInterModules
{
    /**
     * @param string           $source      `table.colonne`, telle que la base la nomme
     * @param CibleDeReference $cible       la table désignée
     * @param array<int, int>  $occurrences identifiant désigné => nombre de lignes qui le désignent
     * @param string|null      $portee      les lignes relevées, en français, quand ce ne sont pas toutes
     *                                      les lignes non nulles (« subject_kind = personne »,
     *                                      « invitations actives »)
     * @param string|null      $tolerance   non nul : une valeur pendante est attendue, et voici pourquoi
     */
    public function __construct(
        public string $source,
        public CibleDeReference $cible,
        public array $occurrences,
        public ?string $portee = null,
        public ?string $tolerance = null,
    ) {
    }

    /** `source [portée]` — de quoi distinguer deux déclarations de la même colonne. */
    public function libelle(): string
    {
        return null === $this->portee ? $this->source : \sprintf('%s [%s]', $this->source, $this->portee);
    }
}
