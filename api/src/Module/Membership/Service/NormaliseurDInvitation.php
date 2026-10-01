<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Membership\Entity\Invitation;
use App\Module\Membership\Entity\Member;

/**
 * La forme JSON d'une invitation — § 3.2 et § 3.3 du contrat d'API.
 *
 * ## La porte de sortie n° 8 est ici
 *
 * `mbr_invitation.person_label` est l'une des neuf colonnes `*_label` qui stockent le nom
 * d'une personne potentiellement vivante **sans jamais traverser le normaliseur de
 * `Tree\Person`** (§ 0.4 du modèle, § 11.3 du contrat, ADR 0003). C'est la porte
 * « qu'aucun des deux corpus n'avait comptée », et la règle est sans exception :
 *
 * > **Toute clé `*Label` désignant une personne est filtrée comme la personne elle-même** —
 * > et la clé `…Id` qui l'accompagne l'est aussi.
 *
 * En pratique cette file est réservée aux administrateurs, donc le risque est contenu.
 * « En pratique » n'est pas une garantie : la bascule « voir comme un membre » (§ 10.4)
 * remplace précisément le cercle de l'administrateur par celui d'un membre, et c'est ce
 * jour-là que le filtre sert.
 *
 * ## `lien` n'apparaît que dans la réponse de création
 *
 * {@see self::creation()} le porte, {@see self::ligne()} ne le porte pas — **absent, pas
 * `null`** (§ 1.3). Le serveur n'a que `hash(jeton)` : il n'existe aucune route « renvoyer
 * l'invitation », et c'est le piège le plus probable de ce module, « la route paraît
 * manquante alors qu'elle est impossible » (§ 3.2).
 */
final readonly class NormaliseurDInvitation
{
    public function __construct(private CercleDeVisibiliteInterface $cercle)
    {
    }

    /**
     * La réponse `201` du § 3.2 — le seul moment où le jeton existe.
     *
     * @return array<string, mixed>
     */
    public function creation(InvitationCreee $resultat): array
    {
        $invitation = $resultat->invitation;

        return [
            'id' => $invitation->getId(),
            'tokenPrefix' => $invitation->getTokenPrefix(),
            'lien' => $resultat->lien,
            'courrielEnvoye' => $resultat->courrielEnvoye,
            ...$this->personne($invitation),
            'email' => $invitation->getEmail(),
            'status' => $invitation->getStatus()->value,
            ...$this->usages($invitation),
            'createdByEmail' => $invitation->getCreatedByEmail(),
            'createdAt' => $invitation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'note' => $invitation->getNote(),
            'inscrits' => [],
            'avertissements' => $resultat->avertissements,
        ];
    }

    /**
     * Une ligne de la liste d'administration — § 3.3.
     *
     * @param list<Member> $inscrits les comptes nés du lien, du plus ancien au plus récent
     *
     * @return array<string, mixed>
     */
    public function ligne(Invitation $invitation, int $tentatives, array $inscrits): array
    {
        return [
            'id' => $invitation->getId(),
            'tokenPrefix' => $invitation->getTokenPrefix(),
            ...$this->personne($invitation),
            'email' => $invitation->getEmail(),
            'status' => $invitation->getStatus()->value,
            ...$this->usages($invitation),
            // Le **dernier** inscrit — conservés pour les clients d'avant les liens
            // multi-usages ; la liste complète est `inscrits`.
            'usedAt' => $invitation->getUsedAt()?->format(\DateTimeInterface::ATOM),
            'usedByEmail' => $invitation->getUsedByEmail(),
            'revokedAt' => $invitation->getRevokedAt()?->format(\DateTimeInterface::ATOM),
            'revokedByEmail' => $invitation->getRevokedByEmail(),
            'createdByEmail' => $invitation->getCreatedByEmail(),
            'createdAt' => $invitation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'note' => $invitation->getNote(),
            // Le décompte des tentatives de l'heure : c'est ce qui permet de voir qu'un
            // lien est en train d'être sondé. Les **critères** soumis ne sont jamais
            // rendus — `query_hash` est une empreinte, pas un texte (§ 4.3, § 16).
            'tentatives' => $tentatives,
            'inscrits' => array_map($this->inscrit(...), $inscrits),
        ];
    }

    /** @return array{maxUses: int|null, useCount: int, expiresAt: string|null} */
    private function usages(Invitation $invitation): array
    {
        return [
            'maxUses' => $invitation->getMaxUses(),
            'useCount' => $invitation->getUseCount(),
            'expiresAt' => $invitation->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Un compte né du lien. La fiche rattachée suit la même règle que le pré-lien : les
     * deux clés ou aucune — `mbr_member.person_label` est la porte de sortie voisine.
     *
     * @return array<string, mixed>
     */
    private function inscrit(Member $membre): array
    {
        $ligne = [
            'userId' => $membre->getUserId(),
            'email' => $membre->getUserEmail(),
            'memberStatus' => $membre->getStatus()->value,
            // `a_rattacher` dit à l'administrateur que ce compte n'a pas de fiche et l'attend
            // dans l'onglet « À rattacher » (ADR 0013).
            'matchKind' => $membre->getMatchKind()->value,
            'createdAt' => $membre->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        $personId = $membre->getPersonId();

        // Un compte sans fiche n'a pas de personne à filtrer : les deux clés sont absentes,
        // comme lorsque le cercle ne la laisse pas voir.
        if (null !== $personId && $this->cercle->voitPersonne($personId)) {
            $ligne['personId'] = $personId;
            $ligne['personLabel'] = $membre->getPersonLabel();
        }

        return $ligne;
    }

    /**
     * `personId` et `personLabel`, **ou ni l'un ni l'autre**.
     *
     * Les deux clés se posent ensemble ou pas du tout. Rendre `personId` seul serait la
     * moitié de la fuite : un identifiant de personne vivante est le point de départ d'une
     * énumération par incrémentation, c'est le motif exact de la divergence 2 du § 17.1.
     *
     * @return array<string, mixed>
     */
    private function personne(Invitation $invitation): array
    {
        $personId = $invitation->getPersonId();

        if (null === $personId) {
            // Invitation sans pré-lien : les clés existent, à `null`. Ce n'est pas un
            // masquage, c'est une absence de donnée — la nuance « absent contre null » du
            // § 1.3 dit précisément qu'il ne faut pas confondre les deux.
            return ['personId' => null, 'personLabel' => null];
        }

        if (!$this->cercle->voitPersonne($personId)) {
            return [];
        }

        return ['personId' => $personId, 'personLabel' => $invitation->getPersonLabel()];
    }
}
