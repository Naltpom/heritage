<?php

declare(strict_types=1);

namespace App\Module\Tree\Enum;

/**
 * Le `QUAY` du GEDCOM : le niveau de confiance d'une citation (§ 3.9 du modèle).
 *
 * **Adossée à `int` et non à `string`**, contrairement à toutes les autres énumérations
 * du projet : la colonne `tree_citation.quay` est un `SMALLINT` qui porte les valeurs
 * 0 à 3 de la norme GEDCOM, et les réécrire en texte casserait l'aller-retour avec le
 * fichier comme la contrainte `ck_tree_citation_quay`. Le § 2.7 du modèle pose la règle
 * générale ; celle-ci en est l'exception assumée, signalée dans le livrable.
 *
 * Les libellés sont ceux déjà retenus dans `graphe.json`, pour que l'interface ne change
 * pas de vocabulaire en route.
 *
 * `PreuveDirecte` n'a **aucune occurrence** dans l'arbre aujourd'hui, et c'est un fait
 * significatif plutôt qu'une lacune : après la requalification décrite dans le
 * `README.md` du chantier, plus rien n'y repose sur une preuve primaire.
 */
enum CitationQuality: int
{
    case RecopieNonVerifiee = 0;
    case IndexOuDeduction = 1;
    case ActeOuRegistre = 2;
    case PreuveDirecte = 3;

    public function label(): string
    {
        return match ($this) {
            self::RecopieNonVerifiee => 'recopie non vérifiée',
            self::IndexOuDeduction => 'index ou déduction',
            self::ActeOuRegistre => 'acte ou registre',
            self::PreuveDirecte => 'preuve directe',
        };
    }

    /** Une citation « appuyée sur un acte » au sens du décompte des 84 fiches (§ 1). */
    public function estAppuyeeSurUnActe(): bool
    {
        return $this->value >= self::ActeOuRegistre->value;
    }
}
