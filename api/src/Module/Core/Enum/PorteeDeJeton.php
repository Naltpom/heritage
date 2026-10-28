<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Ce qu'un {@see \App\Module\Core\Entity\JetonDApi} a le droit de faire (ADR 0021 « Jetons
 * d'API », § 1.9 du contrat d'API).
 *
 * Les portées sont **emboîtées**, du plus faible au plus fort : qui peut valider peut
 * contribuer, qui peut contribuer peut lire. Une portée ne remplace jamais les droits du
 * compte, elle les **réduit** : un jeton ne fait rien qu'une session du même utilisateur ne
 * pourrait faire. La liste blanche de routes de chaque portée vit dans
 * `Core\EventSubscriber\PorteeDuJetonSubscriber`, fermée par défaut.
 */
enum PorteeDeJeton: string
{
    /** Lire ce que le compte voit. Aucune écriture. */
    case Lecture = 'lecture';

    /** Lire, et déposer des demandes (propositions) sans les décider. */
    case Contribution = 'contribution';

    /** Contribuer, et décider des demandes — sous réserve de la règle de l'auteur (`DemandeVoter`). */
    case Validation = 'validation';

    /** 1 pour la plus faible. Sert à comparer, jamais à être stocké. */
    public function niveau(): int
    {
        return match ($this) {
            self::Lecture => 1,
            self::Contribution => 2,
            self::Validation => 3,
        };
    }

    /** Cette portée couvre-t-elle ce que demande `$requise` ? */
    public function couvre(self $requise): bool
    {
        return $this->niveau() >= $requise->niveau();
    }
}
