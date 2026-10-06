<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Statistiques\Contract\PersonneDuReleve;
use App\Module\Statistiques\Contract\PortActesDemandes;
use App\Module\Statistiques\Contract\PortActiviteDesDemandes;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Section 5 — **le chantier**, pour l'administration seulement : ce qui reste à faire dans
 * l'arbre, et l'activité de la famille.
 *
 * Servi par sa propre route, sous `/api/administration` (que `security.yaml` réserve déjà à
 * `ROLE_ADMIN`), plutôt que par un drapeau dans la charge des membres : une section
 * « masquée côté écran » serait une section envoyée à tout le monde.
 *
 * ── Les quatre listes, et leurs définitions ─────────────────────────────────────
 *
 * Elles reprennent celles des filtres de l'arbre (`components/arbre/graphe.ts`), pour que
 * le chiffre de cette page et le nombre de fiches que le filtre allume soient les mêmes :
 *
 * - **sans date de naissance** : aucune date, même floue (`qualifier = inconnue`) ;
 * - **sans parents** : enfant d'aucune famille de l'arbre ;
 * - **sans source** : aucune citation (`citation_count = 0`) ;
 * - **non vérifiées** : des citations, aucune qui soit un acte (`best_quay < 2`), hors
 *   personnes vivantes — exactement le filtre « Non vérifiés ».
 *
 * Les listes passent par la même {@see Population} que le reste : l'administrateur voit
 * tout par son cercle complet, et sous la bascule « voir comme un membre » il voit ce que
 * ce membre verrait — les comptes restent entiers, les noms suivent le cercle.
 */
final class ChantierDeLArbre
{
    /** L'activité se lit sur un an glissant. */
    private const int MOIS = 12;

    /** Un membre « actif » s'est connecté dans les trente derniers jours. */
    private const int JOURS_D_ACTIVITE = 30;

    public function __construct(
        private readonly StatistiquesVisibles $statistiques,
        private readonly PortActiviteDesDemandes $demandes,
        private readonly PortActesDemandes $actes,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $horloge,
    ) {
    }

    /** @return array<string, mixed> */
    public function vue(PorteeDesStatistiques $portee = PorteeDesStatistiques::Arbre): array
    {
        $population = $this->statistiques->population($portee);
        $maintenant = $this->horloge->now();

        $listes = [
            'sansDateDeNaissance' => static fn (PersonneDuReleve $p): bool => !$p->naissance->isKnown(),
            'sansParents' => static fn (PersonneDuReleve $p): bool => !$population->aDesParents($p->id),
            'sansSource' => static fn (PersonneDuReleve $p): bool => 0 === $p->citationCount,
            'nonVerifiees' => static fn (PersonneDuReleve $p): bool => $p->citationCount > 0 && ($p->bestQuay ?? -1) < 2 && !$p->vivant,
        ];

        $fiches = [];
        foreach ($listes as $cle => $critere) {
            $fiches[$cle] = $this->liste($population, $critere);
        }

        $depuis = $maintenant->modify('first day of this month')->modify(\sprintf('-%d months', self::MOIS - 1))->setTime(0, 0);

        return [
            'fiches' => $fiches,
            'activite' => [
                'demandesParMois' => array_map(static fn ($m): array => [
                    'mois' => $m->mois,
                    'proposees' => $m->proposees,
                    'validees' => $m->validees,
                ], $this->demandes->parMois($depuis, $maintenant)),
                'demandesEnAttente' => $this->demandes->enAttente(),
                'membres' => $this->membres($maintenant),
                'actesEnAttente' => $this->actes->enAttente(),
            ],
        ];
    }

    /**
     * @param callable(PersonneDuReleve): bool $critere
     *
     * @return array{n: int, personnes: list<array{id: int, nom: string, naissance: int|null}>}
     */
    private function liste(Population $population, callable $critere): array
    {
        $n = 0;
        $personnes = [];

        foreach ($population->personnes as $personne) {
            if (!$critere($personne)) {
                continue;
            }

            ++$n;

            if (!$population->masquee($personne->id)) {
                $personnes[] = [...$population->reference($personne), 'naissance' => DateRetenue::annee($personne->naissance)];
            }
        }

        usort($personnes, static fn (array $a, array $b): int => strnatcasecmp($a['nom'], $b['nom']) ?: $a['id'] <=> $b['id']);

        return ['n' => $n, 'personnes' => $personnes];
    }

    /**
     * Les comptes actifs (statut `actif`) et ceux qui se sont connectés récemment.
     * `Core\Entity\User` est du noyau partagé : la lire ici est licite (ADR 0002, règle 3).
     *
     * @return array{actifs: int, connectesRecemment: int, jours: int}
     */
    private function membres(\DateTimeImmutable $maintenant): array
    {
        $seuil = $maintenant->modify(\sprintf('-%d days', self::JOURS_D_ACTIVITE));

        $actifs = (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.status = :actif')
            ->setParameter('actif', UserStatus::Actif)
            ->getQuery()
            ->getSingleScalarResult();

        $recents = (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.status = :actif')
            ->andWhere('u.lastLoginAt >= :seuil')
            ->setParameter('actif', UserStatus::Actif)
            ->setParameter('seuil', $seuil)
            ->getQuery()
            ->getSingleScalarResult();

        return ['actifs' => $actifs, 'connectesRecemment' => $recents, 'jours' => self::JOURS_D_ACTIVITE];
    }
}
