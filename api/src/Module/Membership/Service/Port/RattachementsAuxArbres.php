<?php

declare(strict_types=1);

namespace App\Module\Membership\Service\Port;

/**
 * **Les fiches réclamées par un compte** — ce qui fait d'un arbre « l'arbre de
 * quelqu'un » (révision du 2026-10-04 de l'ADR 0003, « un arbre par famille »).
 *
 * La base peut porter plusieurs arbres sans lien entre eux : la famille du commanditaire,
 * puis celle de chaque ami qu'il invite. Un arbre est une composante reliée par les
 * liens de famille ({@see \App\Module\Membership\Service\ArbresDistincts}) ; il est
 * **rattaché** dès qu'une de ses fiches figure ici. Un arbre qu'aucune fiche ne rattache
 * est « sans lien connu », et visible de tout membre.
 *
 * Le sens de l'erreur est choisi : une fiche de trop ici ferme un arbre « sans lien »
 * aux autres membres ; une fiche de moins ouvrirait l'arbre d'un ami à toute la famille.
 * D'où une définition large — tout rattachement, quel que soit son statut (en attente,
 * actif, suspendu), et toute invitation **active** pré-liée à une fiche : inviter un ami
 * sur sa fiche ferme son arbre aux autres avant même qu'il s'inscrive.
 */
interface RattachementsAuxArbres
{
    /**
     * Les identifiants `tree_person.id` réclamés, sans ordre ni doublon garanti.
     *
     * @return list<int>
     */
    public function personnes(): array;
}
