<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\ValueObject\Placement;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * **L'étage 1 du cache** : le placement, calculé sur l'arbre validé complet et **sans
 * aucun filtre de confidentialité** (ADR 0005, § 9.9 du modèle de données).
 *
 * Le « sans filtre » se justifie en une phrase : le cercle de visibilité masque des
 * champs, pas des nœuds (ADR 0003). Le placement ne dépend donc **pas du cercle** — et
 * c'est bien ce mot-là qui compte, pas « pas du lecteur ».
 *
 * ## Une entrée par état de l'arbre **et par point de vue**
 *
 * Il n'y en avait qu'une, pour tout le monde, bâtie sur `tree.personne_racine_id`. Or la
 * mise en page en découle entièrement : `génération 0` est la rangée de cette personne,
 * la récursion d'ascendance part d'elle, et les quatre couleurs de branche veulent dire
 * « le quart d'ascendance **de cette personne** ». La servir à tout le monde était juste
 * pour un compte et faux pour tous les autres — exactement l'argument que
 * {@see \App\Module\Tree\Serializer\ParenteDuLecteur} tient déjà pour les libellés de
 * parenté, appliqué aux positions.
 *
 * L'ego entre donc dans la clé. Ce n'est **pas** l'objection que cette classe écartait
 * auparavant : celle-là visait une clé par *lecteur*, qui aurait été fausse parce qu'elle
 * ne changeait pas au dépôt d'un brouillon — l'auteur n'aurait jamais vu son propre nœud
 * en attente. Ici rien de tel : cet étage ne contient **aucun** nœud en attente, c'est la
 * projection qui les ajoute et elle n'est jamais mise en cache. La clé ne dépend que de
 * deux choses, l'état de l'arbre et le point de vue, et toutes deux y figurent.
 *
 * Le nombre d'entrées est borné par le nombre de comptes rattachés à une personne — une
 * famille, pas un public.
 *
 * **Le moteur, lui, ne change pas d'une ligne.** Il reçoit un `egoId` dans son jeu, comme
 * avant ; seul cet identifiant varie. Le fichier de référence des 377 nœuds reste donc
 * valable tel quel, sur l'ego par défaut — la preuve du portage de `graphe.py` est
 * intacte.
 *
 * **Corollaire à ne jamais perdre de vue : cet objet ne sort jamais tel quel de l'API.**
 * C'est une structure interne, servie uniquement à travers {@see ProjecteurDePlacement}.
 * Le « sans filtre » porte sur le calcul, il n'ouvre aucun mode « sans filtre » dans la
 * sérialisation — que l'ADR 0003 interdit.
 *
 * ## L'invalidation
 *
 * Il n'y en a pas, au sens habituel. La clé **contient** l'empreinte de l'arbre
 * ({@see TreeFingerprint}) : quand l'arbre change, la clé change, et l'ancienne entrée
 * devient simplement inatteignable. **On ne supprime jamais une clé de cache à la
 * main** — un balayage de clés n'est pas atomique sur un adaptateur système de fichiers,
 * et c'est précisément ce qu'on évite. Les entrées orphelines sont ramassées par le TTL.
 *
 * Il n'existe donc **aucune liste de déclencheurs à tenir à jour**, et c'est tout
 * l'intérêt : la liste exhaustive qu'un compteur exigeait était une dette permanente, où
 * chaque nouveau chemin d'écriture — validation de proposition, édition d'administrateur,
 * import GEDCOM, correction en base — était une occasion d'oubli.
 */
final class PlacementPartage implements EmpreinteDeLaMiseEnPage
{
    /**
     * 24 h. Ce n'est pas une durée de fraîcheur : la fraîcheur est garantie par
     * l'empreinte dans la clé. C'est la durée au bout de laquelle une entrée devenue
     * inatteignable est ramassée.
     */
    public const int TTL = 86400;

    public const string PREFIXE_CLE = 'placement.';

