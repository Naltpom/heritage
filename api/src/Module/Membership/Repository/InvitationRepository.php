<?php

declare(strict_types=1);

namespace App\Module\Membership\Repository;

use App\Module\Membership\Entity\Invitation;
use App\Module\Membership\Enum\InvitationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Invitation}.
 *
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    /**
     * **La requête critique du module.** Chaque arrivée sur un lien d'invitation en fait
     * exactement une, `WHERE token_hash = ?`, servie par `uniq_mbr_invitation_token`.
     *
     * Le § 4.1 du modèle explique pourquoi l'index n'est pas une optimisation : « un défaut
     * d'index se paierait en temps de réponse mesurable, donc en canal auxiliaire — un
     * jeton valide répondrait plus lentement qu'un invalide ».
     *
     * On cherche par **empreinte**, jamais par jeton : le jeton en clair n'est pas en base,
     * et il n'y a donc rien à comparer en clair.
     */
    public function parEmpreinte(string $empreinte): ?Invitation
    {
        return $this->findOneBy(['tokenHash' => $empreinte]);
    }

    /**
     * « Une invitation est-elle déjà en cours pour cette personne ? » — posée avant d'en
     * créer une (§ 3.1 du contrat, `409 invitation_en_cours`).
     *
     * Deux liens concurrents vers la même fiche sont une confusion garantie, pas une
     * commodité : le second candidat qui arrive trouve la personne déjà réclamée et
     * n'obtient qu'un `409` incompréhensible de son côté.
     */
    public function activePourPersonne(int $personId): ?Invitation
    {
        return $this->findOneBy(['personId' => $personId, 'status' => InvitationStatus::Active]);
    }

    /**
     * La liste d'administration du § 3.3, paginée et triée par `createdAt DESC`.
     *
     * `$avecRevoquees = false` écarte les liens révoqués, qui encombrent la liste sans
     * rien demander à personne ; il se combine avec `$statut`.
     *
     * @return array{elements: array<int, Invitation>, total: int}
     */
    public function lister(?InvitationStatus $statut, int $page, int $parPage, bool $avecRevoquees = true): array
    {
        $qb = $this->createQueryBuilder('i')->orderBy('i.createdAt', 'DESC')->addOrderBy('i.id', 'DESC');

        if (null !== $statut) {
            $qb->andWhere('i.status = :statut')->setParameter('statut', $statut);
        }
        if (!$avecRevoquees) {
            $qb->andWhere('i.status <> :revoquee')->setParameter('revoquee', InvitationStatus::Revoquee);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(i.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var array<int, Invitation> $elements */
        $elements = $qb
            ->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery()
            ->getResult();

        return ['elements' => $elements, 'total' => $total];
    }
}
