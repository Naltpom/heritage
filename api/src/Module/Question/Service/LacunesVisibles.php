<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Question\Entity\Question;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE POINT UNIQUE OÙ LE CERCLE DE VISIBILITÉ S'APPLIQUE DANS CE MODULE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Ni le détecteur, ni le rédacteur, ni le dépôt, ni le contrôleur ne contiennent de
 * logique de confidentialité. C'est l'idiome de `Almanach\Service\FluxVisible`, et un
 * test le garde : `src/Module/Question/**.php` ne doit contenir **exactement une**
 * occurrence de `CercleDeVisibiliteInterface::class`. Ce n'est pas du zèle — « deux
 * endroits qui décident de la confidentialité, et le second serait celui qu'on
 * oublierait de corriger ».
 *
 * ## Deux méthodes, deux moments, et aucune n'est redondante
 *
 * | quand | quoi | ce que ça empêche |
 * |---|---|---|
 * | {@see filtrerLesLacunes()} | **à la création** | créer une question nommant quelqu'un que ce compte n'a pas le droit de voir |
 * | {@see filtrerLesQuestions()} | **à la lecture** | servir une question créée hier, quand le cercle s'est rétréci depuis |
 *
 * Le second est celui qui compte, et il faut comprendre pourquoi le premier ne suffit
 * pas : **un cercle rétrécit.** `cercle.generations` est un réglage d'administration
 * (ADR 0003 : *« un chiffre qu'on découvre faux »*), et le faire passer de 4 à 3 retire
 * des personnes de tous les cercles d'un coup. Les lignes déjà en base, elles, ne bougent
 * pas : sans le contrôle à la lecture, la page servirait le lendemain des questions
 * nommant des vivants devenus hors cercle, et **aucun test de porte de sortie ne
 * sonnerait** — cette porte-là n'existait pas quand ils ont été écrits.
 *
 * ## Ce qui est filtré : la question ENTIÈRE, jamais un champ
 *
 * Une question ne se sert pas amputée de son sujet. Deux colonnes portent le nom :
 * `sujet_label`, qui est un libellé dénormalisé ordinaire (§ 0.4 du modèle), et surtout
 * `texte` — *« Savez-vous où est née Alberte Bourrache ? »* —, **une phrase en français
 * qui contient le nom**. Aucune liste blanche champ par champ ne l'aurait attrapée : elle
 * aurait regardé `texte` et n'y aurait lu qu'un texte libre. C'est la règle plus générale
 * de l'ADR 0003 qui l'attrape — *« tout champ dérivé d'une donnée masquée est lui-même
 * une donnée masquée »* — et le remède est de ne rien servir du tout.
 *
 * ## Ce que le contrat rend, et ce qu'il ne rend pas
 *
 * {@see CercleDeVisibiliteInterface::filtrerPersonnes()} rend les personnes **visibles**,
 * c'est-à-dire le cercle **plus toutes les personnes décédées** — un fait historique est
 * visible de tout compte connecté (règle 1 du contrat). C'est exactement la garde
 * demandée : *« on ne demande à personne un renseignement sur une personne vivante
 * qu'elle n'a pas le droit de voir »*.
 *
 * Ce n'est **pas** une règle de pertinence, et il ne faut pas la lire comme telle : elle
 * ne dit pas que le lecteur connaissait la personne. C'est
 * {@see DetecteurDeLacunes::memoireVivante()} qui s'en charge, et les deux ne se
 * remplacent pas — l'une protège, l'autre rend la page utile.
 */
final class LacunesVisibles
{
    public function __construct(private readonly CercleDeVisibiliteInterface $cercle)
    {
    }

    /**
     * @param list<Lacune> $lacunes
     *
     * @return list<Lacune>
     */
    public function filtrerLesLacunes(array $lacunes): array
    {
        $visibles = $this->visibles(array_map(static fn (Lacune $l): int => $l->sujetId, $lacunes));

        return array_values(array_filter(
            $lacunes,
            static fn (Lacune $l): bool => isset($visibles[$l->sujetId]),
        ));
    }

    /**
     * @param list<Question> $questions
     *
     * @return list<Question>
     */
    public function filtrerLesQuestions(array $questions): array
    {
        $visibles = $this->visibles(array_map(static fn (Question $q): int => $q->getSujetId(), $questions));

        return array_values(array_filter(
            $questions,
            static fn (Question $q): bool => isset($visibles[$q->getSujetId()]),
        ));
    }

    /**
     * Une seule interrogation du cercle pour tout le lot.
     *
     * `filtrerPersonnes()` existe pour ça, et son en-tête le dit : une boucle sur
     * `voitPersonne()` ferait autant de résolutions de cercle qu'il y a de sujets.
     *
     * @param list<int> $sujets
     *
     * @return array<int, true>
     */
    private function visibles(array $sujets): array
    {
        if ([] === $sujets) {
            // Le contrat n'interdit pas un lot vide, mais l'interroger résoudrait le
            // cercle — donc le calculerait ou le lirait en cache — pour ne rien filtrer.
            return [];
        }

        $ensemble = [];

        foreach ($this->cercle->filtrerPersonnes($sujets) as $id) {
            $ensemble[$id] = true;
        }

        return $ensemble;
    }
}
