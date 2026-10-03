<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Statistiques\Contract\AppuiDuReleve;
use App\Module\Statistiques\Contract\FaitDuReleve;
use App\Module\Statistiques\Contract\FamilleDuReleve;
use App\Module\Statistiques\Contract\LieuDuReleve;
use App\Module\Statistiques\Contract\NatureDuFait;
use App\Module\Statistiques\Contract\PersonneDuReleve;
use App\Module\Statistiques\Contract\PorteeDeLAppui;
use App\Module\Statistiques\Contract\PortStatistiquesArbre;
use App\Module\Statistiques\Contract\ReleveDeLArbre;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Place;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Enum\NoteScope;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'implémentation, côté `Tree`, de {@see PortStatistiquesArbre} — tout ce qu'Explorer ›
 * Stats lit de l'arbre.
 *
 * Une **traduction, pas un calcul**, comme {@see VueDeLArbrePourAlmanach} dont ce fichier
 * est le cousin : les personnes de `tree_person`, les unions de `tree_family` avec leurs
 * enfants (`tree_child`), les professions et résidences de `tree_fact`, les citations de
 * `tree_citation`, les lieux de `tree_place`. L'état vivant vient de
 * {@see ReglePersonneVivante} — jamais du seul drapeau `deceased`, qui traverse à part.
 *
 * **Rien n'est filtré ici** : c'est la règle 1 du contrat, et la confidentialité est
 * appliquée par le module statistique, une fois.
 *
 * Une **citation de personne** traverse avec son `personId`, une citation de famille avec
 * son `familyId` — exactement ce que lit `best_quay` (`IDENTITY(c.person)`), pour que
 * l'évolution mois par mois retombe, aujourd'hui, sur la part vérifiée des fiches. Une
 * citation rattachée seulement à un fait ne traverse sans personne ni famille : elle ne
 * compte pas pour « Vérifié », pas plus que dans `best_quay`.
 */
