<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Entity\User;
use App\Module\Membership\Contract\ComptesARattacher;
use App\Module\Membership\Contract\DeclarationDuCompte;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Repository\MemberRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La réponse de `Membership` au contrat {@see ComptesARattacher} — ce que le module
 * `Assistant` sait des comptes inscrits sans fiche, et le seul chemin par lequel il agit sur
 * eux (ADR 0013).
 *
 * Une traduction, pas une politique : les décisions — qui peut être rattaché, à quoi, avec
 * quels effets — vivent dans {@see ServiceDesMembres}, que l'activation manuelle et
 * « Créer la fiche et rattacher » partagent. Deux chemins d'activation qui dériveraient
 * l'un de l'autre donneraient deux façons d'activer un compte, dont une sans photo, ou sans
 * journal.
 */
final readonly class ComptesARattacherParMembership implements ComptesARattacher
{
    public function __construct(
        private EntityManagerInterface $em,
        private MemberRepository $membres,
        private ServiceDesMembres $service,
    ) {
    }

    public function declaration(int $memberId): ?DeclarationDuCompte
    {
        $membre = $this->aRattacher($memberId);

        if (null === $membre) {
            return null;
        }

        $surname = $membre->getDeclaredSurname();
        $givenNames = $membre->getDeclaredGivenNames();
        $birthDate = $membre->getDeclaredBirthDate();
        $description = $membre->getFamilyDescription();

        // `ck_mbr_member_declaration` ne garantit que l'inverse — pas de déclaration hors
        // d'un compte `a_rattacher` — et une ligne sans déclaration n'a rien à analyser.
        if (null === $surname || null === $givenNames || null === $birthDate) {
            return null;
        }

        // Une description absente n'est plus un trou : depuis le message vocal, la
        // déclaration peut tenir par la voix seule. Le contrat transporte alors un texte
        // vide — l'analyse ne s'appuie plus que sur l'identité, et elle le sait
        // (`aUnMessageVocal`). Le fichier, lui, ne quitte pas ce module.
        $description ??= '';

        $id = $membre->getId();
        \assert(null !== $id);

        return new DeclarationDuCompte(
            memberId: $id,
            userId: $membre->getUserId(),
            email: $membre->getUserEmail(),
            surname: $surname,
            givenNames: $givenNames,
            birthDate: $birthDate,
            description: $description,
            invitationPersonId: $membre->getInvitation()?->getPersonId(),
            suggestion: $membre->getAttachmentSuggestion(),
            suggestionAt: $membre->getAttachmentSuggestionAt(),
            aUnMessageVocal: null !== $membre->getVoiceMessageName(),
        );
    }

    /**
     * ## Relire la ligne avant d'écrire — sous verrou.
     *
     * L'appelant a lu le compte par {@see declaration()} **avant** de lancer l'analyse, qui
     * dure jusqu'à 90 s. Entre-temps, une autre requête a pu le refuser (bouton « Refuser
     * l'inscription », cliqué pendant que l'analyse paresseuse tourne) ou le rattacher. Le
     * `Member` de l'identity map, lui, n'en sait rien : un second `find()` rend la même
     * instance, périmée, et `estARattacher()` y lirait un `en_attente` qui n'est plus vrai.
     *
     * Écrire sur cette foi périmée défaisait la purge du refus — le blob porte le résumé de
     * la description, l'identité déclarée recopiée dans le corps de création et les proches
     * cités, sur un compte suspendu que plus rien ne purgerait — ou, sur un compte rattaché
     * entre-temps, violait `ck_mbr_member_declaration` (une `500` pour l'administrateur).
     *
     * D'où `refresh()` **sous `PESSIMISTIC_WRITE`**, dans une transaction : la ligne est
     * relue de la base, et un refus concurrent attend la fin de l'écriture (PostgreSQL,
     * `SELECT … FOR UPDATE`). La base le redit par `ck_mbr_member_declaration_en_attente`.
     */
    public function enregistrerLaSuggestion(int $memberId, array $suggestion, \DateTimeImmutable $le): bool
    {
        $membre = $this->membres->find($memberId);

        if (null === $membre) {
            return false;
        }

        return $this->em->wrapInTransaction(function () use ($membre, $suggestion, $le): bool {
            $this->em->refresh($membre, LockMode::PESSIMISTIC_WRITE);

            if (!$membre->estARattacher()) {
                return false;
            }

            $membre->enregistrerLaSuggestion($suggestion, $le)->toucher();
            $this->em->flush();

            return true;
        });
    }

    /** La règle vit dans {@see ServiceDesMembres::verrouillerARattacher()}, que l'activation partage. */
    public function verrouillerPourLeRattachement(int $memberId): void
    {
        $this->service->verrouillerARattacher($memberId);
    }

    public function estDejaRattachee(int $personId): bool
    {
        return null !== $this->membres->pourPersonne($personId);
    }

    public function rattacherAFicheCreee(int $memberId, int $personId, string $personLabel, User $administrateur): array
    {
        return $this->service->normaliser(
            $this->service->activerSurFicheCreee($memberId, $personId, $personLabel, $administrateur),
        );
    }

    private function aRattacher(int $memberId): ?Member
    {
        $membre = $this->membres->find($memberId);

        return null !== $membre && $membre->estARattacher() ? $membre : null;
    }
}
