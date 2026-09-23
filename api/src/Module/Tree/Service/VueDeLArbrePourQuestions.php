<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Question\Contract\FicheEtudiee;
use App\Module\Question\Contract\PortArbrePourQuestions;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'adaptateur de `Tree` pour le module `Question` (ADR 0008).
 *
 * Il traduit `tree_person`, `tree_family` et `tree_child` en la seule forme dont la
 * détection des lacunes a besoin : {@see FicheEtudiee}. Il **ne filtre rien** — ni par
 * le cercle, ni par l'état vivant —, et c'est écrit sur le contrat : le filtre du cercle
 * vit à un endroit unique, chez `Question`, et un flux partiel produirait des lacunes
 * *fantômes* (« cette personne n'a pas de mère » parce que la mère n'était pas dans le
 * flux).
 *
 * ## Trois lectures, jamais une par fiche
 *
 * `tree_person`, `tree_family`, `tree_child` — trois requêtes, puis un assemblage en
 * PHP sur 274 lignes. La forme naïve (une requête de parenté par personne) ferait 274
 * allers-retours pour produire la même chose, et la détection tourne **dans la requête
 * du lecteur**, à chaque ouverture de la page.
 *
 * Le résultat est mémorisé pour la durée de la requête : l'état vivant seul coûte le
 * chargement de toute la population (le repli du § 9.2 passe par la médiane des années
 * de naissance de chaque génération, qui est un calcul global), et on ne le paie qu'une
 * fois quoi qu'il arrive.
 *
 * ## `vivante` vient de la règle, jamais de la colonne
 *
 * {@see ReglePersonneVivante::pourTous()}, comme {@see VueDeLArbrePourAlmanach} et pour
 * la même raison : `tree_person.deceased` est une donnée d'import, et « un décès inconnu
 * vaut vivant, donc masqué ». Une seconde définition de « vivant » ici déciderait de ce
 * qui part chez un tiers — c'est le pire endroit du produit où en laisser diverger deux.
 *
 * ## La parenté se lit sur la famille, pas sur un parent gardien
 *
 * `pereId` et `mereId` viennent du couple qui porte l'enfant : `tree_child` mène à une
 * `tree_family`, dont on lit `husband` **et** `wife`, séparément et sans jointure
 * interne. Un `innerJoin` recopié d'un autre écran ferait disparaître en silence le
 * parent manquant — c'est-à-dire **exactement la lacune qu'on cherche**.
 *
 * Deux conséquences à connaître :
 *
 * - une fiche rattachée à **plusieurs** familles d'enfance (adoption, recueil) rend le
 *   premier père et la première mère trouvés. On ne demande pas « qui est l'autre
 *   mère ? » : la question serait juste et incompréhensible pour qui la lit ;
 * - `tree_child.relation` n'est **pas** consulté. Un parent adoptif est un parent pour
 *   cette détection : la lacune qu'on cherche est « cette fiche ne dit pas de qui elle
 *   descend », et une filiation adoptive y répond.
 */
final class VueDeLArbrePourQuestions implements PortArbrePourQuestions
{
    /** @var list<FicheEtudiee>|null mémorisé pour la durée de la requête */
    private ?array $memoire = null;

