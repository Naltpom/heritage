<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

/**
 * Le graphe d'un lecteur, **borné à son périmètre** — révision du 2026-10-04 de l'ADR 0003.
 *
 * Pur, sans base : {@see \App\Tests\Tree\GrapheDuPerimetreTest}.
 */
final class GrapheDuPerimetre
{
    /**
     * **Les arbres des autres familles retirés du graphe** — révision du 2026-10-04 de
     * l'ADR 0003, « un arbre par famille ».
     *
     * Le placement est partagé (ADR 0005) et couvre toute la base ; ce qui sort pour ce
     * lecteur s'arrête à son **périmètre** ({@see CercleDuLecteur::connait()}). Une
     * personne hors périmètre ne devient pas un nœud masqué : elle n'est pas servie, ni
     * elle, ni ses arêtes, ni les nœuds de famille dont tous les membres sont partis. Un
     * arbre entier retiré ne laisse aucun trou dans ceux qui restent : par définition,
     * aucun lien ne l'y relie.
     *
     * Les nœuds proposés (identifiants en `#`) appartiennent aux demandes du lecteur :
     * ils restent.
     *
     * @param list<array<string, mixed>> $noeuds
     * @param list<array<string, mixed>> $aretes
     *
     * @return array{noeuds: list<array<string, mixed>>, aretes: list<array<string, mixed>>}
     */
    public static function retirer(array $noeuds, array $aretes, CercleDuLecteur $cercle): array
    {
        $retires = [];

        foreach ($noeuds as $noeud) {
            if ('personne' === $noeud['type'] && \is_int($noeud['personId'] ?? null) && !$cercle->connait($noeud['personId'])) {
                $retires[$noeud['id']] = true;
            }
        }

        if ([] === $retires) {
            return ['noeuds' => $noeuds, 'aretes' => $aretes];
        }

        // Une famille part quand tous ses voisins sont partis.
        $voisins = [];
        foreach ($aretes as $arete) {
            $voisins[$arete['de']][] = $arete['vers'];
            $voisins[$arete['vers']][] = $arete['de'];
        }

        foreach ($noeuds as $noeud) {
            if ('famille' !== $noeud['type'] || !isset($voisins[$noeud['id']])) {
                continue;
            }

            $restants = array_filter($voisins[$noeud['id']], static fn (string $v): bool => !isset($retires[$v]));

            if ([] === $restants) {
                $retires[$noeud['id']] = true;
            }
        }

        return [
            'noeuds' => array_values(array_filter($noeuds, static fn (array $n): bool => !isset($retires[$n['id']]))),
            'aretes' => array_values(array_filter(
                $aretes,
                static fn (array $a): bool => !isset($retires[$a['de']]) && !isset($retires[$a['vers']]),
            )),
        ];
    }
}
