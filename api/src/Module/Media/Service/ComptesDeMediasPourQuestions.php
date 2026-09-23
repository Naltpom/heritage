<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Question\Contract\PortMediasPourQuestions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'adaptateur de `Media` pour le module `Question` (ADR 0008) : combien de médias
 * visent chaque personne.
 *
 * C'est la donnée de la lacune « cette personne n'a aucune photo », et `Tree` ne peut pas
 * la fournir — `med_media` appartient à ce module (règle 5 de l'ADR 0002).
 *
 * ## Un `GROUP BY`, et pas 274 requêtes
 *
 * `Proposal` pose déjà la même question **par personne**
 * ({@see \App\Module\Proposal\Service\Port\PortMedias::compterLesMediasDe()}), et c'est
 * le bon geste là-bas : il contrôle une suppression, une à la fois. Ici la détection
 * balaie l'arbre entier à chaque ouverture de la page ; une boucle sur la méthode
 * unitaire ferait 274 allers-retours pour produire ce qu'une agrégation rend d'un coup.
 *
 * ## Tous les statuts sont comptés, et c'est le point à ne pas « corriger »
 *
 * Y compris `en_attente` et `refuse`. Le motif est le même que chez `Proposal`, vu de
 * l'autre côté : **une photo déposée hier et pas encore validée est une photo.** La
 * compter pour zéro reposerait la question à celui qui vient d'y répondre — et
 * redemander à quelqu'un ce qu'il vient de donner est la façon la plus sûre de le perdre.
 *
 * `refuse` est le cas qui se discute, et il est tranché du même côté : un média refusé a
 * été **regardé** par l'administrateur. Reposer la question reviendrait à demander à la
 * famille de redéposer ce qu'on vient d'écarter.
 *
 * ## Les brouillons n'y sont pas
 *
 * Un média qui vise une personne **proposée** porte `subject_request_id` +
 * `subject_draft_ref` et **pas** `subject_id` (§ 6.1 du modèle) : il ne peut donc pas
 * compter pour une fiche de l'arbre, puisqu'elle n'existe pas encore. Le `WHERE
 * subject_id IS NOT NULL` est implicite dans l'agrégation, et c'est juste.
 */
final class ComptesDeMediasPourQuestions implements PortMediasPourQuestions
{
    /** @var array<int, int>|null mémorisé pour la durée de la requête */
    private ?array $memoire = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function comptesParPersonne(): array
    {
        return $this->memoire ??= $this->charger();
    }

    /**
     * @return array<int, int>
     */
    private function charger(): array
    {
        /** @var list<array{sujet: int, total: int}> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('m.subjectId AS sujet', 'COUNT(m.id) AS total')
            ->from(Media::class, 'm')
            ->andWhere('m.subjectKind = :personne')
            ->andWhere('m.subjectId IS NOT NULL')
            ->setParameter('personne', MediaSubject::Personne)
            ->groupBy('m.subjectId')
            ->getQuery()
            ->getArrayResult();

        $comptes = [];

        foreach ($lignes as $ligne) {
            $comptes[(int) $ligne['sujet']] = (int) $ligne['total'];
        }

        return $comptes;
    }
}
