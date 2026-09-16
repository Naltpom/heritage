<?php

declare(strict_types=1);

namespace App\Module\Membership\Service\Port;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Tree` — à lire avant d'écrire son adaptateur.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * `Membership` a besoin de trois choses que seul `Tree` possède : l'adjacence de l'arbre
 * (pour calculer le cercle de l'ADR 0003), l'état **vivant** de chaque personne (la
 * propriété calculée du § 9.2), et l'empreinte de l'arbre (pour la clé de cache du
 * § 9.3). Il n'a le droit d'aller les chercher lui-même ni par les entités ni par les
 * tables :
 *
 * - **règle 4 de l'ADR 0002** : « un module en appelle un autre par son service
 *   applicatif, jamais par son repository ni par ses entités » ;
 * - **règle 3 de l'ADR 0002**, vérifiée en intégration continue : un
 *   `use App\Module\Tree\…` dans `Membership` est une infraction, seul `Core` est en
 *   liste blanche.
 *
 * D'où cette interface, **déclarée ici et implémentée dans `Tree`** — exactement comme
 * `Proposal\Service\Port\PortArbre`, et pour les mêmes raisons.
 *
 * ── Ce que l'agent `Tree` a à faire ──────────────────────────────────────────────
 *
 * 1. écrire `Tree\Service\…` implémentant cette interface. Tout est déjà là, côté `Tree` :
 *    `FournisseurDeJeuDePlacement` charge les 274 personnes et les 103 familles dans
 *    l'ordre, `ReglePersonneVivante` donne la carte des vivants, `TreeFingerprint` donne
 *    l'empreinte. L'adaptateur est une traduction, pas un calcul ;
 * 2. remplacer l'alias de `config/services/membership.yaml`, qui pointe aujourd'hui sur
 *    {@see ArbreNonBranche}.
 *
 * ── La règle que l'implémentation n'a pas le droit d'assouplir ───────────────────
 *
 * **`vue()` rend l'arbre validé COMPLET, sans aucun filtre de confidentialité.** C'est
 * contre-intuitif pour un port qui sert la confidentialité, et c'est pourtant la seule
 * forme juste : le cercle de `u` contient des personnes que le **lecteur** n'a pas le
 * droit de voir — c'est même tout l'objet de la bascule « voir comme un membre » (§ 10.4),
 * qui calcule le cercle de quelqu'un d'autre. Filtrer ici donnerait un cercle amputé, donc
 * faux, et un cercle faux est une fuite ou une fiche muette selon le sens de l'erreur.
 * Le filtrage a lieu **une seule fois, dans la sérialisation** (§ 6.2 du dossier).
 *
 * ── Pourquoi `empreinte()` vit ici et pas dans un compteur ───────────────────────
 *
 * § 2.3 du modèle, nommément : « `Membership` l'appelle pour composer sa clé de cercle ».
 * L'empreinte est **recalculée depuis la base**, et **jamais un compteur incrémenté à la
 * main** : *un compteur qu'on oublie d'incrémenter dans un des cinq chemins d'écriture
 * donne un graphe faux qu'on met des semaines à comprendre*, et ici un cercle périmé n'est
 * pas un ralentissement, c'est une fuite.
 */
interface PortArbre
{
    /**
     * L'arbre validé complet — personnes, familles, état vivant —, sans filtre.
     *
     * Appelée **au plus une fois par calcul de cercle**, et seulement quand le cache
     * manque : la clé porte l'empreinte, donc une entrée valide n'ouvre jamais l'arbre.
     */
    public function vue(): VueDeLArbre;

    /**
     * L'empreinte des trois tables structurantes (`tree_person`, `tree_family`,
     * `tree_child`), 64 caractères hexadécimaux, telle que `Tree\Service\TreeFingerprint`
     * la calcule.
     *
     * Elle entre dans la clé de cache `cercle.{empreinte}.{memberId}.{N}` (§ 9.3). Une
     * implémentation qui rendrait une valeur constante ne casserait rien tant que l'arbre
     * ne bouge pas — et servirait un cercle périmé au premier changement validé. C'est
     * l'unique raison pour laquelle {@see ArbreNonBranche} rend une valeur *reconnaissable*
     * plutôt qu'une chaîne vide.
     */
    public function empreinte(): string;
}
