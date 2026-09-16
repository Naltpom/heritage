<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

/**
 * Qui dépose, ou qui lit. Objet valeur, construit une fois par requête par
 * {@see FabriqueDeLecteur}.
 *
 * Il existe pour que les services du module ne reçoivent **jamais** un jeton de sécurité
 * ni une `Request` : un service qui prend un `LecteurMedia` est testable sans pare-feu,
 * et c'est ce qui rend les tests d'attaque de ce module écrivables.
 *
 * ## Ce qu'il ne porte pas, et pourquoi
 *
 * **Ni le régime, ni le cercle.** Les deux se lisent sur
 * {@see VisibiliteDesSujetsInterface}, jamais ici : le noyau veut que le lecteur soit
 * implicite pour que la bascule « voir comme un membre » ne puisse pas être contournée
 * par un appelant qui reconstruirait son lecteur à la main. Un `LecteurMedia` qui aurait
 * porté un régime aurait été exactement ce contournement.
 *
 * ## Ce qu'il porte encore, et pourquoi ce n'est pas la même chose
 *
 * `administrateur` reste, mais il ne sert **jamais** à décider d'une visibilité — il n'y
 * a pas de mode « sans filtre », y compris pour un administrateur. Il ne sert qu'à deux
 * questions qui n'ont rien à voir avec le cercle :
 *
 * - la **file de validation** : un média `en_attente` ou `refuse` n'est lisible que de
 *   son déposant et des administrateurs. C'est une règle d'état, pas de confidentialité ;
 *   elle s'applique *avant* le cercle et ne le remplace pas.
 * - deux **champs privilégiés** de la charge utile, `originalName` et `sha256` (§ 7.2).
 *
 * La suppression administrative est encore autre chose : une permission d'**action**,
 * qui rend `403` et non `404`, parce que l'existence de la ressource n'est pas un secret
 * pour qui vient de la lire.
 */
final readonly class LecteurMedia
{
    public function __construct(
        /** ⇢ inter-module (`Core\User.id`). */
        public int $userId,
        /** Libellé dénormalisé, recopié dans `med_media.uploaded_by_label` au dépôt. */
        public string $libelle,
        public bool $administrateur,
    ) {
    }
}
