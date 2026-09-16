<?php

declare(strict_types=1);

namespace App\Module\Membership\Enum;

/**
 * L'état d'un rattachement compte ↔ personne (§ 4.5 du modèle de données).
 *
 * Un membre `EnAttente` a un compte qui se connecte et dont le cercle de visibilité est
 * **vide**. Ce n'est pas un affichage conditionnel : c'est le cercle qui est vide, et la
 * règle du § 6.2 du dossier (« filtrage appliqué côté serveur, dans la sérialisation »)
 * s'applique sans exception, y compris à ce cas dégénéré.
 *
 * Ce qu'un cercle vide laisse voir, précisément : les personnes **décédées**, et elles
 * seules — soit 256 fiches sur 274. Pas une exception à la règle, sa conséquence
 * mécanique. Décision de produit signalée dans `docs/arbitrages.md` (point N).
 */
enum MemberStatus: string
{
    case Actif = 'actif';
    case EnAttente = 'en_attente';
    case Suspendu = 'suspendu';

    public function label(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::EnAttente => "En attente d'activation",
            self::Suspendu => 'Suspendu',
        };
    }
}
