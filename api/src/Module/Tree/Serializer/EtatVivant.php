<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Tree\Entity\Person;
use App\Module\Tree\Repository\PersonRepository;
use App\Module\Tree\Service\ReglagesDeLArbre;
use App\Module\Tree\Service\ReglePersonneVivante;

/**
 * « Cette personne est-elle vivante ? », posée **une personne à la fois** par la
 * sérialisation.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  IL N'Y A QU'UNE SEULE DÉFINITION DE « VIVANT » DANS LE PRODUIT, ET ELLE N'EST
 *  PAS ICI : C'EST {@see ReglePersonneVivante}, DANS `Tree\Service`.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Cette classe ne décide rien. Elle **adapte** : la règle raisonne sur une population
 * entière, parce que le repli du § 9.2 passe par la médiane des années de naissance de
 * chaque génération — un calcul global, quadratique si on le refait par personne. Le
 * moteur de placement, lui, évalue les 274 personnes d'un coup et n'a besoin de rien
 * d'autre. Une fiche, non : elle en demande une.
 *
 * **Pourquoi ne pas écrire la règle deux fois, une par forme d'appel ?** Parce que les
 * deux entreraient inévitablement en divergence, et que la divergence serait invisible :
 * l'état vivant décide de ce que le cercle masque **et** de la largeur des fiches du
 * graphe, via la constante « vivant·e » de
 * {@see \App\Module\Tree\Service\MesureDeFiche::ligneDates()}. Deux règles qui ne
 * s'accordent plus, c'est une étiquette « vivant·e » dans une boîte dimensionnée pour
 * une date — ou, du mauvais côté, une date envoyée à qui n'y a pas droit.
 *
 * ## Le coût, et pourquoi il est acceptable
 *
 * Servir une fiche charge les 274 lignes de `tree_person` pour calculer douze médianes.
 * C'est beaucoup pour une fiche et négligeable en valeur absolue — l'arbre fait 274
 * personnes, le § 3 du dossier de mission écarte explicitement toute dépendance qui ne
 * sert pas à cette échelle. Le résultat est mémorisé pour la durée de la requête, donc
 * une liste de recherche ou un graphe ne le paie qu'une fois.
 */
final class EtatVivant
{
    /** @var array<int, int>|null les médianes par génération, pour la durée de la requête */
    private ?array $medianes = null;

    private ?int $seuil = null;

    private ?int $anneeCourante = null;

    public function __construct(
        private readonly ReglePersonneVivante $regle,
        private readonly ReglagesDeLArbre $reglages,
        private readonly PersonRepository $personnes,
    ) {
    }

    public function estVivante(Person $personne): bool
    {
        $this->preparer();

        \assert(null !== $this->medianes && null !== $this->seuil && null !== $this->anneeCourante);

        return $this->regle->estVivante($personne, $this->medianes, $this->seuil, $this->anneeCourante);
    }

    private function preparer(): void
    {
        if (null !== $this->medianes) {
            return;
        }

        /** @var list<Person> $population */
        $population = $this->personnes->createQueryBuilder('p')
            ->orderBy('p.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $this->medianes = $this->regle->medianesParGeneration($population);
        $this->seuil = $this->reglages->seuilVivantAnnees();
        // L'année est figée pour la requête : deux fiches de la même réponse ne doivent
        // pas pouvoir tomber de part et d'autre d'un passage à minuit le 31 décembre.
        $this->anneeCourante = (int) (new \DateTimeImmutable())->format('Y');
    }
}