    /**
     * Le chargement de l'arbre n'est plus ici : il vit dans {@see ArbreVuDepuis}, qui sait
     * aussi le relire depuis un autre point de vue. Cette classe ne fait plus que deux
     * choses — la clé, et le cache.
     */
    public function __construct(
        private readonly MoteurDePlacement $moteur,
        private readonly AffinageDuPlacement $affinage,
        private readonly TreeFingerprint $empreinte,
        private readonly CacheInterface $cacheArbre,
        private readonly ArbreVuDepuis $arbre,
    ) {
    }

    /**
     * Le placement, calculé au plus une fois par état de l'arbre et par point de vue.
     *
     * Sur les données réelles, le calcul complet tient largement sous la seconde ; c'est
     * le cache qui évite de le refaire à chaque affichage, pas une optimisation de
     * l'algorithme — qu'il ne faut surtout pas toucher (voir {@see MoteurDePlacement}).
     *
     * @param int|null $egoPersonneId la personne depuis laquelle ce lecteur regarde,
     *                                `null` pour la racine de l'arbre — voir
     *                                {@see \App\Module\Tree\Serializer\PersonneDuLecteur}
     */
    public function placement(?int $egoPersonneId = null): Placement
    {
        $resultat = $this->cacheArbre->get(
            $this->cle($egoPersonneId),
            fn (ItemInterface $entree): Placement => $this->calcule($entree, $egoPersonneId),
        );

        \assert($resultat instanceof Placement);

        return $resultat;
    }

    /**
     * `placement.{empreinte}` pour la racine, `placement.{empreinte}.p{N}` pour un lecteur
     * rattaché à une personne.
     *
     * La forme sans suffixe est conservée pour la racine, et ce n'est pas qu'une commodité
     * d'écriture : c'est l'entrée que le placement de référence décrit, et la garder telle
     * quelle évite de laisser croire qu'elle a changé de contenu. Elle n'a pas changé.
     */
    public function cle(?int $egoPersonneId = null): string
    {
        $base = self::PREFIXE_CLE.$this->empreinte->empreinte();

        return null === $egoPersonneId ? $base : $base.'.p'.$egoPersonneId;
    }

    /**
     * L'empreinte de l'image servie à ce point de vue — {@see EmpreinteDeLaMiseEnPage}.
     *
     * Elle passe par le cache, donc elle ne recalcule rien : sur le chemin d'un `304`,
     * c'est une lecture d'entrée et un haché. La revalidation n'était déjà plus gratuite
     * — elle résout le cercle du lecteur — et ceci s'ajoute au même ordre de grandeur.
     *
     * Ce que ça achète, en échange : un déploiement qui change les règles de mise en page
     * **atteint les navigateurs**. Sans cette composante, ils revalidaient, recevaient
     * `304`, et redessinaient l'ancienne image. Le raisonnement complet est dans
     * {@see Placement::empreinte()}.
     */
    public function empreinteDeLaMiseEnPage(?int $egoPersonneId): string
    {
        return $this->placement($egoPersonneId)->empreinte();
    }

    private function calcule(ItemInterface $entree, ?int $egoPersonneId): Placement
    {
        $entree->expiresAfter(self::TTL);

        // L'arbre vu depuis ce point de vue — mêmes fiches, mêmes largeurs, générations
        // recalculées. Le moteur, lui, ne sait rien de tout cela : il reçoit un jeu et son
        // ego, comme toujours. Voir {@see ArbreVuDepuis}.
        $jeu = $this->arbre->jeu($egoPersonneId);

        // Puis l'affinage : le moteur donne les générations et la structure
        // d'ascendance — ce qui met le lecteur au centre et fonctionne —, l'affinage
        // refait l'ordre des rangées et toutes les abscisses. Voir
        // {@see AffinageDuPlacement} pour les règles et ce qu'elles ont coûté.
        return $this->affinage->affine($this->moteur->calcule($jeu), $jeu);
    }
}
