<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see DraftPerson}.
 *
 * @extends ServiceEntityRepository<DraftPerson>
 */
class DraftPersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DraftPerson::class);
    }

    /**
     * Les brouillons d'une demande, dans l'ordre de leur référence provisoire.
     *
     * @return list<DraftPerson>
     */
    public function pourDemande(ChangeRequest $demande): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.request = :demande')
            ->setParameter('demande', $demande)
            ->orderBy('b.localRef', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le nom des personnes proposées par chaque demande, **en une requête** pour toute
     * une page de la file : « Prénoms Nom », dans l'ordre des références provisoires.
     *
     * @param list<int> $demandeIds
     *
     * @return array<int, list<string>> demande ⇒ noms
     */
    public function nomsParDemande(array $demandeIds): array
    {
        if ([] === $demandeIds) {
            return [];
        }

        /** @var list<array{demande: int|string, givenNames: string, surname: string}> $lignes */
        $lignes = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.request) AS demande', 'b.givenNames', 'b.surname')
            ->andWhere('b.request IN (:demandes)')
            ->setParameter('demandes', $demandeIds)
            ->orderBy('b.localRef', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $noms = [];

        foreach ($lignes as $ligne) {
            $nom = trim($ligne['givenNames'].' '.$ligne['surname']);
            $noms[(int) $ligne['demande']][] = '' === $nom ? 'Personne sans nom' : $nom;
        }

        return $noms;
    }

    /**
     * Un brouillon par sa référence provisoire, **dans sa demande**.
     *
     * L'unicité de `local_ref` est **par demande** (`uniq_prp_draft_person_ref`) : `#p1`
     * de la demande 12 et `#p1` de la demande 13 n'ont aucun rapport. Une recherche par
     * référence seule n'aurait donc pas de sens, et c'est pour cela que cette méthode
     * exige la demande.
     */
    public function parReference(ChangeRequest $demande, string $localRef): ?DraftPerson
    {
        return $this->findOneBy(['request' => $demande, 'localRef' => $localRef]);
    }

    /**
     * Les fiches proposées par les demandes **encore en attente** que ce lecteur a le
     * droit de voir.
     *
     * `$auteurUserId` à `null` veut dire « toutes », et n'est passé que pour un
     * administrateur hors bascule « voir comme un membre ». C'est la règle du § 13.3,
     * la même qu'au calque et à la projection, et le paramètre est **obligatoire à
     * l'appel** plutôt que nullable par défaut : un appelant qui l'oublierait
     * montrerait à n'importe quel membre les propositions des autres.
     *
     * Seules les `en_attente` : un brouillon non soumis n'est pas une proposition —
     * son auteur n'a pas encore dit qu'il la voulait —, et une demande tranchée n'a
     * plus rien à offrir, soit qu'elle ait produit une personne réelle, soit qu'elle
     * ait été refusée.
     *
     * @return list<DraftPerson>
     */
    public function enAttenteVisibles(?int $auteurUserId): array
    {
        $qb = $this->createQueryBuilder('b')
            ->join('b.request', 'd')
            ->andWhere('d.status = :statut')
            ->setParameter('statut', RequestStatus::EnAttente)
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->addOrderBy('b.localRef', 'ASC');

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        /** @var list<DraftPerson> $lignes */
        $lignes = $qb->getQuery()->getResult();

        return $lignes;
    }

    /**
     * Une fiche proposée par son identifiant de ligne, **si ce lecteur a le droit de la
     * voir** — le reste du monde reçoit `null`, c'est-à-dire un `404`.
     *
     * Le statut n'est **pas** filtré ici, et c'est voulu : entre la recherche et le
     * dépôt, la demande citée a pu être validée. Le brouillon porte alors son
     * `resolved_person_id`, et l'appelant désigne la personne réelle plutôt que de
     * refuser une référence qui vient justement d'aboutir. C'est au point d'entrée de
     * trancher — voir {@see \App\Module\Proposal\Service\ConstructeurDeCreation} —,
     * parce que lui seul sait quoi faire de chaque cas.
     */
    public function visiblePourLecteur(int $id, ?int $auteurUserId): ?DraftPerson
    {
        $qb = $this->createQueryBuilder('b')
            ->join('b.request', 'd')
            ->andWhere('b.id = :id')
            ->setParameter('id', $id);

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        $ligne = $qb->getQuery()->getOneOrNullResult();

        return $ligne instanceof DraftPerson ? $ligne : null;
    }
}
