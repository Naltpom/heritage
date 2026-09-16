<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\ValueObject\Placement;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * **L'étage 1 du cache** : le placement commun à tous les lecteurs (ADR 0005, § 9.9 du
 * modèle de données).
 *
 * Un seul objet en cache pour toute l'application, calculé sur l'arbre validé complet,
 * **sans aucun filtre de confidentialité**. Le « sans filtre » se justifie en une
 * phrase : le cercle de visibilité masque des champs, pas des nœuds (ADR 0003). Le
 * placement ne dépend donc pas du lecteur.
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
final class PlacementPartage
{
    /**
     * 24 h. Ce n'est pas une durée de fraîcheur : la fraîcheur est garantie par
     * l'empreinte dans la clé. C'est la durée au bout de laquelle une entrée devenue
     * inatteignable est ramassée.
     */
    public const int TTL = 86400;

    public const string PREFIXE_CLE = 'placement.';

    /**
     * `$fournisseur` est optionnel **le temps qu'une implémentation existe**, et pas par
     * confort : charger l'arbre depuis Doctrine suppose deux règles que d'autres tâches
     * possèdent — le rendu compact des dates incertaines (§ 9.1) et le calcul « personne
     * vivante » (§ 9.2, réglage `tree.seuil_vivant_annees`). Les deux entrent dans la
     * **largeur des fiches** : les deviner ici déplacerait silencieusement les 377 nœuds.
     *
     * Tant qu'aucun service n'implémente {@see FournisseurDeJeuDePlacement}, l'appel à
     * {@see self::placement()} échoue avec un message actionnable plutôt que le conteneur
     * au démarrage.
     */
    public function __construct(
        private readonly MoteurDePlacement $moteur,
        private readonly TreeFingerprint $empreinte,
        private readonly CacheInterface $cacheArbre,
        private readonly ?FournisseurDeJeuDePlacement $fournisseur = null,
    ) {
    }

    /**
     * Le placement partagé, calculé au plus une fois par état de l'arbre.
     *
     * Sur les données réelles, le calcul complet tient largement sous la seconde ; c'est
     * le cache qui évite de le refaire à chaque affichage, pas une optimisation de
     * l'algorithme — qu'il ne faut surtout pas toucher (voir {@see MoteurDePlacement}).
     */
    public function placement(): Placement
    {
        $resultat = $this->cacheArbre->get(
            $this->cle(),
            fn (ItemInterface $entree): Placement => $this->calcule($entree),
        );

        \assert($resultat instanceof Placement);

        return $resultat;
    }

    /**
     * `placement.{empreinte}` — **aucun identifiant de lecteur dans cette clé**.
     *
     * Une clé `placement.{empreinte}.{lecteurId}` ne serait pas seulement coûteuse, elle
     * serait fausse : elle ne changerait qu'à une modification *validée* de l'arbre, or
     * déposer un brouillon n'en est pas une. L'auteur d'une proposition ne verrait jamais
     * son propre nœud en attente. L'arbitrage est journalisé dans `docs/arbitrages.md`.
     */
    public function cle(): string
    {
        return self::PREFIXE_CLE.$this->empreinte->empreinte();
    }

    private function calcule(ItemInterface $entree): Placement
    {
        if (null === $this->fournisseur) {
            throw new \LogicException(\sprintf('Aucun service n\'implémente %s : le placement ne peut pas être chargé depuis la base. Déclarer une implémentation dans config/services/tree.yaml.', FournisseurDeJeuDePlacement::class));
        }

        $entree->expiresAfter(self::TTL);

        return $this->moteur->calcule($this->fournisseur->jeuComplet());
    }
}
