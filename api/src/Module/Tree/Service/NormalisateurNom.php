<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Service\CleDeRecherche;

/**
 * Fabrique les deux colonnes dénormalisées de `tree_person` : `display_name` et
 * `search_key` (§ 3.1 du modèle de données).
 *
 * **Ce service appartient à `Tree` parce que ces deux colonnes lui appartiennent.**
 * Trois écrivains les posent — l'import GEDCOM, la validation d'une proposition, une
 * correction d'administrateur — et si chacun composait le nom d'affichage à sa façon, une
 * personne créée dans l'application ne ressemblerait pas à ses 274 voisines.
 *
 * **Le repliement des accents, lui, n'est pas ici** : il est dans
 * {@see CleDeRecherche}, que ce service et `Tree\Serializer\NormaliseurDeRequete`
 * appellent tous les deux. C'est le point important — celui qui **écrit** `search_key` et
 * celui qui la **cherche** doivent replier de la même façon, et la seule garantie
 * solide est qu'il n'existe qu'un repliement.
 */
final class NormalisateurNom
{
    public function __construct(private readonly CleDeRecherche $cles)
    {
    }

    /**
     * Le nom d'affichage : `nickname ?? given_names`, puis `surname`, puis `?` si les deux
     * sont vides (§ 3.1 du modèle).
     *
     * Le `?` n'est pas un remplissage d'échec : les 4 individus sans `NAME` du fichier
     * sont des ancêtres réellement inconnus, chacun ouvrant une branche de recherche, et
     * `display_name` est `NOT NULL`.
     */
    public function composerAffichage(string $prenoms, string $nom, ?string $surnom): string
    {
        $tete = trim(($surnom ?? '') !== '' ? (string) $surnom : $prenoms);
        $affichage = trim($tete.' '.trim($nom));

        return '' === $affichage ? '?' : mb_substr($affichage, 0, 255, 'UTF-8');
    }

    /**
     * `surname || ' ' || given_names || ' ' || nickname || ' ' || married_name`, replié
     * par {@see CleDeRecherche}.
     *
     * **Le surnom et le nom d'usage sont des arguments et non des options**, et les trois
     * écrivains de la colonne doivent les passer : {@see EcritureDeLArbrePourProposal}
     * à la création et à la modification, et `Gedcom\Service\AnalyseurGedcom` à l'import.
     * En omettre un ferait diverger l'import de la validation — la divergence même que
     * {@see CleDeRecherche} existe pour rendre impossible, et qui ne se verrait pas :
     * juste une personne qu'on ne retrouve plus.
     */
    public function cleDeRecherche(
        string $prenoms,
        string $nom,
        ?string $surnom = null,
        ?string $nomDUsage = null,
    ): string {
        return $this->cles->cleDeNom($prenoms, $nom, $surnom, $nomDUsage);
    }
}
