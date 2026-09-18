<?php

declare(strict_types=1);

namespace App\Module\Tree\ValueObject;

/**
 * La sortie du moteur : les nœuds positionnés, les arêtes, la boîte englobante.
 *
 * Sur les données réelles : 377 nœuds (274 personnes + 103 familles) et 377 arêtes.
 *
 * **Cet objet est l'étage 1 du cache, et il ne sort jamais tel quel de l'API** (ADR 0005).
 * C'est une structure interne, servie uniquement à travers la projection de l'étage 2.
 * Le « calculé sans filtre de confidentialité » porte sur le calcul du placement ; il
 * n'ouvre aucun mode « sans filtre » dans la sérialisation, que l'ADR 0003 interdit.
 *
 * Il ne contient d'ailleurs **aucune donnée personnelle** — que des identifiants et des
 * coordonnées. C'est ce qui rend l'étage 1 partageable entre tous les lecteurs : le
 * cercle de visibilité masque des champs, pas des nœuds.
 */
final readonly class Placement
{
    /**
     * @param array<string, NoeudPlace> $noeuds indexés par identifiant, **dans l'ordre de
     *                                          placement** : rangées croissantes, puis
     *                                          gauche à droite, puis les nœuds familiaux
     * @param list<AreteDePlacement>    $aretes
     * @param string                    $egoId  la personne racine, ramenée à `x = 0`. Le
     *                                          viewer y adosse son cadrage initial, et la
     *                                          projection de l'étage 2 doit pouvoir
     *                                          restaurer l'invariant après insertion —
     *                                          d'où sa présence ici plutôt qu'une
     *                                          détection par « le nœud qui est à zéro »,
     *                                          que deux nœuds peuvent satisfaire
     */
    public function __construct(
        public array $noeuds,
        public array $aretes,
        public BoiteEnglobante $boite,
        public string $egoId,
    ) {
    }

    /**
     * L'empreinte de la mise en page — **l'identité de l'image, dérivée de l'image**.
     *
     * Elle existe pour une raison précise, et il faut la connaître avant d'être tenté de
     * la retirer : l'`ETag` du graphe ne portait que l'etat de l'arbre, le cercle, les
     * demandes et le point de vue. **Aucun des quatre ne bouge quand c'est le CODE de
     * mise en page qui change.** Or `GET /api/arbre/graphe` répond
     * `Cache-Control: private, no-cache` : le navigateur garde la charge et revalide. Un
     * lecteur revenu après un déploiement présentait donc son ancien `ETag`, recevait
     * `304`, et **continuait de dessiner les anciennes coordonnées**.
     *
     * C'est exactement le défaut déjà payé sur `cercle.generations` et raconté dans
     * {@see \App\Module\Tree\Serializer\EtagDuGraphe} : *le changement n'atteignait
     * jamais le client.* Le cache serveur, lui, ne posait pas le problème — il vit dans
     * le conteneur, qu'un déploiement recrée vide.
     *
     * ## Pourquoi dérivée, et pas une constante de version
     *
     * Une constante `VERSION` à bousculer à la main aurait tenu en une ligne. C'est
     * précisément ce que {@see \App\Module\Tree\Service\TreeFingerprint} refuse, avec
     * son argument : *un compteur qu'on oublie d'incrémenter donne un graphe faux qu'on
     * met des semaines à comprendre.* Ici l'oubli est encore plus discret qu'ailleurs —
     * la suite reste verte, la page s'affiche, elle est juste vieille.
     *
     * Une empreinte dérivée de la sortie n'a personne à qui penser : elle distingue
     * **tout** ce qui déplace un nœud, quelle qu'en soit la cause — règles changées,
     * moteur modifié, générations recalculées depuis un autre point de vue.
     *
     * ## Ce qui y entre, et pourquoi
     *
     * Les coordonnées, évidemment. Mais aussi **l'ordre des tableaux** : la projection de
     * l'étage 2 les parcourt tels quels, donc deux placements aux mêmes coordonnées mais
     * d'ordre différent produisent deux charges JSON différentes. Et les arêtes, dont
     * l'ordre voyage de la même façon. La règle de l'`ETag` s'applique ici aussi : *deux
     * empreintes différentes pour deux charges identiques coûtent une revalidation ; une
     * empreinte identique pour deux charges différentes est un bogue.*
     *
     * Aucune donnée personnelle n'y passe — des identifiants et des nombres, comme dans
     * tout cet objet.
     */
    public function empreinte(): string
    {
        $morceaux = [$this->egoId];

        foreach ($this->noeuds as $id => $noeud) {
            $morceaux[] = $id.' '.self::nombre($noeud->x).' '.self::nombre($noeud->y)
                .' '.$noeud->w.' '.$noeud->h;
        }

        foreach ($this->aretes as $arete) {
            $morceaux[] = $arete->de.'>'.$arete->vers.' '.$arete->genre.($arete->divorce ? ' d' : '');
        }

        return hash('sha256', implode('', $morceaux));
    }

    /**
     * Une coordonnée sous une forme qui ne dépend ni de la locale ni du signe du zéro.
     *
     * `%F` et non `%f` : le second suit la locale, et un processus réglé sur une virgule
     * décimale donnerait une empreinte différente pour la même image. `+ 0.0` ramène
     * `-0.0` sur `0.0`, deux écritures du même point.
     */
    private static function nombre(float $valeur): string
    {
        return \sprintf('%.4F', $valeur + 0.0);
    }

    public function noeud(string $id): ?NoeudPlace
    {
        return $this->noeuds[$id] ?? null;
    }
}
