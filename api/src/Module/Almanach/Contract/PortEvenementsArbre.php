<?php

declare(strict_types=1);

namespace App\Module\Almanach\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POINT DE BRANCHEMENT DU MODULE `Tree` — à lire avant d'écrire son adaptateur.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * La carte et le calendrier ont besoin de deux choses que seul `Tree` possède : **tous**
 * les événements datés de l'arbre — naissances, décès, mariages, divorces, `tree_fact` —
 * avec leurs dates non aplaties, leurs sujets et leur état vivant ; et les lignes de
 * `tree_place` qui résolvent les chaînes `PLAC` en coordonnées.
 *
 * `Almanach` n'a le droit d'aller les chercher ni par les entités, ni par les dépôts, ni
 * par les tables :
 *
 * - **règle 4 de l'ADR 0002** : « un module en appelle un autre par son service
 *   applicatif, jamais par son repository ni par ses entités » ;
 * - **frontière `Contract/`**, révision du 2026-09-15 : « un `use` inter-modules n'est
 *   licite que vers `Core\…` ou vers `<Autre>\Contract\…` ».
 *
 * D'où cette interface, **déclarée ici et implémentée dans `Tree`** — même forme que
 * `Timeline\Contract\PortChronologieArbre`, et pour les mêmes raisons.
 *
 * ## Elle satisfait la qualification de l'ADR 0002
 *
 * *« Un contrat dont la signature nomme une `Entity/` ou un `Repository/` du module qui le
 * déclare n'est pas un contrat. »* Celle-ci ne nomme que ses propres objets de transfert —
 * {@see FluxDEvenements}, {@see EvenementDeLArbre}, {@see SujetDeLEvenement},
 * {@see LieuResolu}, {@see TypeDEvenement} — et du vocabulaire de `Core` : `Sex`,
 * `UncertainDate`. `Tree` peut l'implémenter **sans voir une seule classe d'`Almanach` hors
 * de ce dossier**.
 *
 * ── Les quatre règles que l'implémentation n'a pas le droit d'assouplir ──────────
 *
 * **1. `flux()` rend l'arbre validé COMPLET, sans aucun filtre de confidentialité.**
 * C'est contre-intuitif pour un port qui alimente une vue soumise au cercle, et c'est
 * pourtant la seule forme juste — la même que `PortChronologieArbre` énonce pour lui-même :
 *
 * - le filtrage a lieu **une seule fois**, dans {@see
 *   \App\Module\Almanach\Service\FluxVisible}, qui est le point unique de ce module ;
 * - filtrer ici priverait la bascule « voir comme un membre » (§ 10.4 du contrat d'API) de
 *   son objet : elle remplace le cercle du lecteur par celui de quelqu'un d'autre, ce qui
 *   suppose un jeu de départ complet ;
 * - et **deux filtres valent moins qu'un** : celui qu'on oublie de corriger est toujours le
 *   second.
 *
 * **2. Un événement de famille porte ses DEUX époux.** C'est la faute la plus probable de
 * cet adaptateur, parce qu'il existe à côté un modèle qui fait l'inverse :
 * `Tree\Service\VueDeLArbrePourChronologie` écrit `->innerJoin('f.person', 'p')` sur
 * `tree_fact`, ce qui **écarte silencieusement** tous les faits de sujet `family`. Le
 * copier ici ferait disparaître les unions et les faits de famille sans qu'aucune erreur ne
 * se produise — juste des bulles en moins. `tree_fact` admet exactement un sujet
 * (`ck_tree_fact_sujet_unique`) : un fait de famille se joint à `f.family`, et ses sujets
 * sont les époux de cette famille.
 *
 * **3. Les dates traversent en `UncertainDate`, jamais en années ni en jours.** Rendre un
 * `?int $annee` serait l'aplatissement que l'en-tête d'`UncertainDate` ferme. Il n'y a pas
 * non plus de `?int $jour` : la règle qui décide qu'une date mérite un jour d'anniversaire
 * est écrite, avec son motif, dans {@see \App\Module\Almanach\Service\JourAnniversaire},
 * et elle doit rester lisible d'un seul endroit.
 *
 * **4. L'état vivant passe par la règle calculée, jamais par `tree_person.deceased`.**
 * `deceased` dit seulement qu'un `DEAT` figurait dans le GEDCOM. « Vivant » est la
 * propriété calculée du § 9.2, et il n'en existe qu'une définition dans le produit.
 *
 * ── Le défaut est fermé ──────────────────────────────────────────────────────────
 *
 * Tant qu'aucun adaptateur n'est branché, {@see \App\Module\Almanach\Service\FluxNonBranche}
 * rend un flux vide. Lire son en-tête avant d'être tenté de l'« arranger » : ce n'est pas
 * un bouchon, c'est le comportement de référence quand le câblage a sauté.
 */
interface PortEvenementsArbre
{
    /**
     * Tous les événements datés de l'arbre et tous les lieux résolus, sans filtre.
     *
     * Appelée **une fois par requête HTTP** : la carte se dessine entière, le calendrier
     * couvre l'année entière, il n'y a pas de pagination à servir. L'implémentation a tout
     * intérêt à mémoriser son résultat pour la durée de la requête.
     *
     * Les volumes, **recomptés sur la base réelle** : 274 personnes, 103 familles, 87
     * lignes `tree_fact`, et une centaine de chaînes `PLAC` distinctes. L'ordre de
     * grandeur du flux est le millier d'événements — une carte, pas un export.
     */
    public function flux(): FluxDEvenements;
}
