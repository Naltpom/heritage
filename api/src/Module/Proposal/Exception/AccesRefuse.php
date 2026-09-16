<?php

declare(strict_types=1);

namespace App\Module\Proposal\Exception;

/**
 * Les `403` du module.
 *
 * **Pourquoi `403` et non `404` sur la demande d'autrui**, alors que la règle générale
 * du § 15.3 dit l'inverse : « une ressource que le lecteur n'a pas le droit de voir
 * rend `404` ». Une demande n'est pas une ressource secrète — à la différence d'un
 * jeton d'invitation, son existence n'apprend rien sur les personnes de l'arbre. Et un
 * `404` empêcherait de diagnostiquer un lien partagé par erreur (§ 6 du contrat d'API).
 */
final class AccesRefuse extends ErreurDeDemande
{
    private function __construct(string $code, string $message)
    {
        parent::__construct($code, $message, 403);
    }

    public static function demandeDAutrui(): self
    {
        return new self('acces_refuse', 'Cette demande ne vous appartient pas.');
    }

    /**
     * Un compte `en_attente` de validation ne dépose pas de demande : il n'a pas de
     * cercle de visibilité, donc rien sur quoi proposer une correction (§ 12).
     */
    public static function regimeEnAttente(): self
    {
        return new self(
            'regime_en_attente',
            'Votre rattachement est en cours de validation : vous ne pouvez pas encore contribuer.',
        );
    }
}
