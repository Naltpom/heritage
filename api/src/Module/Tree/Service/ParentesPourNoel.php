<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Noel\Contract\PortParentesPourNoel;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Serializer\ParenteDuLecteur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le côté `Tree` du contrat `Noel\Contract\PortParentesPourNoel` : {@see ParenteDuLecteur}
 * depuis la fiche du lecteur — celle d'un membre **actif** seulement, pour la même raison
 * que sur la fiche : une parenté calculée depuis un rattachement non validé serait une
 * affirmation que personne n'a approuvée.
 *
 * Les conjoints se lisent comme pour `Tirage` ({@see VueDeLArbrePourTirages::couples()}) :
 * une union divorcée ne compte pas.
 */
final readonly class ParentesPourNoel implements PortParentesPourNoel
{
    public function __construct(
        private AnnuaireDesMembresInterface $membres,
        private ParenteDuLecteur $parentes,
        private EntityManagerInterface $em,
    ) {
    }

    public function liens(int $lecteurUserId, array $fiches): array
    {
        $ego = [] === $fiches ? null : $this->membres->personneDuCompte($lecteurUserId);

        if (null === $ego) {
            return [];
        }

        $liens = [];

        foreach ($fiches as $userId => $personId) {
            $lien = $personId === $ego ? null : $this->parentes->depuis($ego, $personId);

            if (null !== $lien) {
                $liens[$userId] = $lien;
            }
        }

        return $liens;
    }

    public function conjoints(int $lecteurUserId, array $fiches): array
    {
        $ego = [] === $fiches ? null : $this->membres->personneDuCompte($lecteurUserId);

        if (null === $ego) {
            return [];
        }

        /** @var list<array{mari: int|string|null, epouse: int|string|null}> $unions */
        $unions = $this->em->createQueryBuilder()
            ->select('IDENTITY(f.husband) AS mari', 'IDENTITY(f.wife) AS epouse')
            ->from(Family::class, 'f')
            ->where('f.husband = :ego OR f.wife = :ego')
            ->andWhere('f.divorced = false')
            ->setParameter('ego', $ego)
            ->getQuery()
            ->getArrayResult();

        $unis = [];
        foreach ($unions as $union) {
            foreach ([$union['mari'], $union['epouse']] as $personId) {
                if (null !== $personId && (int) $personId !== $ego) {
                    $unis[(int) $personId] = true;
                }
            }
        }

        return array_keys(array_filter($fiches, static fn (int $personId): bool => isset($unis[$personId])));
    }
}
