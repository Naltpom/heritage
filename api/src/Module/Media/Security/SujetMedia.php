<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

use App\Module\Media\Enum\MediaSubject;

/**
 * Ce à quoi un média se rattache, tel que le module propriétaire (`Tree`) le décrit.
 *
 * `Media` ne lit jamais `tree_person` : il **demande**, et il ne stocke un `subject_id`
 * qu'après que `Tree` le lui a confirmé et lui a rendu le libellé. C'est la mesure 1 de
 * la compensation de l'ADR 0002 — « aucun identifiant n'entre en base sans être passé
 * par son propriétaire ». Sans elle, la suppression de la clé étrangère inter-module
 * n'est plus un choix d'architecture, c'est un trou.
 */
final readonly class SujetMedia
{
    public function __construct(
        public MediaSubject $kind,
        public int $id,
        /**
         * Le libellé à dénormaliser dans `med_media.subject_label`.
         *
         * Il est traité comme la personne elle-même (§ 11.3 du contrat) : un média dont
         * le sujet n'est pas visible n'est pas rendu **du tout**, donc son libellé ne
         * sort pas non plus. C'est ce qui ferme la « huitième porte » ici.
         */
        public string $libelle,
    ) {
    }
}
