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
     * Un administrateur qui voudrait compléter le **brouillon** d'un membre (§ 6.6 ter).
     *
     * Un brouillon non envoyé est le travail en cours de son auteur : il ne l'a pas encore
     * soumis, et y écrire serait décider à sa place de ce qu'il enverra — la même raison
     * qui interdit à l'administrateur d'y joindre une pièce (`FichesProposeesInterface`).
     * Le même code `acces_refuse` qu'une demande d'autrui : le front n'a pas d'autre geste
     * à proposer que d'attendre l'envoi.
     */
    public static function brouillonDAutrui(): self
    {
        return new self('acces_refuse', 'Un brouillon ne se complète que par son auteur : attendez qu\'il soit envoyé.');
    }

    /**
     * Sous un jeton d'API, une demande en attente ne se retire pas (ADR 0021 § 2) : elle est
     * dans la file, et c'est à un humain d'en décider.
     */
    public static function retraitSousJeton(): self
    {
        return new self('acces_refuse', 'Sous un jeton d\'API, une demande en attente ne se retire pas : elle attend un humain.');
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
