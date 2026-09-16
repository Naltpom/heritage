<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Entity\Invitation;

/**
 * Le résultat d'une création d'invitation — **le seul objet du produit qui porte un jeton
 * en clair**.
 *
 * Il ne vit que le temps d'une requête, entre le service et le normaliseur : il n'est ni
 * persisté, ni mis en cache, ni journalisé. C'est un type distinct de l'entité, et pas une
 * commodité : `Invitation` ne peut pas porter `lien`, sinon quelqu'un finirait par le
 * sérialiser depuis la liste d'administration — où le § 3.2 exige que la clé soit
 * **absente**, pas `null`.
 */
final readonly class InvitationCreee
{
    /**
     * @param string       $lien           l'URL absolue `/inscription/{jeton}`, affichée une
     *                                     seule fois et jamais rejouable
     * @param list<string> $avertissements vide si tout s'est bien passé ; sinon la raison
     *                                     pour laquelle le courriel n'est pas parti —
     *                                     l'invitation, elle, existe de toute façon (§ 3.1)
     */
    public function __construct(
        public Invitation $invitation,
        public string $lien,
        public bool $courrielEnvoye,
        public array $avertissements = [],
    ) {
    }
}
