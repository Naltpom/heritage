<?php

declare(strict_types=1);

namespace App\Module\Evenement\Service;

use App\Module\Evenement\Entity\Invitation;
use App\Module\Proposal\Contract\DependanceDeFiche;
use App\Module\Proposal\Contract\DependanceMesuree;
use App\Module\Proposal\Contract\DependancesDUneFiche;
use App\Module\Proposal\Contract\FicheASupprimer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les invitations d'une fiche à un événement de famille (ADR 0014).
 *
 * - **À venir ou en cours, non annulé** : la suppression est **bloquée**. L'invitation vit
 *   — elle est relancée, elle attend une réponse, son lien personnel s'ouvre —, et
 *   l'organisateur a le geste pour la défaire : « Retirer » dans le suivi des invités.
 * - **Passé ou annulé** : l'invitation **reste**, et la suppression passe. Plus rien ne s'y
 *   écrit (`ServiceDesEvenements::exigerModifiable()`), « on annule, on ne supprime pas »,
 *   et le suivi garde le libellé dénormalisé de l'invité. Effacer la ligne réécrirait
 *   l'histoire de la fête — « 12 invités, 8 présents » deviendrait « 11, 7 » — sans que
 *   personne ne l'ait décidé. `heritage:verifier-integrite` la relève comme tolérée.
 *
 * La frontière est **exactement** celle de `exigerModifiable()` : une invitation qu'on ne
 * peut pas retirer ne doit pas pouvoir bloquer, sinon la fiche ne se supprimerait jamais.
 */
final readonly class DependancesDesEvenements implements DependancesDUneFiche
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function mesurer(FicheASupprimer $fiche): array
    {
        $nombre = (int) $this->em->createQuery(\sprintf(
            'SELECT COUNT(i.id) FROM %s i JOIN i.evenement e WHERE i.personId = :personne AND e.annuleLe IS NULL AND e.fin >= :aujourdhui',
            Invitation::class,
        ))
            ->setParameter('personne', $fiche->personneId)
            ->setParameter('aujourdhui', new \DateTimeImmutable('today'), 'date_immutable')
            ->getSingleScalarResult();

        return $nombre > 0 ? [new DependanceMesuree(DependanceDeFiche::EvenementAVenir, $nombre)] : [];
    }

    /** Rien à effacer : l'invitation à venir bloque, celle d'un événement clos est l'historique. */
    public function detacher(FicheASupprimer $fiche): array
    {
        return [];
    }
}
