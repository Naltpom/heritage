<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\ValueObject\FamillePlacable;
use App\Module\Tree\ValueObject\IdentifiantDeNoeud;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\PersonnePlacable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Charge l'arbre validé complet depuis `tree_person`, `tree_family` et `tree_child`, et
 * le rend sous la forme que {@see MoteurDePlacement} attend.
 *
 * C'est le seul endroit du chemin de placement qui touche la base. Le moteur, lui, n'y
 * accède jamais — c'est la contrainte de l'ADR 0005, et c'est elle qui le rend testable
 * contre `graphe.json`.
 *
 * ## Trois choses qui décident du résultat, et qu'on ne voit pas en lisant
 *
 * **1. L'ordre fait foi, et il est explicite.** `ORDER BY p.id` pour les personnes,
 * `ORDER BY f.id` pour les familles, `sibling_order` puis `id` pour les enfants d'une
 * famille. L'import GEDCOM initial a créé les lignes dans l'ordre du fichier, et celles
 * créées depuis viennent à la suite : cet ordre **prolonge** celui que parcourait le
 * script d'origine. Ce n'est pas une commodité de lecture : le
 * point fixe en 12 passes et le décrochage rangée par rangée départagent par l'ordre de
 * parcours deux nœuds à égalité de position. Une requête sans `ORDER BY` rendrait
 * PostgreSQL libre de son ordre, et le graphe changerait au gré des `VACUUM`.
 *
 * **2. Les adjacences se reconstruisent depuis `tree_family` et `tree_child`**, jamais
 * depuis une liste dénormalisée. C'est le piège déjà payé une fois : dans `graphe.json`,
 * `gens[k]["enfants"]` et `["conjoints"]` sont écrits `sorted(set(…))` **après** le
 * placement, et les lire à la place des tables brutes déplaçait des nœuds. La base a
 * exactement la même capacité à mentir si on lui demande des listes dans un ordre qui
 * n'est pas celui du moteur. On ne rend donc ici que `epoux` et `enfants` par famille —
 * le moteur en dérive lui-même parents, enfants et conjoints, dans l'ordre des familles.
 *
 * **3. L'étiquette des dates passe par {@see EtiquetteDeDateGedcom}**, jamais par
 * `UncertainDate::format()` : la seconde rend de la prose française, deux fois plus
 * longue, et `w` est mesurée sur cette chaîne. Et l'état vivant passe par
 * {@see ReglePersonneVivante}, dont la constante « vivant·e » ferme la fuite par la
 * largeur (ADR 0003) **et** satisfait le critère d'égalité exacte (ADR 0005) du même
 * geste.
 *
 * ## Aucun filtre de confidentialité ici
 *
 * L'étage 1 est calculé sur l'arbre **complet** : le cercle de visibilité masque des
 * champs, pas des nœuds. Filtrer ici donnerait un placement par compte et ferait tomber
 * tout le cache partagé (ADR 0005).
 */
