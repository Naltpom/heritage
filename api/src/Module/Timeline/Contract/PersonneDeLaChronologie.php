<?php

declare(strict_types=1);

namespace App\Module\Timeline\Contract;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Une personne de l'arbre, réduite à ce qu'une **barre** de la vue chronologique a besoin
 * de savoir — et à rien de plus.
 *
 * Volontairement pauvre, pour la raison qu'énonce déjà
 * `Membership\Service\Port\PersonneDeLArbre` : **ce qui ne traverse pas ce port ne peut
 * pas fuir par ce port.** Le § 9.1 du contrat d'API énumère les onze clés d'une barre ;
 * tout ce qui n'y figure pas est fermé ici par absence de champ, plutôt que par la
 * vigilance du normaliseur. Ni citations, ni sources, ni médias, ni notes, ni nom de
 * jeune fille, ni `searchKey` : une barre est un segment sur un axe, pas une fiche.
 *
 * ## `birth` et `death` sont des `UncertainDate`, pas des années
 *
 * C'est **le** point de ce fichier, et il se perdrait à la première relecture distraite.
 * Le port pourrait rendre `?int $anneeNaissance` — ce serait plus simple, et ce serait
 * l'aplatissement que le § 4 du dossier de mission interdit en base comme à l'écran.
 * Marne Dolomite Sureau porte `BET 1901 AND 1909` : une fourchette de huit ans, qu'un
 * `int` réduirait à un point au moment même où le § 7.4 demande de la **hachurer**.
 *
 * La conversion en `{min, max, certain}` a lieu une seule fois, dans
 * {@see \App\Module\Timeline\Service\IntervalleDAnnees}, et elle est visible à l'endroit
 * où elle est faite — comme l'exige l'en-tête d'{@see UncertainDate}, qui n'offre
 * délibérément ni `getDate()` ni `getYear()`.
 *
 * ## Les étiquettes compactes traversent le port, elles ne se recalculent pas ici
 *
 * `ligneDates` d'une barre est la forme **compacte** du § 14.4 — `1901-1909`, et non la
 * prose `entre 1901 et 1909`. Sa grammaire est celle de `jolie()` de `graphe.py`, portée
 * dans `Tree\Service\EtiquetteDeDateGedcom`, qui vit dans `Service/` : l'interne de `Tree`
 * au sens de l'ADR 0002. `Timeline` n'a donc pas le droit de l'appeler, et **surtout pas
 * de la réécrire** — deux implémentations d'une même grammaire de sept cas divergent, et
 * elles divergent silencieusement, sur `AFT` ou sur un mois isolé que personne ne teste.
 *
 * Le port transporte donc les deux étiquettes **déjà composées par leur propriétaire**.
 * C'est exactement ce que fait déjà `Membership\Service\Port\PersonneDeLArbre` avec son
 * `etiquetteNaissance`, et pour la même raison.
 *
 * L'**assemblage**, lui, appartient à l'appelant : `n. `, `† `, le demi-cadratin entouré
 * d'espaces, `—` quand il n'y a rien. C'est là que `ligneDates` se sépare
 * d'`etiquetteDates` — celle-ci court-circuite sur `vivant` et rend `vivant·e`, celle-là
 * jamais (§ 14.4). Faire composer la ligne entière par `Tree` reviendrait à lui demander
 * laquelle des deux règles appliquer, c'est-à-dire à faire traverser la question au lieu
 * de la réponse.
 *
 * ## `vivant` est lu, jamais recalculé
 *
 * C'est la propriété **calculée** du § 9.2 du modèle de données : `Tree` la possède, avec
 * sa règle, son seuil `tree.seuil_vivant_annees` et sa médiane par génération. `Timeline`
 * la reçoit. *Une règle de confidentialité ne se trompe qu'une fois pour fuir*, et une
 * seconde définition de « vivant » écrite ici déciderait un jour autrement de ce que la
 * sérialisation masque.
 */
final readonly class PersonneDeLaChronologie
{
    /**
     * @param int           $id          `tree_person.id`
     * @param string        $displayName le nom d'affichage de la fiche
     * @param Branch|null   $branch      le quart d'ascendance ; `null` n'est pas plaçable
     *                                   dans une colonne de branche
     * @param int|null      $generation  `gen` du GEDCOM, de 0 à -11
     * @param Sex           $sex         pour la couleur de la barre
     * @param bool          $vivant      la propriété calculée du § 9.2, calculée par `Tree`
     * @param UncertainDate $birth       **jamais aplatie** : c'est le cas Sureau
     * @param UncertainDate $death       idem ; `inconnue()` pour une personne vivante
     * @param string        $etiquetteNaissance la forme **compacte** du § 14.4 — `1901-1909`,
     *                                   `26.9.2012`, `~1889`, `<1920` —, chaîne vide si la
     *                                   date est inconnue
     * @param string        $etiquetteDeces idem pour le décès
     */
    public function __construct(
        public int $id,
        public string $displayName,
        public ?Branch $branch,
        public ?int $generation,
        public Sex $sex,
        public bool $vivant,
        public UncertainDate $birth,
        public UncertainDate $death,
        public string $etiquetteNaissance = '',
        public string $etiquetteDeces = '',
    ) {
    }
}
