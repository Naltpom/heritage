<?php

declare(strict_types=1);

namespace App\Module\Proposal\Controller;

use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Security\DemandeVoter;
use App\Module\Proposal\Service\FabriqueDeLecteur;
use App\Module\Proposal\Service\RefusDeDemande;
use App\Module\Proposal\Service\ResultatDeValidation;
use App\Module\Proposal\Service\SerialiseurDeDemande;
use App\Module\Proposal\Service\ValidationDeDemande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La file d'administration, le diff, la validation et le refus.
 *
 * `/api/administration` est le seul préfixe partagé entre modules, « parce
 * qu'“administration” est un public, pas un domaine » : c'est le **troisième segment**
 * qui désigne le propriétaire, et `demandes` appartient à `Proposal` (§ 1.7).
 *
 * ── Lesquelles écrivent — la revue, route par route ─────────────────────────────
 *
 * | Route | Écrit | `refuserSiActive()` |
 * |---|:-:|:-:|
 * | `GET /api/administration/demandes` | non | — |
 * | `GET /api/administration/demandes/{id}` | non | — |
 * | `POST …/{id}/valider` | oui | **oui** |
 * | `POST …/{id}/refuser` | oui | **oui** |
 *
 * Ce sont les deux routes où l'oubli coûterait le plus cher. **On ne tranche pas une
 * demande sous une identité empruntée** : la `prp_revision` porterait l'administrateur
 * — avec les bons droits, le bon auteur, tout juste — pendant que l'écran qui a
 * déclenché le clic montrait le cercle de quelqu'un d'autre. Le journal d'audit
 * enregistrerait alors une décision parfaitement cohérente, prise sur des données que
 * l'opérateur ne regardait pas. Rien ne casse, rien ne se voit, et l'historique devient
 * ininterprétable.
 *
 * Les deux lectures, elles, doivent rester ouvertes : vérifier ce que verrait un membre
 * est précisément ce que la bascule existe pour permettre.
 */
#[Route('/api/administration/demandes')]
final class AdministrationDemandeController extends AbstractController
{
    public function __construct(
        private readonly FabriqueDeLecteur $lecteurs,
        private readonly SerialiseurDeDemande $serialiseur,
        private readonly ChangeRequestRepository $demandes,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    /**
     * La file, triée par `submittedAt` **croissant** et regroupée par personne côté
     * affichage : l'API rend `targetKind`/`targetId`, le front groupe (§ 6.7).
     */
    #[Route('', name: 'proposal_admin_demande_lister', methods: ['GET'])]
    public function lister(Request $requete): JsonResponse
    {
        $this->lecteurs->courant();
        [$page, $parPage] = $this->pagination($requete);

        $resultat = $this->demandes->file(
            $this->statut($requete),
            $this->nature($requete),
            $this->cible($requete),
            $page,
            $parPage,
        );

        return $this->charge($this->serialiseur->enveloppe(
            array_map($this->serialiseur->resume(...), $resultat['elements']),
            $resultat['total'],
            $page,
            $parPage,
        ));
    }

    /**
     * Le diff, avec **tout ce qu'il faut pour décider avant de cliquer** : la valeur
     * courante de chaque champ, un indicateur `aBouge`, le bandeau `cible`, et
     * `resolutionPrevue`.
     *
     * Sans `resolutionPrevue`, « l'administrateur découvre la caducité en cliquant, ce
     * qui la rend indistinguable d'une panne » (§ 6.7).
     */
    #[Route('/{id}', name: 'proposal_admin_demande_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(int $id): JsonResponse
    {
        $demande = $this->trouver($id);
        $this->exigerDecideur($demande);

        return $this->charge($this->serialiseur->pourAdministration($demande));
    }

    /**
     * Valider — **quatre issues**, dont deux `409` aux conseils opposés (§ 6.8).
     *
     * Les succès portent `resultat` : `validee` ou `rejouee`. Le second veut dire que le
     * verrou s'était déclenché et que la demande a été **rejouée automatiquement** sur la
     * version courante ; le front doit le dire, parce qu'une écriture qu'aucun humain n'a
     * réexaminée doit se voir.
     */
    #[Route('/{id}/valider', name: 'proposal_admin_demande_valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function valider(int $id, ValidationDeDemande $validation): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $demande = $this->trouver($id);
        $this->exigerDecideur($demande);

        $resultat = $validation->valider($demande, $this->lecteurs->courant());

        return $this->charge($this->issue($resultat));
    }

