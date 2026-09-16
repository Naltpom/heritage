<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Tree\Service\MesureDeFiche;
use App\Module\Tree\ValueObject\PersonnePlacable;

/**
 * `etiquetteNom` — **le nom que le moteur a mesuré**, et donc le seul que le canevas ait
 * le droit de dessiner : `(nickname ?? givenNames)` réduit à son **premier mot**, puis
 * `surname`. « Marne Sureau », et non « Marne Dolomite Sureau ».
 *
 * ## Pourquoi ce champ existe, en un chiffre
 *
 * `displayName` porte **tous** les prénoms. Un canevas qui le dessine dans une boîte
 * dimensionnée sur le premier seul fait déborder **220 fiches sur 274**, jusqu'à 169
 * unités de trop sur une boîte de 149. C'est la même famille de défaut que la largeur
 * mesurée sur une chaîne et le texte servi dans une autre : rien ne tombe, et ça se voit
 * à l'œil une fois en production.
 *
 * ## Et pourquoi le client ne peut pas le dériver
 *
 * Parce que **« de la Tour » n'est pas découpable par une règle**. Un nom de famille
 * contient des espaces, `displayName` les concatène, et aucun découpage côté client ne
 * retrouve la frontière. Trois clés, trois usages : `displayName` pour **lire**,
 * `etiquetteNom` pour **dessiner**, `surname` / `givenNames` pour **éditer**.
 *
 * ## Une classe qui ne fait plus que déléguer — et pourquoi elle reste
 *
 * Elle a **composé le nom elle-même** pendant un temps, en recopiant deux lignes privées
 * de `MesureDeFiche::largeur()` : la méthode publique que le § 5.2 désignait n'existait
 * pas encore, et `Tree\Service` porte un portage validé à 377/377 nœuds exacts que cette
 * tâche n'avait pas le droit de modifier. La duplication était alors tenue par un
 * invariant plutôt que par une promesse.
 *
 * `MesureDeFiche::etiquetteNom()` existe désormais, et c'est elle qui compose. **Il n'y a
 * donc plus qu'une seule définition du nom mesuré dans le produit**, ce qui est la seule
 * fin acceptable pour ce genre de duplication.
 *
 * Ce qui reste ici est le point de passage nommé entre le vocabulaire du moteur et une
 * clé de l'API : `MesureDeFiche` mesure des fiches, `etiquetteNom` est une clé de charge
 * utile, et c'est le rôle du normaliseur de faire correspondre les deux. C'est la même
 * raison qui fait qu'on ne renomme pas `ligneDates()` en `etiquetteDates()` là-bas : un
 * nom de méthode interne n'a pas à suivre un nom de clé d'API — d'autant que cette
 * méthode-là sert **deux** clés sous deux règles différentes.
 *
 * ## L'invariant reste, et il n'est pas devenu décoratif
 *
 * `RoutesDeLArbreTest::testLaLargeurDeChaqueNoeudSeRecalculeDepuisLesDeuxChainesEmises`
 * recalcule `w` à partir des deux chaînes **émises** et le compare au `w` du moteur, sur
 * chaque nœud. La délégation garantit le chemin ; l'invariant garantit le **résultat**,
 * et c'est lui qui attraperait un `w` mesuré sur une chaîne et un texte servi dans une
 * autre — le défaut qui est revenu trois fois dans ce projet, sous trois formes.
 */
final class EtiquetteDuNom
{
    public function __construct(private readonly MesureDeFiche $mesure = new MesureDeFiche())
    {
    }

    public function pour(PersonnePlacable $personne): string
    {
        return $this->mesure->etiquetteNom($personne);
    }
}
