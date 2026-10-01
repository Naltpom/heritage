<?php

declare(strict_types=1);

namespace App\Module\Tree\Controller;

use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Security\ReserveAuxMembres;
use App\Module\Tree\Serializer\EtagDuGraphe;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\FabriqueDuCercle;
use App\Module\Tree\Serializer\FabriqueDuLecteur;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\NormaliseurDeFamille;
use App\Module\Tree\Serializer\NormaliseurDeGraphe;
use App\Module\Tree\Serializer\NormaliseurDePersonne;
use App\Module\Tree\Serializer\NormaliseurDeRecherche;
use App\Module\Tree\Serializer\NormaliseurDeRequete;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les quatre routes de lecture de l'arbre — § 5 du contrat d'API.
 *
 * ```
 * GET /api/arbre/graphe            MEMBRE   200 · 304
 * GET /api/arbre/personnes/{id}    MEMBRE   200      (réduite, jamais 404, si hors cercle)
 * GET /api/arbre/familles/{id}     MEMBRE   200
 * GET /api/arbre/recherche         MEMBRE   200 · 422
 * ```
 *
 * Compte en attente : `403 regime_en_attente` sur les quatre.
 *
 * ## Ce contrôleur ne filtre rien, et c'est une règle et non un style
 *
 * « Le cercle de visibilité est appliqué **dans la sérialisation**, pas dans le
 * contrôleur » (§ 8 du dossier de mission). Ces méthodes chargent, délèguent, et
 * répondent. Le jour où l'une d'elles écrirait un `if ($estVisible)`, il existerait deux
 * endroits qui décident de la confidentialité — et le second serait celui qu'on
 * oublierait de corriger.
 *
 * ## `MEMBRE` sur les quatre routes — révision du 01/10/2026 (ADR 0003)
 *
 * Un compte en attente de rattachement lisait jusque-là « l'arbre ancien » : les défunts
 * en entier, et chaque vivant en **nœud restreint** — nom complet, sexe, génération,
 * branche ({@see NormaliseurDeGraphe}). Le lien d'invitation circule dans un groupe de
 * messagerie : n'importe qui pouvait s'inscrire sous un faux nom et lire la liste des
 * vivants de la famille. Il ne lit donc plus **rien** ici avant sa validation
 * ({@see ReserveAuxMembres}), et le refus est rendu avant que le graphe soit chargé.
 *
 * Le cercle reste appliqué dans la sérialisation pour les membres : l'attribut ferme la
 * porte au régime `aucun`, il ne remplace pas le filtrage de ceux qui entrent.
 *
 * ## `{id}` porte `\d+`, et c'est une protection gratuite
 *
 * Un identifiant provisoire (`#12/p1`) ne correspond alors tout simplement **pas** à la
 * route et produit une `404` du routeur, sans qu'aucune ligne de code ait à s'en
 * soucier. Le détail d'un nœud en attente passe par `GET /api/demandes/{id}/brouillons/{ref}`,
 * qui appartient à `Proposal`.
 */
#[ReserveAuxMembres]
final class ArbreController extends AbstractController
{
    public function __construct(
        private readonly FabriqueDuLecteur $lecteurs,
        private readonly FabriqueDuCercle $cercles,
        private readonly EtatVivant $vivants,
        private readonly LectureDeLArbre $lecture,
    ) {
    }

    /**
     * Le graphe, servi avec un `ETag` **fort**.
     *
     * Les trois en-têtes vont ensemble et aucun n'est décoratif :
     *
     * - `ETag` — la revalidation. Cinq composantes (§ 5.2) : l'empreinte de l'arbre,
     *   **celle du cercle effectif du lecteur**, celle de ses demandes en attente, son
     *   point de vue, et **celle de la mise en page elle-même**. La deuxième est celle qui
     *   manquait d'abord : sans elle, trois lecteurs aux charges différentes recevaient le
     *   même `ETag`, et l'un pouvait obtenir `304` sur le graphe d'un autre. La cinquième
     *   est la seule qui ne vienne pas de la base — sans elle, un déploiement qui change
     *   les règles de placement ne parvient à aucun navigateur qui revalide. La bascule « voir comme un membre » y entre désormais **par
     *   conséquence**, le cercle effectif étant celui du membre emprunté. Le détail et les
     *   mesures sont dans {@see EtagDuGraphe}.
     * - `Cache-Control: private, no-cache` — `private` parce que la charge dépend du
     *   lecteur, `no-cache` parce qu'il faut **revalider** et non servir de mémoire.
     * - `Vary: Cookie` — sans lui, un cache partagé peut servir le graphe d'un membre du
     *   cercle à un membre hors cercle.
     *
     * Le `304` porte les mêmes en-têtes : un `304` sans `ETag` fait repartir le client de
     * zéro au rechargement suivant.
     */
    #[Route('/api/arbre/graphe', name: 'tree_graphe', methods: ['GET'])]
    public function graphe(Request $requete, NormaliseurDeGraphe $normaliseur, EtagDuGraphe $etags): JsonResponse
    {
        $lecteur = $this->lecteurs->courant();
        $etag = $etags->pour($lecteur);

        if ($etags->correspond($requete->headers->get('If-None-Match'), $etag)) {
            $reponse = new JsonResponse(null, JsonResponse::HTTP_NOT_MODIFIED);

            return $this->enTetesDuGraphe($reponse, $etag);
        }

        $charge = $normaliseur->graphe($lecteur, $this->cercles->pour($lecteur));

        return $this->enTetesDuGraphe(new JsonResponse($charge), $etag);
    }