final class JeuDePlacementDoctrine implements FournisseurDeJeuDePlacement
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EtiquetteDeDateGedcom $dates,
        private readonly ReglePersonneVivante $regleVivante,
        private readonly ReglagesDeLArbre $reglages,
    ) {
    }

    public function jeuComplet(): JeuDePlacement
    {
        $personnes = $this->chargeLesPersonnes();
        $familles = $this->chargeLesFamilles();
        $enfantsParFamille = $this->chargeLesEnfants();

        $vivants = $this->regleVivante->pourTous(
            $personnes,
            $this->reglages->seuilVivantAnnees(),
            new \DateTimeImmutable(),
        );

        $placables = [];

        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null === $id) {
                continue;
            }

            $placables[] = $this->enPersonnePlacable($personne, $id, $vivants[$id] ?? false);
        }

        $famillesPlacables = [];

        foreach ($familles as $famille) {
            $id = $famille->getId();

            if (null === $id) {
                continue;
            }

            $famillesPlacables[] = new FamillePlacable(
                id: IdentifiantDeNoeud::famille($id),
                // `HUSB` puis `WIFE`, les absents retirés — c'est l'ordre de `graphe.py`,
                // et c'est lui qui désigne le premier parent d'un enfant.
                epoux: $this->identifiants([$famille->getHusband(), $famille->getWife()]),
                enfants: $enfantsParFamille[$id] ?? [],
                aDivorce: $famille->isDivorced(),
            );
        }

        return new JeuDePlacement(
            $placables,
            $famillesPlacables,
            null !== ($racine = $this->reglages->personneRacineId())
                ? IdentifiantDeNoeud::personne($racine)
                : '',
        );
    }

    private function enPersonnePlacable(Person $personne, int $id, bool $vivante): PersonnePlacable
    {
        $naissance = $personne->getBirth();

        return new PersonnePlacable(
            id: IdentifiantDeNoeud::personne($id),
            // `pre or "?"` dans `graphe.py` : une fiche sans prénom mesure quand même
            // un caractère. La remplacer par une chaîne vide rétrécirait la fiche.
            prenom: '' !== $personne->getGivenNames() ? $personne->getGivenNames() : '?',
            nom: $personne->getSurname(),
            // Le `NICK` du GEDCOM, et non `married_name` : c'est `usage` que `graphe.py`
            // fait primer sur le prénom pour composer l'étiquette.
            usage: $personne->getNickname() ?? '',
            sexe: $personne->getSex(),
            nais: $this->dates->etiquette($naissance),
            deces: $this->dates->etiquette($personne->getDeath()),
            anNais: $this->anneeDeNaissance($naissance),
            vivant: $vivante,
            // `gen.setdefault(k, 0)` : le filet de sécurité du script d'origine.
            generation: $personne->getGeneration() ?? 0,
        );
    }

    /**
     * L'année qui **ordonne les fratries** : le premier millésime de la chaîne GEDCOM,
     * où qu'il soit, et non `birth_min`.
     *
     * La nuance n'est pas décorative : `BEF 1920` n'a pas de borne basse — `birth_min`
     * est nul — mais `graphe.py` en tire 1920 et trie la fratrie avec. Prendre
     * `birth_min` laisserait ces fiches en fin de fratrie, à 9999.
     */
    private function anneeDeNaissance(UncertainDate $naissance): ?int
    {
        $depuisLeBrut = $this->dates->annee($naissance->getRaw());

        if (null !== $depuisLeBrut) {
            return $depuisLeBrut;
        }

        $min = $naissance->getMin();

        return null === $min ? null : (int) $min->format('Y');
    }

    /**
     * @param list<Person|null> $personnes
     *
     * @return list<string>
     */
    private function identifiants(array $personnes): array
    {
        $ids = [];

        foreach ($personnes as $personne) {
            $id = $personne?->getId();

            if (null !== $id) {
                $ids[] = IdentifiantDeNoeud::personne($id);
            }
        }

        return $ids;
    }

    /** @return list<Person> */
    private function chargeLesPersonnes(): array
    {
        /** @var list<Person> $personnes */
        $personnes = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $personnes;
    }

    /** @return list<Family> */
    private function chargeLesFamilles(): array
    {
        /** @var list<Family> $familles */
        $familles = $this->em->createQueryBuilder()
            ->select('f', 'h', 'w')
            ->from(Family::class, 'f')
            // Jointures explicites : sans elles, chaque `getHusband()` déclenche une
            // requête, soit 206 allers-retours pour 103 familles.
            ->leftJoin('f.husband', 'h')
            ->leftJoin('f.wife', 'w')
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $familles;
    }

    /**
     * Les enfants groupés par famille, **dans l'ordre des lignes `CHIL` du GEDCOM**
     * (`sibling_order`), que porte l'index `idx_tree_child_fratrie`.
     *
     * Ce n'est **pas** l'ordre des naissances — 50 personnes n'ont aucune date — et le
     * moteur ne le confond pas : il retrie lui-même la fratrie par année quand il la
     * centre sous le couple parental. Les deux ordres coexistent et ne servent pas à la
     * même chose ; c'est `sibling_order` qui départage les ex æquo.
     *
     * @return array<int, list<string>>
     */
    private function chargeLesEnfants(): array
    {
        /** @var list<Child> $liens */
        $liens = $this->em->createQueryBuilder()
            ->select('c', 'f', 'p')
            ->from(Child::class, 'c')
            ->join('c.family', 'f')
            ->join('c.person', 'p')
            ->orderBy('c.siblingOrder', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $parFamille = [];

        foreach ($liens as $lien) {
            $familleId = $lien->getFamily()->getId();
            $personneId = $lien->getPerson()->getId();

            if (null !== $familleId && null !== $personneId) {
                $parFamille[$familleId][] = IdentifiantDeNoeud::personne($personneId);
            }
        }

        return $parFamille;
    }
}
