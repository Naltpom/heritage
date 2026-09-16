<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Le **régime de lecture** d'un compte — et non son rôle, ni son cercle.
 *
 * Deux valeurs, et la distinction entre elles est une décision de produit, pas une
 * commodité d'affichage (ADR 0003, `docs/arbitrages.md` § 3, § 12 du contrat d'API) :
 *
 * - `Aucun` : le compte **n'a pas de cercle de visibilité**. Il lit les personnes
 *   décédées, et aucune personne vivante — y compris celle à laquelle il prétend se
 *   rattacher.
 * - `Cercle` : le compte a un cercle, calculé par `Membership` selon la formule de
 *   l'ADR 0003 avec le `N` du réglage `cercle.generations`.
 *
 * > **Ce n'est pas « un cercle avec N = 0 ».** Par la formule de l'ADR, `A(u) = { u }`
 * > quand `N = 0` ; après la passe des conjoints, le cercle contient donc `u` **et son
 * > conjoint** — exactement les deux fiches de personnes vivantes qu'on veut fermer. Un
 * > `N = 0` n'est pas un cercle vide, c'est une implémentation fausse immédiatement.
 * > D'où une énumération, et non un entier : il n'y a pas de `N` pour `Aucun`, donc pas
 * > de valeur à mettre à zéro.
 *
 * Dans la charge de session (§ 2.2 du contrat), la clé `cercle.regime` porte cette valeur
 * et **le front branche dessus** ; `generations` et `taille` sont *absents* du régime
 * `Aucun`, de sorte qu'un front qui testerait `taille === 0` lirait `undefined`.
 */
enum Regime: string
{
    case Aucun = 'aucun';
    case Cercle = 'cercle';

    public function label(): string
    {
        return match ($this) {
            self::Aucun => 'Personnes décédées uniquement',
            self::Cercle => 'Cercle de visibilité',
        };
    }
}
