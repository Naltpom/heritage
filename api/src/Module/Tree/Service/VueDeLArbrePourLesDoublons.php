<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Contract\ArbrePourLesDoublons;
use App\Module\Proposal\Contract\PersonneCandidate;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Serializer\CercleDuLecteur;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\FabriqueDuCercle;
use App\Module\Tree\Serializer\FabriqueDuLecteur;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\NormaliseurDeDate;
use App\Module\Tree\Serializer\NormaliseurDeRequete;

/**
 * Les personnes de l'arbre qu'une création pourrait doubler — **le filtre de la
 * recherche**, appliqué à une seconde porte de sortie.
 *
 * Le rapprochement des doublons est une recherche : il rend des personnes à partir d'un
 * nom tapé. Il en hérite donc les deux règles, prises au même endroit que
 * {@see \App\Module\Tree\Serializer\NormaliseurDeRecherche} :
 *
 * 1. **absente, pas réduite** : une fiche que le cercle ne montre pas — depuis la
 *    révision du 2026-10-01 de l'ADR 0003, une fiche confidentielle hors du cercle — ne
 *    sort pas, sous aucune forme. Une ligne « une personne correspond, mais vous ne la
 *    voyez pas » serait l'oracle même que la recherche s'interdit ;
 * 2. **la naissance réduite** : pour un vivant, le rapprochement ne compare que ce que la
 *    réciprocité accorde (ADR 0016). La date servie et la date comparée sont la même.
 *
 * Le plafond est appliqué sur le jeu **déjà filtré**, et le sur-échantillonnage est celui
 * de {@see LectureDeLArbre::candidatsDeRecherche()}.
 */
final readonly class VueDeLArbrePourLesDoublons implements ArbrePourLesDoublons
{
    public function __construct(
        private FabriqueDuLecteur $lecteurs,
        private FabriqueDuCercle $cercles,
        private EtatVivant $vivants,
        private LectureDeLArbre $lecture,
        private NormaliseurDeRequete $requetes,
        private NormaliseurDeDate $dates,
    ) {
    }

    public function candidats(string $terme, int $limite): array
    {
        $mots = $this->requetes->mots($terme);

        if ([] === $mots || $limite < 1) {
            return [];
        }

        $cercle = $this->cercles->pour($this->lecteurs->courant());
        $candidats = [];

        foreach ($this->lecture->candidatsDeRecherche($mots, $limite) as $personne) {
            $id = $personne->getId();

            if (null === $id || !$cercle->voit($id, $this->vivants->estVivante($personne))) {
                // **Absente**, et non réduite — la règle de la recherche (ADR 0003).
                continue;
            }

            if (\count($candidats) >= $limite) {
                break;
            }

            $candidats[] = $this->candidate($personne, $id, $cercle);
        }

        return $candidats;
    }

    private function candidate(Person $personne, int $id, CercleDuLecteur $cercle): PersonneCandidate
    {
        $vivante = $this->vivants->estVivante($personne);
        $permises = $cercle->naissance($id, $vivante);
        $naissance = $personne->getBirth();

        // `reduite()` rend `null` quand l'année n'est pas partagée : il ne reste alors rien
        // de comparable, et la date vaut « inconnue » pour le rapprochement — le lieu, qui
        // n'est pas concerné par la réciprocité, est gardé.
        $comparee = $naissance->reduite($permises) ?? UncertainDate::inconnue($naissance->getPlace());

        $filiations = $this->lecture->filiationsDe($personne);
        $unions = $this->lecture->unionsDe($personne);

        return new PersonneCandidate(
            personId: $id,
            displayName: $personne->getDisplayName(),
            givenNames: $personne->getGivenNames(),
            surname: $personne->getSurname(),
            marriedName: $personne->getMarriedName(),
            nickname: $personne->getNickname(),
            sex: $personne->getSex()->value,
            deceased: $personne->isDeceased(),
            vivant: $vivante,
            naissance: $comparee,
            deces: $personne->getDeath(),
            blocNaissance: $this->dates->naissancePourFiche($naissance, $permises),
            blocDeces: $this->dates->pourFiche($personne->getDeath()),
            ligneDates: $this->dates->ligneDeFiche($naissance, $personne->getDeath(), $permises),
            parents: $this->parents($filiations, $personne, $cercle),
            conjoints: $this->conjoints($unions, $personne, $cercle),
            enfants: $this->enfants($unions, $cercle),
        );
    }

    /**
     * @param list<Child> $filiations
     *
     * @return list<array{personId: int, displayName: string}>
     */
    private function parents(array $filiations, Person $personne, CercleDuLecteur $cercle): array
    {
        $sortie = [];

        foreach ($filiations as $filiation) {
            $famille = $filiation->getFamily();

            foreach ([$famille->getHusband(), $famille->getWife()] as $parent) {
                if (null !== $parent && $parent !== $personne) {
                    $this->ajouter($sortie, $parent, $cercle);
                }
            }
        }

        return array_values($sortie);
    }

    /**
     * @param list<Family> $unions
     *
     * @return list<array{personId: int, displayName: string}>
     */
    private function conjoints(array $unions, Person $personne, CercleDuLecteur $cercle): array
    {
        $sortie = [];

        foreach ($unions as $famille) {
            $conjoint = $famille->getHusband() === $personne ? $famille->getWife() : $famille->getHusband();

            if (null !== $conjoint) {
                $this->ajouter($sortie, $conjoint, $cercle);
            }
        }

        return array_values($sortie);
    }

    /**
     * @param list<Family> $unions
     *
     * @return list<array{personId: int, displayName: string}>
     */
    private function enfants(array $unions, CercleDuLecteur $cercle): array
    {
        $sortie = [];

        foreach ($this->lecture->enfantsDe($unions) as $lignes) {
            foreach ($lignes as $ligne) {
                $this->ajouter($sortie, $ligne->getPerson(), $cercle);
            }
        }

        return array_values($sortie);
    }

    /**
     * Une référence courte, **dont le nom suit le cercle** — même règle que les proches
     * d'une fiche (`NormaliseurDePersonne::reference()`) : la personne reste citée, sous
     * le masque si le cercle ne la montre pas. Dédoublonnée par identifiant : un enfant
     * de deux unions du même couple ne se compte pas deux fois.
     *
     * @param array<int, array{personId: int, displayName: string}> $sortie
     */
    private function ajouter(array &$sortie, Person $proche, CercleDuLecteur $cercle): void
    {
        $id = $proche->getId();

        // Hors périmètre (révision du 2026-10-04 de l'ADR 0003), le proche n'existe pas
        // pour le lecteur : ni nom, ni masque — absent.
        if (null === $id || isset($sortie[$id]) || !$cercle->connait($id)) {
            return;
        }

        $sortie[$id] = [
            'personId' => $id,
            'displayName' => $cercle->voit($id, $this->vivants->estVivante($proche))
                ? $proche->getDisplayName()
                : CercleDeVisibiliteInterface::NOM_MASQUE,
        ];
    }
}
