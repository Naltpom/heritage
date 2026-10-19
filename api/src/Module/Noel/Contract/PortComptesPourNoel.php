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
     * Le compte `userId`, tout juste créé **en attente**, devient un membre avec l'identité
     * qu'il a déclarée. Si elle désigne **sans ambiguïté** une fiche vivante et libre de
     * l'arbre — même nom, un prénom en commun, la date de naissance au jour près —, il y
     * est rattaché et activé d'office ; sinon il est « à rattacher » et les
     * administrateurs relient sa fiche par le parcours habituel. Les administrateurs sont
     * prévenus dans les deux cas. Dans la transaction de l'appelant.
     *
     * @return bool `true` si le compte a été rattaché et activé : l'appelant passe alors le
     *              compte `actif`
     */
    public function declarerUnCompte(
        int $userId,
        string $email,
        string $nomDeNaissance,
        string $prenom,
        \DateTimeImmutable $naissance,
    ): bool;

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
