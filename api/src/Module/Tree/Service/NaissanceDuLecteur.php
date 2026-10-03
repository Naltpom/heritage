<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Security\ReciprociteInterface;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Person;

/**
 * La naissance d'une personne **telle que le lecteur courant a le droit de la voir** — la
 * réciprocité des vivants (ADR 0016) pour les adaptateurs de `Tree` qui ne passent pas par
 * {@see \App\Module\Tree\Serializer\CercleDuLecteur} (l'assistant, sa consultation).
 *
 * Un défunt : sa date. Un vivant : la date **dégradée** aux composantes permises, ou une
 * date inconnue sans l'année. Le lieu, lui, reste.
 */
final readonly class NaissanceDuLecteur
{
    public function __construct(private ReciprociteInterface $reciprocite)
    {
    }

    public function pour(Person $personne, bool $vivante): UncertainDate
    {
        $naissance = $personne->getBirth();
        $id = $personne->getId();

        if (!$vivante || null === $id) {
            return $naissance;
        }

        $permises = $this->reciprocite->naissance($id);

        return $permises->estComplete()
            ? $naissance
            : ($naissance->reduite($permises) ?? UncertainDate::inconnue($naissance->getPlace()));
    }
}
