<?php

declare(strict_types=1);

namespace App\Module\Question\Contract;

/**
 * Ce qu'un dépôt de réponse a produit dans la file de validation.
 *
 * Deux champs, et il n'en faut pas un troisième.
 *
 * `nature` est la **valeur** de `Proposal\Enum\RequestKind`, en chaîne : `Question` n'a
 * pas le droit de nommer cette énumération (règle 3 de l'ADR 0002), et c'est le même
 * choix que partout au bord de ce module — « elle traverse en chaîne ». Elle est
 * recopiée telle quelle dans `qst_question_demande.nature`, où elle sert à dire « votre
 * photo a été transmise » plutôt que « demande n° 42 », sans jointure.
 *
 * **Ce qu'on ne rapporte pas : l'état de la demande.** Il change — en attente, validée,
 * refusée, caduque —, et une copie d'un état qui change est une copie fausse le
 * lendemain. Qui veut l'état va le lire chez `Proposal`, par l'écran « Mes demandes »
 * qui existe pour ça.
 */
final readonly class ReponseDeposee
{
    public function __construct(
        public int $demandeId,
        public string $nature,
    ) {
    }
}
