<?php

declare(strict_types=1);

namespace App\Module\Noel\Contract;

/**
 * Ce que `Noel` demande à `Membership` : déclarer un compte né sur le site de Noël, et
 * savoir qui sont les comptes d'un tirage.
 *
 * `Membership` l'implémente (`Membership\Service\ComptesPourNoel`). Le contrat ne
 * transporte que des identifiants, des chaînes et des dates (ADR 0002).
 */
interface PortComptesPourNoel
{
    /**
     * Le compte `userId`, tout juste créé **en attente**, devient un membre « à rattacher »
     * avec l'identité qu'il a déclarée ; les administrateurs en sont prévenus et le
     * relient à sa fiche par le parcours habituel. Dans la transaction de l'appelant.
     */
    public function declarerUnCompteEnAttente(
        int $userId,
        string $email,
        string $nomDeNaissance,
        string $prenom,
        \DateTimeImmutable $naissance,
    ): void;

    /**
     * L'identité de chaque compte demandé qui a un membre : la déclaration du compte, ou à
     * défaut la fiche à laquelle il est rattaché.
     *
     * @param list<int> $userIds
     *
     * @return array<int, IdentiteDuCompte> indexé par identifiant de compte
     */
    public function identites(array $userIds): array;
}
