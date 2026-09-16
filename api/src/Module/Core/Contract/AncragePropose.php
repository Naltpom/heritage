<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * Comment un nœud proposé se rattache à l'arbre — l'intention, pas la famille.
 *
 * ── Pourquoi une intention et pas une famille ───────────────────────────────────
 *
 * **Il n'existe pas de brouillon de famille.** « La demande décrit l'intention
 * (“conjoint de X”), le service en déduit les familles à créer **au moment de la
 * validation**, pas au moment de la demande » (§ 6.4 du dossier, § 9.5 du modèle). Si on
 * figeait la famille à la saisie, une autre demande validée entre-temps aurait pu la
 * créer, et on en insérerait une seconde — que `uniq_tree_family_couple` refuserait.
 *
 * Conséquence directe pour le rendu, et c'est la divergence 9 du test des trois routes :
 * un conjoint proposé sans famille existante a besoin d'un **losange** entre lui et son
 * conjoint, et `Proposal` n'a rien à envoyer pour cela. La famille provisoire est donc
 * **allouée par la projection, en mémoire seulement**, numérotée dans l'ordre des liens
 * (`#12/f1`). Rien n'est persisté, et `#12/f1` de ce matin n'est pas garanti d'être
 * `#12/f1` cet après-midi : le front ne le stocke pas.
 *
 * ── Les deux extrémités sont des identifiants de nœud ───────────────────────────
 *
 * `p55` pour une personne validée, `#12/p1` pour une personne proposée. **C'est
 * délibéré** : l'appelant n'a aucune règle à connaître pour distinguer les deux — « un
 * identifiant de nœud qui commence par `#` est provisoire » —, et la règle de
 * composition reste dans le module qui l'a définie.
 */
final readonly class AncragePropose
{
    /**
     * @param string      $kind              `conjoint_de`, `enfant_de` ou `parent_de` — valeur de `LinkKind`
     * @param string      $sujetNoeudId      l'extrémité qui porte le lien : `#12/p1` ou `p55`
     * @param string|null $autreNoeudId      l'autre extrémité, `null` quand un couple existant est désigné
     * @param string|null $autre2NoeudId     le **second parent** d'une filiation, quand le demandeur en a
     *                                       désigné deux plutôt qu'une famille
     * @param int|null    $familleId         `tree_family.id` quand le demandeur a désigné un **couple
     *                                       existant** : il n'y a alors aucun losange à inventer
     * @param string|null $familleProvisoire `#12/f1` quand le rendu a besoin d'un losange qui n'existe
     *                                       nulle part — alloué à la projection, jamais persisté
     */
    public function __construct(
        public int $demandeId,
        public int $seq,
        public string $kind,
        public string $sujetNoeudId,
        public ?string $autreNoeudId,
        public ?string $autre2NoeudId,
        public ?int $familleId,
        public ?string $familleProvisoire,
        public bool $divorced,
    ) {
    }
}
