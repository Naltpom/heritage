<?php

declare(strict_types=1);

namespace App\Module\Almanach\Service;

use App\Module\Almanach\Contract\FluxDEvenements;
use App\Module\Almanach\Contract\PortEvenementsArbre;
use Psr\Log\LoggerInterface;

/**
 * Implémentation de repli de {@see PortEvenementsArbre}, tant que `Tree` n'a pas posé la
 * sienne.
 *
 * ## Elle ne lève pas — même arbitrage que `Timeline\Service\ArbreNonBranche`
 *
 * `Proposal\Service\Port\ArbreNonBranche` lève sur ses méthodes, parce qu'une
 * **validation** qui réussirait sans rien écrire serait pire qu'une erreur franche. Ici la
 * question est inverse : ce port sert une **lecture**, et le comportement juste d'une
 * lecture dont le câblage a sauté est de ne rien montrer. Une exception ferait tomber les
 * deux routes en `500` ; un flux vide les laisse répondre, en régime fermé — une carte
 * sans bulle et un calendrier sans jour, avec leurs résumés à zéro.
 *
 * C'est la même règle que `Core\Security\CercleFerme`, et il faut la lire de la même
 * façon : **ce n'est pas un bouchon, c'est le comportement de référence quand rien n'est
 * branché.** Un défaut *ouvert* transformerait une erreur de configuration — un alias mal
 * écrit, une refonte des services dans deux ans — en publication silencieuse des dates et
 * des lieux de naissance de personnes vivantes. Un défaut *fermé* la transforme en écran
 * vide : visible tout de suite, corrigé dans l'heure, et jamais au détriment de quelqu'un.
 *
 * **Il ne faut pas l'« arranger »** en rendant un flux partiel : la moitié d'un arbre
 * rendue sans cercle est exactement la fuite qu'on ferme ici.
 *
 * ## L'avertissement n'est journalisé qu'une fois
 *
 * Deux routes, chacune appelant le port une fois par requête : sans ce drapeau, une
 * navigation ordinaire remplirait le journal d'une ligne identique par écran. Un
 * avertissement qu'on apprend à ignorer ne prévient plus de rien.
 */
final class FluxNonBranche implements PortEvenementsArbre
{
    private bool $dejaAverti = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function flux(): FluxDEvenements
    {
        if (!$this->dejaAverti) {
            $this->dejaAverti = true;

            $this->logger->warning(\sprintf(
                'Le port vers Tree n\'est pas branché : la carte et les anniversaires travaillent sur un flux vide, '
                .'donc aucun lieu et aucun événement ne sont servis. '
                .'Remplacer l\'alias de %s dans config/services/almanach.yaml.',
                PortEvenementsArbre::class,
            ));
        }

        return FluxDEvenements::vide();
    }
}
