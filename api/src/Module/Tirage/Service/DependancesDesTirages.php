<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

use App\Module\Proposal\Contract\DependanceDeFiche;
use App\Module\Proposal\Contract\DependanceMesuree;
use App\Module\Proposal\Contract\DependancesDUneFiche;
use App\Module\Proposal\Contract\FicheASupprimer;
use App\Module\Tirage\Entity\Participant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les tirages au sort où figure une fiche (ADR 0009).
 *
 * - **Participant, ou référent d'un participant** : la suppression est **bloquée**. Un
 *   participant pendant serait tiré au sort, recevrait un cadeau qu'il ne peut pas
 *   recevoir, et aucun écran ne permettrait plus de le retirer — le service cherche le
 *   participant par sa personne. Le geste existe : « Retirer » sur le tirage, à son
 *   organisateur, à son référent ou à lui-même ; il est refusé tant qu'une édition **à
 *   venir** est tirée avec lui, et c'est voulu — quelqu'un attend son cadeau.
 * - **Attributions et présences des éditions passées** : elles **restent**. « Les éditions
 *   passées gardent leurs attributions, qui sont l'historique » (ADR 0009), avec les deux
 *   libellés dénormalisés qui les rendent lisibles sans la fiche, et le secret de chaque
 *   année intact : aucune trace d'effacement n'aurait à nommer qui offrait à qui.
 *   `heritage:verifier-integrite` les relève comme tolérées.
 *
 * Le référent est compté avec le participant : il est lui-même participant du même tirage
 * (`ServiceDesTirages::ajouterParticipant()`), et le retirer décroche ceux qu'il
 * accompagnait (`Tirage::retirerParticipant()`). Le compte est celui des **tirages**, pas
 * des lignes : « participe à 2 tirages » est ce qu'on peut aller défaire.
 */
final readonly class DependancesDesTirages implements DependancesDUneFiche
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function mesurer(FicheASupprimer $fiche): array
    {
        $nombre = (int) $this->em->createQuery(\sprintf(
            'SELECT COUNT(DISTINCT IDENTITY(p.tirage)) FROM %s p WHERE p.personId = :personne OR p.referentPersonId = :personne',
            Participant::class,
        ))->setParameter('personne', $fiche->personneId)->getSingleScalarResult();

        return $nombre > 0 ? [new DependanceMesuree(DependanceDeFiche::Tirage, $nombre)] : [];
    }

    /** Rien à effacer : la participation bloque, et l'historique reste. */
    public function detacher(FicheASupprimer $fiche): array
    {
        return [];
    }
}
