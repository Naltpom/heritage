<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Service\AuditService;
use App\Module\Proposal\Contract\DependanceDeFiche;
use App\Module\Proposal\Contract\DependanceMesuree;
use App\Module\Proposal\Contract\DependancesDUneFiche;
use App\Module\Proposal\Contract\FicheASupprimer;
use App\Module\Question\Entity\Question;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les questions posées au sujet d'une fiche — **nettoyées** avec elle (ADR 0008).
 *
 * C'est la seule dépendance qui part dans la transaction de la suppression, et pour trois
 * raisons qui vont dans le même sens :
 *
 * - **elle n'a de sens que par la fiche.** Une question est une lacune détectée sur une
 *   personne : « quand est-elle née ? ». La personne effacée, la lacune ne sera plus jamais
 *   détectée, et la question ne sera plus jamais servie (`ServiceDesQuestions::servir()`
 *   ne sert que ce qui est encore détecté) — elle resterait en base pour rien, avec le
 *   libellé d'une personne peut-être vivante ;
 * - **elle ne témoigne d'aucune décision.** Ce qu'une réponse a produit vit dans
 *   `prp_change_request`, qui ne se supprime jamais ; le lien `qst_question_demande` n'en
 *   est que le contexte, et il part avec sa question ;
 * - **aucun écran ne la redirigerait.** La signature d'une question contient l'identifiant
 *   de son sujet : la faire pointer sur l'autre fiche fabriquerait une question que la
 *   détection n'a jamais posée.
 *
 * Elle est annoncée d'avance par l'inventaire, inscrite dans la révision avec le reste, et
 * tracée ici au journal d'audit avec les identifiants effacés.
 *
 * Les questions d'une **union** emportée avec la fiche partent aussi : la lacune d'union
 * n'est pas encore livrée, mais le jour où elle le sera, l'oubli serait silencieux.
 */
final readonly class DependancesDesQuestions implements DependancesDUneFiche
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditService $audit,
    ) {
    }

    public function mesurer(FicheASupprimer $fiche): array
    {
        $nombre = \count($this->questionsDe($fiche));

        return $nombre > 0 ? [new DependanceMesuree(DependanceDeFiche::Question, $nombre)] : [];
    }

    /**
     * Les questions sont **chargées puis retirées**, et non effacées par un `DELETE` de
     * masse : une entité restée gérée dans l'unité de travail ressortirait au `flush`
     * suivant — celui du validateur —, la même raison que
     * `Tree\Service\EcritureDeLArbrePourProposal::effacerLesAnnexes()`. Leurs liens vers les
     * demandes partent explicitement, sans s'en remettre au `ON DELETE CASCADE` que SQLite
     * n'applique pas toujours.
     */
    public function detacher(FicheASupprimer $fiche): array
    {
        $questions = $this->questionsDe($fiche);

        if ([] === $questions) {
            return [];
        }

        $identifiants = [];

        foreach ($questions as $question) {
            $identifiants[] = $question->getId();

            foreach ($question->getDemandes() as $lien) {
                $this->em->remove($lien);
            }

            $this->em->remove($question);
        }

        // `enregistrer()` vide l'unité de travail : les retraits ci-dessus partent avec la
        // ligne de journal, dans la transaction de la validation.
        $this->audit->enregistrer(
            AuditAction::Suppression,
            'personne',
            (string) $fiche->personneId,
            null,
            [
                'questions' => ['avant' => $identifiants, 'apres' => null],
                'motif' => ['avant' => null, 'apres' => 'fiche supprimée par une demande validée'],
            ],
        );

        return [new DependanceMesuree(DependanceDeFiche::Question, \count($questions))];
    }

    /** @return list<Question> */
    private function questionsDe(FicheASupprimer $fiche): array
    {
        $requete = $this->em->createQuery(\sprintf(
            'SELECT q FROM %s q WHERE (q.sujetKind = :personne AND q.sujetId = :id) OR (q.sujetKind = :famille AND q.sujetId IN (:familles)) ORDER BY q.id ASC',
            Question::class,
        ))
            ->setParameter('personne', SubjectKind::Personne)
            ->setParameter('id', $fiche->personneId)
            ->setParameter('famille', SubjectKind::Famille)
            // `IN ()` n'est pas du SQL : une liste vide devient `[0]`, identifiant
            // qu'aucune ligne ne porte — le même détour que l'arbre.
            ->setParameter('familles', [] === $fiche->famillesEmportees ? [0] : $fiche->famillesEmportees);

        /** @var list<Question> $questions */
        $questions = $requete->getResult();

        return $questions;
    }
}
