<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Entity\Member;
use App\Module\Membership\Repository\MemberRepository;
use App\Module\Membership\Service\Port\PortArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use App\Module\Noel\Contract\IdentiteDuCompte;
use App\Module\Noel\Contract\PortComptesPourNoel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le côté `Membership` du contrat `Noel\Contract\PortComptesPourNoel`.
 *
 * Un compte né sur le site de Noël devient un membre **à rattacher**, exactement comme
 * une inscription sans fiche : même fabrique ({@see Member::aRattacher()}), même avis aux
 * administrateurs, même onglet « À rattacher » pour le relier à sa fiche — simplement
 * sans invitation (`invitation_id` est nullable).
 *
 * L'identité rendue est la **déclaration** du compte quand il en a une ; sinon la fiche à
 * laquelle il est rattaché (son prénom, son nom, son année de naissance). L'arbre n'est
 * ouvert que si un compte en a besoin, et une seule fois.
 */
final class ComptesPourNoel implements PortComptesPourNoel
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MemberRepository $membres,
        private readonly NotificationDesAdministrateurs $notifications,
        private readonly PortArbre $arbre,
    ) {
    }

    public function declarerUnCompteEnAttente(
        int $userId,
        string $email,
        string $nomDeNaissance,
        string $prenom,
        \DateTimeImmutable $naissance,
    ): void {
        $membre = Member::aRattacher($userId, $email, $nomDeNaissance, $prenom, $naissance, 'Inscription depuis le site du tirage de Noël (noel2026).');
        $this->em->persist($membre);
        $this->em->flush();

        $this->notifications->compteARattacher($membre);
    }

    public function identites(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<Member> $membres */
        $membres = $this->membres->findBy(['userId' => $userIds]);
        $vue = null;
        $identites = [];

        foreach ($membres as $membre) {
            $prenom = $membre->getDeclaredGivenNames();
            $nom = $membre->getDeclaredSurname();
            $naissance = $membre->getDeclaredBirthDate();
            $annee = null;
            $personId = $membre->getPersonId();

            if (null !== $personId && (null === $prenom || null === $nom || null === $naissance)) {
                $vue ??= $this->vue();
                $personne = $vue->personne($personId);

                if (null !== $personne) {
                    $prenom ??= trim(explode(' ', trim($personne->prenoms))[0]);
                    $nom ??= $personne->nom;
                    $annee = $personne->anneeNaissance;
                } elseif (null !== $membre->getPersonLabel()) {
                    $mots = explode(' ', trim($membre->getPersonLabel()), 2);
                    $prenom ??= $mots[0];
                    $nom ??= $mots[1] ?? '';
                }
            }

            if (null === $prenom || '' === $prenom) {
                continue;
            }

            $identites[$membre->getUserId()] = new IdentiteDuCompte(
                $membre->getUserId(),
                $prenom,
                $nom ?? '',
                $naissance,
                $annee,
                $personId,
            );
        }

        return $identites;
    }

    private function vue(): VueDeLArbre
    {
        try {
            return $this->arbre->vue();
        } catch (\Throwable) {
            return VueDeLArbre::vide();
        }
    }
}
