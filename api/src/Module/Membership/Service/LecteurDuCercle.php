<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Enum\Regime;

/**
 * Qui lit, et sous quelle identité. Objet valeur, construit une fois par requête par
 * {@see FabriqueDuLecteurDuCercle}.
 *
 * Il existe pour la même raison que `Tree\Serializer\LecteurDeLArbre` et
 * `Proposal\Service\Lecteur` : aucun service de calcul de ce module ne reçoit jamais un
 * jeton de sécurité ni une `Request`. Un service qui prend un `LecteurDuCercle` se teste
 * en une ligne, sans pare-feu, sans session et sans base — et c'est ce qui rend
 * écrivables les tests de la règle la plus sensible du produit.
 *
 * ## `personneAncre` peut être nulle alors que le régime est `Cercle`
 *
 * Trois cas, et ils ne se confondent pas :
 *
 * - **un administrateur sans rattachement** — le compte d'amorçage créé en console n'a pas
 *   de `mbr_member` (§ 4.2 du modèle : « `NULL` pour le compte d'amorçage »). Il obtient
 *   le **cercle complet**, qui n'a pas d'ancre parce qu'il n'a pas de formule ;
 * - **un compte actif dont le `mbr_member` a disparu** — référence pendante, contrepartie
 *   assumée de l'absence de clé étrangère inter-module (ADR 0002). Défaut fermé : pas de
 *   cercle ;
 * - **un compte `en_attente`** — régime `Aucun`, et il n'y a rien à ancrer.
 */
final readonly class LecteurDuCercle
{
    /**
     * @param int      $userId        ⇢ inter-module (`Core\User.id`)
     * @param Regime   $regime        le régime **effectif** : `Cercle` sous la bascule, même
     *                                si l'administrateur n'a pas de rattachement à lui
     * @param int|null $memberId      `mbr_member.id` du lecteur **effectif** — celui du membre
     *                                emprunté sous la bascule. Il entre dans la clé de cache
     *                                du § 9.3
     * @param int|null $personneAncre le `u` de la formule de l'ADR 0003, `null` pour un
     *                                cercle complet ou pour l'absence de cercle
     * @param bool     $cercleComplet l'administrateur hors bascule : il voit tout, **par un
     *                                cercle qui contient tout**, jamais par un
     *                                `if ($estAdmin) { return true; }` que l'ADR 0003 interdit
     * @param bool     $sousBascule   la bascule « voir comme un membre » est active (§ 10.4)
     */
    public function __construct(
        public int $userId,
        public Regime $regime,
        public ?int $memberId = null,
        public ?int $personneAncre = null,
        public bool $cercleComplet = false,
        public bool $sousBascule = false,
    ) {
    }

    /** Le lecteur n'a pas de cercle : personnes décédées uniquement (§ 12 du contrat). */
    public static function sansCercle(int $userId): self
    {
        return new self($userId, Regime::Aucun);
    }
}
