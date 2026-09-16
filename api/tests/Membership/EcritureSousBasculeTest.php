<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Membership\Enum\MemberStatus;

/**
 * « **Toute route d'écriture rend `409 vue_comme_membre_active`** tant que la bascule est
 * active — création de demande, validation, refus, **invitation**, téléversement,
 * réglages » (§ 10.4, règle 2).
 *
 * Le motif est celui de la traçabilité : `mbr_invitation.created_by_user_id` porterait
 * l'administrateur alors que l'écran montrait autre chose, et le journal d'audit
 * deviendrait ininterprétable. « Le front désactive les boutons ; l'API ne compte pas
 * dessus. »
 *
 * La garde elle-même vit dans `Core\Service\BasculeVoirComme` — c'est lui qui **écrit** la
 * clé de session, donc lui qui sait la lire. Ce fichier vérifie que les deux routes
 * d'écriture de `Membership` l'appellent bien.
 */
final class EcritureSousBasculeTest extends CasDeTestMembership
{
    public function testCreerUneInvitationSousBasculeEstRefuse(): void
    {
        $this->activerLaBascule();

        $this->requete('POST', '/api/administration/invitations', ['personId' => ArbreFamilial::COUSIN]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());

        $corps = $this->charge();
        self::assertIsArray($corps['erreur']);
        self::assertSame('vue_comme_membre_active', $corps['erreur']['code']);
    }

    public function testActiverUnMembreSousBasculeEstRefuse(): void
    {
        $membre = $this->activerLaBascule();

        $this->requete('POST', \sprintf('/api/administration/membres/%d/activer', $membre));

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('vue_comme_membre_active', $this->charge()['erreur']['code']);
    }

    /**
     * **La lecture, elle, reste ouverte** — et c'est tout l'intérêt de la bascule : on
     * explore l'arbre sous une autre identité. Seules les écritures sont fermées.
     */
    public function testLaLectureResteOuverteSousBascule(): void
    {
        $this->activerLaBascule();

        $this->requete('GET', '/api/administration/invitations');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Ouvre une session d'administrateur, pose un membre à emprunter, active la bascule, et
     * rend l'identifiant du membre emprunté.
     */
    private function activerLaBascule(): int
    {
        $this->creerUtilisateur('admin@example.net', ['ROLE_ADMIN']);
        $cible = $this->creerUtilisateur('cobalt@example.net');
        $membre = $this->creerMembre($cible, ArbreFamilial::EGO, 'Natron Romarin', MemberStatus::Actif);

        $this->connexion('admin@example.net');

        $identifiant = $membre->getId();
        \assert(null !== $identifiant);

        // On passe par la vraie route de `Core` plutôt que par son service : la bascule vit
        // dans la **session**, et une session ne s'écrit pas hors d'une requête. C'est
        // aussi ce qui rend ce test honnête — il exerce le même chemin que l'écran
        // d'administration, cookie compris.
        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $identifiant]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'La bascule doit s’activer.');

        return $identifiant;
    }
}
