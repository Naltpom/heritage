<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * L'état d'un compte d'authentification (§ 2.7 du modèle de données).
 *
 * Un compte `EnAttente` **s'authentifie**, et il voit les 256 fiches de personnes
 * décédées et aucune personne vivante — y compris celle à laquelle il prétend se
 * rattacher.
 *
 * > **Il n'a pas « un cercle vide » : il n'a pas de cercle du tout.** La nuance est la
 * > décision du commanditaire (ADR 0003, `docs/arbitrages.md` § 3, § 12 du contrat
 * > d'API), et elle n'est pas de vocabulaire. Un cercle vide, ça se fabrique en posant
 * > `N = 0` — et un `N = 0` **reste un cercle** : par la formule de l'ADR,
 * > `A(u) = { u }`, puis la passe des conjoints y ajoute le conjoint. On obtient donc
 * > exactement les deux fiches de personnes vivantes qu'on voulait fermer. « Cercle
 * > vide » est une implémentation fausse immédiatement, pas une formulation
 * > approximative.
 *
 * C'est pourquoi le régime est porté par une énumération à deux valeurs sans
 * profondeur — {@see Regime} — et non par un entier : il n'existe aucune valeur de `N`
 * qui produise ce régime, et le code ne doit pas laisser croire le contraire.
 * `Core\Security\RegimeVoter::regimeDe()` est la seule correspondance statut → régime du
 * projet.
 */
enum UserStatus: string
{
    case Actif = 'actif';
    case EnAttente = 'en_attente';
    case Suspendu = 'suspendu';

    public function label(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::EnAttente => 'En attente de validation',
            self::Suspendu => 'Suspendu',
        };
    }
}
