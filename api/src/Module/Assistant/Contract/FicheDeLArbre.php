<?php

declare(strict_types=1);

namespace App\Module\Assistant\Contract;

/**
 * La fiche complète d'une personne, pour l'outil `lire_personne`.
 *
 * Les **champs éditables** sont ceux de la liste blanche des demandes de modification
 * (§ 6.2 du contrat d'API) : c'est ce que le modèle peut proposer de corriger, et rien
 * d'autre. `version` lui est montrée pour qu'il comprenne qu'il parle d'une ligne précise,
 * mais ce n'est **pas** lui qui la recopie dans la demande : `baseVersion` et `oldValue`
 * sont relus en base par `Proposal` au moment de l'analyse, jamais repris du modèle.
 */
final readonly class FicheDeLArbre
{
    /**
     * @param list<PersonneDeLArbre> $parents
     * @param list<UnionDeLArbre>    $unions
     * @param list<PersonneDeLArbre> $fratrie
     */
    public function __construct(
        public PersonneDeLArbre $personne,
        public int $version,
        public string $prenoms,
        public string $nom,
        public ?string $nomDUsage,
        public ?string $surnom,
        public bool $decedee,
        public array $parents,
        public array $unions,
        public array $fratrie,
    ) {
    }
}
