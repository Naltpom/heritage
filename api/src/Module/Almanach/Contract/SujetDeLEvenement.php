<?php

declare(strict_types=1);

namespace App\Module\Almanach\Contract;

use App\Module\Core\Enum\Sex;

/**
 * Une personne **concernée** par un événement — un sujet, pas un propriétaire.
 *
 * Le mot compte. Un mariage a **deux** sujets et aucun propriétaire ; lui en désigner un
 * serait attribuer l'événement à l'un des deux époux, ce que
 * `src/Module/Timeline/Contract/FaitDeLaChronologie.php` a explicitement refusé de faire
 * pour les faits de famille :
 *
 * > un fait de famille n'a pas de `personId` à y mettre, et lui en inventer un — le
 * > premier époux, par exemple — serait attribuer à quelqu'un un événement qui ne le
 * > nomme pas.
 *
 * `Timeline` en a tiré la conséquence d'écarter ces faits de son port. `Almanach` en tire
 * l'autre : une liste de sujets, de un à deux, et l'événement n'appartient à personne.
 * C'est ce qui lui permet de porter les 103 unions que la chronologie ne peut pas montrer.
 *
 * ## `vivant` traverse le port, et il n'est pas décoratif
 *
 * Il vient de `Tree\Service\ReglePersonneVivante`, c'est-à-dire de **la** définition
 * calculée du § 9.2 — jamais de `tree_person.deceased`, qui dit seulement qu'un `DEAT`
 * figurait dans le GEDCOM. Il sert au rendu (une fiche de vivant se signale à l'écran) et
 * il rend le filtrage **lisible en test** : un sujet `vivant: true` absent de la réponse
 * est un cercle qui a fait son travail.
 *
 * Il ne **décide** de rien : c'est {@see \App\Module\Almanach\Service\FluxVisible} qui
 * filtre, en interrogeant le cercle de visibilité, et lui seul.
 *
 * ## `personId` et `displayName` gardent leurs noms anglais
 *
 * Ce sont les deux colonnes de `tree_person` qu'ils transportent, et le contrat d'API les
 * écrit ainsi partout ailleurs. Une clé `identifiantPersonne` obligerait le front à
 * connaître deux noms pour la même donnée selon la route qui la sert.
 */
final readonly class SujetDeLEvenement
{
    public function __construct(
        public int $personId,
        public string $displayName,
        public Sex $sex,
        public bool $vivant,
    ) {
    }

    /** @return array{personId: int, displayName: string, sex: string, vivant: bool} */
    public function enJson(): array
    {
        return [
            'personId' => $this->personId,
            'displayName' => $this->displayName,
            'sex' => $this->sex->value,
            'vivant' => $this->vivant,
        ];
    }
}
