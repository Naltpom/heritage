<?php

declare(strict_types=1);

namespace App\Module\Proposal\Controller;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\ChangeRequestRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Repository\RevisionRepository;
use App\Module\Proposal\Security\DemandeVoter;
use App\Module\Proposal\Service\DepotDeDemande;
use App\Module\Proposal\Service\FabriqueDeLecteur;
use App\Module\Proposal\Service\RefaireDemande;
use App\Module\Proposal\Service\SerialiseurDeDemande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les routes du membre : déposer, suivre, retirer, refaire (§ 6 du contrat d'API).
 *
 * Les contrôleurs de ce module sont **délibérément maigres** : ils lisent la requête,
 * appellent un service, sérialisent. Toute la règle métier vit dans `Service/`, et
 * chaque service prend un {@see Lecteur} explicite plutôt
 * que d'interroger la session. C'est ce qui rend la règle la plus sensible du module —
 * « le calque est invisible d'un autre membre » — testable sans monter un pare-feu.
 *
 * **Aucune route ne rend `204`** : « une action qui réussit rend toujours l'état
 * résultant ; le front n'a jamais à refaire un `GET` pour savoir ce qu'il vient de
 * faire » (§ 1.1).
 *
 * ── Lesquelles écrivent — la revue, route par route ─────────────────────────────
 *
 * | Route | Écrit | `refuserSiActive()` |
 * |---|:-:|:-:|
 * | `POST /api/demandes` | oui | **oui** |
 * | `GET /api/demandes` | non | — |
 * | `GET /api/demandes/{id}` | non | — |
 * | `POST /api/demandes/{id}/soumettre` | oui | **oui** |
 * | `POST /api/demandes/{id}/retirer` | oui | **oui** |
 * | `GET /api/demandes/{id}/refaire` | **non** | — |
 * | `GET /api/demandes/{id}/brouillons/{ref}` | non | — |
 * | `GET /api/demandes/historique/{kind}/{id}` | non | — |
 *
 * `refaire` mérite sa ligne : c'est la seule route dont le nom suggère une écriture et
 * qui n'en fait aucune. Elle rend un **corps pré-rempli** que le front montre à
 * l'auteur, et c'est l'auteur qui le poste s'il le veut — d'où le `GET`. Y poser le
 * garde-fou interdirait à l'administrateur de **lire** une reprise sous la bascule,
 * c'est-à-dire de vérifier ce que verrait le membre : exactement ce que la bascule
 * existe pour permettre.
 *
 * ── Pourquoi le garde-fou est ici et pas seulement dans les services ────────────
 *
 * {@see BasculeVoirComme::refuserSiActive()} est la source
 * autoritaire : elle porte le code, le message et le `memberLabel` réel, que `Proposal`
 * ne sait pas produire. Les services gardent malgré tout leur propre contrôle, sur le
 * `Lecteur` — c'est le filet du jour où quelqu'un ajoutera une route d'écriture sans
 * relire ce tableau. Les deux ne peuvent pas diverger : ils lisent le **même** fait,
 * `BasculeVoirComme::memberEmprunte()`, l'un directement et l'autre via le `Lecteur`.
 */
#[Route('/api/demandes')]
final class DemandeController extends AbstractController
{
    public function __construct(
        private readonly FabriqueDeLecteur $lecteurs,
        private readonly SerialiseurDeDemande $serialiseur,
        private readonly ChangeRequestRepository $demandes,
        private readonly BasculeVoirComme $bascule,
    ) {
    }

    /**
     * Déposer une demande. **La table de l'objet visé n'est pas touchée** : seules
     * `prp_change_request` et `prp_change_op` sont écrites.
     */
    #[Route('', name: 'proposal_demande_creer', methods: ['POST'])]
    public function creer(Request $requete, DepotDeDemande $depot): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $demande = $depot->deposer($this->corps($requete), $this->lecteurs->courant());

        return $this->charge($this->serialiseur->pourAuteur($demande), 201);
    }

    /** « Mes demandes » — refusées et caduques comprises, elles restent consultables. */
    #[Route('', name: 'proposal_demande_lister', methods: ['GET'])]
    public function lister(Request $requete): JsonResponse
    {
        $lecteur = $this->lecteurs->courant();
        [$page, $parPage] = $this->pagination($requete);

        $resultat = $this->demandes->pourAuteur(
            $lecteur->identiteEffective(),
            $this->statut($requete),
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

    /** Son auteur et les administrateurs, personne d'autre — `403`, pas `404` (§ 6). */
    #[Route('/{id}', name: 'proposal_demande_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(int $id): JsonResponse
    {
        $demande = $this->trouver($id);
        $this->exiger(DemandeVoter::VOIR, $demande);

        return $this->charge($this->serialiseur->pourAuteur($demande));
    }

    #[Route('/{id}/soumettre', name: 'proposal_demande_soumettre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function soumettre(int $id, DepotDeDemande $depot): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $demande = $this->trouver($id);
        $this->exiger(DemandeVoter::MODIFIER, $demande);

        return $this->charge($this->serialiseur->pourAuteur(
            $depot->soumettre($demande, $this->lecteurs->courant()),
        ));
    }

    #[Route('/{id}/retirer', name: 'proposal_demande_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirer(int $id, DepotDeDemande $depot): JsonResponse
    {
        $this->bascule->refuserSiActive();

        $demande = $this->trouver($id);
        $this->exiger(DemandeVoter::MODIFIER, $demande);

        return $this->charge($this->serialiseur->pourAuteur(
            $depot->retirer($demande, $this->lecteurs->courant()),
        ));
    }

    /**
     * Le corps pré-rempli d'une reprise — **lecture seule, aucune écriture** (§ 6.10).
     *
     * `GET` et non `POST` parce que rien n'est créé : le front reçoit un brouillon de
     * corps qu'il montre à l'auteur, et c'est l'auteur qui le poste s'il le veut.
     */
    #[Route('/{id}/refaire', name: 'proposal_demande_refaire', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function refaire(int $id, RefaireDemande $reprise): JsonResponse
    {
        $demande = $this->trouver($id);
        $this->exiger(DemandeVoter::VOIR, $demande);

        return $this->charge($reprise->corpsPreRempli($demande));
    }

    /**
     * Le détail d'un nœud en attente — **la référence sans le dièse** (§ 6.6).
     *
     * Le `#` est le séparateur de fragment d'URL : `/api/demandes/12/brouillons/#p1`
     * enverrait au serveur une route sans référence, et tout ce qui suit le dièse ne
     * quitterait jamais le navigateur. La panne est silencieuse et se diagnostique mal
     * (§ 17.3, divergence 8) — d'où la contrainte `[A-Za-z]\d+` sur le segment.
     */
    #[Route(
        '/{id}/brouillons/{ref}',
        name: 'proposal_demande_brouillon',
        requirements: ['id' => '\d+', 'ref' => '[A-Za-z]\d+'],
        methods: ['GET'],
    )]
    public function brouillon(int $id, string $ref, DraftPersonRepository $brouillons): JsonResponse
    {
        $demande = $this->trouver($id);
        $this->exiger(DemandeVoter::VOIR, $demande);

        $brouillon = $brouillons->parReference($demande, '#'.$ref);

        if (null === $brouillon) {
            throw Introuvable::brouillon($ref);
        }

        return $this->charge($this->serialiseur->brouillon($brouillon));
    }

    /**
     * L'historique d'une fiche (§ 6.11) — les révisions **effectivement appliquées**.
     *
     * Accessible au régime `ATTENTE` : ce sont des faits historiques sur des lignes que
     * le lecteur a déjà le droit de voir. Le filtrage par le cercle de visibilité est
     * appliqué en amont par le normaliseur de `Tree`, qui rend `404` sur une personne
     * vivante hors cercle — « il porte des dates et des lieux, c'est-à-dire tout ce que
     * la liste blanche exclut ».
     */
    #[Route(
        '/historique/{subjectKind}/{subjectId}',
        name: 'proposal_demande_historique',
        requirements: ['subjectId' => '\d+'],
        methods: ['GET'],
    )]
    public function historique(
        string $subjectKind,
        int $subjectId,
        Request $requete,
        RevisionRepository $revisions,
    ): JsonResponse {
        $this->lecteurs->courant();
        $kind = SubjectKind::tryFrom($subjectKind);

        if (null === $kind) {
            throw new Introuvable('Portée inconnue.');
        }

        [$page, $parPage] = $this->pagination($requete);
        $resultat = $revisions->historique($kind, $subjectId, $page, $parPage);

        return $this->charge($this->serialiseur->enveloppe(
            array_map($this->serialiseur->revision(...), $resultat['elements']),
            $resultat['total'],
            $page,
            $parPage,
        ));
    }

    private function trouver(int $id): ChangeRequest
    {
        $demande = $this->demandes->find($id);

        if (null === $demande) {
            throw Introuvable::demande($id);
        }

        return $demande;
    }

    private function exiger(string $attribut, ChangeRequest $demande): void
    {
        if (!$this->isGranted($attribut, $demande)) {
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

        if (!\is_array($decode)) {
            throw DonneesInvalides::pourChamp('corps', 'Le corps de la requête est un objet JSON.');
        }

        return $decode;
    }

    /**
     * `page` commence à **1**, `parPage` vaut 25 par défaut et **est ramené à 100** sans
     * erreur au-delà : « un plafond qui lève une 422 apprend à l'appelant où est la
     * limite, un plafond qui rabote ne lui apprend rien et sert le même résultat »
     * (§ 1.5).
     *
     * @return array{int, int}
     */
    private function pagination(Request $requete): array
    {
        $page = max(1, $requete->query->getInt('page', 1));
        $parPage = min(100, max(1, $requete->query->getInt('parPage', 25)));

        return [$page, $parPage];
    }

    private function statut(Request $requete): ?RequestStatus
    {
        $brut = $requete->query->getString('status');

        return '' === $brut ? null : RequestStatus::tryFrom($brut);
    }

    /** @param array<string, mixed> $charge */
    private function charge(array $charge, int $statut = 200): JsonResponse
    {
        $reponse = new JsonResponse($charge, $statut);
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
