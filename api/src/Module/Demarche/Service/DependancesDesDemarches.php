<?php

declare(strict_types=1);

namespace App\Module\Demarche\Service;

use App\Module\Demarche\Entity\PersonneConcernee;
use App\Module\Proposal\Contract\DependanceDeFiche;
use App\Module\Proposal\Contract\DependanceMesuree;
use App\Module\Proposal\Contract\DependancesDUneFiche;
use App\Module\Proposal\Contract\FicheASupprimer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les démarches d'acte qui concernent une fiche — **elles bloquent sa suppression**.
 *
 * Une démarche se montre à qui voit **toutes** les personnes qu'elle concerne (ADR 0011),
 * et une personne disparue n'est vue de personne : supprimer la fiche rendrait la démarche
 * invisible à tous, texte des courriers compris, sans que rien ne le dise. La retirer de la
 * démarche ne vaut pas mieux — le texte de la demande d'acte la nomme toujours, et une
 * démarche qui ne concerne plus personne n'est montrée à personne non plus
 * (`ServiceDesDemarches::filtrer()`).
 *
 * Le geste existe : « Corriger » sur la démarche, ouvert à tout membre qui la voit, change
 * ses personnes. Dans le cas réel — un doublon — on y désigne l'autre fiche, puis on
 * supprime celle-ci.
 */
final readonly class DependancesDesDemarches implements DependancesDUneFiche
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function mesurer(FicheASupprimer $fiche): array
    {
        $nombre = (int) $this->em->createQuery(\sprintf(
            'SELECT COUNT(DISTINCT IDENTITY(p.demarche)) FROM %s p WHERE p.personId = :personne',
            PersonneConcernee::class,
        ))->setParameter('personne', $fiche->personneId)->getSingleScalarResult();

        return $nombre > 0 ? [new DependanceMesuree(DependanceDeFiche::Demarche, $nombre)] : [];
    }

    /** Rien à effacer : une démarche bloque, elle ne se nettoie pas. */
    public function detacher(FicheASupprimer $fiche): array
    {
        return [];
    }
}
