<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Relit l'arbre, recalcule `branch`, `generation` et `rank`, et les repose en base.
 *
 * C'est le « service de `Tree` » que le § 3.1 du modèle désigne : ces colonnes sont
 * dérivées de `tree.personne_racine_id`, et elles doivent être **recalculées après toute
 * écriture structurante** — une personne créée, une famille nouée, un enfant rattaché,
 * un changement de racine, le chargement de l'arbre d'essai.
 *
 * ## Deux choses à savoir avant d'y toucher
 *
 * **1. On n'écrit que ce qui a changé, et ce n'est pas une optimisation.** `Person` et
 * `Family` portent un `#[ORM\Version]` : réécrire la même valeur sur 274 lignes
 * incrémenterait 274 versions, ce qui rendrait caduques toutes les demandes en attente
 * qui visaient ces fiches — leur `base_version` ne correspondrait plus. On s'appuie donc
 * sur le suivi des changements de Doctrine, qui n'émet un `UPDATE` que si la valeur
 * diffère réellement. Poser `setGeneration(-2)` sur une ligne déjà à −2 ne produit rien.
 * Une personne dont la génération a *vraiment* bougé, elle, doit bien voir sa version
 * changer : c'est une modification de sa fiche.
 *
 * **2. Sans racine, on ne pose rien.** Tant que `tree.personne_racine_id` vaut 0 — l'état
 * que laisse la migration tant qu'aucune racine n'a été choisie —, « branche paternelle du père » ne
 * désigne rien. Les colonnes restent `NULL`, ce qu'elles savent faire, plutôt que de
 * recevoir une valeur tirée d'une racine arbitraire.
 */
final class RecalculDesColonnesDerivees
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FournisseurDeJeuDePlacement $jeux,
        private readonly CalculAscendance $ascendance,
    ) {
    }

    /**
     * Recalcule et repose les colonnes dérivées de tout l'arbre.
     *
     * **Ne `flush()` pas** : l'appelant est dans une transaction dont il décide seul les
     * bornes — c'est la règle du port de `Proposal`, et les fixtures s'y conforment aussi. Les
     * entités sont modifiées, il reste à les envoyer.
     *
     * @return int le nombre de lignes dont une colonne dérivée a réellement changé
     */
    public function recalculer(): int
    {
        $jeu = $this->jeux->jeuComplet();

        if ('' === $jeu->egoId) {
            return 0;
        }

        $placement = $this->ascendance->pourLeJeu($jeu);
        $touchees = 0;

        foreach ($this->personnesParId() as $id => $personne) {
            $noeud = IdentifiantDeNoeud::personne($id);
            $generation = $placement->generations[$noeud] ?? null;
            $branche = $placement->branches[$noeud] ?? null;

            if ($personne->getGeneration() === $generation && $personne->getBranch() === $branche) {
                continue;
            }

            $personne->setGeneration($generation)->setBranch($branche)->toucher();
            ++$touchees;
        }

        foreach ($this->famillesParId() as $id => $famille) {
            $noeud = IdentifiantDeNoeud::famille($id);
            $generation = $placement->generationsFamille[$noeud] ?? null;
            $rang = $placement->rangs[$noeud] ?? null;

            if ($famille->getGeneration() === $generation && $famille->getRank() === $rang) {
                continue;
            }

            $famille->setGeneration($generation)->setRank($rang)->toucher();
            ++$touchees;
        }

        return $touchees;
    }

    /**
     * @return array<int, Person>
     */
    private function personnesParId(): array
    {
        /** @var array<int, Person> $personnes */
        $personnes = $this->em
            ->createQuery(\sprintf('SELECT p FROM %s p INDEX BY p.id', Person::class))
            ->getResult();

        return $personnes;
    }

    /**
     * @return array<int, Family>
     */
    private function famillesParId(): array
    {
        /** @var array<int, Family> $familles */
        $familles = $this->em
            ->createQuery(\sprintf('SELECT f FROM %s f INDEX BY f.id', Family::class))
            ->getResult();

        return $familles;
    }
}
