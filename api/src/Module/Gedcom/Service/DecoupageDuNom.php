<?php

declare(strict_types=1);

namespace App\Module\Gedcom\Service;

/**
 * Découpe la valeur d'un tag `NAME` selon la syntaxe de la norme GEDCOM.
 *
 * `Jade Pyrite /Romarin/ Jr` : le nom de famille est entre barres obliques, ce qui
 * précède est le prénom, ce qui suit est un suffixe. C'est une règle de **grammaire
 * GEDCOM**, pas une règle de l'arbre — d'où sa place ici, et non dans
 * {@see \App\Module\Tree\Service\NormalisateurNom}, qui compose les colonnes
 * dénormalisées à partir du résultat.
 */
final class DecoupageDuNom
{
    /**
     * Découpe `Jade Pyrite /Romarin/ Jr` en ses trois morceaux.
     *
     * @return array{prenoms: string, nom: string, suffixe: string}
     */
    public function decouper(?string $name): array
    {
        $name = trim((string) $name);

        if (1 === preg_match('#^(.*?)/([^/]*)/(.*)$#s', $name, $trouve)) {
            return [
                'prenoms' => trim($trouve[1]),
                'nom' => trim($trouve[2]),
                'suffixe' => trim($trouve[3]),
            ];
        }

        // Pas de barres obliques : la norme dit que tout est prénom. C'est rare et c'est
        // ce que fait `build_data.py`.
        return ['prenoms' => $name, 'nom' => '', 'suffixe' => ''];
    }
}
