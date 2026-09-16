<?php

declare(strict_types=1);

namespace App\Module\Timeline\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Tree` — à lire avant d'écrire son adaptateur.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * La vue chronologique a besoin de deux choses que seul `Tree` possède : les personnes de
 * l'arbre avec leurs **dates non aplaties** et leur état vivant, et les `tree_fact` datés
 * qui font les jalons. Elle n'a le droit d'aller les chercher elle-même ni par les
 * entités, ni par les dépôts, ni par les tables :
 *
 * - **règle 4 de l'ADR 0002** : « un module en appelle un autre par son service
 *   applicatif, jamais par son repository ni par ses entités » ;
 * - **frontière `Contract/`**, révision du 2026-09-15 : « un `use` inter-modules n'est
 *   licite que vers `Core\…` ou vers `<Autre>\Contract\…` ».
 *
 * D'où cette interface, **déclarée ici et implémentée dans `Tree`** — exactement comme
 * `Proposal\Service\Port\PortArbre` et `Membership\Service\Port\PortArbre`, et pour les
 * mêmes raisons. Elle est rangée dans `Contract/` et non dans `Service/Port/` parce que
 * c'est là que la révision de l'ADR place désormais la surface publique d'un module.
 *
 * ## Elle satisfait la qualification de l'ADR 0002
 *
 * *« Un contrat dont la signature nomme une `Entity/` ou un `Repository/` du module qui le
 * déclare n'est pas un contrat. »* Celle-ci ne nomme que ses propres objets de transfert
 * — {@see ArbreDeLaChronologie}, {@see PersonneDeLaChronologie},
 * {@see FaitDeLaChronologie} — et du vocabulaire de `Core` : `Branch`, `Sex`,
 * `UncertainDate`. `Tree` peut l'implémenter **sans voir une seule classe de `Timeline`
 * hors de ce dossier**, et `tml_preference` n'y figure nulle part.
 *
 * ── Ce que l'agent `Tree` a à faire ──────────────────────────────────────────────
 *
 * 1. écrire `Tree\Service\…` implémentant cette interface. Tout est déjà là, côté `Tree` :
 *    `PersonRepository` pour les 274 personnes, `ReglePersonneVivante` pour la carte des
 *    vivants (avec `ReglagesDeLArbre::seuilVivantAnnees()`), `FactRepository` pour les
 *    faits. L'adaptateur est une **traduction, pas un calcul** ;
 * 2. remplacer l'alias de `config/services/timeline.yaml`, qui pointe aujourd'hui sur
 *    {@see \App\Module\Timeline\Service\ArbreNonBranche}.
 *
 * ── La règle que l'implémentation n'a pas le droit d'assouplir ───────────────────
 *
 * **`vue()` rend l'arbre validé COMPLET, sans aucun filtre de confidentialité.** C'est
 * contre-intuitif pour un port qui alimente une vue soumise au cercle, et c'est pourtant
 * la seule forme juste — la même que `Membership\Service\Port\PortArbre` énonce pour
 * lui-même :
 *
 * - le filtrage a lieu **une seule fois, dans la sérialisation** (§ 6.2 du dossier de
 *   mission, § 8 : « le cercle est appliqué dans la sérialisation, pas dans le
 *   contrôleur ») ;
 * - filtrer ici priverait la bascule « voir comme un membre » (§ 10.4) de son objet :
 *   elle remplace le cercle du lecteur par celui de quelqu'un d'autre, ce qui suppose un
 *   jeu de départ complet ;
 * - et **deux filtres valent moins qu'un** : celui qu'on oublie de corriger est toujours
 *   le second.
 *
 * Les **bornes de l'arbre** (§ 9.1) s'en trouvent calculées sur les seules barres
 * visibles, donc différentes d'un lecteur à l'autre. C'est voulu : des bandes qui
 * commenceraient en 1646 alors que le lecteur ne voit rien avant 1900 lui diraient qu'il
 * existe des fiches plus anciennes.
 *
 * ── Le défaut est fermé ──────────────────────────────────────────────────────────
 *
 * Tant qu'aucun adaptateur n'est branché, {@see \App\Module\Timeline\Service\ArbreNonBranche}
 * rend un arbre vide. Lire son en-tête avant d'être tenté de l'« arranger » : ce n'est pas
 * un bouchon, c'est le comportement de référence quand le câblage a sauté.
 */
interface PortChronologieArbre
{
    /**
     * L'arbre validé complet — personnes, dates, état vivant, faits datés —, sans filtre.
     *
     * Appelée **une fois par requête HTTP** : la chronologie dessine l'axe entier, il n'y
     * a pas de pagination à servir. Une seule lecture suffit, et l'implémentation a tout
     * intérêt à mémoriser son résultat pour la durée de la requête.
     *
     * Les volumes, **recomptés sur la base réelle** et non repris d'un document : 274
     * personnes, 87 lignes `tree_fact` dont **30** portent une date et un sujet `person`
     * — les seules que ce port rend —, et **aucune** de sujet `family`. Le modèle de
     * données annonce « environ 90 » pour la table entière, ce qui reste juste ; le
     * chiffre qui compte ici est 30.
     */
    public function vue(): ArbreDeLaChronologie;
}
