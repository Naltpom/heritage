<?php

declare(strict_types=1);

namespace App\Module\Membership\Service\Port;

/**
 * Une personne de l'arbre, réduite à ce dont `Membership` a besoin — et à rien de plus.
 *
 * Volontairement pauvre, pour la même raison que `Tree\ValueObject\PersonnePlacable` est
 * pauvre : **ce qui ne traverse pas ce port ne peut pas fuir par ce port.** Le § 4.2 du
 * contrat d'API énumère ce que la recherche de rattachement ne renvoie jamais — prénoms
 * entiers, date exacte, lieu, sexe, génération, branche, parenté, portrait. La moitié de
 * cette liste est fermée ici, par absence de champ, plutôt que par la vigilance du
 * normaliseur.
 *
 * Trois champs demandent une explication.
 *
 * **`vivante`** est la propriété calculée du § 9.2 du modèle, **jamais une colonne**.
 * Elle est calculée par `Tree` — c'est lui qui possède la règle, le seuil
 * `tree.seuil_vivant_annees` et la médiane par génération —, et `Membership` ne la
 * recalcule pas : *une règle de confidentialité ne se trompe qu'une fois pour fuir*.
 *
 * **`anneeNaissance`** est l'année affichée dans le libellé minimal `P. Romarin, 1980`.
 * Une année, pas une date : le § 4.2 interdit de rendre la date exacte à quelqu'un qui
 * n'est pas encore inscrit.
 *
 * **`etiquetteNaissance`** est la forme compacte du § 5.2 du contrat (`22.5.1949`,
 * `~1934`, `1901-1909`, `<1920`). Elle **ne sort jamais de l'API** : elle sert
 * uniquement, à l'intérieur du serveur, à confronter la date soumise par le candidat à
 * celle de la fiche. C'est la seule granularité dont dispose la preuve de connaissance,
 * et c'est assez : on répond « oui » ou « non », jamais « la date attendue est en 1934 ».
 */
final readonly class PersonneDeLArbre
{
    /**
     * @param int      $id                 `tree_person.id`
     * @param string   $prenoms            les prénoms complets — **jamais rendus tels quels** ;
     *                                     seule leur initiale sort, par {@see self::libelleMinimal()}
     * @param string   $nom                le nom de famille
     * @param string   $libelle            le nom d'affichage de la fiche, pour les colonnes
     *                                     `*_label` dénormalisées (§ 0.4 du modèle)
     * @param int|null $anneeNaissance     l'année de naissance, `null` si inconnue
     * @param string   $etiquetteNaissance la date de naissance compacte, chaîne vide si inconnue
     * @param bool     $vivante            la propriété calculée du § 9.2, calculée par `Tree`
     */
    public function __construct(
        public int $id,
        public string $prenoms,
        public string $nom,
        public string $libelle,
        public ?int $anneeNaissance,
        public string $etiquetteNaissance,
        public bool $vivante,
    ) {
    }

    /**
     * **L'affichage minimal du § 6.1 du dossier de mission**, fixé au caractère près par
     * le § 4.2 du contrat d'API pour qu'aucune implémentation ne « complète utilement ».
     *
     * `initiale du premier prénom` + `.` + espace + `nom` + `,` + espace + `année`, soit
     * `P. Romarin, 1980`. Si l'année est inconnue, le libellé s'arrête au nom :
     * `P. Romarin`. Si le prénom est inconnu, il n'y a pas d'initiale : le point isolé
     * d'un `?. Romarin` en dirait autant qu'une absence tout en étant plus laid.
     *
     * Ce libellé est ce qu'un porteur de jeton d'invitation obtient de plus intime sur
     * une personne vivante de l'arbre. C'est délibérément juste assez pour se reconnaître
     * soi-même, et pas assez pour documenter quelqu'un d'autre.
     */
    public function libelleMinimal(): string
    {
        $initiale = $this->initiale();
        $libelle = '' !== $initiale ? $initiale.'. '.$this->nom : $this->nom;

        return null !== $this->anneeNaissance ? $libelle.', '.$this->anneeNaissance : $libelle;
    }

    /** La première lettre du premier prénom, en majuscule. Chaîne vide si aucun prénom exploitable. */
    private function initiale(): string
    {
        $premier = trim(explode(' ', trim($this->prenoms))[0]);

        // `graphe.py` remplace un prénom manquant par « ? » pour que la fiche mesure
        // quand même un caractère ; ici un « ? » ne serait qu'un bruit dans un libellé.
        if ('' === $premier || '?' === $premier) {
            return '';
        }

        return mb_strtoupper(mb_substr($premier, 0, 1));
    }
}
