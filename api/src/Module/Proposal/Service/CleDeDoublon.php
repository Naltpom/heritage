<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Service\CleDeRecherche;
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
 * pas encore, et ne peut pas exister. La clé est donc composée ici, sur le brouillon.
 *
 * Elle n'a pas à coïncider avec la clé de `Tree`, et il ne faut pas essayer de l'y faire
 * coïncider : les deux clés ne comparent pas les mêmes choses. Celle de `Tree` cherche
 * une personne dans l'arbre validé ; celle-ci rapproche **deux demandes en attente
 * l'une de l'autre**, et rien d'autre. `idx_prp_request_dedup` est un index partiel sur
 * les seules demandes `en_attente` précisément pour cette raison.
 *
 * ── Le repliement, lui, est celui de `Core` — et il ne l'a pas toujours été ──────
 *
 * Ce qui diffère entre les deux clés, c'est leur **forme** (`nom|prénom|année` ici, la
 * suite des mots de l'identité là-bas) — jamais la façon de retirer un accent. Celle-ci
 * n'a **qu'une** définition dans le projet, {@see CleDeRecherche::replier()}, et ce
 * fichier l'appelle.
 *
 * **Il employait `iconv('UTF-8', 'ASCII//TRANSLIT')`, et c'était un bogue de
 * plateforme** — celui-là même que l'en-tête de `CleDeRecherche` interdit nommément. Le
 * résultat d'`iconv` dépend de la bibliothèque du système : `é` devient `e` sous la glibc
 * de Linux, `'e` sous la libiconv de Windows, et **`?` sous la musl d'Alpine**, l'image
 * Docker de production. La même « Génévrolle » avait donc trois clés selon la machine
 * qui recevait la soumission :
 *
 * | Bibliothèque | Clé du nom |
 * |---|---|
 * | glibc | `genevrolle` |
 * | libiconv 1.17 (Windows, mesuré le 09/10/2026) | `g en evrolle` |
 * | musl (Alpine, la production) | `g n vrolle` |
 *
 * Et, plus grave, deux saisies d'une même personne qui ne diffèrent que d'un accent —
 * « Génévrolle » et « Génevrolle » — ne se rapprochaient plus **que sous glibc**, la
 * seule des trois sur laquelle le projet ne tourne pas : en production, `g n vrolle`
 * contre `g nevrolle`, donc **aucun doublon signalé**, alors que c'est exactement
 * l'écart que le repliement existe pour effacer. Les tests ne le voyaient pas : ils
 * comparaient deux saisies identiques, qui donnent la même clé sur toute plateforme.
 *
 * Les clés déjà posées sur les demandes en attente se reprennent par
 * `heritage:recalculer-les-cles-de-doublon`
 * ({@see \App\Module\Proposal\Command\RecalculerLesClesDeDoublonCommand}), à lancer une
 * fois après le déploiement de ce correctif.
 *
 * ── Ce que la normalisation fait, et pourquoi elle est prudente ─────────────────
 *
 * Minuscules, accents retirés, ligatures dépliées (`œ` → `oe`), ponctuation et espaces
 * réduits — l'alphabet de la clé est `[a-z0-9 ]` et le séparateur `|`, comme avant le
 * correctif : seule la façon d'y arriver change. Elle **ne fait pas** de phonétique ni de
 * distance d'édition : un rapprochement trop large afficherait des doublons qui n'en sont
 * pas, l'administrateur apprendrait à les ignorer, et le jour où il y en a un vrai il
 * l'ignorerait aussi. Mieux vaut manquer un rapprochement — la fiche reste relisible à
 * la main — que le rendre inutile.
 *
 * L'année vient de la **borne basse** de la naissance : pour une fourchette
 * `BET 1901 AND 1909`, c'est `1901`. Deux demandes qui proposent la même personne avec
 * des fourchettes différentes ne se rapprocheront donc pas toujours. C'est assumé : la
 * seule alternative serait de comparer des intervalles, donc de rapprocher tout un
 * siècle sur une date inconnue.
 */
final readonly class CleDeDoublon
{
    /** `prp_change_request.dedup_key` fait 160 caractères. */
    private const int LONGUEUR_MAXIMALE = 160;

    /**
     * Le repliement est **injecté**, mais il a une valeur par défaut : la classe est
     * construite par le conteneur en production, et à la main par plus d'un test. La
     * valeur par défaut n'est pas un second repliement — c'est le même, instancié sans
     * conteneur, et `CleDeRecherche` n'a ni état ni dépendance qui pourrait les faire
     * diverger.
     */
    public function __construct(private CleDeRecherche $repliement = new CleDeRecherche())
    {
    }

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
     * La clé d'une **demande** : celle de la première fiche qui en a une, dans l'ordre où
     * on les lui passe.
     *
     * Extraite de `ConstructeurDeCreation::verifierAvantSoumission()`, qui la calculait en
     * ligne, pour que la soumission et la reprise
     * ({@see \App\Module\Proposal\Command\RecalculerLesClesDeDoublonCommand}) fassent
     * **exactement** le même calcul. Une reprise qui recomposerait la règle de son côté
     * réécrirait les clés selon une seconde formule, et le premier écart entre les deux
     * ferait disparaître un rapprochement sans que rien ne le signale.
     *
     * Une seule clé par demande, même si elle propose plusieurs personnes : c'est une
     * limite du schéma (`dedup_key` est une colonne de la demande), pas un choix de cette
     * méthode.
     *
     * @param iterable<DraftPerson> $brouillons
     */
    public function pourLesBrouillons(iterable $brouillons): ?string
    {
        foreach ($brouillons as $brouillon) {
            $cle = $this->pour($brouillon);

            if (null !== $cle) {
                return $cle;
            }
        }

        return null;
    }

    /**
     * Le **premier** prénom seulement : « Marne Dolomite » et « Marne » désignent
     * souvent la même personne dans deux relevés, et le second prénom est ce qui varie
     * le plus d'un acte à l'autre.
     *
     * Le découpage se fait sur la saisie brute, **avant** le repliement : un prénom
     * composé à trait d'union (« Jean-Baptiste ») reste donc un seul prénom, que le
     * repliement transforme ensuite en `jean baptiste`.
     */
    private function premierPrenom(string $prenoms): string
    {
        $morceaux = preg_split('/\s+/u', trim($prenoms), 2);

        return \is_array($morceaux) && isset($morceaux[0]) ? $morceaux[0] : '';
    }

    /**
     * `[a-z0-9 ]`, rien d'autre — la même propriété que la clé de `Core`, en minuscules.
     *
     * `CleDeRecherche::replier()` rend du `[A-Z0-9 ]` : accents repliés par une table
     * explicite, ligatures dépliées, tout le reste réduit à des espaces simples, bords
     * retirés. Il ne reste qu'à passer en minuscules, et `strtolower()` suffit — la
     * chaîne n'a plus un seul octet hors ASCII.
     */
    private function normaliser(string $valeur): string
    {
        return strtolower($this->repliement->replier($valeur));
    }
}
