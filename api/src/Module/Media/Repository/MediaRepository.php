<?php

declare(strict_types=1);

namespace App\Module\Media\Repository;

use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Media}.
 *
 * Chaque requête vise un index déclaré sur l'entité, et le dit. Aucune ne filtre la
 * visibilité : **le cercle s'applique dans la sérialisation**, pas en SQL (§ 8 du
 * dossier). Un filtre SQL de visibilité aurait l'air plus efficace et serait une
 * seconde source de vérité à côté du normaliseur — le jour où les deux divergent, c'est
 * celui qui oublie de masquer qui gagne.
 *
 * @extends ServiceEntityRepository<Media>
 */
class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    /**
     * Les médias d'un sujet. Sert `idx_med_media_sujet (subject_kind, subject_id, status)`.
     *
     * @param non-empty-list<MediaStatus> $statuts
     * @param int|null                    $deposant restreint aux dépôts de ce compte — c'est ce qui
     *                                              fait qu'un membre demandant `status=en_attente` ne
     *                                              reçoit **que les siens** (§ 7.3 du contrat)
     *
     * @return list<Media>
     */
    public function pourSujet(
        MediaSubject $sujetKind,
        int $sujetId,
        array $statuts,
        ?MediaKind $kind = null,
        ?int $deposant = null,
    ): array {
        $requete = $this->createQueryBuilder('m')
            ->andWhere('m.subjectKind = :sujetKind')
            ->andWhere('m.subjectId = :sujetId')
            ->andWhere('m.status IN (:statuts)')
            ->setParameter('sujetKind', $sujetKind)
            ->setParameter('sujetId', $sujetId)
            ->setParameter('statuts', $statuts)
            // Le portrait actif d'abord, puis du plus récent au plus ancien : c'est
            // l'ordre d'affichage d'une fiche, il n'a pas à être refait côté client.
            ->addOrderBy('m.isActivePortrait', 'DESC')
            ->addOrderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC');

        if (null !== $kind) {
            $requete->andWhere('m.kind = :kind')->setParameter('kind', $kind);
        }

        if (null !== $deposant) {
            $requete->andWhere('m.uploadedByUserId = :deposant')->setParameter('deposant', $deposant);
        }

        return array_values($requete->getQuery()->getResult());
    }

    /**
     * Les médias déjà présents portant la même empreinte. Sert `idx_med_media_sha`.
     *
     * Deux empreintes sont interrogées : celle du fichier **stocké** et celle du fichier
     * **tel que reçu**. La seconde existe parce que le ré-encodage rend deux dépôts du
     * même original différents octet pour octet — sans elle, la détection de doublon ne
     * verrait rien sur le cas le plus fréquent, qui est justement le même fichier
     * redéposé par quelqu'un d'autre.
     *
     * @return list<Media>
     */
    public function doublons(string $sha256, ?string $originalSha256): array
    {
        $requete = $this->createQueryBuilder('m')
            ->andWhere('m.status IN (:statuts)')
            ->setParameter('statuts', [MediaStatus::Actif, MediaStatus::EnAttente, MediaStatus::Remplace])
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults(10);

        if (null !== $originalSha256) {
            $requete
                ->andWhere('m.sha256 = :sha OR m.originalSha256 = :original')
                ->setParameter('sha', $sha256)
                ->setParameter('original', $originalSha256);
        } else {
            $requete->andWhere('m.sha256 = :sha')->setParameter('sha', $sha256);
        }

        return array_values($requete->getQuery()->getResult());
    }

    /**
     * Le portrait actif d'une personne — au plus un, garanti par `uniq_med_portrait_actif`.
     */
    /**
     * Les portraits actifs d'un lot de personnes — une requête pour tout l'arbre.
     *
     * @param list<int> $personIds
     *
     * @return list<Media>
     */
    public function portraitsActifsPour(array $personIds): array
    {
        if ([] === $personIds) {
            return [];
        }

        /** @var list<Media> $resultat */
        $resultat = $this->createQueryBuilder('m')
            ->andWhere('m.subjectKind = :personne')
            ->andWhere('m.subjectId IN (:ids)')
            ->andWhere('m.kind = :portrait')
            ->andWhere('m.isActivePortrait = true')
            ->setParameter('personne', MediaSubject::Personne)
            ->setParameter('ids', $personIds)
            ->setParameter('portrait', MediaKind::Portrait)
            ->getQuery()
            ->getResult();

        return $resultat;
    }

    public function portraitActif(int $personId): ?Media
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.subjectKind = :personne')
            ->andWhere('m.subjectId = :id')
            ->andWhere('m.kind = :portrait')
            ->andWhere('m.isActivePortrait = true')
            ->setParameter('personne', MediaSubject::Personne)
            ->setParameter('id', $personId)
            ->setParameter('portrait', MediaKind::Portrait)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * La file de validation des médias. Sert `idx_med_media_attente (status, created_at)`.
     *
     * @param int|null $deposant `null` pour un administrateur, qui voit toute la file
     *
     * @return list<Media>
     */
    public function fileDAttente(?int $deposant = null, int $limite = 200): array
    {
        $requete = $this->createQueryBuilder('m')
            ->andWhere('m.status = :attente')
            // **Jamais un portrait de compte** (ADR 0013). Il n'entre pas dans le circuit de
            // validation : il ne passe sur une fiche qu'au rattachement du compte. Le montrer
            // ici offrirait de le « valider » hors de ce rattachement — c'est-à-dire de
            // poser un portrait sur une fiche que personne n'a désignée.
            ->andWhere('m.subjectUserId IS NULL')
            ->setParameter('attente', MediaStatus::EnAttente)
            ->addOrderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limite);

        if (null !== $deposant) {
            $requete->andWhere('m.uploadedByUserId = :deposant')->setParameter('deposant', $deposant);
        }

        return array_values($requete->getQuery()->getResult());
    }

    /**
     * Les portraits en attente posés sur un **compte** sans fiche (ADR 0013). Au plus un
     * d'ordinaire — `uniq_med_media_portrait_de_compte` y veille —, mais la méthode rend une
     * liste : c'est ce qui permet au dépôt suivant de tout nettoyer, même si l'index
     * partiel n'existait pas (SQLite de test, base restaurée à la main).
     *
     * @return list<Media>
     */
    public function portraitsEnAttenteDuCompte(int $userId): array
    {
        return array_values($this->createQueryBuilder('m')
            ->andWhere('m.subjectUserId = :compte')
            ->andWhere('m.status = :attente')
            ->setParameter('compte', $userId)
            ->setParameter('attente', MediaStatus::EnAttente)
            ->addOrderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Parmi ces personnes, lesquelles portent **au moins un média actif** ?
     *
     * Le filtre « Avec image » de la barre d'outils du graphe. Une requête pour les 274
     * personnes de l'arbre, et non 274 : le graphe pose la question à chaque affichage,
     * et une boucle sur {@see portraitActif()} y ferait 274 allers-retours pour un
     * booléen.
     *
     * `GROUP BY` plutôt que `DISTINCT` sur la ligne entière : on ne rapatrie qu'une
     * colonne d'entiers, jamais les lignes — la question porte sur l'existence, et rendre
     * les médias ici obligerait l'appelant à les filtrer, donc à savoir les filtrer.
     *
     * @param list<int> $personneIds
     *
     * @return list<int>
     */
    public function personnesAvecMediaActif(array $personneIds): array
    {
        if ([] === $personneIds) {
            return [];
        }

        /** @var list<array{subjectId: int}> $lignes */
        $lignes = $this->createQueryBuilder('m')
            ->select('m.subjectId AS subjectId')
            ->andWhere('m.subjectKind = :personne')
            ->andWhere('m.subjectId IN (:ids)')
            ->andWhere('m.status = :actif')
            ->groupBy('m.subjectId')
            ->setParameter('personne', MediaSubject::Personne)
            ->setParameter('ids', $personneIds)
            ->setParameter('actif', MediaStatus::Actif)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $ligne): int => $ligne['subjectId'], $lignes));
    }

    /**
     * Les médias rattachés à une personne encore à créer. Sert `idx_med_media_brouillon`.
     *
     * C'est la requête de la résolution des références provisoires à la validation
     * (§ 9.5 du modèle) : l'agent du circuit de validation la rappellera pour rattacher
     * les médias à l'identifiant définitif.
     *
     * @return list<Media>
     */
    public function pourBrouillon(int $demandeId, ?string $brouillonRef = null): array
    {
        $requete = $this->createQueryBuilder('m')
            ->andWhere('m.subjectRequestId = :demande')
            ->setParameter('demande', $demandeId)
            ->addOrderBy('m.id', 'ASC');

        if (null !== $brouillonRef) {
            $requete->andWhere('m.subjectDraftRef = :ref')->setParameter('ref', $brouillonRef);
        }

        return array_values($requete->getQuery()->getResult());
    }
}
