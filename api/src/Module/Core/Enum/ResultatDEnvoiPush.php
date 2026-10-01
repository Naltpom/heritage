<?php

declare(strict_types=1);

namespace App\Module\Core\Enum;

/**
 * Ce que le service push a répondu pour UN appareil.
 *
 * Trois issues et pas deux, parce qu'elles appellent trois gestes différents :
 *
 *  - `Remis`  — accepté (`201`) : on note l'heure, et c'est tout ;
 *  - `Expire` — `404`/`410` : l'abonnement n'existe plus chez le service push, **on le
 *               supprime**. Le garder, c'est un appel perdu à chaque notification, pour
 *               toujours ;
 *  - `Echec`  — tout le reste (réseau, délai dépassé, `5xx`, `429`, `403`…) : l'appareil
 *               existe peut-être encore, on ne touche à rien. Supprimer sur une panne
 *               passagère désabonnerait un téléphone en parfait état sans qu'il le sache.
 */
enum ResultatDEnvoiPush: string
{
    case Remis = 'remis';
    case Expire = 'expire';
    case Echec = 'echec';

    /**
     * L'issue à retenir quand un même appareil a reçu plusieurs messages dans une salve :
     * `Expire` l'emporte (l'appareil n'existe plus, quel que soit le message), puis
     * `Remis`, puis `Echec`.
     */
    public static function laPlusDecisive(?self $deja, self $nouvelle): self
    {
        foreach ([self::Expire, self::Remis] as $prioritaire) {
            if ($prioritaire === $deja || $prioritaire === $nouvelle) {
                return $prioritaire;
            }
        }

        return self::Echec;
    }
}
