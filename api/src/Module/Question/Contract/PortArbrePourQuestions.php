<?php

declare(strict_types=1);

namespace App\Module\Question\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * POINT DE BRANCHEMENT DU MODULE `Tree` — **en lecture seule, sans aucune exception**.
 *
 * Ce module ne possède que `qst_question` et `qst_question_demande`. Tout ce qu'il sait
 * de l'arbre entre par ici, et **rien n'en ressort** : il n'existe volontairement
 * aucune méthode d'écriture sur ce port. Une réponse de membre part par
 * {@see DepotDeReponse}, qui passe par la file de l'ADR 0004.
 *
 * *Si l'on se surprend à vouloir ajouter ici une méthode qui écrit, on s'est trompé de
 * route.*
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ## Le flux **n'est pas filtré** par le cercle, et c'est une décision, pas un oubli
 *
 * Ce port rend **toutes** les fiches, personnes vivantes comprises, sans considération
 * du lecteur. Deux raisons, qui n'ont rien à voir l'une avec l'autre :
 *
 * 1. **la détection raisonne sur l'arbre entier**, parce qu'une lacune existe
 *    indépendamment de qui pourrait la combler. C'est le choix du **destinataire** qui
 *    est filtré par le cercle, pas la recherche du trou ;
 * 2. **le corpus envoyé au modèle de langage est l'arbre entier** — arbitrage du
 *    commanditaire, ADR 0008 § 1, rendu en connaissance de la réserve de l'ADR 0003.
 *
 * Ces deux raisons sont aussi **le seul endroit du produit où un flux d'arbre non
 * filtré existe**, et c'est pourquoi la règle qui l'encadre est écrite ici plutôt que
 * dans un service :
 *
 * > **Une fiche lue par ce port ne doit jamais atteindre un lecteur par ce chemin.**
 * > Ce qui atteint un lecteur, c'est une `qst_question` sérialisée, filtrée par le
 * > `CercleDeVisibiliteInterface` du noyau comme les huit autres portes de sortie
 * > (ADR 0003). Un contrôleur qui rendrait le résultat de ce port publierait les dates
 * > de naissance des dix-huit personnes vivantes de l'arbre.
 *
 * ## Ce que l'adaptateur n'a pas le droit d'assouplir
 *
 * - **Le flux est complet.** Pas de `LIMIT`, pas de filtre sur `deceased`. Un flux
 *   partiel produirait des lacunes fantômes — « cette personne n'a pas de mère » parce
 *   que la mère n'était pas dans la page.
 * - **`vivante` vient de `ReglePersonneVivante`**, jamais de `tree_person.deceased`
 *   (§ 9.2 du modèle). C'est la même règle que partout, et la dédoubler ici ferait
 *   diverger deux définitions de « vivant » dont l'une décide ce qui part chez un tiers.
 * - **Les dates traversent en `UncertainDate`**, entières. Voir {@see FicheEtudiee}.
 * - **La parenté est celle du couple, pas du seul parent gardien** : `pereId` et
 *   `mereId` se lisent sur la famille qui porte l'enfant. Un `innerJoin` recopié d'un
 *   autre écran ferait disparaître en silence le parent qui manque — c'est-à-dire
 *   exactement la lacune qu'on cherche.
 *
 * ## Le repli
 *
 * {@see \App\Module\Question\Service\Port\ArbreNonBranchePourQuestions} rend un flux
 * **vide** : aucune lacune, donc aucune question, donc une page qui dit « rien à
 * demander pour l'instant ». C'est volontaire — un module dont la source n'est pas
 * câblée doit échouer fermé. Un flux partiel rendu sans que personne ne le sache
 * produirait des questions fausses sur des fiches qu'on croit incomplètes.
 */
interface PortArbrePourQuestions
{
    /**
     * Toutes les fiches de l'arbre, dans l'ordre des identifiants.
     *
     * L'ordre est demandé pour une raison mesurable : la détection produit les questions
     * dans cet ordre, et un ordre instable ferait varier la page d'un chargement à
     * l'autre sans qu'aucune donnée n'ait changé.
     *
     * @return list<FicheEtudiee>
     */
    public function fiches(): array;

    /**
     * Le libellé courant d'une personne — `display_name`, recalculé par `Tree`.
     *
     * Sert au rafraîchissement de `qst_question.sujet_label` : une fiche renommée après
     * qu'une question a été posée ne doit pas s'afficher sous deux orthographes, ce qui
     * ferait douter des deux.
     *
     * Rend `null` si la personne n'existe plus.
     */
    public function libelleDe(int $personneId): ?string;
}
