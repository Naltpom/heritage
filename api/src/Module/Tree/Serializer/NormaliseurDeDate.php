<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Service\EtiquetteDeDateGedcom;

/**
 * Le **bloc date** du § 14 du contrat d'API : un objet, jamais une chaîne.
 *
 * ```json
 * { "raw": "BET 1901 AND 1909", "qualifier": "entre",
 *   "min": "1901-01-01", "max": "1909-12-31",
 *   "place": "Saule, Ombrage, Sylvanie", "texte": "entre 1901 et 1909" }
 * ```
 *
 * **Six clés dans une fiche, cinq dans une valeur d'op** : `texte` est le rendu de
 * {@see UncertainDate::format()}, il est en lecture seule et n'entre jamais dans un
 * corps de requête. Ce module n'émet que la forme à six clés — la forme à cinq
 * appartient à `Proposal`, qui l'a définie. Les deux sont rappelées ici pour qu'on ne
 * prenne pas l'une pour l'autre en ajoutant un champ.
 *
 * **Les cinq clés sont toujours émises, même à `null`.** Un bloc date à qui il manque
 * une clé serait indistinguable d'un bloc dont la clé n'est pas renseignée — « pour
 * qu'un champ absent soit toujours une erreur et jamais un non-renseigné implicite »
 * (§ 5.2 du modèle). Attention : cela **n'entre pas en conflit** avec la règle « absent
 * contre `null` » du § 1.3, qui porte sur les champs d'une fiche. Un bloc date interdit
 * n'est pas émis à `null` : il n'est **pas émis du tout**.
 *
 * Rien ici n'aplatit une fourchette. Il n'existe aucun accesseur qui rendrait un point
 * sur {@see UncertainDate}, et ce normaliseur n'en fabrique pas : `min` et `max` partent
 * tels quels.
 */
final class NormaliseurDeDate
{
    public function __construct(private readonly EtiquetteDeDateGedcom $compacte = new EtiquetteDeDateGedcom())
    {
    }

    /**
     * @return array{raw: string|null, qualifier: string, min: string|null, max: string|null, place: string|null, texte: string}
     */
    public function pourFiche(UncertainDate $date): array
    {
        return [
            'raw' => $date->getRaw(),
            'qualifier' => $date->getQualifier()->value,
            'min' => $date->getMin()?->format('Y-m-d'),
            'max' => $date->getMax()?->format('Y-m-d'),
            'place' => $date->getPlace(),
            'texte' => $date->format(),
        ];
    }

    /**
     * `ligneDates` — la **forme compacte** du § 14.4 : « 1901-1909 – 1987 »,
     * « n. 26.9.2012 ».
     *
     * ═══════════════════════════════════════════════════════════════════════════
     *  TROIS FORMES, TROIS PRODUCTEURS — LES CONFONDRE EST LE DÉFAUT QUI REVIENT.
     * ═══════════════════════════════════════════════════════════════════════════
     *
     * | Forme | Clé | Producteur | Où |
     * |---|---|---|---|
     * | Prose | `texte`, **dans** le bloc date | `UncertainDate::format()` | fiches, calque |
     * | **Compacte** | **`ligneDates`** | **{@see EtiquetteDeDateGedcom}** | fiche, recherche, chronologie |
     * | Étiquette mesurée | `etiquetteDates` | `MesureDeFiche::ligneDates()` | nœud du graphe, et nulle part ailleurs |
     *
     * **Cette méthode a rendu la prose pendant un temps**, en appelant
     * `UncertainDate::format()` — le producteur de `texte`. Le squelette d'assemblage
     * était juste ; c'est le formateur par date qui était le mauvais. Le défaut ne se
     * voyait qu'en regardant la charge réelle : « entre 1901 et 1909 – 1987 » est une
     * phrase parfaitement plausible, et deux fois trop longue pour l'endroit où elle
     * allait.
     *
     * ## La différence avec `etiquetteDates`, et pourquoi elle ne se transpose pas
     *
     * `etiquetteDates` **court-circuite sur `vivant`** et rend la constante « vivant·e » ;
     * `ligneDates` ne court-circuite **jamais**. Ce n'est pas une incohérence :
     *
     * - `etiquetteDates` est **mesurée**, à l'étage 1 du cache de placement — partagé, et
     *   sans filtre de confidentialité. Si elle portait les vraies dates d'une personne
     *   vivante, la largeur de son nœud en encoderait la longueur pour **tous** les
     *   lecteurs, y compris hors cercle ;
     * - `ligneDates` est **filtrée en aval** : elle n'est émise que si le lecteur a le
     *   droit de voir ces dates — elle tombe d'une fiche restreinte, et une personne
     *   vivante hors cercle est absente de la recherche. Lui servir « vivant·e » masquerait
     *   une donnée que le cercle vient d'autoriser.
     *
     * La même personne porte donc deux chaînes différentes dans la même réponse, et c'est
     * correct.
     *
     * L'assemblage, lui, est **identique** pour les deux clés — c'est `ligne_dates()` de
     * `graphe.py`, ligne 399. Le tiret est un demi-cadratin entouré d'espaces, et l'absence
     * totale de date rend « — », jamais la chaîne vide.
     */
    public function ligneDeFiche(UncertainDate $naissance, UncertainDate $deces): string
    {
        $n = $this->compacte->etiquette($naissance);
        $d = $this->compacte->etiquette($deces);

        if ('' !== $n && '' !== $d) {
            return $n.' – '.$d;
        }

        if ('' !== $n) {
            return 'n. '.$n;
        }

        if ('' !== $d) {
            return '† '.$d;
        }

        return '—';
    }
}
