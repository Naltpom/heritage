<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

/**
 * Ce qu'un lecteur a le droit de voir d'une personne — la clé `visibilite` du § 5.2 et
 * du § 11.1 du contrat d'API.
 *
 * Deux valeurs, et **aucune troisième** : il n'existe pas de « masquée », pas
 * d'« absente », pas de « partielle ». Une personne hors cercle reste un nœud du graphe
 * et une fiche à sept clés (ADR 0003 : le cercle masque des champs, pas des nœuds). Le
 * seul endroit où une personne disparaît vraiment est la **recherche**, et cela ne se
 * dit pas par un niveau de visibilité : la ligne n'est pas émise du tout (§ 5.5).
 *
 * C'est une énumération et non un booléen parce que la valeur **part dans le JSON** telle
 * quelle, et que le front branche dessus.
 */
enum NiveauDeVisibilite: string
{
    /** Tous les champs auxquels le régime du lecteur donne droit. */
    case Complete = 'complete';

    /**
     * Les sept clés de la liste blanche : `id`, `displayName`, `sex`, `generation`,
     * `branch`, `vivant`, `visibilite`. Rien d'autre, et surtout aucune clé à `null` —
     * un champ interdit est **absent**, un champ inconnu est `null` (§ 1.3).
     */
    case Restreinte = 'restreinte';

    public function estComplete(): bool
    {
        return self::Complete === $this;
    }
}
