<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Contract\CalqueEnAttenteInterface;
use App\Module\Tree\Service\EmpreinteDeLaMiseEnPage;
use App\Module\Tree\Service\TreeFingerprint;

/**
 * L'`ETag` de `GET /api/arbre/graphe` — **fort, jamais faible** (§ 1.8, § 5.2).
 *
 * ```
 * ETag = "sha256( empreinte_de_l_arbre                     -- § 2.3, identique pour tous
 *         || ':' || empreinte_du_cercle_effectif_du_lecteur
 *         || ':' || empreinte_des_demandes_du_lecteur      -- '' s'il n'en a aucune
 *         || ':' || point_de_vue_du_lecteur                -- '' s'il n'est rattaché à personne
 *         || ':' || empreinte_de_la_mise_en_page )"         -- l'image elle-même
 * ```
 *
 * ── La règle, en une ligne ───────────────────────────────────────────────────────
 *
 * > **L'`ETag` doit distinguer tout ce qui distingue la représentation.**
 *
 * Et l'arbitrage qui la fonde, parce qu'il se reposera à chaque composante qu'on hésitera
 * à ajouter : **deux `ETag` différents pour deux charges identiques coûtent une
 * revalidation — quelques kilo-octets, une fois. Un `ETag` identique pour deux charges
 * différentes est une FUITE. Quand on hésite, on distingue.** Le risque n'est pas
 * symétrique ; c'est ce qui tranche, et non l'élégance de la formule.
 *
 * ── Pourquoi le cercle a dû y entrer : la mesure ─────────────────────────────────
 *
 * Les deux premières composantes ne portent **ni** le cercle : l'empreinte de l'arbre est
 * la même pour tout le monde — c'est son intérêt, elle rend le placement partageable
 * (ADR 0005) — et celle des demandes ne porte que le calque. Or le cercle est exactement
 * ce qui fait différer les charges. Mesuré sur la pile, trois comptes réels, aucun d'eux
 * n'ayant de demande en cours :
 *
 * | Compte | Charge | Cercle (session) | Fiches complètes | `ETag` servi |
 * |---|---|---|---|---|
 * | administrateur | 124 412 o | `cercle`, 274 | 274 / 274 | `7295404d…` |
 * | membre actif | 124 092 o | `cercle`, 49 | 270 / 274 | `7295404d…` |
 * | compte en attente | 122 954 o | `aucun` | 256 / 274 | `7295404d…` |
 *
 * Trois charges, trois `md5` distincts, **un seul `ETag`** — et, en présentant celui-ci,
 * le compte en attente recevait `304 Not Modified` sur le graphe de l'administrateur.
 *
 * > **Ce défaut n'était pas exploitable au moment où il a été trouvé, et il ne faut pas
 * > le raconter autrement.** `Cache-Control: private` avec `Vary: Cookie` protège les
 * > caches partagés, et le socle front oublie son `ETag` à tout changement de session.
 * > Le client était couvert deux fois. Mais un `ETag` qui ne distingue pas deux
 * > représentations **n'est pas une clé de cache correcte** : il était désamorcé par deux
 * > garde-fous qu'on peut retirer sans s'en apercevoir, dont l'un vit dans du code front
 * > qu'aucun test d'API ne surveille.
 *
 * ── Ce que la troisième composante ferme, et qu'on n'aurait pas listé ────────────
 *
 * Trois cas tombent d'un coup, et **aucun n'est une exception à retenir** : ce sont des
 * conséquences de « le cercle effectif entre dans la clé ».
 *
 * 1. **Deux lecteurs de cercles différents.** Le cas du § 17.5, gardé par
 *    `tests/Tree/EtagDuGrapheTest` et `tests/Tree/RoutesDeLArbreTest`.
 * 2. **La bascule « voir comme un membre » (§ 10.4).** Le cercle effectif *est* celui du
 *    membre emprunté ; l'`ETag` en hérite sans qu'une seule ligne parle de la bascule.
 *    Auparavant c'était un `if` à part, donc un `if` qu'un remaniement pouvait perdre —
 *    et sans lui l'administrateur revalide, reçoit `304`, et regarde son propre graphe
 *    non filtré sous l'identité qu'il croit tester. *Un outil de vérification qui donne
 *    une fausse assurance est pire que pas d'outil du tout.*
 * 3. **Le réglage `cercle.generations` (1..6), modifiable par l'administrateur.** Il
 *    entre dans la clé de cache serveur du cercle (§ 9.3 du modèle) mais ne touche
 *    **aucune** table `tree_*` : ni l'empreinte de l'arbre ni celle des demandes ne
 *    bougent quand il passe de 3 à 2. Avec l'ancienne formule, resserrer la
 *    confidentialité ne changeait pas l'`ETag` : chaque navigateur de membre revalidait,
 *    recevait `304`, et continuait d'afficher le cercle **large**. *Le durcissement
 *    n'atteignait jamais le client.* Ici il n'y a rien de spécial à faire : un `N` plus
 *    petit donne un cercle plus petit, donc une liste d'identifiants différente, donc une
 *    empreinte différente — le cas se ferme tout seul, et c'est la raison pour laquelle
 *    on hache le cercle **résolu** plutôt que les réglages qui le produisent.
 *
 * ── La quatrième composante : le point de vue ────────────────────────────────────
 *
 * Depuis que la mise en page est calculée **par lecteur** ({@see PlacementPartage}), deux
 * comptes de même cercle et sans demande — deux administrateurs, par exemple — reçoivent
 * des charges dont **toutes les coordonnées diffèrent**, et ne différaient par aucune des
 * trois autres composantes. C'est le même défaut que celui mesuré plus haut, sur une autre
 * dimension : un `ETag` identique pour deux charges différentes.
 *
 * On ajoute donc le point de vue, et il n'y a rien de plus à en dire — c'est l'application
 * directe de la règle en tête de ce fichier. `''` quand le lecteur n'est rattaché à
 * personne : il regarde alors depuis la racine, comme tous ceux qui sont dans son cas, et
 * ils peuvent partager une entrée.
 *
 * ── La cinquième composante : l'image elle-même ──────────────────────────────────
 *
 * Les quatre premières décrivent **l'état de la base et le lecteur**. Aucune ne bouge
 * quand c'est le *code* de mise en page qui change. Or cette route répond
 * `Cache-Control: private, no-cache` : le navigateur garde la charge et revalide. Un
 * lecteur revenu après un déploiement qui change les règles de placement présentait son
 * ancien `ETag`, recevait `304`, et **continuait de dessiner l'ancienne image** — pendant
 * que le serveur, lui, en calculait une nouvelle que personne ne recevait.
 *
 * C'est le cas 3 ci-dessus, mot pour mot, sur une cinquième dimension : *le durcissement
 * n'atteignait jamais le client.* Et comme lui, il ne se referme pas par un `if` mais en
 * mettant dans la clé **ce qui est réellement servi**. D'où une empreinte de la mise en
 * page, dérivée du placement — jamais une constante de version à bousculer à la main, que
 * {@see \App\Module\Tree\ValueObject\Placement::empreinte()} explique en détail.
 *
 * Elle rend d'ailleurs la quatrième redondante en pratique — deux points de vue donnent
 * deux images — et on la garde quand même : elle est lisible, gratuite, et la redondance
 * n'a jamais coûté qu'une revalidation.
 *
 * Le coût, lui, s'ajoute à celui déjà assumé plus bas : le chemin du `304` lit désormais
 * aussi le placement en cache (§ 9.9). Une entrée de cache et un haché.
 *
 * ── La composante des demandes reste nécessaire ──────────────────────────────────
 *
 * Déposer un brouillon ne touche aucune table `tree_*`, donc ne change pas l'empreinte —
 * ni le cercle. Sans elle, l'auteur d'une proposition revalide, reçoit `304`, et ne voit
 * jamais son propre nœud en attente, c'est-à-dire précisément la seule chose que ce
 * calque existe pour produire (ADR 0005). D'où
 * {@see CalqueEnAttenteInterface::empreinteDesDemandes()}.
 *
 * ── Ce qu'on a perdu, et pourquoi c'est le bon prix ──────────────────────────────
 *
 * Avant, deux lecteurs sans demande en cours partageaient un `ETag` : leur navigateur ne
 * retéléchargeait rien tant que l'arbre ne bougeait pas. Ce n'est plus vrai entre cercles
 * différents — et ce ne pouvait pas l'être, puisque leurs charges diffèrent. Ce qui est
 * conservé est ce qui compte : **un même lecteur, d'une requête à l'autre, retrouve son
 * `ETag`** tant que ni l'arbre, ni son cercle, ni ses demandes n'ont changé. C'est la
 * propriété dont dépend la revalidation, et elle est intacte.
 *
 * Le coût mesurable est ailleurs : le chemin du `304` doit désormais **résoudre le
 * cercle** (une lecture des identifiants de personnes, puis le cercle — déjà en cache 24 h,
 * § 9.3). Une revalidation n'est plus gratuite. C'est le prix de la correction, et il est
 * assumé ici plutôt que découvert en service.
 *
 * ── Pourquoi fort, et pas `W/` ───────────────────────────────────────────────────
 *
 * Un `ETag` faible autorise un intermédiaire à considérer deux représentations « assez
 * équivalentes ». Ici, deux représentations qui diffèrent d'un champ diffèrent d'une
 * donnée personnelle.
 */
