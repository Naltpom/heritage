<?php

declare(strict_types=1);

namespace App\Module\Proposal\Controller;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\Security\ReserveAuxMembres;
use App\Module\Core\Service\BasculeVoirComme;
use App\Module\Core\Service\LectureDeSaisieDIdentite;
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
use App\Module\Proposal\Service\InventaireDeLaSuppression;
use App\Module\Proposal\Service\RechercheDeBrouillons;
use App\Module\Proposal\Service\RefaireDemande;
use App\Module\Proposal\Service\RessemblanceDesPropositions;
use App\Module\Proposal\Service\SerialiseurDeDemande;
use App\Module\Proposal\Service\ValeursMasquees;
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
 * | `GET /api/demandes/brouillons/recherche` | non | — |
 * | `POST /api/demandes/brouillons/ressemblances` | **non** | — |
 * | `GET /api/demandes/brouillons/{brouillonId}` | non | — |
 * | `GET /api/demandes/historique/{kind}/{id}` | non | — |
 *
 * `refaire` mérite sa ligne : c'est la seule route dont le nom suggère une écriture et
 * qui n'en fait aucune. Elle rend un **corps pré-rempli** que le front montre à
 * l'auteur, et c'est l'auteur qui le poste s'il le veut — d'où le `GET`. Y poser le
 * garde-fou interdirait à l'administrateur de **lire** une reprise sous la bascule,
 * c'est-à-dire de vérifier ce que verrait le membre : exactement ce que la bascule
 * existe pour permettre.
 *
 * `ressemblances` mérite la sienne pour une raison voisine : c'est un **`POST` qui n'écrit
 * rien**. Le verbe garde la saisie — un nom et une naissance, souvent d'un vivant — hors
 * de l'URL et des journaux ; il ne dit pas une écriture, et le garde-fou n'a donc rien à
 * y garder.
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
            $this->serialiseur->resumes($resultat['elements']),
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
     * Chercher parmi les personnes **proposées et non encore validées** (§ 6.6 bis).
     *
     * ## Pourquoi cette route existe
     *
     * `GET /api/arbre/recherche` cherche dans l'arbre **validé** : une fiche sans
     * identifiant n'y figure pas, et son auteur ne pouvait donc pas la désigner dans une
     * seconde demande. Décrire une branche exigeait de tout saisir d'un trait, ou
     * d'attendre une validation, ou de ressaisir la même personne — c'est-à-dire de
     * fabriquer le doublon que le § 9.5 passe son temps à écarter.
     *
     * ## Ce qu'elle ne fait pas
     *
     * Aucune écriture, donc aucun `refuserSiActive()` : sous la bascule « voir comme un
     * membre », un administrateur doit pouvoir **lire** ce que le membre trouverait — et
     * c'est justement ce que `voitToutesLesDemandes()` lui rend, ni plus ni moins.
     *
     * Elle n'applique pas non plus le cercle de visibilité, et il n'y a rien à
     * appliquer : une fiche proposée n'est pas dans l'arbre, elle n'a pas de cercle. Ce
     * qui la protège est la règle du § 13.3, la même qu'au calque — auteur et
     * administrateurs, personne d'autre —, et elle est portée par le service.
     */
    #[Route('/brouillons/recherche', name: 'proposal_demande_chercher_brouillons', methods: ['GET'])]
    public function chercherDesBrouillons(Request $requete, RechercheDeBrouillons $recherche): JsonResponse
    {
        $lecteur = $this->lecteurs->courant();
        $terme = trim($requete->query->getString('q'));

        if (mb_strlen($terme) < RechercheDeBrouillons::LONGUEUR_MINIMALE) {
            throw DonneesInvalides::pourChamp('q', \sprintf('La recherche demande au moins %d caractères.', RechercheDeBrouillons::LONGUEUR_MINIMALE));
        }

        $resultat = $recherche->resultats(
            $terme,
            $recherche->limite($requete->query->get('limite')),
            $lecteur,
        );

        return $this->charge([
            'elements' => array_map($this->serialiseur->resultatDeRecherche(...), $resultat['elements']),
            // Pas de `total`, comme au § 5.5 : « une recherche qui se pagine est une
            // énumération déguisée », et un compte avant filtrage dirait combien de
            // propositions on ne montre pas.
            'tronque' => $resultat['tronque'],
        ]);
    }

    /**
     * « Une proposition semblable attend déjà » — § 6.6 quater.
     *
     * ## Pourquoi cette route existe
     *
     * Le formulaire « Ajouter une personne » déposait une création sans rien vérifier. Un
     * doublon vient aussi d'une personne **déjà proposée** — par soi-même un autre soir, ou
     * par un autre membre —, que personne d'autre que l'administrateur ne pouvait voir.
     * Cette route le dit pendant la saisie, et dit ce qu'on peut en faire
     * (`action` : compléter sur place, ou déposer un complément).
     *
     * ## L'exception au § 13.3, et ce qui la borne
     *
     * Elle parcourt les propositions **de tous les membres**. Ce qui en sort est décidé par
     * {@see RessemblanceDesPropositions}, fiche par fiche : en clair pour les siennes, pour
     * un administrateur, et pour un défunt ; **en signal seul** pour un vivant proposé par
     * un autre membre — ni nom, ni date, ni lieu, ni auteur, ni numéro de demande, et des
     * dates qui ne sont même pas comparées.
     *
     * ## Un `POST` qui n'écrit rien
     *
     * Pour que noms et naissances de vivants ne partent pas dans l'URL — donc dans les
     * journaux. Aucune écriture, donc aucun `refuserSiActive()` : sous la bascule « voir
     * comme un membre », l'administrateur lit ce que lirait le membre, ce que
     * `Lecteur::voitToutesLesDemandes()` lui rend, ni plus ni moins.
     *
     * Un compte en attente reçoit `{elements: [], tronque: false}`, comme à la recherche
     * des brouillons — il ne dépose pas de demande, il n'a rien à compléter.
     */
    #[Route('/brouillons/ressemblances', name: 'proposal_demande_ressemblances', methods: ['POST'])]
    public function ressemblances(
        Request $requete,
        RessemblanceDesPropositions $ressemblances,
        LectureDeSaisieDIdentite $saisies,
    ): JsonResponse {
        $lecteur = $this->lecteurs->courant();
        $corps = $this->corps($requete);
        $saisie = $saisies->identite($corps);

        return $this->charge($ressemblances->resultats(
            $saisie,
            $saisies->limite($corps['limite'] ?? null),
            $lecteur,
            $this->sauf($corps['sauf'] ?? null),
        ));
    }

    /**
     * Une fiche proposée **par l'identifiant de sa ligne** (§ 6.6 bis).
     *
     * ## Pourquoi elle existe à côté de `/{id}/brouillons/{ref}`
     *
     * Cette dernière exige de connaître la demande **et** la référence provisoire. Or ce
     * qu'un corps de lien transporte est l'identifiant de la ligne, et lui seul : c'est
     * la seule désignation qui vaille hors de sa demande. Un front qui tient un
     * `otherDraftPersonId` — une reprise de demande, par exemple — n'a donc aucune façon
     * de nommer la personne qu'il désigne, et afficherait « personne n° 7 » là où tout le
     * reste du produit s'interdit de mettre un identifiant sous les yeux du lecteur.
     *
     * Elle rend la **ligne de recherche** et rien de plus : de quoi nommer, pas de quoi
     * consulter. Le détail d'un nœud en attente reste sur l'autre route, avec ses liens,
     * ses médias et sa note.
     *
     * `404` sur la fiche d'un autre membre, jamais `403` — même règle qu'à la recherche.
     */
    #[Route(
        '/brouillons/{brouillonId}',
        name: 'proposal_demande_fiche_proposee',
        requirements: ['brouillonId' => '\d+'],
        methods: ['GET'],
    )]
    public function ficheProposee(int $brouillonId, DraftPersonRepository $brouillons): JsonResponse
    {
        $lecteur = $this->lecteurs->courant();

        // Le régime `ATTENTE` ne dépose pas de demande : il n'a donc aucune fiche
        // proposée à nommer, et surtout pas celle d'un autre (§ 13.3, § 16.3).
        $brouillon = $lecteur->regimeEnAttente ? null : $brouillons->visiblePourLecteur(
            $brouillonId,
            $lecteur->voitToutesLesDemandes() ? null : $lecteur->identiteEffective(),
        );

        if (null === $brouillon) {
            throw Introuvable::brouillonPropose($brouillonId);
        }

        return $this->charge($this->serialiseur->resultatDeRecherche($brouillon));
    }

    /**
     * L'historique d'une fiche (§ 6.11) — les révisions **effectivement appliquées**.
     *
     * **Réservée aux membres** depuis le 01/10/2026 : un compte en attente ne lit plus
     * rien de l'arbre, défunts compris (ADR 0003 révisée), et un historique de fiche en
     * est une lecture — `403 regime_en_attente`. Pour un membre, le filtrage par le
     * cercle reste appliqué en amont par le normaliseur de `Tree`, qui rend `404` sur une
     * personne vivante hors cercle — « il porte des dates et des lieux, c'est-à-dire tout
     * ce que la liste blanche exclut ».
     */
    #[Route(
        '/historique/{subjectKind}/{subjectId}',
        name: 'proposal_demande_historique',
        requirements: ['subjectId' => '\d+'],
        methods: ['GET'],
    )]
    #[ReserveAuxMembres]
    public function historique(
        string $subjectKind,
        int $subjectId,
        Request $requete,
        RevisionRepository $revisions,
        ValeursMasquees $masques,
    ): JsonResponse {
        $this->lecteurs->courant();
        $kind = SubjectKind::tryFrom($subjectKind);

        if (null === $kind) {
            throw new Introuvable('Portée inconnue.');
        }

        // « L'historique d'une personne vivante hors cercle n'est pas accessible : 404 »
        // (§ 6.11) — écrit au contrat, et que rien ne tenait : la route rendait ses
        // révisions, dates d'avant et d'après comprises, à tout membre. Le même `404`
        // qu'une fiche inconnue (§ 15.3).
        if ($masques->sujetFerme($kind, $subjectId)) {
            throw new Introuvable('Historique introuvable.');
        }

        // Une citation, une note, un fait, un média n'ont pas de nom à masquer, mais ils
        // appartiennent à une fiche : celle que visaient les demandes qui les ont écrits.
        // Fermée, ou dans l'arbre d'un autre compte (révision du 2026-10-04 de
        // l'ADR 0003), l'historique l'est aussi — le même `404`.
        if (SubjectKind::Personne !== $kind && SubjectKind::Famille !== $kind) {
            foreach ($revisions->ciblesDesDemandes($kind, $subjectId) as $cible) {
                if (\in_array($cible['kind'], [SubjectKind::Personne, SubjectKind::Famille], true)
                    && $masques->sujetFerme($cible['kind'], $cible['id'])) {
                    throw new Introuvable('Historique introuvable.');
                }
            }
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

    /**
     * Ce qu'une suppression de fiche emporterait — **lecture seule, avant tout dépôt**.
     *
     * ## Pourquoi cette route existe, et pourquoi elle n'est pas facultative
     *
     * Le formulaire de suppression doit dire ce qu'il efface **avant** que l'auteur écrive
     * son motif. Sans elle, il n'aurait que deux façons de l'apprendre :
     *
     * - recomposer le décompte côté front depuis la fiche, ce qui recopierait dans du
     *   TypeScript les six clés étrangères de `tree_person` et la contrainte `CHECK` qui
     *   décide du sort d'une union. Deux sources de vérité pour une opération qui
     *   détruit ;
     * - poster et lire la `422`, c'est-à-dire découvrir après avoir rédigé que ce n'était
     *   pas cette fiche-là qu'il fallait supprimer.
     *
     * `bloquee` et `raisons` sont rendus **à côté** de l'inventaire, et non déduits de lui
     * par le front : la règle qui bloque appartient au serveur, et un front qui la
     * redériverait finirait par diverger dans le sens permissif.
     *
     * ## Ce qu'elle ne fait pas
     *
     * Aucune écriture, donc aucun `refuserSiActive()` : un administrateur sous la bascule
     * « voir comme un membre » doit pouvoir **lire** ce que verrait le membre. C'est le
     * même raisonnement que pour {@see refaire()}, et c'est exactement ce que la bascule
     * existe pour permettre.
     *
     * Le filtrage de visibilité est celui de la cible : `404` sur une fiche que le lecteur
     * n'a pas le droit de voir, rendu par le port de l'arbre qui ne la trouve pas.
     */
    #[Route(
        '/suppression/personne/{personneId}',
        name: 'proposal_demande_apercu_suppression',
        requirements: ['personneId' => '\d+'],
        methods: ['GET'],
    )]
    // L'inventaire décrit une fiche de l'arbre : lecture réservée aux membres (ADR 0003,
    // révision du 01/10/2026). Un compte en attente ne dépose de toute façon rien.
    #[ReserveAuxMembres]
    public function apercuDeSuppression(int $personneId, InventaireDeLaSuppression $inventaires, CercleDeVisibiliteInterface $cercle): JsonResponse
    {
        $this->lecteurs->courant();

        // L'arbre d'une autre famille : `404`, comme une fiche inexistante (révision du
        // 2026-10-04 de l'ADR 0003). L'inventaire nomme la fiche et ses descendants.
        $inventaire = $cercle->dansLePerimetre($personneId) ? $inventaires->de($personneId) : null;

        if (null === $inventaire) {
            throw Introuvable::cible(SubjectKind::Personne->value, $personneId);
        }

        return $this->charge([
            'inventaire' => $inventaire->versJson(),
            'resume' => $inventaire->enUneLigne(),
            'bloquee' => $inventaire->estBloquee(),
            'raisons' => $inventaire->raisonsDuBlocage(),
        ]);
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

    /**
     * `sauf` : l'identifiant d'une demande à ne pas parcourir, ou rien. Un identifiant qui
     * ne désigne aucune demande n'est pas une erreur — il n'exclut simplement rien.
     */
    private function sauf(mixed $brut): ?int
    {
        if (null === $brut) {
            return null;
        }

        if (!\is_int($brut) || $brut < 1) {
            throw DonneesInvalides::pourChamp('sauf', 'L\'identifiant de la demande à exclure est un entier positif, ou null.');
        }

        return $brut;
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
