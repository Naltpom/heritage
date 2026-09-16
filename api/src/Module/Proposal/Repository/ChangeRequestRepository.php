<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see ChangeRequest}.
 *
 * Chaque requête d'ici est adossée à un index déclaré sur l'entité ; quand ce n'est pas
 * le cas, c'est écrit. Ce n'est pas de la coquetterie : la file d'administration et le
 * calque « en attente » sont appelés à chaque affichage de fiche, et un balayage
 * séquentiel s'y remarquerait tout de suite.
 *
 * @extends ServiceEntityRepository<ChangeRequest>
 */
class ChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangeRequest::class);
    }

    /**
     * Les demandes **en attente** qui visent une ligne donnée, de la plus ancienne à la
     * plus récente.
     *
     * L'ordre porte une règle : le calque retient, pour un champ disputé, l'entrée de
     * la demande **la plus ancienne par `submittedAt`**, et liste les autres dans
     * `autresDemandes` (§ 13.1). Trier ici évite d'avoir à le refaire en mémoire, et
     * surtout évite qu'on oublie de le faire.
     *
     * Index : `idx_prp_request_cible` (`target_kind`, `target_id`, `status`).
     *
     * @return list<ChangeRequest>
     */
    public function enAttentePourCible(SubjectKind $kind, int $id, ?int $auteurUserId = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.targetKind = :kind')
            ->andWhere('d.targetId = :id')
            ->andWhere('d.status = :statut')
            ->setParameter('kind', $kind)
            ->setParameter('id', $id)
            ->setParameter('statut', RequestStatus::EnAttente)
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Les autres demandes en attente sur la même cible — le bloc `demandesConcurrentes`
     * du § 6.7.
     *
     * L'ADR 0004 refuse de sérialiser les demandes : deux membres proposent en
     * parallèle, et « une file qui refuserait les demandes concurrentes découragerait la
     * contribution, qui est le but du produit ». L'arbitrage se fait à la validation, et
     * l'administrateur doit voir les deux diffs côte à côte avant de trancher.
     *
     * @return list<ChangeRequest>
     */
    public function concurrentesDe(ChangeRequest $demande): array
    {
        $kind = $demande->getTargetKind();
        $id = $demande->getTargetId();

        if (null === $kind || null === $id) {
            return [];
        }

        return array_values(array_filter(
            $this->enAttentePourCible($kind, $id),
            static fn (ChangeRequest $autre): bool => $autre->getId() !== $demande->getId(),
        ));
    }

    /**
     * Les doublons probables : mêmes `dedup_key`, en attente, hors la demande elle-même.
     *
     * « Deux cousins peuvent proposer le même arrière-grand-oncle » (§ 6.4 du dossier) ;
     * l'administrateur doit le voir avant de trancher, et refuser l'une en renvoyant à
     * l'autre — c'est à ça que sert le motif obligatoire.
     *
     * Index partiel : `idx_prp_request_dedup`.
     *
     * @return list<ChangeRequest>
     */
    public function doublonsProbablesDe(ChangeRequest $demande): array
    {
        $cle = $demande->getDedupKey();

        if (null === $cle) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->andWhere('d.dedupKey = :cle')
            ->andWhere('d.status = :statut')
            ->andWhere('d.id <> :moi')
            ->setParameter('cle', $cle)
            ->setParameter('statut', RequestStatus::EnAttente)
            ->setParameter('moi', $demande->getId() ?? 0)
            ->addOrderBy('d.submittedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * « Mes demandes » — **y compris les refusées et les caduques**, qui restent
     * consultables par leur auteur avec leur motif (§ 6.3 du dossier). Une demande n'est
     * jamais supprimée : elle est l'historique de la discussion familiale autour d'une
     * fiche.
     *
     * Index : `idx_prp_request_auteur`.
     *
     * @return array{elements: list<ChangeRequest>, total: int}
     */
    public function pourAuteur(int $auteurUserId, ?RequestStatus $statut, int $page, int $parPage): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.authorUserId = :auteur')
            ->setParameter('auteur', $auteurUserId)
            ->addOrderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC');

        if (null !== $statut) {
            $qb->andWhere('d.status = :statut')->setParameter('statut', $statut);
        }

        return $this->paginer($qb, $page, $parPage);
    }

    /**
     * La file d'administration, triée par `submittedAt` **croissant** : le plus ancien
     * en premier, parce qu'une file de validation qui montre le plus récent en haut
     * laisse les demandes anciennes glisser hors de l'écran, et qu'un membre dont la
     * demande dort trois semaines n'en dépose pas de seconde.
     *
     * Index : `idx_prp_request_file`.
     *
     * @return array{elements: list<ChangeRequest>, total: int}
     */
    public function file(
        ?RequestStatus $statut,
        ?RequestKind $kind,
        ?int $targetId,
        int $page,
        int $parPage,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if (null !== $statut) {
            $qb->andWhere('d.status = :statut')->setParameter('statut', $statut);
        }

        if (null !== $kind) {
            $qb->andWhere('d.kind = :kind')->setParameter('kind', $kind);
        }

        if (null !== $targetId) {
            $qb->andWhere('d.targetId = :cible')->setParameter('cible', $targetId);
        }

        return $this->paginer($qb, $page, $parPage);
    }

    /**
     * Toutes les demandes en attente visant une des lignes données, pour composer le
     * calque de plusieurs fiches en une requête.
     *
     * Une fiche affichée seule n'en profite pas ; une liste de résultats de recherche,
     * si — et c'est exactement le cas où une requête par ligne se remarque.
     *
     * @param list<int> $ids
     *
     * @return list<ChangeRequest>
     */
    public function enAttentePourCibles(SubjectKind $kind, array $ids, ?int $auteurUserId = null): array
    {
        if ([] === $ids) {
            return [];
        }

        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.targetKind = :kind')
            ->andWhere('d.targetId IN (:ids)')
            ->andWhere('d.status = :statut')
            ->setParameter('kind', $kind)
            ->setParameter('ids', $ids)
            ->setParameter('statut', RequestStatus::EnAttente)
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Les demandes en attente qui ont **quelque chose à dessiner dans le graphe** :
     * celles qui proposent une personne, et celles qui proposent un lien.
     *
     * `$auteurUserId` à `null` veut dire « toutes », et n'est passé que pour un
     * administrateur. C'est **toute** la différence entre ce que voit l'auteur et ce que
     * voit l'administration, et le paramètre est obligatoire à l'appel plutôt que
     * nullable par défaut : un appelant qui l'oublierait montrerait à n'importe qui les
     * personnes proposées par les autres.
     *
     * Les deux natures sont retenues ensemble parce que le rendu en a besoin ensemble :
     * une `ajout_lien` ne propose aucune personne — « ils étaient frère et sœur » — et
     * a pourtant une arête à tracer.
     *
     * @return list<ChangeRequest>
     */
    public function enAttenteAvecProjection(?int $auteurUserId): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.status = :statut')
            ->andWhere('d.kind IN (:natures)')
            ->setParameter('statut', RequestStatus::EnAttente)
            ->setParameter('natures', [RequestKind::CreationPersonne, RequestKind::AjoutLien])
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * **Toutes** les demandes en attente que ce lecteur voit, quelle que soit leur
     * nature — la matière de l'empreinte du calque (ADR 0005).
     *
     * Contrairement à {@see enAttenteAvecProjection()}, les `modification_*` en font
     * partie : elles ne posent aucun nœud dans le graphe, mais elles allument bien la
     * légende « en attente » et changent ce que le lecteur voit sur les fiches.
     *
     * @return list<ChangeRequest>
     */
    public function enAttenteDuLecteur(?int $auteurUserId): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.status = :statut')
            ->setParameter('statut', RequestStatus::EnAttente)
            ->addOrderBy('d.id', 'ASC');

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{elements: list<ChangeRequest>, total: int}
     */
    private function paginer(QueryBuilder $qb, int $page, int $parPage): array
    {
        $requete = $qb
            ->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery();

        $paginateur = new Paginator($requete, false);

        return [
            'elements' => array_values(iterator_to_array($paginateur)),
            'total' => \count($paginateur),
        ];
    }
}
