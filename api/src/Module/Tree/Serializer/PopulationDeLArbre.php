<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Tree\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les identifiants de **toutes** les personnes de l'arbre validé, triés.
 *
 * Elle existe pour une seule raison, et il vaut mieux la connaître avant d'être tenté de
 * lui trouver d'autres usages : {@see CercleDuLecteur::empreinte()} a besoin d'un
 * **domaine** à soumettre au cercle. Le contrat du noyau
 * ({@see \App\Module\Core\Security\CercleDeVisibiliteInterface}) ne sait pas énumérer un
 * cercle — il répond « celui-ci, oui ou non » et « parmi ceux-ci, lesquels » —, et c'est
 * délibéré : il ne rend jamais une liste que personne ne lui a demandé de rendre. Pour
 * obtenir le cercle effectif, il faut donc lui présenter la population.
 *
 * ## Pourquoi `Tree` lit sa propre table plutôt que le port de `Membership`
 *
 * `Membership\Service\Port\PortArbre` charge exactement les mêmes identifiants, il est
 * mémorisé pour la requête, et le réflexe serait de le réutiliser pour ne pas payer une
 * seconde lecture. Ce serait prendre la dépendance **à l'envers** : ce port est ce que
 * `Tree` **fournit** à `Membership` (§ 0.4 du modèle — les tables `tree_*` ne se lisent
 * que par leur propriétaire). Un module qui relit ses propres données par le port qu'il
 * expose à un autre se retrouve, le jour où ce port est remplacé par un double en test ou
 * par un adaptateur dégradé, à lire un arbre qui n'est pas le sien. C'est exactement ce
 * qui se produirait ici : `config/services/membership.yaml` remplace `PortArbre` sous
 * `when@test` par un arbre en mémoire vide.
 *
 * ## Le coût, mesuré et assumé
 *
 * Une requête, une colonne, 274 entiers. Elle s'ajoute au chemin du `304`, qui n'en
 * faisait aucune : c'est le prix de la correction de la fuite, et il est à comparer à ce
 * que le cercle coûte déjà de l'autre côté du contrat — la résolution du cercle charge
 * l'arbre entier et la met en cache pour 24 h (§ 9.3 du modèle).
 *
 * L'ordre est posé par le `ORDER BY` et non par un `sort()` d'appelant : l'empreinte du
 * cercle est un haché de liste, et une liste dont l'ordre dépend du moteur donnerait deux
 * `ETag` différents pour un même cercle à chaque `VACUUM`.
 */
final class PopulationDeLArbre
{
    /**
     * Mémorisée pour la durée de la requête. Le graphe n'en demande qu'une, mais rien
     * n'interdit à une seconde route de composer un `ETag` un jour.
     *
     * @var list<int>|null
     */
    private ?array $memoire = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<int> croissants, sans doublon */
    public function identifiants(): array
    {
        return $this->memoire ??= $this->charger();
    }

    /** @return list<int> */
    private function charger(): array
    {
        /** @var list<mixed> $colonne */
        $colonne = $this->em->createQueryBuilder()
            ->select('p.id')
            ->from(Person::class, 'p')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(
            // SQLite rend des chaînes là où PostgreSQL rend des entiers. Le haché porte
            // sur la concaténation : sans cette normalisation, les deux moteurs
            // produiraient le même cercle et deux empreintes différentes.
            static fn (mixed $id): int => (int) (\is_scalar($id) ? $id : 0),
            $colonne,
        );
    }
}