final class VueDeLArbrePourStatistiques implements PortStatistiquesArbre
{
    private ?ReleveDeLArbre $memoire = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglePersonneVivante $regleVivante,
        private readonly ReglagesDeLArbre $reglages,
    ) {
    }

    /**
     * Un `COUNT` et un horodatage maximal par table lue, plus le jour et les deux réglages
     * qui changent la lecture (la racine des branches, le seuil « vivant »). Six requêtes
     * d'agrégat : rien à côté de ce qu'elles évitent.
     */
    public function jeton(): string
    {
        $connexion = $this->em->getConnection();
        $morceaux = [
            (new \DateTimeImmutable())->format('Y-m-d'),
            (string) $this->reglages->personneRacineId(),
            (string) $this->reglages->seuilVivantAnnees(),
        ];

        foreach ([
            [Person::class, 'updated_at'],
            [Family::class, 'updated_at'],
            [Child::class, 'created_at'],
            [Citation::class, 'updated_at'],
            [Fact::class, 'updated_at'],
            [Place::class, 'resolu_le'],
        ] as [$entite, $horodatage]) {
            $table = $this->em->getClassMetadata($entite)->getTableName();
            $ligne = $connexion->fetchAssociative(\sprintf(
                'SELECT COUNT(*) AS n, MAX(%s) AS dernier, MAX(id) AS plus_grand FROM %s',
                $connexion->quoteSingleIdentifier($horodatage),
                $connexion->quoteSingleIdentifier($table),
            ));
            $morceaux[] = $table.'='.implode('/', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $ligne ?: []));
        }

        return implode('|', $morceaux);
    }

    public function releve(): ReleveDeLArbre
    {
        return $this->memoire ??= $this->charger();
    }

    private function charger(): ReleveDeLArbre
    {
        /** @var list<Person> $personnes */
        $personnes = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        $vivants = $this->regleVivante->pourTous($personnes, $this->reglages->seuilVivantAnnees(), new \DateTimeImmutable());

        $releve = [];
        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null === $id) {
                continue;
            }

            $releve[] = new PersonneDuReleve(
                id: $id,
                displayName: $personne->getDisplayName(),
                prenoms: $personne->getGivenNames(),
                nom: $personne->getSurname(),
                sex: $personne->getSex(),
                naissance: self::copie($personne->getBirth()),
                deces: self::copie($personne->getDeath()),
                vivant: $vivants[$id] ?? true,
                decede: $personne->isDeceased(),
                branch: $personne->getBranch(),
                // Une fiche sans branche n'est pas reliée à la racine (« sans lien
                // connu ») : sa génération est comptée depuis son propre îlot, qui
                // repart de 0 — des aïeux de 1750 y passaient en « génération 0 ».
                // Elle n'a donc pas de rang dans les séries par génération.
                generation: null === $personne->getBranch() ? null : $personne->getGeneration(),
                bestQuay: $personne->getBestQuay(),
                citationCount: $personne->getCitationCount(),
                creeLe: $personne->getCreatedAt(),
            );
        }

        return new ReleveDeLArbre(
            personnes: $releve,
            familles: $this->familles(),
            faits: $this->faits(),
            appuis: $this->appuis(),
            lieux: $this->lieux(),
            racineId: $this->reglages->personneRacineId(),
        );
    }

    /** @return list<FamilleDuReleve> */
    private function familles(): array
    {
        /** @var list<array{famille: int|string, personne: int|string}> $liens */
        $liens = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.family) AS famille', 'IDENTITY(c.person) AS personne')
            ->from(Child::class, 'c')
            ->orderBy('c.siblingOrder', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $enfants = [];
        foreach ($liens as $lien) {
            $enfants[(int) $lien['famille']][] = (int) $lien['personne'];
        }

        /** @var list<Family> $familles */
        $familles = $this->em->createQueryBuilder()
            ->select('fam', 'mari', 'epouse')
            ->from(Family::class, 'fam')
            ->leftJoin('fam.husband', 'mari')
            ->leftJoin('fam.wife', 'epouse')
            ->orderBy('fam.id', 'ASC')
            ->getQuery()
            ->getResult();

        $releve = [];
        foreach ($familles as $famille) {
            $id = $famille->getId();

            if (null === $id) {
                continue;
            }

            $releve[] = new FamilleDuReleve(
                id: $id,
                mariId: $famille->getHusband()?->getId(),
                epouseId: $famille->getWife()?->getId(),
                mariage: self::copie($famille->getMarriage()),
                divorce: $famille->isDivorced(),
                dateDuDivorce: self::copie($famille->getDivorce()),
                enfants: $enfants[$id] ?? [],
                // Même règle que pour les personnes : un couple dont aucun membre n'est
                // relié à la racine n'a pas de génération qui ait un sens.
                generation: null === $famille->getHusband()?->getBranch() && null === $famille->getWife()?->getBranch()
                    ? null
                    : $famille->getGeneration(),
            );
        }

        return $releve;
    }

    /** @return list<FaitDuReleve> */
    private function faits(): array
    {
        /** @var list<Fact> $faits */
        $faits = $this->em->createQueryBuilder()
            ->select('f')
            ->from(Fact::class, 'f')
            ->where('f.type IN (:types)')
            ->setParameter('types', [FactType::Profession->value, FactType::Residence->value])
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();

        $releve = [];
        foreach ($faits as $fait) {
            $releve[] = new FaitDuReleve(
                nature: FactType::Profession === $fait->getType() ? NatureDuFait::Profession : NatureDuFait::Residence,
                personId: $fait->getPerson()?->getId(),
                familyId: $fait->getFamily()?->getId(),
                libelle: $fait->getLabel(),
                date: self::copie($fait->getHappened()),
            );
        }

        return $releve;
    }

    /** @return list<AppuiDuReleve> */
    private function appuis(): array
    {
        /** @var list<Citation> $citations */
        $citations = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Citation::class, 'c')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $releve = [];
        foreach ($citations as $citation) {
            $releve[] = new AppuiDuReleve(
                personId: $citation->getPerson()?->getId(),
                familyId: $citation->getFamily()?->getId(),
                portee: match ($citation->getScope()) {
                    NoteScope::Generale => PorteeDeLAppui::Generale,
                    NoteScope::Naissance => PorteeDeLAppui::Naissance,
                    NoteScope::Deces => PorteeDeLAppui::Deces,
                    NoteScope::Mariage => PorteeDeLAppui::Mariage,
                    default => PorteeDeLAppui::Autre,
                },
                quay: $citation->getQuay()?->value,
                creeLe: $citation->getCreatedAt(),
            );
        }

        return $releve;
    }

    /** @return list<LieuDuReleve> */
    private function lieux(): array
    {
        /** @var list<Place> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('l')
            ->from(Place::class, 'l')
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        $releve = [];
        foreach ($lignes as $ligne) {
            $pays = $ligne->getPays();

            $releve[] = new LieuDuReleve(
                cle: $ligne->getRawKey(),
                libelle: $ligne->getLibelle(),
                commune: $ligne->getCommune(),
                subdivision: $ligne->getSubdivision(),
                pays: $pays,
                nomDuPays: null !== $pays ? (NomsDesPays::CLDR[$pays] ?? null) : null,
                lat: $ligne->getLat(),
                lon: $ligne->getLon(),
            );
        }

        return $releve;
    }

    /**
     * Une copie détachée de l'objet embarqué : le relevé est mis en cache, et un
     * `UncertainDate` encore tenu par une entité gérée ne doit pas voyager avec elle.
     */
    private static function copie(UncertainDate $date): UncertainDate
    {
        return new UncertainDate($date->getRaw(), $date->getQualifier(), $date->getMin(), $date->getMax(), $date->getPlace());
    }
}
