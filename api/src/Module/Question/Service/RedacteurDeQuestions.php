<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  L'INTERFACE DERRIÈRE LAQUELLE GEMINI SE BRANCHERA — ET LE CONTRAT QUI REND
 *  SON ABSENCE INOFFENSIVE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * > **Rédiger rend une entrée pour CHAQUE lacune reçue. Sans exception, quelle que
 * > soit la panne.**
 *
 * Ce n'est pas une politesse de documentation, c'est la clause qui fait tenir l'ADR
 * 0008 : *« une page vide parce qu'un tiers ne répond pas est un défaut de conception,
 * pas un incident »*. Une implémentation qui rendrait moins d'entrées qu'elle n'en a
 * reçu ferait disparaître des questions en silence — pas d'erreur, pas de trace, juste
 * une page plus courte les jours où Google va mal.
 *
 * ## Comment Gemini tiendra ce contrat : par DÉCORATION, pas par un `if`
 *
 * L'implémentation Gemini du jalon 4 **enveloppera** {@see RedactionParGabarit} et lui
 * repassera tout ce qu'elle n'a pas su écrire — délai dépassé, quota épuisé, réponse
 * illisible, clé absente. C'est le mécanisme que `Media` emploie déjà pour décorer le
 * port de `Proposal`, et il vaut mieux ici qu'un `try/catch` dans l'appelant, pour une
 * raison précise :
 *
 * **l'appelant n'aurait aucun moyen de savoir qu'il doit se rattraper.** Il recevrait un
 * tableau plus court et n'y verrait rien d'anormal — les lacunes déjà connues sont
 * légitimement absentes du lot à rédiger. La garantie doit donc vivre du côté qui sait
 * ce qui a échoué, c'est-à-dire chez le décorateur.
 *
 * Le corollaire est que **le repli est toujours le dernier maillon**, et qu'il ne doit
 * jamais dépendre de quoi que ce soit d'extérieur. {@see RedactionParGabarit} n'a aucune
 * dépendance, et c'est délibéré.
 *
 * ## Ce que le rédacteur n'a PAS le droit de faire
 *
 * - **ouvrir ou fermer une question** — il reçoit les lacunes que le code a trouvées et
 *   rend une phrase pour chacune. Une entrée en trop, ou une entrée pour une signature
 *   qu'on ne lui a pas donnée, est ignorée par l'appelant ;
 * - **mentir sur `redacteur`** — une phrase de repli servie par une implémentation
 *   Gemini porte `RedacteurDeQuestion::Regles`, pas `Gemini`. C'est ce qui permet de
 *   répondre à « le repli a-t-il servi ? » par une requête plutôt que par une fouille de
 *   journaux (voir {@see TexteDeQuestion}) ;
 * - **appeler un tiers quand le lot est vide** — `[]` en entrée, `[]` en sortie, et
 *   aucun appel sortant. Le cas est fréquent : c'est celui de toute page rechargée.
 */
interface RedacteurDeQuestions
{
    /**
     * @param list<Lacune> $lacunes
     *
     * @return array<string, TexteDeQuestion> indexé par {@see Lacune::signature()},
     *                                        **une entrée par lacune reçue**
     */
    public function rediger(array $lacunes): array;
}
