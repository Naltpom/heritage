<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Tree` — ce que les statistiques lisent de l'arbre.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Même forme que `Almanach\Contract\PortEvenementsArbre`, et pour les mêmes raisons :
 * règle 4 de l'ADR 0002 (un module en appelle un autre par un service, jamais par ses
 * entités) et frontière `Contract/` (un `use` inter-modules ne vise que `Core\…` ou
 * `<Autre>\Contract\…`). Déclarée ici, implémentée dans `Tree`
 * (`Tree\Service\VueDeLArbrePourStatistiques`), sans qu'aucune signature nomme une entité.
 *
 * ── Les règles que l'implémentation n'a pas le droit d'assouplir ─────────────────
 *
 * **1. `releve()` rend l'arbre validé COMPLET, sans filtre de confidentialité.** Le cercle
 * s'applique une seule fois, dans {@see \App\Module\Statistiques\Service\StatistiquesVisibles},
 * point unique du module — exactement la règle d'`Almanach` (« deux filtres valent moins
 * qu'un : celui qu'on oublie de corriger est toujours le second »). Les agrégats anonymes
 * comptent tout le monde (décision du commanditaire, 02/10/2026) ; ce qui nomme ou date
 * une personne passe par le cercle.
 *
 * **2. Les dates traversent en `UncertainDate`, jamais aplaties.** Décider qu'une date
 * « compte » (exacte, année seule) ou non (floue, inconnue) est une règle du module
 * statistique, écrite dans {@see \App\Module\Statistiques\Service\DateRetenue} et nulle
 * part ailleurs.
 *
 * **3. « Vivant » vient de la règle calculée** (`ReglePersonneVivante`, § 9.2), jamais du
 * seul drapeau `deceased` — qui traverse aussi, sous le nom `decede`, parce qu'il dit
 * autre chose : « un décès est noté », ce qui interdit de calculer un âge jusqu'à
 * aujourd'hui.
 *
 * **4. `jeton()` est bon marché.** Il sert de clé de cache au relevé : quelques `COUNT` et
 * `MAX` par table, jamais un chargement. S'il coûtait autant que `releve()`, le cache ne
 * servirait à rien.
 */
interface PortStatistiquesArbre
{
    /**
     * Une empreinte de fraîcheur : elle change dès qu'une personne, une famille, un lien
     * de filiation, une citation, un fait ou un lieu change (ajout, retrait, modification
     * horodatée), et chaque jour (les âges des vivants avancent).
     */
    public function jeton(): string;

    /** Tout ce que les statistiques lisent, en un seul objet. */
    public function releve(): ReleveDeLArbre;
}
