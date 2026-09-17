<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * Une source de l'arbre, telle que `Proposal` a besoin de la voir **au dépôt**.
 *
 * Deux champs, et pas un de plus : de quoi vérifier qu'elle existe, et de quoi écrire le
 * diff sans jointure. Le constructeur d'une demande n'a rien à savoir de l'auteur, de la
 * publication ni du `SourceKind` — il vérifie une existence et recopie un titre.
 *
 * Elle vit dans `Contract\` et non dans `Service\Port\` pour la raison exposée en tête
 * de {@see CitationAcreer} : la frontière passe par `Contract\`, et les voisins qui n'y
 * sont pas encore y sont attendus.
 *
 * ── Pourquoi une méthode de port plutôt qu'un `chargerCible(SubjectKind::Source, …)` ──
 *
 * Parce que `SubjectKind` n'a pas de cas `source`, et qu'il ne doit pas en avoir un :
 * ses six valeurs disent sur quoi porte un **op** ou une **révision** (§ 5.7), et une
 * source n'est ni l'un ni l'autre — on ne modifie pas une source par une demande, on
 * cite. Lui en ajouter un obligerait `EcritureDeLArbrePourProposal::ligne()`,
 * `SurfaceEditable::champs()` et le `match` de trois autres services à traiter un cas
 * qui n'a ni surface éditable ni verrou optimiste. Une méthode nommée coûte moins cher
 * qu'une énumération élargie pour un seul appelant.
 */
final readonly class SourceCitable
{
    /**
     * @param string $titre `tree_source.title` — ce qui s'affiche dans le diff, jamais ce
     *                      sur quoi on décide (§ 0.4 du modèle)
     */
    public function __construct(
        public int $id,
        public string $titre,
    ) {
    }
}
