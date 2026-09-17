<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Core\Contract\AnnuaireDesMembresInterface;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Membership\Repository\InvitationRepository;
use App\Module\Membership\Repository\MemberRepository;

/**
 * La réponse de `Membership` aux deux questions que `Core` lui pose
 * ({@see AnnuaireDesMembresInterface}) : le libellé d'un membre, et la charge `membre`
 * d'un compte.
 *
 * `Core` tient les comptes, pas le rattachement à l'arbre. Plutôt qu'une jointure
 * inter-modules — que l'ADR 0002 interdit —, il publie ces deux questions et ignore tout
 * de `mbr_member`.
 *
 * ## Ces méthodes ne filtrent pas, et c'est écrit sur le contrat
 *
 * Le libellé d'un membre est celui d'une personne possiblement vivante : c'est la
 * huitième porte de sortie de l'ADR 0003. Elle est fermée ici par `access_control` —
 * les deux seules routes appelantes sont `POST /api/administration/voir-comme` et
 * `GET /api/administration/utilisateurs`, toutes deux derrière `ROLE_ADMIN` — et **pas**
 * par le cercle. Le motif est mécanique : `POST /api/administration/voir-comme` nomme le
 * membre qu'on **s'apprête** à emprunter, donc avant que son cercle ne s'applique. Un
 * filtre ici rendrait la bascule incapable de nommer sa propre cible.
 *
 * La différence avec {@see ServiceDesMembres::normaliser()}, qui filtre, lui : celle-ci
 * sert une file d'administration lue **sous** un cercle — potentiellement celui d'un
 * membre emprunté. Les deux formes se ressemblent ; leurs lecteurs ne sont pas au même
 * endroit du parcours.
 */
final readonly class AnnuaireDesMembres implements AnnuaireDesMembresInterface
{
    public function __construct(
        private MemberRepository $membres,
        private InvitationRepository $invitations,
    ) {
    }

    public function libelleDuMembre(int $memberId): ?string
    {
        return $this->membres->find($memberId)?->getPersonLabel();
    }

    /**
     * La charge `membre` du § 10.1 du contrat.
     *
     * `personId` et `personLabel` **sont** présents ici, contrairement à la charge de
     * session d'un compte en attente : le lecteur est un administrateur qui instruit
     * précisément ce rattachement, et le § 10.1 les montre dans son exemple — le compte
     * décrit y est d'ailleurs `en_attente`. C'est la seule réponse de l'API où le libellé
     * d'un rattachement non validé sort, et c'est parce qu'elle sert la décision de le
     * valider.
     *
     * @return array<string, mixed>|null
     */
    public function membreDuCompte(int $userId): ?array
    {
        $membre = $this->membres->pourUtilisateur($userId);

        if (null === $membre) {
            return null;
        }

        return [
            'id' => $membre->getId(),
            'status' => $membre->getStatus()->value,
            'personId' => $membre->getPersonId(),
            'personLabel' => $membre->getPersonLabel(),
            'matchKind' => $membre->getMatchKind()->value,
            'invitationId' => $membre->getInvitation()?->getId(),
            'linkedAt' => $membre->getLinkedAt()->format(\DateTimeInterface::ATOM),
            'activatedAt' => $membre->getActivatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    public function userIdDuMembre(int $memberId): ?int
    {
        return $this->membres->find($memberId)?->getUserId();
    }

    /**
     * **Seul un rattachement `actif` compte.** Un membre en attente de validation est
     * rattaché à une personne sur le papier — `mbr_member.person_id` est renseigné dès
     * l'inscription —, mais ce rattachement n'est pas établi, et la parenté calculée
     * depuis lui serait une affirmation que personne n'a validée. Le régime « en
     * attente » n'a pas de point de départ dans l'arbre, et c'est cohérent avec le fait
     * qu'il n'a pas de cercle.
     */
    public function personneDuCompte(int $userId): ?int
    {
        $membre = $this->membres->pourUtilisateur($userId);

        return null !== $membre && MemberStatus::Actif === $membre->getStatus()
            ? $membre->getPersonId()
            : null;
    }

    /**
     * Ce qui, côté comptes, dépend d'une fiche — la question que `Proposal` pose avant
     * d'accepter une demande de suppression.
     *
     * ## **Tous** les statuts de rattachement comptent, et c'est l'inverse de
     * {@see personneDuCompte()}
     *
     * Celle-ci ne rend la racine que d'un membre `actif` : une parenté calculée depuis un
     * rattachement non validé serait une affirmation que personne n'a approuvée.
     *
     * Ici, la question n'est pas « que puis-je affirmer » mais « qu'est-ce que je
     * casserais », et un rattachement `en_attente` se casse aussi bien qu'un autre — mieux,
     * même : son titulaire n'a pas encore de cercle, il ne verrait donc **rien** et n'aurait
     * aucun moyen de comprendre pourquoi. Un `suspendu` compte pour la même raison : une
     * suspension se lève, une fiche effacée ne revient pas.
     *
     * `uniq_mbr_member_person` garantit qu'il y en a **au plus un**.
     *
     * ## L'invitation, elle, doit être `active`
     *
     * Une invitation `utilisee` a déjà produit son membre — lequel est couvert par la
     * ligne ci-dessus. Une `expiree` ou une `revoquee` ne produira plus rien. Seule une
     * `active` créerait un compte sans racine à son acceptation, c'est-à-dire après la
     * suppression et loin d'elle.
     *
     * @return array{membreId: int|null, membreLibelle: string|null, invitationsActives: int}
     */
    public function rattachementsDeLaPersonne(int $personneId): array
    {
        $membre = $this->membres->pourPersonne($personneId);

        return [
            'membreId' => $membre?->getId(),
            // Le libellé de la **fiche**, jamais l'adresse du compte : cette méthode est la
            // seule du contrat à être appelée hors d'un écran `ROLE_ADMIN`. Voir l'interface.
            'membreLibelle' => $membre?->getPersonLabel(),
            'invitationsActives' => null === $this->invitations->activePourPersonne($personneId) ? 0 : 1,
        ];
    }
}
