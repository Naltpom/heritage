<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see DraftLink}.
 *
 * @extends ServiceEntityRepository<DraftLink>
 */
class DraftLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DraftLink::class);
    }

    /**
     * Les liens d'une demande, dans l'ordre de `seq`.
     *
     * @return list<DraftLink>
     */
    public function pourDemande(ChangeRequest $demande): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.request = :demande')
            ->setParameter('demande', $demande)
            ->orderBy('l.seq', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les liens **qui citent cette fiche**, où qu'ils soient — le rafraîchissement des
     * libellés après une complétion, et le repointage après une fusion (ADR 0017).
     *
     * Une fiche se cite de deux façons, et cette méthode rend les deux :
     *
     * - **dans sa propre demande**, par sa référence provisoire (`#p1`) — `*DraftRef`, qui
     *   ne vaut que là (`uniq_prp_draft_person_ref`) ;
     * - **depuis une autre demande**, par l'identifiant de sa ligne — `*DraftPersonId`
     *   (§ 6.3 bis). Seulement celles qui ne sont **pas tranchées** (`brouillon` ou
     *   `en_attente`) : une demande validée a déjà recopié ses libellés dans
     *   `prp_revision`, une demande refusée n'a plus rien à afficher, et les réécrire
     *   changerait l'histoire d'une décision prise.
     *
     * L'appelant distingue les deux par `getRequest()`. Aucun index ne sert les colonnes
     * `*_draft_person_id` — elles sont rares, et la question n'est posée qu'à une écriture
     * d'auteur ou d'administrateur, jamais à une lecture de graphe.
     *
     * @return list<DraftLink>
     */
    public function citant(DraftPerson $fiche): array
    {
        $id = $fiche->getId();
        $qb = $this->createQueryBuilder('l')
            ->addSelect('d')
            ->join('l.request', 'd')
            ->andWhere(
                '(l.request = :demande AND (l.subjectDraftRef = :ref OR l.otherDraftRef = :ref OR l.other2DraftRef = :ref))'
                .(null === $id ? '' : ' OR (d.status IN (:ouvertes) AND (l.subjectDraftPersonId = :id OR l.otherDraftPersonId = :id OR l.other2DraftPersonId = :id))'),
            )
            ->setParameter('demande', $fiche->getRequest())
            ->setParameter('ref', $fiche->getLocalRef())
            ->addOrderBy('d.id', 'ASC')
            ->addOrderBy('l.seq', 'ASC');

        if (null !== $id) {
            $qb->setParameter('ouvertes', [RequestStatus::Brouillon, RequestStatus::EnAttente])
                ->setParameter('id', $id);
        }

        /** @var list<DraftLink> $liens */
        $liens = $qb->getQuery()->getResult();

        return $liens;
    }

    /**
     * Le plus grand rang de lien **enregistré** pour une demande, ou `0`.
     *
     * `uniq_prp_draft_link_seq` refuse deux liens de même rang dans une demande : un lien
     * ajouté après coup — une complétion, une fusion — prend donc le rang qui suit. Lu en
     * base et non sur l'unité de travail : l'appelant ajoute ses propres liens en mémoire
     * et compte à partir d'ici, il sait ce qu'il a déjà persisté.
     */
    public function plusGrandRang(ChangeRequest $demande): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COALESCE(MAX(l.seq), 0)')
            ->andWhere('l.request = :demande')
            ->setParameter('demande', $demande)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Combien de liens porte une demande — le contrôle du § 6.3 : « au moins un lien,
     * sinon la personne flotterait hors de l'arbre », vérifié **à la soumission** et
     * non à la création, parce qu'on peut enregistrer un brouillon incomplet.
     */
    public function compterPourDemande(ChangeRequest $demande): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.request = :demande')
            ->setParameter('demande', $demande)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
