<?php

declare(strict_types=1);

namespace App\Module\Proposal\Exception;

/**
 * `404 introuvable` — « partout, **et sur tout ce que le lecteur n'a pas le droit de
 * voir** » (§ 15.2 du contrat d'API).
 *
 * Le message ne nomme **jamais** une personne (§ 16.2) : il nomme ce qui a été
 * cherché, en termes de structure. « La personne 17 est introuvable » est acceptable ;
 * « Marne Dolomite Sureau est introuvable » est une fuite.
 */
final class Introuvable extends ErreurDeDemande
{
    public function __construct(string $message = 'Ressource introuvable.')
    {
        parent::__construct('introuvable', $message, 404);
    }

    public static function demande(int $id): self
    {
        return new self(\sprintf('La demande n° %d est introuvable.', $id));
    }

    public static function cible(string $kind, int $id): self
    {
        return new self(\sprintf('La cible visée (%s n° %d) est introuvable.', $kind, $id));
    }

    public static function brouillon(string $ref): self
    {
        return new self(\sprintf('Le brouillon %s est introuvable dans cette demande.', $ref));
    }

    /**
     * Une fiche proposée désignée par **l'identifiant de sa ligne**, depuis une autre
     * demande que la sienne.
     *
     * `404` couvre ici deux cas que l'appelant ne doit pas pouvoir distinguer : la ligne
     * n'existe pas, ou elle appartient à la demande de quelqu'un d'autre. « Partout, et
     * sur tout ce que le lecteur n'a pas le droit de voir » (§ 15.2) — répondre `403`
     * sur le second apprendrait qu'il existe.
     */
    public static function brouillonPropose(int $id): self
    {
        return new self(\sprintf('La fiche proposée n° %d est introuvable.', $id));
    }
}
