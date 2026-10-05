<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Contract\ArbreDuLecteurInterface;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Serializer\FabriqueDuLecteur;
use App\Module\Tree\Serializer\PersonneDuLecteur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'implémentation, côté `Tree`, de {@see ArbreDuLecteurInterface} : la composante reliée
 * de la fiche du lecteur ({@see PersonneDuLecteur}, bascule « voir comme » comprise), à
 * défaut celle de la racine (`tree.personne_racine_id`).
 *
 * Trois lectures légères (identifiants seulement), au plus une fois par requête. Sans
 * ancre connue — base vide, racine non réglée, compte sans fiche —, rien n'est retiré.
 */
final class ArbreDuLecteur implements ArbreDuLecteurInterface
{
    /** @var array<int, true>|false|null `false` : calculé, pas d'arbre à désigner */
    private array|false|null $composante = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FabriqueDuLecteur $lecteurs,
        private readonly PersonneDuLecteur $pointDeVue,
        private readonly ReglagesDeLArbre $reglages,
    ) {
    }

    public function filtrer(array $personIds, bool $toutLaBase = false): array
    {
        $composante = $toutLaBase ? false : $this->composante();

        if (false === $composante) {
            return array_values($personIds);
        }

        return array_values(array_filter($personIds, static fn (int $id): bool => isset($composante[$id])));
    }

    /** @return array<int, true>|false */
    private function composante(): array|false
    {
        if (null !== $this->composante) {
            return $this->composante;
        }

        $connexion = $this->em->getConnection();
        $table = fn (string $entite): string => $this->em->getClassMetadata($entite)->getTableName();

        /** @var list<int> $personnes */
        $personnes = array_map('intval', $connexion->fetchFirstColumn(\sprintf('SELECT id FROM %s', $table(Person::class))));

        $familles = [];
        foreach ($connexion->fetchAllAssociative(\sprintf('SELECT id, husband_id, wife_id FROM %s', $table(Family::class))) as $ligne) {
            $familles[(int) $ligne['id']] = [
                'mari' => null !== $ligne['husband_id'] ? (int) $ligne['husband_id'] : null,
                'epouse' => null !== $ligne['wife_id'] ? (int) $ligne['wife_id'] : null,
                'enfants' => [],
            ];
        }
        foreach ($connexion->fetchAllAssociative(\sprintf('SELECT family_id, person_id FROM %s', $table(Child::class))) as $ligne) {
            if (isset($familles[(int) $ligne['family_id']])) {
                $familles[(int) $ligne['family_id']]['enfants'][] = (int) $ligne['person_id'];
            }
        }

        foreach ([$this->pointDeVue->pour($this->lecteurs->courant()), $this->reglages->personneRacineId()] as $ancre) {
            if (null !== $ancre && $ancre > 0) {
                $composante = ComposanteReliee::de($ancre, $personnes, array_values($familles));
                if (null !== $composante) {
                    return $this->composante = $composante;
                }
            }
        }

        return $this->composante = false;
    }
}
