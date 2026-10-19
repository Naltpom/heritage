<?php

declare(strict_types=1);

namespace App\Tests\Evenement;

use App\Module\Core\Entity\Notification;
use App\Module\Core\Enum\NotificationKind;
use App\Module\Evenement\Entity\Invitation;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le socle des tests du module `Evenement` : **l'arbre, les comptes et le cercle** de
 * {@see CasDeTestDuCercle}, tels quels, et les outils propres aux événements.
 *
 * Il faut exactement ce qu'il sème — des vivants avec et sans compte, un défunt, une voisine
 * confidentielle hors du cercle d'`ego`, et le **vrai** cercle de `Membership`. Le rappel,
 * pour lire les tests :
 *
 * | personne | compte | rôle ici |
 * |---|---|---|
 * | `ego` | Opale | l'organisateur, qui s'invite lui-même |
 * | `conjointe` | Jaspe | invitée avec un compte |
 * | `cousin` | Onyx | invité avec un compte |
 * | `oncle` | — | invité **sans compte** : il répond par son lien |
 * | `enfant` | — | invitée sans compte |
 * | `tante` | Ambre | **pas** invitée |
 * | `voisine` | — | vivante, confidentielle, hors du cercle d'`ego` |
 * | `aieule` | — | décédée |
 *
 * Les dates sont **l'été de l'an prochain** : l'événement est à venir quelle que soit la date
 * du jour où la suite tourne.
 */
abstract class CasDeTestEvenements extends CasDeTestDuCercle
{
    protected int $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->annee = (int) date('Y') + 1;
    }

    /**
     * « Cousinade » : ego, conjointe, cousin, oncle et enfant — trois comptes, deux sans.
     *
     * @param list<string>|null $invites
     */
    protected function cousinade(?array $invites = null): int
    {
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', '/api/evenements', $this->annonce() + ['invites' => array_map(
            fn (string $cle): int => $this->idDe($cle),
            $invites ?? ['ego', 'conjointe', 'cousin', 'oncle', 'enfant'],
        )]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        $id = $this->charge()['id'];
        self::assertIsInt($id);

        return $id;
    }

    /** @return array<string, mixed> */
    protected function annonce(): array
    {
        return [
            'titre' => 'Cousinade été '.$this->annee,
            'debut' => $this->annee.'-07-17',
            'fin' => $this->annee.'-07-18',
            'lieu' => 'Chez Tante Ardoise',
            'description' => "Apportez un plat.\nOn dort sur place.",
        ];
    }

    /** L'invitation de cette personne, relue en base. */
    protected function invitationDe(int $evenement, string $personne): Invitation
    {
        $this->em()->clear();
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy([
            'evenement' => $evenement,
            'personId' => $this->idDe($personne),
        ]);
        self::assertNotNull($invitation, $personne);

        return $invitation;
    }

    /** @return list<Notification> */
    protected function notifications(NotificationKind $nature): array
    {
        /** @var list<Notification> $notifications */
        $notifications = $this->em()->getRepository(Notification::class)->findBy(['kind' => $nature]);

        return $notifications;
    }

    /** @return list<int> les destinataires des notifications de cette nature */
    protected function destinataires(NotificationKind $nature): array
    {
        return array_map(static fn (Notification $n): int => $n->getRecipientUserId(), $this->notifications($nature));
    }

    /**
     * Le suivi, groupe par groupe, en clés de personnes — lu par l'organisateur.
     *
     * @return array<string, list<string>>
     */
    protected function groupesDuSuivi(int $evenement): array
    {
        $this->connexionDe(self::OPALE);
        $this->requete("/api/evenements/{$evenement}");
        $suivi = $this->charge()['suivi'];
        self::assertIsArray($suivi);

        $cles = array_flip(array_map(fn ($p): ?int => $p->getId(), $this->personnes));
        $groupes = [];

        foreach (['presents', 'absents', 'fantomes', 'nonVus'] as $groupe) {
            $noms = array_map(static fn (array $ligne): string => (string) $cles[$ligne['personId']], $suivi[$groupe]);
            sort($noms);
            $groupes[$groupe] = $noms;
        }

        return $groupes;
    }
}