    /** @var array<int, string>|null les libellés, indexés — sous-produit du même chargement */
    private ?array $libelles = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglePersonneVivante $regleVivante,
        private readonly ReglagesDeLArbre $reglages,
    ) {
    }

    public function fiches(): array
    {
        return $this->memoire ??= $this->charger();
    }

    public function libelleDe(int $personneId): ?string
    {
        if (null === $this->libelles) {
            $this->fiches();
        }

        \assert(null !== $this->libelles);

        return $this->libelles[$personneId] ?? null;
    }

    /**
     * @return list<FicheEtudiee>
     */
    private function charger(): array
    {
        $personnes = $this->chargeLesPersonnes();

        $vivants = $this->regleVivante->pourTous(
            $personnes,
            $this->reglages->seuilVivantAnnees(),
            new \DateTimeImmutable(),
        );

        [$pere, $mere, $enfants] = $this->parente();
        $conjoints = $this->conjoints();

        $fiches = [];
        $this->libelles = [];

        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null === $id) {
                continue;
            }

            $this->libelles[$id] = $personne->getDisplayName();

            $fiches[] = new FicheEtudiee(
                id: $id,
                nomAffiche: $personne->getDisplayName(),
                prenoms: $personne->getGivenNames(),
                nom: $personne->getSurname(),
                sexe: $personne->getSex()->value,
                // « Un décès inconnu vaut vivant » : le défaut du `??` ferme, il n'ouvre pas.
                vivante: $vivants[$id] ?? true,
                naissance: $personne->getBirth(),
                deces: $personne->getDeath(),
                pereId: $pere[$id] ?? null,
                mereId: $mere[$id] ?? null,
                // `array_unique` et pas seulement `array_values` : un remariage au sein
                // du même couple, ou deux familles portant les mêmes époux, nommeraient
                // deux fois le même conjoint. C'est sans effet sur la détection, qui ne
                // fait qu'un test d'appartenance ; ce ne le serait pas sur le corpus
                // envoyé au modèle, qui décrirait une parenté fausse.
                conjointIds: array_values(array_unique($conjoints[$id] ?? [])),
                enfantIds: array_values(array_unique($enfants[$id] ?? [])),
            );
        }

        return $fiches;
    }

    /**
     * Père, mère et enfants, en **une** lecture de `tree_child` jointe à sa famille.
     *
     * @return array{array<int, int>, array<int, int>, array<int, list<int>>}
     */
    private function parente(): array
    {
        /** @var list<array{enfant: int, pere: int|null, mere: int|null}> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.person) AS enfant', 'IDENTITY(fam.husband) AS pere', 'IDENTITY(fam.wife) AS mere')
            ->from(Child::class, 'c')
            ->innerJoin('c.family', 'fam')
            ->getQuery()
            ->getArrayResult();

        $pere = [];
        $mere = [];
        $enfants = [];

        foreach ($lignes as $ligne) {
            $enfant = (int) $ligne['enfant'];

            if (null !== $ligne['pere']) {
                $pere[$enfant] ??= (int) $ligne['pere'];
                $enfants[(int) $ligne['pere']][] = $enfant;
            }

            if (null !== $ligne['mere']) {
                $mere[$enfant] ??= (int) $ligne['mere'];
                $enfants[(int) $ligne['mere']][] = $enfant;
            }
        }

        return [$pere, $mere, $enfants];
    }

    /**
     * Les conjoints, dans les deux sens.
     *
     * Une union sans les deux époux — le cas courant d'un arbre incomplet — n'en produit
     * aucun : il n'y a personne à nommer de l'autre côté.
     *
     * @return array<int, list<int>>
     */
    private function conjoints(): array
    {
        /** @var list<array{mari: int|null, epouse: int|null}> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(fam.husband) AS mari', 'IDENTITY(fam.wife) AS epouse')
            ->from(Family::class, 'fam')
            ->getQuery()
            ->getArrayResult();

        $conjoints = [];

        foreach ($lignes as $ligne) {
            if (null === $ligne['mari'] || null === $ligne['epouse']) {
                continue;
            }

            $mari = (int) $ligne['mari'];
            $epouse = (int) $ligne['epouse'];

            $conjoints[$mari][] = $epouse;
            $conjoints[$epouse][] = $mari;
        }

        return $conjoints;
    }

    /**
     * @return list<Person>
     */
    private function chargeLesPersonnes(): array
    {
        /** @var list<Person> $personnes */
        $personnes = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            // L'ordre est demandé par le contrat : la détection produit les questions dans
            // cet ordre, et un ordre instable ferait varier la page d'un chargement à
            // l'autre sans qu'aucune donnée n'ait changé.
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $personnes;
    }
}
