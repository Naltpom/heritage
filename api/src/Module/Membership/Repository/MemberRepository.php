<?php

declare(strict_types=1);

namespace App\Module\Membership\Repository;

use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MemberStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de {@see Member}.
 *
 * Les deux premières méthodes servent les deux contraintes d'unicité du § 4.2 du modèle
 * — « un compte est rattaché à **une** personne », « une personne ne peut être réclamée
 * que par **un** compte ». Elles ne les **garantissent** pas : c'est le rôle de
 * `uniq_mbr_member_user` et `uniq_mbr_member_person`, et c'est écrit ainsi dans le modèle
 * (« la base le garantit, pas le code »). Elles servent à rendre un `409` lisible plutôt
 * qu'une violation de contrainte.
 *
 * @extends ServiceEntityRepository<Member>
 */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    /** Le rattachement d'un compte, ou `null` — le compte d'amorçage créé en console n'en a pas. */
    public function pourUtilisateur(int $userId): ?Member
    {
        return $this->findOneBy(['userId' => $userId]);
    }

    /**
     * « Cette personne est-elle déjà réclamée ? » — posée avant de créer une invitation
     * (§ 3.1 du contrat, `409 personne_deja_rattachee`) et de nouveau à l'étape 2 de
     * l'inscription, parce qu'un second candidat a pu passer entre-temps.
     */
    public function pourPersonne(int $personId): ?Member
    {
        return $this->findOneBy(['personId' => $personId]);
    }

    /**
     * La file d'activation de l'administrateur — servie par `idx_mbr_member_status`.
     *
     * @return array<int, Member>
     */
    public function enAttente(): array
    {
        return $this->findBy(['status' => MemberStatus::EnAttente], ['createdAt' => 'ASC']);
    }
}