    /**
     * Refuser — **message obligatoire**, qui parvient à l'auteur dans sa notification.
     *
     * `doublonDeId` permet de refuser une demande **en renvoyant à une autre** : l'auteur
     * lit « votre proposition rejoint la demande n° 11 » plutôt qu'un refus sec. C'est
     * exactement à cela que sert le motif obligatoire quand deux cousins ont proposé le
     * même arrière-grand-oncle.
     */
    #[Route('/{id}/refuser', name: 'proposal_admin_demande_refuser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refuser(int $id, Request $requete, RefusDeDemande $refus): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $demande = $this->trouver($id);
        $this->exigerDecideur($demande);

        $corps = $this->corps($requete);
        $refuse = $refus->refuser(
            $demande,
            $this->lecteurs->courant(),
            $corps['message'] ?? null,
            $corps['doublonDeId'] ?? null,
        );

        return $this->charge($this->serialiseur->pourAuteur($refuse));
    }

    /** @return array<string, mixed> */
    private function issue(ResultatDeValidation $resultat): array
    {
        $charge = [
            'resultat' => $resultat->resultat,
            'demande' => [
                'id' => $resultat->demande->getId(),
                'status' => $resultat->demande->getStatus()->value,
                'decidedAt' => $resultat->demande->getDecidedAt()?->format(\DateTimeInterface::ATOM),
                'decidedByLabel' => $resultat->demande->getDecidedByLabel(),
                // Un administrateur peut valider sa propre demande : c'est autorisé — il
                // n'y en a qu'un, l'interdire bloquerait le produit — et **marqué**, pas
                // passé sous silence (ADR 0004).
                'autoValidated' => $resultat->demande->isAutoValidated(),
            ],
            'revisions' => array_map(
                static fn ($revision): array => [
                    'id' => $revision->getId(),
                    'subjectKind' => $revision->getSubjectKind()->value,
                    'subjectId' => $revision->getSubjectId(),
                    'operation' => $revision->getOperation()->value,
                    'versionBefore' => $revision->getVersionBefore(),
                    'versionAfter' => $revision->getVersionAfter(),
                ],
                $resultat->revisions,
            ),
            // La nouvelle empreinte de l'arbre : le front s'en sert pour savoir que son
            // `ETag` de graphe est périmé sans attendre une revalidation (§ 6.8).
            'empreinte' => $resultat->empreinte,
        ];

        if (null !== $resultat->rejeu) {
            $charge['rejeu'] = $resultat->rejeu;
        }

        return $charge;
    }

    private function trouver(int $id): ChangeRequest
    {
        $demande = $this->demandes->find($id);

        if (null === $demande) {
            throw Introuvable::demande($id);
        }

        return $demande;
    }

    private function exigerDecideur(ChangeRequest $demande): void
    {
        if (!$this->isGranted(DemandeVoter::DECIDER, $demande)) {
            throw AccesRefuse::demandeDAutrui();
        }
    }

    /** @return array<string, mixed> */
    private function corps(Request $requete): array
    {
        $brut = $requete->getContent();

        try {
            $decode = '' === $brut ? [] : json_decode($brut, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw DonneesInvalides::pourChamp('corps', 'Le corps de la requête n\'est pas du JSON valide.');
        }

        return \is_array($decode) ? $decode : [];
    }

    /** @return array{int, int} */
    private function pagination(Request $requete): array
    {
        return [
            max(1, $requete->query->getInt('page', 1)),
            min(100, max(1, $requete->query->getInt('parPage', 25))),
        ];
    }

    private function statut(Request $requete): ?RequestStatus
    {
        $brut = $requete->query->getString('status');

        return '' === $brut ? null : RequestStatus::tryFrom($brut);
    }

    private function nature(Request $requete): ?RequestKind
    {
        $brut = $requete->query->getString('kind');

        return '' === $brut ? null : RequestKind::tryFrom($brut);
    }

    private function cible(Request $requete): ?int
    {
        $brut = $requete->query->getInt('targetId');

        return 0 === $brut ? null : $brut;
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = 200): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
