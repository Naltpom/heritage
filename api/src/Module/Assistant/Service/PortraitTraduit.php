<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

/**
 * Un portrait repéré par le modèle dans une photo jointe, **bien formé** mais pas encore
 * rattaché à la réponse : la personne visée est soit une fiche de l'arbre (`personId`,
 * dont l'existence est vérifiée par {@see AnalyseParLAssistant}), soit un brouillon d'une
 * demande de création de la même réponse (`proposition` + `brouillonRef`) — qui ne vaut
 * que si cette demande est **gardée** par le dépôt à blanc.
 *
 * Exactement l'un des deux est renseigné.
 */
final readonly class PortraitTraduit
{
    /**
     * @param int                                                  $document     rang **0-based** du document dans `fichiers[]`
     * @param array{haut: int, gauche: int, bas: int, droite: int} $cadre        repère 0–1000 de l'image, haut < bas, gauche < droite
     * @param int|null                                             $proposition  rang dans {@see PropositionsTraduites::$propositions}
     * @param string|null                                          $brouillonRef la référence canonique du brouillon (`#p1`)
     */
    public function __construct(
        public int $document,
        public array $cadre,
        public string $justification,
        public ?int $personId,
        public ?int $proposition = null,
        public ?string $brouillonRef = null,
    ) {
    }
}
