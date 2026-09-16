<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Entity\DraftPerson;

/**
 * La clé de rapprochement des doublons : nom + année de naissance.
 *
 * ── Le cas qu'elle traite ────────────────────────────────────────────────────────
 *
 * « Deux cousins peuvent proposer le même arrière-grand-oncle » (§ 6.4 du dossier).
 * L'administrateur doit le voir **avant** de trancher, et pouvoir refuser l'une en
 * renvoyant à l'autre — c'est exactement à cela que sert le motif obligatoire au refus.
 * Sans ce rapprochement, l'arbre gagne deux fois la même personne et personne ne s'en
 * aperçoit avant longtemps.
 *
 * ── Pourquoi ce n'est pas le `search_key` de `Tree` ─────────────────────────────
 *
 * Le § 9.5 du modèle écrit « `search_key` + année de naissance ». `search_key` est une
 * **colonne dérivée de `tree_person`**, posée par un service de `Tree` à l'insertion —
 * or une personne proposée n'a aucune ligne dans `tree_person` : la colonne n'existe
 * pas encore, et ne peut pas exister. La normalisation est donc faite ici, sur le
 * brouillon.
 *
 * Elle n'a pas à coïncider avec celle de `Tree`, et il ne faut pas essayer de l'y faire
 * coïncider : les deux clés ne comparent pas les mêmes choses. Celle de `Tree` cherche
 * une personne dans l'arbre validé ; celle-ci rapproche **deux demandes en attente
 * l'une de l'autre**, et rien d'autre. `idx_prp_request_dedup` est un index partiel sur
 * les seules demandes `en_attente` précisément pour cette raison.
 *
 * ── Ce que la normalisation fait, et pourquoi elle est prudente ─────────────────
 *
 * Minuscules, accents retirés, ponctuation et espaces réduits. Elle **ne fait pas** de
 * phonétique ni de distance d'édition : un rapprochement trop large afficherait des
 * doublons qui n'en sont pas, l'administrateur apprendrait à les ignorer, et le jour où
 * il y en a un vrai il l'ignorerait aussi. Mieux vaut manquer un rapprochement — la
 * fiche reste relisible à la main — que le rendre inutile.
 *
 * L'année vient de la **borne basse** de la naissance : pour une fourchette
 * `BET 1901 AND 1909`, c'est `1901`. Deux demandes qui proposent la même personne avec
 * des fourchettes différentes ne se rapprocheront donc pas toujours. C'est assumé : la
 * seule alternative serait de comparer des intervalles, donc de rapprocher tout un
 * siècle sur une date inconnue.
 */
final class CleDeDoublon
{
    /** `prp_change_request.dedup_key` fait 160 caractères. */
    private const int LONGUEUR_MAXIMALE = 160;

    /**
     * Rend `null` quand la personne n'a pas assez d'identité pour être rapprochée : une
     * clé vide regrouperait entre elles toutes les fiches sans nom, ce qui est le
     * contraire d'un rapprochement.
     */
    public function pour(DraftPerson $brouillon): ?string
    {
        $nom = $this->normaliser($brouillon->getSurname());
        $prenom = $this->normaliser($this->premierPrenom($brouillon->getGivenNames()));

        if ('' === $nom && '' === $prenom) {
            return null;
        }

        $annee = $brouillon->getBirth()->getMin()?->format('Y') ?? '?';

        return mb_substr($nom.'|'.$prenom.'|'.$annee, 0, self::LONGUEUR_MAXIMALE);
    }

    /**
     * Le **premier** prénom seulement : « Marne Dolomite » et « Marne » désignent
     * souvent la même personne dans deux relevés, et le second prénom est ce qui varie
     * le plus d'un acte à l'autre.
     */
    private function premierPrenom(string $prenoms): string
    {
        $morceaux = preg_split('/\s+/u', trim($prenoms), 2);

        return \is_array($morceaux) && isset($morceaux[0]) ? $morceaux[0] : '';
    }

    private function normaliser(string $valeur): string
    {
        $sansAccent = @iconv('UTF-8', 'ASCII//TRANSLIT', $valeur);

        // `iconv` peut échouer sur un caractère qu'aucune translittération ne couvre ;
        // on retombe alors sur la valeur d'origine plutôt que sur une chaîne vide, qui
        // ferait silencieusement disparaître le rapprochement.
        $base = false === $sansAccent ? $valeur : $sansAccent;
        $minuscules = mb_strtolower($base, 'UTF-8');

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $minuscules));
    }
}
