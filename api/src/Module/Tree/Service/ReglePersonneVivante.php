<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\Entity\Person;

/**
 * « Cette personne est-elle vivante ? » — la règle du § 9.2 du modèle de données.
 *
 * **C'est une propriété calculée, jamais une colonne.** Il n'existe aucune colonne
 * `is_living` : ce serait stocker le résultat d'un calcul dont **toutes les entrées
 * bougent** — la date du jour avance, le seuil est un réglage, un décès enregistré change
 * la réponse. Une colonne demanderait une tâche nocturne de recalcul, et le jour où cette
 * tâche échoue en silence, **une fiche de personne vivante devient publique**. C'est le
 * mode de défaillance qu'on ne peut pas se permettre ici.
 *
 * ```
 * borne(p)     = année(p.birth_max)
 *                sinon, si inconnue : médiane des années de naissance de sa génération
 * estVivant(p) = p.force_privacy
 *              OR ( NOT p.deceased
 *                   AND borne(p) is not null
 *                   AND (annéeCourante − borne(p)) <= seuil )
 * ```
 *
 * ## Le repli par génération n'est pas un raffinement
 *
 * Sans lui, la règle naïve « pas de décès + né il y a moins de N ans » produit
 * **27 faux positifs** : les personnes sans aucune date de naissance ni `DEAT`,
 * c'est-à-dire des ancêtres du XVIII<sup>e</sup> siècle que la règle déclarerait vivants
 * parce qu'elle ne sait rien d'eux. Masquer 27 fiches d'ancêtres n'est pas « prudent » :
 * c'est rendre l'arbre inutilisable et entraîner les utilisateurs à ignorer la
 * signalétique de confidentialité.
 *
 * Vérifié sur les 274 personnes réelles : avec le repli et un seuil de 110, la règle
 * reproduit **exactement** les 18 `RESN privacy` du GEDCOM — zéro manque, zéro faux
 * positif. Sans repli, 45 personnes seraient masquées.
 *
 * ## Une seule échappatoire, dans un seul sens
 *
 * `force_privacy` permet de dire « masque-la de toute façon ». **Il n'existe
 * volontairement aucun moyen de forcer dans l'autre sens** : pas de `force_public`. La
 * seule façon de déclarer quelqu'un décédé est d'enregistrer son décès. Un dérogateur qui
 * démasque est un dérogateur qui, un jour, démasquera par erreur ; un dérogateur qui
 * masque ne peut se tromper que dans le sens prudent.
 *
 * Service **sans état et sans accès à la base** : le seuil et la date lui sont donnés.
 * C'est ce qui permet de le tester sans base et de le faire rejouer à une date fixe.
 */
final class ReglePersonneVivante
{
    public const int SEUIL_DEFAUT = 110;

    public function __construct(private readonly EtiquetteDeDateGedcom $dates = new EtiquetteDeDateGedcom())
    {
    }

    /**
     * Évalue toute la population d'un coup : les médianes par génération sont un calcul
     * global, les refaire par personne serait quadratique.
     *
     * @param list<Person> $population l'arbre validé complet, sans filtre
     *
     * @return array<int, bool> indexé par identifiant de personne
     */
    public function pourTous(array $population, int $seuil, \DateTimeImmutable $maintenant): array
    {
        $medianes = $this->medianesParGeneration($population);
        $anneeCourante = (int) $maintenant->format('Y');

        $vivants = [];

        foreach ($population as $personne) {
            $id = $personne->getId();

            if (null === $id) {
                continue;
            }

            $vivants[$id] = $this->estVivante($personne, $medianes, $seuil, $anneeCourante);
        }

        return $vivants;
    }

    /** @param array<int, int> $medianes */
    public function estVivante(Person $personne, array $medianes, int $seuil, int $anneeCourante): bool
    {
        if ($personne->isForcePrivacy()) {
            return true;
        }

        if ($personne->isDeceased()) {
            return false;
        }

        $borne = $this->borne($personne, $medianes);

        return null !== $borne && ($anneeCourante - $borne) <= $seuil;
    }

    /**
     * La médiane des années de naissance de chaque génération.
     *
     * **Médiane au sens strict** — moyenne des deux valeurs centrales quand l'effectif
     * est pair, tronquée à l'entier. Ce n'est pas un détail de goût : la variante « valeur
     * basse » ne retombe que sur 10 des 12 médianes publiées au § 9.2 du modèle de
     * données, et un test verrouille les douze.
     *
     * Calculée depuis la population déjà en mémoire plutôt que par un `GROUP BY` : le
     * fournisseur vient de charger les 274 personnes, une seconde requête ne dirait rien
     * de plus. Ce qui compte au § 9.2 est qu'elles ne soient **pas des constantes en
     * dur**, et elles ne le sont pas.
     *
     * @param list<Person> $population
     *
     * @return array<int, int>
     */
    public function medianesParGeneration(array $population): array
    {
        $annees = [];

        foreach ($population as $personne) {
            $generation = $personne->getGeneration();
            $annee = $this->anneeDeNaissanceHaute($personne);

            if (null !== $generation && null !== $annee) {
                $annees[$generation][] = $annee;
            }
        }

        $medianes = [];

        foreach ($annees as $generation => $liste) {
            sort($liste, \SORT_NUMERIC);
            $n = \count($liste);
            $milieu = intdiv($n, 2);

            $medianes[$generation] = 0 === $n % 2
                ? (int) (($liste[$milieu - 1] + $liste[$milieu]) / 2)
                : $liste[$milieu];
        }

        return $medianes;
    }

    /**
     * `année(p.birth_max)` — la **borne haute** de la naissance, et non `birth_min` : on
     * demande « au plus tôt, quand cette personne aurait-elle 110 ans ? », donc on prend
     * la date de naissance la plus tardive que la donnée autorise.
     *
     * @param array<int, int> $medianes
     */
    private function borne(Person $personne, array $medianes): ?int
    {
        $annee = $this->anneeDeNaissanceHaute($personne);

        if (null !== $annee) {
            return $annee;
        }

        $generation = $personne->getGeneration();

        return null === $generation ? null : ($medianes[$generation] ?? null);
    }

    /**
     * `année(p.birth_max)`, avec le dernier millésime de la chaîne GEDCOM en repli.
     *
     * L'ordre des deux sources suit le § 9.2 à la lettre : la **borne analysée** d'abord,
     * le brut seulement quand elle manque — c'est-à-dire pour une naissance non bornée à
     * droite (`AFT 1850`). Sur les 274 personnes de référence, les deux sources
     * coïncident : aucune naissance n'est `BEF` ni `AFT`, les six cas du fichier portent
     * tous sur des décès. Le choix est donc sans effet observable aujourd'hui, et il est
     * écrit ici pour qu'il reste délibéré quand une fiche `AFT` apparaîtra.
     */
    private function anneeDeNaissanceHaute(Person $personne): ?int
    {
        $naissance = $personne->getBirth();
        $max = $naissance->getMax();

        return null !== $max
            ? (int) $max->format('Y')
            : $this->dates->anneeMax($naissance->getRaw());
    }
}