final readonly class EtagDuGraphe
{
    public function __construct(
        private TreeFingerprint $empreinte,
        private CalqueEnAttenteInterface $calque,
        private FabriqueDuCercle $cercles,
        private PopulationDeLArbre $population,
        private PersonneDuLecteur $pointDeVue,
        private EmpreinteDeLaMiseEnPage $miseEnPage,
    ) {
    }

    /** Avec ses guillemets : c'est la valeur d'en-tête, pas un condensat nu. */
    public function pour(LecteurDeLArbre $lecteur): string
    {
        $pointDeVue = $this->pointDeVue->pour($lecteur);

        return '"'.$this->empreinte->etag(
            $this->empreinteDuCercleEffectif($lecteur),
            $this->calque->empreinteDesDemandes($lecteur->pourLeCalque()),
            null === $pointDeVue ? '' : 'p'.$pointDeVue,
            $this->miseEnPage->empreinteDeLaMiseEnPage($pointDeVue),
        ).'"';
    }

    /**
     * Le client renvoie l'`ETag` **tel quel**, guillemets compris. Un navigateur peut
     * cependant émettre plusieurs valeurs séparées par des virgules, ou `*`.
     */
    public function correspond(?string $ifNoneMatch, string $etag): bool
    {
        if (null === $ifNoneMatch) {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidat) {
            $candidat = trim($candidat);

            if ('*' === $candidat || $candidat === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * **Le cercle EFFECTIF, c'est-à-dire celui qui sert à la sérialisation** — jamais
     * celui du compte authentifié.
     *
     * C'est la même {@see FabriqueDuCercle} que le contrôleur passe au normaliseur, et
     * elle mémorise son résultat pour la requête : la charge et son `ETag` ne peuvent pas
     * être calculés sur deux cercles différents. Un second chemin qui reconstruirait « le
     * cercle du lecteur » à sa façon serait une seconde définition de la confidentialité,
     * et c'est la nature même de ce défaut que les deux finissent par diverger.
     */
    private function empreinteDuCercleEffectif(LecteurDeLArbre $lecteur): string
    {
        return $this->cercles->pour($lecteur)->empreinte($this->population->identifiants());
    }
}
