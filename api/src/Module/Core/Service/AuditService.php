<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\AuditLog;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\AuditAction;
use App\Module\Core\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Écriture et lecture du **journal d'audit**.
 *
 * Deux voies alimentent `core_audit_log`, et elles ne font pas le même travail :
 *
 * 1. {@see \App\Module\Core\EventSubscriber\AuditDoctrineSubscriber} trace
 *    **mécaniquement** toute création, modification et suppression d'entité. On ne peut
 *    pas l'oublier : elle ne dépend d'aucun appel.
 * 2. `enregistrer()` trace les **décisions** — validation, refus, invitation, changement
 *    de rôle, réglage modifié, bascule « voir comme un membre » (§ 8 du dossier de
 *    mission). Une décision n'est pas déductible d'un diff de colonnes : refuser une
 *    demande et la retirer touchent la même colonne `status`.
 *
 * Les deux voies coexistent volontairement : un `PATCH` de rôle laisse une ligne
 * `role_modifie` (la décision, filtrable à l'écran) *et* une ligne `modification` (le
 * diff exact, qu'aucune relecture n'a pu omettre). L'écran de journal filtre par
 * `action` ; on préfère une ligne de trop à un trou.
 *
 * **Rien ici ne met à jour ni ne supprime.** L'inaltérabilité côté applicatif tient à
 * cette absence, pas à une permission de base de données : aucune méthode d'écriture
 * autre que l'insertion n'existe, et aucune route n'en expose (§ 2.4 du modèle).
 */
final class AuditService
{
    /** Plafond dur de `parPage`, § 1.5 du contrat d'API. */
    public const int PAR_PAGE_MAX = 100;

    public const int PAR_PAGE_DEFAUT = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogRepository $depot,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    /**
     * Consigne une décision. `$acteur` à `null` = on prend l'utilisateur de la session
     * courante ; il reste `null` pour les actions système (commande en console).
     *
     * @param array<string, mixed>|null $changes
     */
    public function enregistrer(
        AuditAction $action,
        string $subjectType,
        ?string $subjectId = null,
        ?string $subjectLabel = null,
        ?array $changes = null,
        ?User $acteur = null,
    ): AuditLog {
        // L'incarnant n'est posé que pour l'acteur **de la session** : un acteur passé
        // explicitement (une commande console, un amorçage) n'agit sous personne.
        $incarnant = null === $acteur ? $this->incarnant() : null;
        $acteur ??= $this->utilisateurCourant();
        $requete = $this->requestStack->getCurrentRequest();

        $journal = new AuditLog(
            $action,
            $subjectType,
            $subjectId,
            $subjectLabel,
            $changes,
            $acteur?->getId(),
            $acteur?->getEmail(),
            // L'IP vient du proxy de confiance, pas de REMOTE_ADDR : `trusted_proxies`
            // est posé dans `config/packages/framework.yaml` (§ 2.4 du modèle).
            $requete?->getClientIp(),
            $this->tronquer($requete?->headers->get('User-Agent')),
            $incarnant?->getId(),
            $incarnant?->getEmail(),
        );

        $this->em->persist($journal);
        $this->em->flush();

        return $journal;
    }

    /**
     * La liste paginée de `GET /api/administration/journal`, du plus récent au plus
     * ancien (`idx_core_audit_occurred_at`).
     *
     * @param array{action?: string|null, actorUserId?: int|null, subjectType?: string|null, subjectId?: string|null} $filtres
     *
     * @return array{elements: list<array<string, mixed>>, total: int, page: int, parPage: int}
     */
    public function lister(array $filtres = [], int $page = 1, int $parPage = self::PAR_PAGE_DEFAUT): array
    {
        $page = max(1, $page);
        // « Au-delà, la valeur est ramenée à 100 sans erreur » (§ 1.5) : un plafond qui
        // lève une 422 apprend à l'appelant où est la limite ; un plafond qui rabote ne
        // lui apprend rien et sert le même résultat.
        $parPage = min(self::PAR_PAGE_MAX, max(1, $parPage));

        $qb = $this->depot->createQueryBuilder('a');

        foreach ($this->clausesDeFiltre($filtres) as $champ => $valeur) {
            $qb->andWhere(\sprintf('a.%s = :%s', $champ, $champ))->setParameter($champ, $valeur);
        }

        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        /** @var list<AuditLog> $lignes */
        $lignes = $qb
            ->orderBy('a.occurredAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(($page - 1) * $parPage)
            ->setMaxResults($parPage)
            ->getQuery()
            ->getResult();

        return [
            'elements' => array_map($this->normaliser(...), $lignes),
            'total' => $total,
            'page' => $page,
            'parPage' => $parPage,
        ];
    }

    /**
     * `changes` est rendu **tel qu'il est en base** : les champs sensibles y sont déjà
     * `"***"`, masqués à l'écriture. Un journal qu'on filtre à la lecture est un journal
     * qui contient encore le secret (§ 10.3 du contrat).
     *
     * @return array<string, mixed>
     */
    public function normaliser(AuditLog $ligne): array
    {
        return [
            'id' => $ligne->getId(),
            'occurredAt' => $ligne->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'actorUserId' => $ligne->getActorUserId(),
            'actorEmail' => $ligne->getActorEmail(),
            'action' => $ligne->getAction()->value,
            'subjectType' => $ligne->getSubjectType(),
            'subjectId' => $ligne->getSubjectId(),
            'subjectLabel' => $ligne->getSubjectLabel(),
            'changes' => $ligne->getChanges(),
            'ip' => $ligne->getIp(),
            'userAgent' => $ligne->getUserAgent(),
            // L'administrateur qui incarnait l'acteur, ou `null` (ADR 0010).
            'impersonatorUserId' => $ligne->getImpersonatorUserId(),
            'impersonatorEmail' => $ligne->getImpersonatorEmail(),
        ];
    }

    /**
     * @param array{action?: string|null, actorUserId?: int|null, subjectType?: string|null, subjectId?: string|null} $filtres
     *
     * @return array<string, mixed>
     */
    private function clausesDeFiltre(array $filtres): array
    {
        $clauses = [];

        // Une valeur d'action inconnue n'est pas une erreur : c'est un filtre qui ne
        // ramène rien. On ne renseigne pas l'appelant sur les actions qui existent.
        if (isset($filtres['action']) && null !== $action = AuditAction::tryFrom($filtres['action'])) {
            $clauses['action'] = $action;
        }
        foreach (['actorUserId', 'subjectType', 'subjectId'] as $champ) {
            if (isset($filtres[$champ]) && '' !== $filtres[$champ]) {
                $clauses[$champ] = $filtres[$champ];
            }
        }

        return $clauses;
    }

    private function utilisateurCourant(): ?User
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof User ? $utilisateur : null;
    }

    /** Le compte réel derrière une incarnation en cours, ou `null`. */
    private function incarnant(): ?User
    {
        $jeton = $this->security->getToken();

        if (!$jeton instanceof SwitchUserToken) {
            return null;
        }

        $original = $jeton->getOriginalToken()->getUser();

        return $original instanceof User ? $original : null;
    }

    /** `user_agent` est un `VARCHAR(255)` : on tronque plutôt que d'échouer l'insertion. */
    private function tronquer(?string $valeur): ?string
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }

        return mb_substr($valeur, 0, 255);
    }
}
