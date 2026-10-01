<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * Un quota d'usage tel que le module qui le **consomme** le déclare : son nom, sa fenêtre,
 * son plafond par défaut — et l'identité exacte de son compteur.
 *
 * ## Pourquoi c'est le consommateur qui le déclare
 *
 * `Core` stocke les limites personnelles (`core_quota_limite`) et sait compter ; il ne sait
 * pas — et ne doit pas savoir — ce qu'est une « analyse de l'assistant » ni combien on en
 * accorde par jour (règle 3 de l'ADR 0002 : `Core` reste minimal). Le défaut reste donc
 * dans le fichier de configuration du module propriétaire (`assistant.yaml`,
 * `media.yaml`), qui le passe ici. `Core` ne compare jamais `cle` à un littéral : il la
 * range et la relit, c'est tout — ce n'est pas un vocabulaire déguisé (ADR 0002, « Ce
 * qu'un contrat a le droit d'être »).
 *
 * ## `identifiant` et `fenetre` sont un engagement de compatibilité
 *
 * Ils désignent le compteur **déjà écrit** dans le cache (`<identifiant>-<clé du compte>`,
 * forme de `RateLimiterFactory::create()`). Les changer remet à zéro la consommation de
 * tout le monde, en silence — c'est exactement ce que la reprise des anciens limiteurs
 * devait éviter. La **limite**, elle, peut changer à volonté : la fenêtre glissante ne la
 * stocke pas, elle ne garde que les coups comptés.
 */
final readonly class Quota
{
    /**
     * @param string      $cle                   la clé publique (`assistant.analyses`), celle de l'API et de la table
     * @param string      $libelle               ce que l'écran d'administration affiche (« Questions IA »)
     * @param string      $identifiant           l'`id` du limiteur d'origine — voir l'en-tête
     * @param int         $limiteParDefaut       la valeur de configuration, appliquée quand aucune limite n'est posée
     * @param string      $fenetre               l'intervalle glissant, au format de `RateLimiterFactory` (`24 hours`)
     * @param string      $fenetreLisible        la même, pour un humain (« par 24 h »)
     * @param string|null $compteurGlobal        `null` : un compteur par compte. Sinon la clé **unique** du
     *                                           compteur partagé par toute l'installation (`rattachements`) —
     *                                           sa limite est alors réglable globalement, jamais par compte
     * @param bool        $administrateurExempte l'administrateur n'est pas compté (téléversements, décision
     *                                           du 17 septembre 2026) : sa ligne le dira au lieu d'afficher un
     *                                           plafond qui ne s'applique pas
     */
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $identifiant,
        public int $limiteParDefaut,
        public string $fenetre,
        public string $fenetreLisible,
        public ?string $compteurGlobal = null,
        public bool $administrateurExempte = false,
    ) {
    }

    public function estGlobal(): bool
    {
        return null !== $this->compteurGlobal;
    }
}
