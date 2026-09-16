<?php

declare(strict_types=1);

namespace App\Module\Core\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * `413 fichier_trop_volumineux` (§ 15.2 du contrat d'API).
 *
 * Écrite par l'agent `Media`, montée ici telle quelle : le jour où `Gedcom` importera des
 * pièces jointes, il aura besoin du même code, et **deux exceptions au même code d'erreur
 * dans deux modules** est exactement le doublon qui finit par produire deux enveloppes
 * différentes pour la même panne.
 *
 * Comme toutes les sœurs de {@see DomainException}, elle ne construit aucune réponse
 * HTTP : l'enveloppe du § 15.1 est composée en un seul endroit,
 * {@see \App\Module\Core\EventSubscriber\ApiExceptionSubscriber}.
 */
final class FichierTropVolumineuxException extends DomainException
{
    public function __construct(private readonly int $maxOctets)
    {
        parent::__construct(\sprintf('Le fichier dépasse la taille maximale autorisée (%d octets).', $maxOctets));
    }

    public function statutHttp(): int
    {
        return Response::HTTP_REQUEST_ENTITY_TOO_LARGE;
    }

    public function codeErreur(): string
    {
        return 'fichier_trop_volumineux';
    }

    /** Le plafond est rendu : refuser sans dire la limite oblige à la deviner par essais. */
    public function details(): array
    {
        return ['maxOctets' => $this->maxOctets];
    }
}
