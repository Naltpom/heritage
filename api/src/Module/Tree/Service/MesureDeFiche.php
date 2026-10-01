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
    /* ------------------------------------------------------------------ */
    /* La fiche telle qu'elle est AFFICHÉE — photo à gauche, dates des vivants */
    /* ------------------------------------------------------------------ */

    /**
     * L'emplacement photo, **fixe pour tout le monde**, silhouette quand il n'y a pas
     * de portrait. Fixe, parce qu'une largeur qui changerait selon qui a une photo
     * réorganiserait l'arbre à chaque portrait déposé.
     */
    public const int LARGEUR_PHOTO = 40;

    /** Entre le bord gauche de la fiche et la photo, puis entre la photo et le texte. */
    public const int ESPACE_PHOTO = 8;

    /** 56 et non 40 : la photo est carrée et il faut deux lignes de texte à côté. */
    public const int HAUTEUR_AFFICHEE = 56;

    /**
     * La ligne de dates **telle qu'elle est affichée** : pour une personne vivante,
     * sa naissance puis « vivant·e », et non « vivant·e » seul.
     *
     * Elle est distincte de {@see ligneDates()}, et ce n'est pas un doublon : cette
     * dernière est **ce que le moteur mesure**, et la preuve de non-régression du
     * moteur — 377 nœuds au centième — dépend de ce qu'elle rend. La ligne affichée
     * change ici, le moteur ne le voit pas, et c'est l'affinage qui remesure.
     *
     * Elle n'est servie que sur une fiche visible du lecteur : sur une fiche
     * restreinte, aucune ligne de dates ne part, ni celle-ci ni l'autre (§ 16.2).
     */
    public function ligneDatesAffichee(PersonnePlacable $p): string
    {
        if ($p->vivant) {
            return '' !== $p->nais ? 'n. '.$p->nais.' · vivant·e' : 'vivant·e';
        }

        return $this->ligneDates($p);
    }

    /**
     * **La largeur affichée de TOUTE fiche vivante** — révision du 2026-10-01.
     *
     * ── POURQUOI UNE CONSTANTE ──────────────────────────────────────────────────────
     *
     * ADR 0003 : *tout champ dérivé d'une donnée masquée est lui-même une donnée masquée*.
     * Mesurée sur son texte, la fiche d'un vivant encodait deux choses qu'un lecteur hors
     * cercle n'a pas le droit de lire : la longueur de sa **date de naissance** (la ligne
     * affichée « n. 3.4.1960 · vivant·e »), et — depuis que son nom n'est plus servi hors
     * cercle — la longueur de son **nom**. Et pas seulement par `w` : l'écart entre deux
     * fiches voisines la redonne par les `x`.
     *
     * La largeur ne peut pas dépendre du lecteur (le placement est partagé, ADR 0005). Elle
     * ne dépend donc plus de rien : **même boîte pour tous les vivants, vus ou non**.
     *
     * ── LA VALEUR ───────────────────────────────────────────────────────────────────
     *
     * La formule de {@see largeurAffichee()}, appliquée à la fiche vivante **la plus
     * longue qu'on veuille afficher sans troncature** : une date complète,
     * « n. 12.12.1985 · vivant·e » (24 signes × 5,8 = 139,2), et un nom de 21 signes
     * (× 6,6 = 138,6). `(int) (139,2 + 28) = 167` de texte, plus 48 de photo : **215**.
     * Un nom plus long est tronqué par le canevas, qui retombe sur le prénom (le texte des
     * fiches s'adapte déjà à sa boîte) ; une date plus longue n'existe pas sous la forme
     * compacte.
     *
     * Le prix, assumé : les fiches de vivants s'élargissent un peu pour tout le monde
     * (≈ 180 → 215 pour une naissance en année seule), et l'arbre avec elles.
     * `MesureDeFicheTest` refait le calcul pour que la constante ne dérive pas en silence.
     */
    public const int LARGEUR_AFFICHEE_VIVANT = 215;

    /**
     * La largeur **affichée** : le texte, mesuré sur la ligne de dates affichée, plus
     * l'emplacement photo et ses deux marges. Pour une personne vivante, la constante
     * {@see LARGEUR_AFFICHEE_VIVANT} — elle ne lit ni le nom ni la date.
     */
    public function largeurAffichee(PersonnePlacable $p): int
    {
        if ($p->vivant) {
            return self::LARGEUR_AFFICHEE_VIVANT;
        }

        $largeurNom = self::longueur($this->etiquetteNom($p)) * MoteurDePlacement::LARG_CAR;
        $largeurDates = self::longueur($this->ligneDatesAffichee($p)) * MoteurDePlacement::LARG_CAR_DATES;

        $texte = max(
            MoteurDePlacement::LARGEUR_MINIMALE,
            (int) (max($largeurNom, $largeurDates) + 2 * MoteurDePlacement::PAD),
        );

        return $texte + self::LARGEUR_PHOTO + self::ESPACE_PHOTO;
    }

    private static function longueur(string $texte): int
    {
        $n = iconv_strlen($texte, 'UTF-8');

        return false === $n ? \strlen($texte) : $n;
    }
}
