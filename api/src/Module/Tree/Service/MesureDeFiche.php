<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\ValueObject\PersonnePlacable;

/**
 * La largeur d'une fiche, mesurée sur son texte — `graphe.py` 399-416.
 *
 * Extrait du moteur pour une raison précise : un **nœud en attente** (§ 6.4 du dossier
 * de mission) doit être mesuré par la même règle que les nœuds validés, sans quoi il
 * détonnerait dans sa fratrie. Deux formules de largeur dans le produit, c'est une
 * divergence qui finit par se voir à l'écran.
 *
 * Sans état, sans dépendance : le moteur peut l'instancier lui-même.
 */
final class MesureDeFiche
{
    /**
     * `max(104, int(max(len(nom) × 6.6, len(dates) × 5.8) + 28))`.
     *
     * La troncature est vers zéro des deux côtés (`int()` en Python, `(int)` en PHP), et
     * les multiplications sont des opérations IEEE 754 identiques bit pour bit — c'est
     * ce qui rend l'égalité exacte atteignable.
     */
    public function largeur(PersonnePlacable $p): int
    {
        $largeurNom = self::longueur($this->etiquetteNom($p)) * MoteurDePlacement::LARG_CAR;
        $largeurDates = self::longueur($this->ligneDates($p)) * MoteurDePlacement::LARG_CAR_DATES;

        return max(
            MoteurDePlacement::LARGEUR_MINIMALE,
            (int) (max($largeurNom, $largeurDates) + 2 * MoteurDePlacement::PAD),
        );
    }

    /**
     * **Le nom que le moteur a mesuré** — donc le seul que le canevas ait le droit de
     * dessiner : le nom d'usage à défaut du prénom, réduit à son **premier mot**, puis le
     * nom de famille. « Marne Sureau », et non « Marne Dolomite Sureau ».
     *
     * Publique pour la même raison que {@see ligneDates()} : **la largeur d'un nœud
     * dépend de deux chaînes, et de deux seulement.** Servir au canevas une chaîne que le
     * moteur n'a pas mesurée fait déborder les fiches — mesuré sur les données réelles :
     * dessiner `displayName`, qui porte *tous* les prénoms, fait déborder **220 fiches sur
     * 274**, jusqu'à 169 unités de trop sur une boîte de 149. Rien ne tombe, et ça se voit
     * à l'œil une fois en service.
     *
     * Et le client ne peut pas la dériver lui-même : `displayName` concatène prénoms et
     * nom, et **« de la Tour » n'est pas découpable par une règle**. D'où trois clés pour
     * trois usages — `displayName` pour lire, `etiquetteNom` pour dessiner,
     * `givenNames`/`surname` pour éditer.
     *
     * Le nom de la méthode suit le contrat d'API (§ 5.2). Son pendant pour les dates s'y
     * appelle `etiquetteDates` mais reste `ligneDates()` ici : le renommer casserait ses
     * appelants dans `Serializer` et `Timeline` sans rien gagner — la correspondance se
     * fait dans le normaliseur, qui est son rôle.
     */
    public function etiquetteNom(PersonnePlacable $p): string
    {
        // Le nom d'usage prime sur le prénom, et on ne garde que le premier prénom :
        // une fiche affiche « Jade Romarin », pas « Jade Marne Jargon Romarin ».
        $affiche = '' !== $p->usage ? $p->usage : $p->prenom;

        return explode(' ', $affiche)[0].' '.$p->nom;
    }

    /**
     * `graphe.py` 399-409.
     *
     * **Pour une personne vivante, la ligne vaut la constante « vivant·e »** et rien
     * d'autre. Ce n'est pas un détail d'affichage : sans cette constante, la largeur de
     * la fiche encoderait la longueur des dates d'une personne masquée, ce qu'aucun test
     * de liste blanche ne verrait (ADR 0003 : tout champ dérivé d'une donnée masquée est
     * une donnée masquée). L'ADR 0005 le redit, parce que c'est le genre de
     * court-circuit qu'un relecteur prend pour une simplification à nettoyer.
     */
    public function ligneDates(PersonnePlacable $p): string
    {
        if ($p->vivant) {
            return 'vivant·e';
        }

        if ('' !== $p->nais && '' !== $p->deces) {
            return $p->nais.' – '.$p->deces;
        }

        if ('' !== $p->nais) {
            return 'n. '.$p->nais;
        }

        if ('' !== $p->deces) {
            return '† '.$p->deces;
        }

        return '—';
    }

    /**
     * Des **caractères**, pas des octets : « vivant·e » fait 8 caractères et 9 octets, et
     * le moindre nom accentué décalerait la fiche si on comptait en octets.
     *
     * `iconv_strlen()` plutôt que `mb_strlen()` : `ext-iconv` est une dépendance ferme
     * déclarée du projet, `ext-mbstring` ne l'est pas.
     */
    private static function longueur(string $texte): int
    {
        $n = iconv_strlen($texte, 'UTF-8');

        return false === $n ? \strlen($texte) : $n;
    }
}
