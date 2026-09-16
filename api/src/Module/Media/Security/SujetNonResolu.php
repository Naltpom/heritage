<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

use App\Module\Media\Enum\MediaSubject;
use Psr\Log\LoggerInterface;

/**
 * L'implémentation **par défaut** de {@see ResolutionDuSujetInterface} : aucun sujet
 * n'existe.
 *
 * Même motif que `Core\Security\CercleFerme`, appliqué à l'autre couture : tant que
 * `Tree` n'a pas branché la sienne, `Media` ne peut ni confirmer qu'une personne existe,
 * ni savoir de qui dépend la visibilité d'une union. Répondre « oui, et ça ne dépend de
 * personne » serait une réponse ouverte fabriquée à partir d'une ignorance — c'est-à-dire
 * la pire des deux : elle sert des fichiers en se réclamant d'un cercle qu'elle n'a pas
 * consulté.
 *
 * Répondre `null` rend le module inerte : aucun dépôt n'aboutit, aucun octet ne sort.
 * Visible immédiatement, et jamais au détriment de quelqu'un.
 *
 * Avertissement **une fois par requête** et non par appel : une fiche interroge ce
 * service pour chacun de ses médias, et dix lignes identiques noieraient le message au
 * lieu de le porter.
 */
final class SujetNonResolu implements ResolutionDuSujetInterface
{
    private bool $dejaAverti = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function resoudre(MediaSubject $kind, int $id): ?SujetResolu
    {
        if (!$this->dejaAverti) {
            $this->dejaAverti = true;

            $this->logger->warning(
                'La résolution des sujets de médias n\'est pas branchée : aucun média ne sera déposé ni servi. '
                .'Remplacer l\'alias de Media\\Security\\ResolutionDuSujetInterface par l\'implémentation de Tree.'
            );
        }

        return null;
    }
}
