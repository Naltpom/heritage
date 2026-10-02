<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Statistiques\Contract\DemandesDuMois;
use App\Module\Statistiques\Contract\PortActiviteDesDemandes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'implémentation, côté `Proposal`, de {@see PortActiviteDesDemandes} : le graphique
 * d'activité du chantier (Explorer › Stats, administration seulement).
 *
 * - **proposée** : déposée (`submitted_at`) ce mois-là — un brouillon jamais envoyé n'a
 *   rien proposé à personne ;
 * - **validée** : décidée (`decided_at`) ce mois-là avec le statut « validée », les
 *   validations automatiques comprises (elles sont des demandes validées comme les autres).
 *
 * Les dates sont lues en colonnes et rangées en PHP plutôt que par un `GROUP BY` sur une
 * fonction de date : SQLite (les tests) et PostgreSQL (la production) n'ont pas la même,
 * et le volume — quelques centaines de demandes — ne justifie pas deux dialectes.
 */
final class ActiviteDesDemandesPourStatistiques implements PortActiviteDesDemandes
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function parMois(\DateTimeImmutable $depuis, \DateTimeImmutable $jusqua): array
    {
        $debut = $depuis->modify('first day of this month')->setTime(0, 0);
        $mois = [];

        for ($courant = $debut; $courant <= $jusqua; $courant = $courant->modify('+1 month')) {
            $mois[$courant->format('Y-m')] = ['proposees' => 0, 'validees' => 0];
        }

        /** @var list<array{depose: \DateTimeImmutable|null, decide: \DateTimeImmutable|null, statut: RequestStatus}> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('d.submittedAt AS depose', 'd.decidedAt AS decide', 'd.status AS statut')
            ->from(ChangeRequest::class, 'd')
            ->where('d.submittedAt >= :debut OR d.decidedAt >= :debut')
            ->setParameter('debut', $debut)
            ->getQuery()
            ->getResult();

        foreach ($lignes as $ligne) {
            $depose = $ligne['depose']?->format('Y-m');
            if (null !== $depose && isset($mois[$depose])) {
                ++$mois[$depose]['proposees'];
            }

            $decide = $ligne['decide']?->format('Y-m');
            if (RequestStatus::Validee === $ligne['statut'] && null !== $decide && isset($mois[$decide])) {
                ++$mois[$decide]['validees'];
            }
        }

        $releve = [];
        foreach ($mois as $cle => $compte) {
            $releve[] = new DemandesDuMois((string) $cle, $compte['proposees'], $compte['validees']);
        }

        return $releve;
    }

    public function enAttente(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(ChangeRequest::class, 'd')
            ->where('d.status = :attente')
            ->setParameter('attente', RequestStatus::EnAttente)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
