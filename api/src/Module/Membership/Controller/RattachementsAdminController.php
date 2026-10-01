<?php

declare(strict_types=1);

namespace App\Module\Membership\Controller;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Membership\Contract\PortraitsDesComptes;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Repository\MemberRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'onglet « À rattacher » — `GET /api/administration/rattachements` (ADR 0013).
 *
 * Les comptes inscrits **sans fiche**, du plus ancien au plus récent, avec ce que chacun a
 * déclaré : identité, description de sa famille, photo. C'est la matière de la décision de
 * l'administrateur — rattacher à une fiche, en créer une, ou refuser. Les actions vivent
 * ailleurs : l'activation et le refus sur {@see MembresAdminController}, la suggestion et
 * « créer la fiche et rattacher » dans le module `Assistant`.
 *
 * ## Refusée sous la bascule « voir comme un membre »
 *
 * `409 vue_comme_membre_active`, alors que c'est une **lecture**. La règle 2 du § 10.4 ne
 * refuse d'ordinaire que les écritures ; celle-ci est l'exception, et pour une raison
 * mécanique : la description de famille est un texte libre, **qu'aucun cercle ne sait
 * filtrer**. Sous un cercle emprunté, la rendre montrerait au membre simulé des noms de
 * vivants hors de son cercle ; la masquer rendrait un écran faux. On refuse.
 *
 * `ROLE_ADMIN` par `access_control` (`^/api/administration`) : ni voter ni contrôle ici.
 */
final class RattachementsAdminController extends AbstractController
{
    public function __construct(
        private readonly MemberRepository $membres,
        private readonly PortraitsDesComptes $portraits,
        private readonly CercleDeVisibiliteInterface $cercle,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    #[Route('/api/administration/rattachements', name: 'membership_admin_rattachements_lister', methods: ['GET'])]
    public function lister(): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $elements = array_map($this->element(...), $this->membres->aRattacher());

        return new JsonResponse([
            'elements' => $elements,
            'total' => \count($elements),
            'page' => 1,
            'parPage' => \count($elements),
        ]);
    }

    /** @return array<string, mixed> */
    private function element(Member $membre): array
    {
        $invitation = $membre->getInvitation();
        $invitationPersonId = $invitation?->getPersonId();

        // Le pré-lien de l'invitation suit la règle de la porte de sortie n° 8 : l'identifiant
        // et le libellé ensemble, ou ni l'un ni l'autre. L'administrateur a un cercle
        // complet et la bascule est refusée plus haut ; le filtre reste, parce qu'il ne coûte
        // rien et que c'est lui qui tiendra le jour où l'une des deux prémisses changera.
        $preLienVisible = null !== $invitationPersonId && $this->cercle->voitPersonne($invitationPersonId);

        return [
            'memberId' => $membre->getId(),
            'userId' => $membre->getUserId(),
            'email' => $membre->getUserEmail(),
            'inscritLe' => $membre->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'invitationId' => $invitation?->getId(),
            'invitationPersonId' => $preLienVisible ? $invitationPersonId : null,
            'invitationPersonLabel' => $preLienVisible ? $invitation?->getPersonLabel() : null,
            'declaration' => [
                'surname' => $membre->getDeclaredSurname(),
                'givenNames' => $membre->getDeclaredGivenNames(),
                'birthDate' => $membre->getDeclaredBirthDate()?->format('Y-m-d'),
                'description' => $membre->getFamilyDescription(),
            ],
            'photo' => $this->portraits->portraitEnAttente($membre->getUserId()),
            'suggestionCalculeeLe' => $membre->getAttachmentSuggestionAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
