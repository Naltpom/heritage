<?php

declare(strict_types=1);

namespace App\Module\Proposal\Repository;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
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

        // **Un complément n'est pas un doublon de son origine** (ADR 0017) : il porte
        // presque toujours la même clé — c'est la même personne, c'est tout l'objet —, et
        // l'administrateur le voit déjà dans `complements` ou dans `complementDe`, avec le
        // bouton qui convient, « Fusionner ». Le lister une seconde fois comme doublon
        // probable l'inviterait au geste du § 6.9 — refuser en renvoyant à l'autre —, qui
        // perdrait les liens et les pièces que la fusion reprend.
        //
        // Trois lignes à écarter, et la troisième se déduit des deux premières : les
        // compléments de cette demande, son origine si elle est elle-même un complément,
        // et les autres compléments de cette même origine — deux membres qui complètent la
        // même proposition ne sont pas en concurrence, ils seront fusionnés tous les deux.
        $origine = $demande->getComplementDe()?->getRequest();

        return $this->createQueryBuilder('d')
            ->leftJoin('d.complementDe', 'f')
            ->andWhere('d.dedupKey = :cle')
            ->andWhere('d.status = :statut')
            ->andWhere('d.id <> :moi')
            ->andWhere('f.id IS NULL OR IDENTITY(f.request) NOT IN (:demandesDeLaFamille)')
            ->andWhere('d.id <> :origine')
            ->setParameter('cle', $cle)
            ->setParameter('statut', RequestStatus::EnAttente)
            ->setParameter('moi', $demande->getId() ?? 0)
            ->setParameter('origine', $origine?->getId() ?? 0)
            ->setParameter('demandesDeLaFamille', [$demande->getId() ?? 0, $origine?->getId() ?? 0])
            ->addOrderBy('d.submittedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les **compléments** d'une proposition : les demandes dont `complement_de_id` désigne
     * une de ses fiches (ADR 0017), dans l'ordre de soumission.
     *
     * Sans les brouillons — un complément non envoyé n'est visible que de son auteur
     * (§ 5.7), et l'administrateur n'a rien à en fusionner — mais **avec** les tranchés : un
     * complément déjà fusionné reste dans l'historique de l'origine, et la vue
     * d'administration le montre avec son statut. `$seulementEnAttente` restreint aux
     * compléments que la fusion peut encore prendre : c'est la question du contrôle
     * préalable, qui bloque la validation de l'origine tant qu'il en reste un.
     *
     * Index : `idx_prp_request_complement`.
     *
     * @return list<ChangeRequest>
     */
    public function complementsDe(ChangeRequest $origine, bool $seulementEnAttente = false): array
    {
        if (null === $origine->getId()) {
            return [];
        }

        $qb = $this->createQueryBuilder('d')
            ->addSelect('f')
            ->join('d.complementDe', 'f')
            ->andWhere('f.request = :origine')
            ->andWhere('d.status <> :brouillon')
            ->setParameter('origine', $origine)
            ->setParameter('brouillon', RequestStatus::Brouillon)
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if ($seulementEnAttente) {
            $qb->andWhere('d.status = :enAttente')->setParameter('enAttente', RequestStatus::EnAttente);
        }

        /** @var list<ChangeRequest> $complements */
        $complements = $qb->getQuery()->getResult();

        return $complements;
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
     * `$kind` accepte une liste — les onglets de la file regroupent plusieurs natures
     * (« Modifications », « Liens »…). `$recherche` (2026-10-04) : un **numéro de
     * demande** (`42`, `#42`, `n° 42`) la désigne seule ; tout autre texte cherche, sans
     * casse, dans la fiche visée, l'auteur, le message et le nom des personnes proposées.
     *
     * @param RequestKind|list<RequestKind>|null $kind
     *
     * @return array{elements: list<ChangeRequest>, total: int}
     */
    public function file(
        ?RequestStatus $statut,
        RequestKind|array|null $kind,
        ?int $targetId,
        int $page,
        int $parPage,
        ?int $auteurUserId = null,
        ?string $recherche = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->addOrderBy('d.submittedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC');

        if (null !== $statut) {
            $qb->andWhere('d.status = :statut')->setParameter('statut', $statut);
        }

        if ($kind instanceof RequestKind) {
            $qb->andWhere('d.kind = :kind')->setParameter('kind', $kind);
        } elseif (\is_array($kind) && [] !== $kind) {
            $qb->andWhere('d.kind IN (:kinds)')->setParameter('kinds', array_map(static fn (RequestKind $k): string => $k->value, $kind));
        }

        if (null !== $targetId) {
            $qb->andWhere('d.targetId = :cible')->setParameter('cible', $targetId);
        }

        if (null !== $auteurUserId) {
            $qb->andWhere('d.authorUserId = :auteur')->setParameter('auteur', $auteurUserId);
        }

        $recherche = null === $recherche ? '' : trim($recherche);

        if (1 === preg_match('/^(?:n°|no|#)?\s*(\d+)$/iu', $recherche, $numero)) {
            $qb->andWhere('d.id = :numero')->setParameter('numero', (int) $numero[1]);
        } elseif ('' !== $recherche) {
            // Un `%` ou un `_` tapé reste un joker : la route est réservée à l'administration.
            $motif = '%'.mb_strtolower($recherche).'%';
            $qb->andWhere($qb->expr()->orX(
                'LOWER(d.targetLabel) LIKE :motif',
                'LOWER(d.authorLabel) LIKE :motif',
                'LOWER(d.message) LIKE :motif',
                'EXISTS (SELECT b.id FROM '.DraftPerson::class.' b WHERE b.request = d AND LOWER(CONCAT(b.givenNames, \' \', b.surname)) LIKE :motif)',
            ))->setParameter('motif', $motif);
        }

        return $this->paginer($qb, $page, $parPage);
    }

    /**
     * Les auteurs qui ont réellement déposé quelque chose, pour peupler le filtre.
     *
     * **La liste des utilisateurs ne conviendrait pas.** Elle porte tous les comptes,
     * y compris ceux qui n'ont jamais rien proposé : un sélecteur où presque toutes
     * les entrées ne donnent aucun résultat est un sélecteur qu'on n'ouvre plus.
     *
     * Elle n'est **pas** restreinte au statut couramment filtré, et c'est voulu :
     * changer de statut ne doit pas faire disparaître sous le doigt l'auteur qu'on
     * venait de choisir.
     *
     * @return list<array{userId: int, libelle: string}>
     */
    public function auteursDeLaFile(): array
    {
        /** @var list<array{userId: int, libelle: string}> $lignes */
        $lignes = $this->createQueryBuilder('d')
            ->select('DISTINCT d.authorUserId AS userId', 'd.authorLabel AS libelle')
            ->addOrderBy('d.authorLabel', 'ASC')
            ->getQuery()
            ->getResult();

        return $lignes;
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
