<?php

declare(strict_types=1);

namespace App\Module\Core\Contract;

/**
 * L'implémentation **par défaut** de {@see AnnuaireDesMembresInterface} : elle ne connaît
 * personne.
 *
 * Même raisonnement que `Core\Security\CercleFerme`, et le même arbitrage : sans
 * `Membership` branché, la bascule « voir comme un membre » rend `404` et la charge
 * d'administration ne porte pas de `membre`. Des écrans incomplets, jamais une identité
 * inventée.
 *
 * Elle n'avertit pas dans les journaux, à la différence de `CercleFerme` : ne pas
 * connaître les membres n'expose rien, alors que ne pas connaître le cercle ferait taire
 * une règle de confidentialité. Deux absences, deux niveaux de bruit.
 */
final class AnnuaireVide implements AnnuaireDesMembresInterface
{
    public function libelleDuMembre(int $memberId): ?string
    {
        return null;
    }

    public function membreDuCompte(int $userId): ?array
    {
        return null;
    }

    public function userIdDuMembre(int $memberId): ?int
    {
        return null;
    }

    public function personneDuCompte(int $userId): ?int
    {
        return null;
    }

    /**
     * Aucun rattachement, donc aucun blocage — et **c'est la réponse la plus dangereuse
     * de cette classe**.
     *
     * Les trois autres méthodes répondent « je ne connais personne » à des questions
     * d'affichage : le pire qu'elles produisent est un écran incomplet. Celle-ci répond
     * « rien à signaler » à une question de sûreté, et laisserait donc passer la
     * suppression d'une fiche qui sert de racine à un compte.
     *
     * C'est assumé pour la même raison que le reste — le conteneur doit compiler sans
     * `Membership` — et c'est écrit ici pour que celui qui débranche le module sache ce
     * qu'il débranche avec. Sans `Membership`, il n'y a de toute façon ni membre ni
     * invitation à décrocher.
     *
     * @return array{membreId: int|null, membreLibelle: string|null, invitationsActives: int}
     */
    public function rattachementsDeLaPersonne(int $personneId): array
    {
        return ['membreId' => null, 'membreLibelle' => null, 'invitationsActives' => 0];
    }
}
