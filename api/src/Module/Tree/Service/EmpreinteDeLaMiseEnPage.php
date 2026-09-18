<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

/**
 * « Quelle image ce point de vue reçoit-il ? », en une chaîne — et rien d'autre.
 *
 * Ce port existe pour que {@see \App\Module\Tree\Serializer\EtagDuGraphe} puisse porter
 * la mise en page dans sa clé **sans dépendre de {@see PlacementPartage}**, qui traîne
 * derrière lui le cache, Doctrine et le chargement de l'arbre. `EtagDuGraphe` est une
 * règle de clé : elle se relit et s'éprouve sur des doubles de six lignes, et c'est cette
 * propriété-là qu'on protège ici — la même raison qui a fait exister
 * {@see FournisseurDeJeuDePlacement} pour le moteur.
 *
 * L'implémentation de production est {@see PlacementPartage} : elle a déjà le placement
 * en cache, donc la réponse ne coûte qu'une lecture d'entrée et un haché.
 */
interface EmpreinteDeLaMiseEnPage
{
    /**
     * @param int|null $egoPersonneId la personne depuis laquelle ce lecteur regarde,
     *                                `null` pour la racine de l'arbre
     */
    public function empreinteDeLaMiseEnPage(?int $egoPersonneId): string;
}
