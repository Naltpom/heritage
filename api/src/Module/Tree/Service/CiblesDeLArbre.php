<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Contract\CiblesDeReferenceInterface;
use App\Module\Core\Enum\CibleDeReference;
use App\Module\Core\Service\ReleveurDeReferences;

/**
 * Les trois tables de l'arbre que les autres modules désignent : la personne, la famille,
 * la citation (§ 10.8 du modèle de données).
 *
 * C'est la seule lecture que `heritage:verifier-integrite` fait dans `tree_*`, et elle ne
 * porte que sur la clé primaire : « ces identifiants existent-ils ? ». Le cercle de
 * visibilité n'a rien à faire ici — la commande ne sert aucune donnée, elle compte.
 */
final readonly class CiblesDeLArbre implements CiblesDeReferenceInterface
{
    public function __construct(private ReleveurDeReferences $releveur)
    {
    }

    public function cibles(): array
    {
        return [CibleDeReference::Personne, CibleDeReference::Famille, CibleDeReference::Citation];
    }

    public function existants(CibleDeReference $cible, array $identifiants): array
    {
        return $this->releveur->existants($cible->value, $identifiants);
    }
}
