<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Core\Security\CercleDeVisibiliteInterface;

/**
 * Obtient le {@see CercleDuLecteur} de la requête en cours — **le seul endroit de `Tree`
 * qui sache d'où vient un cercle**.
 *
 * Elle ne calcule rien. La formule du § 6.2 du dossier de mission appartient à
 * `Membership`, elle est consultée par le contrat unique du noyau, et **il ne doit jamais
 * en apparaître une seconde implémentation** : une règle de confidentialité ne se trompe
 * qu'une fois pour fuir. Les deux pièges de cette formule — les conjoints ajoutés en
 * **une seule passe**, qui ne propagent rien, et le régime `Aucun` qui n'est **pas** un
 * cercle avec `N = 0` — sont exactement ceux qu'une réécriture retomberait.
 *
 * ## Pourquoi `$lecteur` n'est pas transmis
 *
 * `CercleDeVisibiliteInterface` ne prend **aucun** lecteur en paramètre : l'implémentation
 * le résout elle-même, session et bascule « voir comme un membre » comprises. C'est un
 * choix du contrat, et son motif tient en une phrase : *un lecteur passé par chaque
 * appelant est un lecteur qu'un appelant finira par construire sans la bascule*, et une
 * bascule qu'on oublie de rejouer sur un appel ne vérifie plus rien.
 *
 * Le paramètre reste dans la signature parce que les appelants de `Tree` l'ont sous la
 * main et que la mémorisation par identité effective a du sens ici — pas parce que le
 * cercle en dépendrait.
 *
 * ## Le défaut est fermé, et il ne faut pas l'« arranger »
 *
 * Tant qu'aucun adaptateur n'est branché, le noyau rend `Core\Security\CercleFerme`, qui
 * refuse tout : **aucune personne vivante n'est visible de personne**. Une application
 * familiale qui échoue ouverte publie des dates de naissance de personnes vivantes, sans
 * message d'erreur et sans que rien ne tombe ; une application qui échoue fermée affiche
 * des fiches muettes, visible dans l'heure. Les deux pannes n'ont pas le même prix, et
 * une seule des deux se répare après coup.
 */
final class FabriqueDuCercle
{
    /** @var array<string, CercleDuLecteur> mémorisé pour la durée de la requête */
    private array $memoire = [];

    public function __construct(private readonly CercleDeVisibiliteInterface $cercle)
    {
    }

    public function pour(LecteurDeLArbre $lecteur): CercleDuLecteur
    {
        return $this->memoire[$lecteur->cle()] ??= CercleDuLecteur::delegue($this->cercle);
    }
}