    /**
     * La fiche d'une personne — complète, ou réduite à sept clés.
     *
     * **Une personne vivante hors cercle rend `200`, pas `404`.** C'est l'une des deux
     * exceptions nommées à la règle « 404 plutôt que 403 » du § 15.3, et sa raison est
     * ergonomique : le nœud est visible dans le graphe, cliquer dessus doit donner un nom
     * et une impasse claire, pas une erreur qui ressemble à une panne.
     */
    #[Route('/api/arbre/personnes/{id}', name: 'tree_personne', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function personne(int $id, NormaliseurDePersonne $normaliseur): JsonResponse
    {
        $personne = $this->lecture->personne($id);

        if (null === $personne) {
            throw new NotFoundException();
        }

        $lecteur = $this->lecteurs->courant();

        return new JsonResponse($normaliseur->fiche(
            $personne,
            $lecteur,
            $this->cercles->pour($lecteur),
            $this->vivants,
        ));
    }

    #[Route('/api/arbre/familles/{id}', name: 'tree_famille', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function famille(int $id, NormaliseurDeFamille $normaliseur): JsonResponse
    {
        $famille = $this->lecture->famille($id);

        if (null === $famille) {
            throw new NotFoundException();
        }

        $lecteur = $this->lecteurs->courant();

        return new JsonResponse($normaliseur->fiche(
            $famille,
            $lecteur,
            $this->cercles->pour($lecteur),
            $this->vivants,
        ));
    }

    /**
     * La recherche. Liste **non paginée** et **sans `total`** : une recherche qui se
     * pagine est une énumération déguisée, et un décompte avant filtrage dirait combien
     * de personnes on cache (§ 5.5).
     *
     * `vivants=1` ne garde que les personnes **vivantes** : les tirages au sort la passent,
     * parce qu'on n'y inscrit pas une personne disparue (ADR 0009, révision du
     * 2026-10-01). Toute autre valeur, ou son absence, laisse la recherche telle qu'elle
     * était — les propositions et les actes demandés cherchent aussi les défunts.
     */
    #[Route('/api/arbre/recherche', name: 'tree_recherche', methods: ['GET'])]
    public function recherche(
        Request $requete,
        NormaliseurDeRecherche $normaliseur,
        NormaliseurDeRequete $requetes,
    ): JsonResponse {
        $terme = trim((string) $requete->query->get('q', ''));

        // Le plancher est sur le **terme saisi** et non sur sa forme normalisée : une
        // saisie de deux caractères dont l'un est une apostrophe serait sinon refusée
        // avec un message qui ne correspond à rien de ce que l'utilisateur voit.
        if (mb_strlen($terme) < NormaliseurDeRequete::LONGUEUR_MINIMALE) {
            throw new ValidationException(['q' => \sprintf('La recherche demande au moins %d caractères.', NormaliseurDeRequete::LONGUEUR_MINIMALE)]);
        }

        $lecteur = $this->lecteurs->courant();

        return new JsonResponse($normaliseur->resultats(
            $terme,
            $requetes->limite($requete->query->get('limite')),
            $this->cercles->pour($lecteur),
            $this->vivants,
            '1' === $requete->query->get('vivants'),
        ));
    }

    private function enTetesDuGraphe(JsonResponse $reponse, string $etag): JsonResponse
    {
        $reponse->headers->set('ETag', $etag);
        $reponse->headers->set('Cache-Control', 'private, no-cache');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }
}
