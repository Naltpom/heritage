<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Timeline\Contract\ArbreDeLaChronologie;
use App\Module\Timeline\Contract\PortChronologieArbre;
use Psr\Log\LoggerInterface;

/**
 * Implémentation de repli de {@see PortChronologieArbre}, tant que `Tree` n'a pas posé la
 * sienne.
 *
 * ## Elle ne lève pas — même arbitrage que le repli de `Membership`
 *
 * `Proposal\Service\Port\ArbreNonBranche` lève sur ses méthodes, parce qu'une
 * **validation** qui réussirait sans rien écrire serait pire qu'une erreur franche. Ici la
 * question est inverse : ce port sert une **lecture**, et le comportement juste d'une
 * lecture dont le câblage a sauté est de ne rien montrer. Une exception ferait tomber les
 * trois routes du § 9 en `500` ; un arbre vide les laisse répondre, en régime fermé — une
 * chronologie sans barre, avec ses bandes et ses préférences intactes.
 *
 * C'est la même règle que `Core\Security\CercleFerme` et il faut la lire de la même
 * façon : **ce n'est pas un bouchon, c'est le comportement de référence quand rien n'est
 * branché.** Un défaut *ouvert* transformerait une erreur de configuration — un alias mal
 * écrit, une refonte des services dans deux ans — en publication silencieuse des dates de
 * naissance de personnes vivantes. Un défaut *fermé* la transforme en écran vide : visible
 * tout de suite, corrigé dans l'heure, et jamais au détriment de quelqu'un.
 *
 * **Il ne faut pas l'« arranger »** en rendant un arbre partiel : la moitié d'un arbre
 * rendue sans cercle est exactement la fuite qu'on ferme ici.
 */
final class ArbreNonBranche implements PortChronologieArbre
{
    private bool $dejaAverti = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function vue(): ArbreDeLaChronologie
    {
        if (!$this->dejaAverti) {
            $this->dejaAverti = true;

            $this->logger->warning(
                'Le port vers Tree n\'est pas branché : la vue chronologique travaille sur un arbre vide, '
                .'donc aucune barre et aucun jalon ne sont servis. '
                .'Remplacer l\'alias de Timeline\\Contract\\PortChronologieArbre dans config/services/timeline.yaml.'
            );
        }

        return ArbreDeLaChronologie::vide();
    }
}
