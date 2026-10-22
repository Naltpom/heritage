<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\FicheDeLArbre;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Assistant\Contract\UnionDeLArbre;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\NormaliseurDeRequete;

/**
 * L'arbre **entier**, tel que l'assistant de proposition le parcourt.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  AUCUN FILTRE DE CERCLE ICI, ET C'EST LA DÉCISION DU COMMANDITAIRE.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Le modèle doit pouvoir reconnaître n'importe quelle personne citée par un document —
 * une grand-tante vivante hors du cercle du membre comprise — sans quoi il proposerait
 * de la créer une seconde fois. La confidentialité est tenue **à la sortie**, par
 * l'assistant, qui confronte `vivante` au cercle du lecteur avant de rendre quoi que ce
 * soit au navigateur (lire l'en-tête de `Assistant\Contract\ArbrePourLAssistant`).
 *
 * C'est la seule porte de `Tree` qui rende des vivants hors cercle à un appelant piloté
 * par un membre. Elle ne rend **aucun** drapeau de visibilité : ce fichier ne sait pas
 * qui lit, et n'a pas à le savoir.
 *
 * ── Ce qu'elle réutilise, et pourquoi ce n'est pas une seconde recherche ─────────
 *
 * La recherche est celle de `GET /api/arbre/recherche` — mêmes mots, même clé, même
 * conjonction ({@see LectureDeLArbre::candidatsDeRecherche()}) — sans le filtre de
 * `NormaliseurDeRecherche`. Et `vivante` est la règle du graphe ({@see EtatVivant}),
 * pas le drapeau `deceased` : un décès inconnu vaut vivant, et c'est cette règle-là que
 * l'assistant appliquera au moment de masquer.
 */
final readonly class VueDeLArbrePourLAssistant implements ArbrePourLAssistant
{
    public function __construct(
        private LectureDeLArbre $lecture,
        private NormaliseurDeRequete $requetes,
        private EtatVivant $vivants,
        private NaissanceDuLecteur $naissances,
    ) {
    }

    public function chercher(string $requete, int $limite): array
    {
        $mots = $this->requetes->mots($requete);

        if ([] === $mots || $limite < 1) {
            return [];
        }

        $trouvees = [];

        foreach ($this->lecture->candidatsDeRecherche($mots, $limite) as $personne) {
            if (\count($trouvees) >= $limite) {
                break;
            }

            $trouvees[] = $this->avecSaParente($personne);
        }

        return $trouvees;
    }

    public function personne(int $personneId): ?PersonneDeLArbre
    {
        $personne = $this->lecture->personne($personneId);

        return null === $personne ? null : $this->avecSaParente($personne);
    }

    public function fiche(int $personneId): ?FicheDeLArbre
    {
        $personne = $this->lecture->personne($personneId);

        if (null === $personne) {
            return null;
        }

        $filiations = $this->lecture->filiationsDe($personne);
        $famillesDOrigine = array_values(array_map(static fn (Child $c): Family => $c->getFamily(), $filiations));

        $parents = [];
        foreach ($famillesDOrigine as $famille) {
            foreach ([$famille->getHusband(), $famille->getWife()] as $parent) {
                if (null !== $parent) {
                    $parents[] = $this->seule($parent);
                }
            }
        }

        $unions = $this->lecture->unionsDe($personne);
        $enfantsParFamille = $this->lecture->enfantsDe($unions);

        $vues = [];
        foreach ($unions as $union) {
            $familleId = $union->getId();

            if (null === $familleId) {
                continue;
            }

            $conjoint = $union->getHusband() === $personne ? $union->getWife() : $union->getHusband();

            $vues[] = new UnionDeLArbre(
                $familleId,
                null === $conjoint ? null : $this->seule($conjoint),
                $union->getMarriage(),
                $union->isDivorced(),
                array_map(fn (Child $c): PersonneDeLArbre => $this->seule($c->getPerson()), $enfantsParFamille[$familleId] ?? []),
                marie: $union->isMarried(),
            );
        }

        $fratrie = [];
        $dejaVus = [];
        foreach ($this->lecture->fratrieDe($personne, $famillesDOrigine) as $ligne) {
            $frere = $ligne->getPerson();
            $id = $frere->getId();

            // Un demi-frère présent dans deux familles d'origine n'est cité qu'une fois.
            if (null === $id || isset($dejaVus[$id])) {
                continue;
            }

            $dejaVus[$id] = true;
            $fratrie[] = $this->seule($frere);
        }

        return new FicheDeLArbre(
            $this->avecSaParente($personne),
            $personne->getVersion(),
            $personne->getGivenNames(),
            $personne->getSurname(),
            $personne->getMarriedName(),
            $personne->getNickname(),
            $personne->isDeceased(),
            $parents,
            $vues,
            $fratrie,
        );
    }

    /**
     * La personne avec ses parents et conjoints : leurs noms départagent deux « Jean
     * Dupont » dans une liste de résultats.
     *
     * Des personnes et non des noms : ce fichier ne sait pas qui lit, et c'est l'assistant
     * qui décide, d'après `vivante` et le cercle du lecteur, si un parent ou un conjoint
     * est confidentiel — un conjoint vivant hors cercle doit l'être aussi bien que la
     * personne trouvée.
     */
    private function avecSaParente(Person $personne): PersonneDeLArbre
    {
        $parents = [];
        foreach ($this->lecture->filiationsDe($personne) as $filiation) {
            $famille = $filiation->getFamily();

            foreach ([$famille->getHusband(), $famille->getWife()] as $parent) {
                if (null !== $parent) {
                    $parents[] = $this->seule($parent);
                }
            }
        }

        $conjoints = [];
        foreach ($this->lecture->unionsDe($personne) as $union) {
            $conjoint = $union->getHusband() === $personne ? $union->getWife() : $union->getHusband();

            if (null !== $conjoint) {
                $conjoints[] = $this->seule($conjoint);
            }
        }

        return $this->seule($personne, $parents, $conjoints);
    }

    /**
     * @param list<PersonneDeLArbre> $parents
     * @param list<PersonneDeLArbre> $conjoints
     */
    private function seule(Person $personne, array $parents = [], array $conjoints = []): PersonneDeLArbre
    {
        return new PersonneDeLArbre(
            (int) $personne->getId(),
            $personne->getDisplayName(),
            $personne->getSex(),
            $vivante = $this->vivants->estVivante($personne),
            // ADR 0016 : la naissance d'un vivant passe par la réciprocité.
            $this->naissances->pour($personne, $vivante),
            $personne->getDeath(),
            $parents,
            $conjoints,
        );
    }
}
