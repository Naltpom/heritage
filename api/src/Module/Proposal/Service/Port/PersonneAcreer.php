<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service\Port;

use App\Module\Core\ValueObject\UncertainDate;

/**
 * La fiche d'une personne **proposée**, telle que `Tree` doit l'insérer.
 *
 * C'est la copie colonne à colonne du sous-ensemble éditable de `tree_person` : le
 * brouillon a été conçu pour que la validation soit exactement cela, « sans table de
 * correspondance — et une table de correspondance entre deux jeux de noms est l'endroit
 * où les champs se perdent en silence quand on en ajoute un » (§ 5.3 du modèle).
 *
 * **Ce qu'elle ne porte pas** : ni `displayName`, ni `searchKey`, ni `branch`, ni
 * `generation`, ni `gedcomXref`, ni `version`. Ce sont des colonnes dérivées, et c'est
 * `Tree` qui les pose — lui seul sait composer un nom d'affichage comme il le fait pour
 * les 274 autres, et lui seul attribue un `gedcom_xref` après le `flush` (§ 9.8). Les
 * calculer ici produirait une personne créée qui ne ressemble pas à ses voisines.
 *
 * `sex` traverse en **chaîne** — la valeur de `Core\Enum\Sex` — comme tout ce qui est
 * énumération au bord de ce port : voir le tableau de {@see PortArbre}.
 */
final readonly class PersonneAcreer
{
    public function __construct(
        public string $givenNames,
        public string $surname,
        public ?string $marriedName,
        public ?string $nickname,
        public string $sex,
        public bool $deceased,
        public UncertainDate $birth,
        public UncertainDate $death,
        /** La note de recherche du demandeur ; elle deviendra une `tree_note` de portée générale. */
        public ?string $note = null,
    ) {
    }
}
