<?php

declare(strict_types=1);

namespace App\Module\Timeline\Controller;

use App\Module\Core\Security\RegimeVoter;
use App\Module\Timeline\Service\IdentiteDuLecteur;
use App\Module\Timeline\Service\NormaliseurDeChronologie;
use App\Module\Timeline\Service\NormaliseurDeRequete;
use App\Module\Timeline\Service\ServiceDesPreferences;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les trois routes de la vue chronologique — § 9 du contrat d'API.
 *
 * ```
 * GET /api/chronologie                 ATTENTE   200 · 422
 * GET /api/chronologie/preferences     ATTENTE   200 · 401
 * PUT /api/chronologie/preferences     ATTENTE   200 · 422
 * ```
 *
 * ## Ce contrôleur ne filtre rien
 *
 * « Le cercle de visibilité est appliqué dans la sérialisation, pas dans le contrôleur »
 * (§ 8 du dossier de mission). Ces trois méthodes lisent, délèguent et répondent. Tout le
 * filtrage vit dans {@see NormaliseurDeChronologie} ; le jour où un `if` de
 * confidentialité apparaîtrait ici, il y aurait deux endroits qui en décident, et le
 * second serait celui qu'on oublierait de corriger.
 *
 * ## `ATTENTE` sur les trois, y compris sur le `PUT`
 *
 * C'est ce que porte le tableau du § 9, et c'est cohérent malgré l'apparence : `PUT`
 * **écrit**, mais il écrit un réglage d'affichage du compte lui-même, pas une donnée de
 * l'arbre. Le § 12.2 ferme l'écriture *sur l'arbre* à un compte en attente — proposer,
 * téléverser —, pas le droit de régler sa propre vue. Un compte en attente lit l'arbre
 * ancien pendant plusieurs jours (§ 12.1) ; lui interdire de choisir sa largeur de bande
 * ne protégerait personne et lui laisserait un écran qu'il ne peut pas cadrer.
 *
 * ## `GET` ne devient jamais une écriture
 *
 * `?bande=50` change ce que cette requête **répond**, jamais ce que le compte a
 * **enregistré** (§ 9.1). C'est `PUT` qui écrit, et lui seul. Sans cette séparation,
 * « chaque exploration d'un réglage devient une écriture, et la préférence ne veut plus
 * rien dire » — et un `GET` cesserait d'être idempotent, ce qui se paierait en `INSERT`
 * à chaque mouvement de curseur du front.
 */
#[IsGranted(RegimeVoter::ATTENTE)]
final class ChronologieController extends AbstractController
{
    public function __construct(
        private readonly IdentiteDuLecteur $lecteur,
        private readonly ServiceDesPreferences $preferences,
        private readonly NormaliseurDeRequete $requetes,
    ) {
    }

    /**
     * Les données de la vue — § 9.1.
     *
     * `Cache-Control: private, no-store` et `Vary: Cookie` : la charge dépend du cercle du
     * lecteur, donc de son cookie de session. Sans `Vary`, un cache partagé peut servir la
     * chronologie d'un membre du cercle à un membre hors cercle — c'est la même précaution
     * que sur `GET /api/arbre/graphe`, et elle ne se devine pas.
     *
     * Pas d'`ETag` ici, contrairement au graphe : il faudrait une empreinte qui incorpore
     * l'arbre, le cercle du lecteur **et** les trois paramètres de vue, et un `ETag` qui
     * oublie une de ces entrées sert la vue de quelqu'un d'autre. Le graphe en a un parce
     * qu'il pèse 377 nœuds pré-placés et qu'il est rechargé à chaque navigation ; la
     * chronologie se recalcule à chaque changement de bande de toute façon. On s'en passe
     * tant que personne n'a mesuré qu'il manque.
     */
    #[Route('/api/chronologie', name: 'timeline_vue', methods: ['GET'])]
    public function vue(Request $requete, NormaliseurDeChronologie $normaliseur): JsonResponse
    {
        $preference = $this->preferences->pour($this->lecteur->userId());
        $parametres = $this->requetes->parametresDeVue($requete, $preference);

        $reponse = new JsonResponse($normaliseur->vue($parametres));
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('Vary', 'Cookie');

        return $reponse;
    }

    /** La préférence enregistrée, ou le défaut du projet — **sans jamais créer la ligne**. */
    #[Route('/api/chronologie/preferences', name: 'timeline_preferences_lire', methods: ['GET'])]
    public function lirePreferences(): JsonResponse
    {
        return new JsonResponse($this->preferences->pour($this->lecteur->userId())->enJson());
    }

    /**
     * Remplacement complet — § 9.2. Quatre champs, tous obligatoires, aucun `PATCH`.
     *
     * La réponse rend la préférence **relue depuis l'entité** et non le corps soumis : ce
     * qui revient au client est ce que la base porte, pas ce qu'il a envoyé.
     */
    #[Route('/api/chronologie/preferences', name: 'timeline_preferences_ecrire', methods: ['PUT'])]
    public function ecrirePreferences(Request $requete): JsonResponse
    {
        $soumise = $this->requetes->preferenceSoumise($requete);

        return new JsonResponse(
            $this->preferences->remplacer($this->lecteur->userId(), $soumise)->enJson(),
        );
    }
}
