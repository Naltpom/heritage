<?php

declare(strict_types=1);

namespace App\Tests\Frontieres;

/**
 * Une référence d'un module vers un autre, telle que le balayage la lit dans le
 * code source.
 *
 * L'unité comptée est la **référence**, pas le fichier : un fichier qui cite
 * quatre classes d'un autre module en produit quatre. C'est la spécification de
 * l'ADR 0002 (§ « Le compte doit être reproductible avant d'être une porte »,
 * point 1), et c'est ce qui permet à la liste d'exemptions d'être nommée
 * « fichier par fichier ET cible par cible » plutôt que par fichier seulement —
 * un fichier exempté pour une cible reste gardé pour toutes les autres.
 */
final readonly class ReferenceInterModules
{
    /**
     * @param string $moduleSource          module où vit le fichier qui cite
     * @param string $moduleCible           module de la classe citée
     * @param string $fichier               chemin relatif à `src/Module`, séparateurs `/`
     * @param string $classeSource          FQCN déduit du chemin (PSR-4)
     * @param string $classeCible           FQCN cité
     * @param string $coucheCible           premier segment après le module : `Enum`, `Entity`, `Service`, `Contract`…
     * @param int    $ligne                 ligne du fichier source
     * @param bool   $nomPleinementQualifie vrai si la référence est écrite `\App\Module\…` dans le corps du code
     *                                      plutôt qu'importée par un `use` — même franchissement, écriture différente
     */
    public function __construct(
        public string $moduleSource,
        public string $moduleCible,
        public string $fichier,
        public string $classeSource,
        public string $classeCible,
        public string $coucheCible,
        public int $ligne,
        public bool $nomPleinementQualifie,
    ) {
    }

    /**
     * La frontière passe par `Contract/`.
     *
     * Une cible dont le chemin commence par `Contract\` est la surface publique
     * déclarée par le module qui la porte : elle est licite sans exemption.
     * Tout le reste — `Entity\`, `Service\`, `Enum\`, `ValueObject\`, `Security\`,
     * `Serializer\`, y compris `Service\Port\` — est l'intérieur d'un module.
     *
     * Le test ne juge que des chemins. Il ne sait pas si un contrat transporte un
     * vocabulaire déguisé ; c'est une règle de conception qui s'applique en
     * relecture (docs/arbitrages.md, point II). Ce qu'il sait vérifier — qu'un
     * `Contract/` ne transporte ni entité ni dépôt — est un test à part.
     */
    public function estLicite(): bool
    {
        return 'Contract' === $this->coucheCible;
    }

    public function couple(): string
    {
        return $this->moduleSource.' -> '.$this->moduleCible;
    }

    /**
     * L'identité d'une exemption : un fichier source, une classe cible.
     * C'est cette clé, et pas le couple de modules, qui se retire de la liste
     * quand la dette est soldée.
     */
    public function cle(): string
    {
        return $this->classeSource.' => '.$this->classeCible;
    }

    public function decrire(): string
    {
        return \sprintf(
            '%s:%d  %s -> %s   (couche ciblée : %s)%s',
            $this->fichier,
            $this->ligne,
            $this->classeSource,
            $this->classeCible,
            $this->coucheCible,
            $this->nomPleinementQualifie ? '  [nom pleinement qualifié, sans `use`]' : '',
        );
    }
}
