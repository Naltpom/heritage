<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;

/**
 * **Depuis qui ce lecteur regarde-t-il l'arbre ?** — une seule réponse, un seul endroit.
 *
 * Deux choses en dépendent aujourd'hui, et elles ne doivent pas répondre différemment :
 * la **parenté** affichée sur chaque fiche (« ta grand-mère ») et, depuis que la mise en
 * page est calculée par lecteur, la **position de chaque nœud** dans le graphe. Deux
 * chemins qui en décideraient chacun de leur côté finiraient par n'en décider qu'un : on
 * servirait la parenté d'un lecteur sur la mise en page d'un autre, et rien dans la charge
 * ne le dirait.
 *
 * ── Quand la réponse est `null`, et pourquoi c'est la bonne ─────────────────────
 *
 * - **Compte en attente de validation** : il n'a pas de rattachement établi, donc pas de
 *   point de vue. Lui en servir un reviendrait à confirmer le rattachement qu'il
 *   *prétend* avoir, avant qu'un administrateur ne l'ait validé.
 * - **Compte sans rattachement, ou annuaire non branché** : rien à calculer.
 * - **Sous la bascule « voir comme un membre », si le compte emprunté n'a pas pu être
 *   résolu** : on ne sert jamais le point de vue de l'**administrateur** sur un écran qui
 *   prétend montrer celui d'un membre. C'est
 *   {@see LecteurDeLArbre::identiteEffective()} qui ferme, et c'est le même mécanisme qui
 *   ferme le calque — exprès.
 *
 * Sous la bascule **résolue**, en revanche, on répond : le point de vue servi est celui du
 * membre emprunté, et c'est précisément ce que la bascule existe pour montrer.
 *
 * Un administrateur hors bascule peut enfin **choisir** la personne (`?depuis=`, voir
 * {@see LecteurDeLArbre::$pointDeVueChoisi}) : c'est alors elle, avant tout rattachement.
 *
 * `null` veut dire « on retombe sur la racine de l'arbre », pas « interdit ».
 *
 * ── Ce qu'on ne demande jamais ──────────────────────────────────────────────────
 *
 * Le rattachement d'un compte **tiers**. `personneDuCompte()` ne filtre rien et ne doit
 * rien filtrer : apprendre le sien n'apprend rien — il est déjà dans la charge de session
 * (§ 2.2) —, apprendre celui d'un autre serait lire un rattachement sans passer par le
 * cercle.
 */
final class PersonneDuLecteur
{
    /** @var array<string, int|null> mémorisé pour la durée de la requête */
    private array $memoire = [];

    public function __construct(private readonly AnnuaireDesMembresInterface $annuaire)
    {
    }

    public function pour(LecteurDeLArbre $lecteur): ?int
    {
        if ($lecteur->enRegimeDAttente()) {
            return null;
        }

        if (null !== $lecteur->pointDeVueChoisi) {
            return $lecteur->pointDeVueChoisi;
        }

        $compte = $lecteur->identiteEffective();

        if (null === $compte) {
            return null;
        }

        // La clé porte le compte **effectif**, donc celui du membre emprunté sous la
        // bascule : mémoriser sur le compte authentifié servirait le point de vue de
        // l'administrateur au second appel de la même requête.
        $cle = (string) $compte;

        return $this->memoire[$cle] ??= $this->annuaire->personneDuCompte($compte);
    }
}
