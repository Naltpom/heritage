<?php

declare(strict_types=1);

namespace App\Module\Membership\Controller;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\SessionPayloadBuilder;
use App\Module\Membership\Service\ServiceDInscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * L'inscription en trois étapes — § 4 du contrat d'API. **`PUBLIC`.**.
 *
 * `^/api/inscription` est la seule famille de routes hors connexion à ne demander aucune
 * session : « on n'a pas encore de compte quand on s'y présente ; sa protection est le
 * jeton, et son refus est un `404` ».
 *
 * ## Le contrôleur ne décide de rien
 *
 * Il lit le corps, appelle le service, rend le résultat. Les quatre règles qui portent la
 * sécurité de ce parcours — `404` plutôt que `403`, plafond indistinguable, « on cherche
 * puis on jette », pré-lien sans accès en lecture — vivent dans
 * {@see ServiceDInscription}, où elles se testent sans monter de pare-feu. Un contrôleur
 * qui en porterait une seule serait le premier endroit où elle divergerait.
 */
final class InscriptionController extends AbstractController
{
    public function __construct(private readonly ServiceDInscription $inscription)
    {
    }

    /** Étape 1a — le jeton est-il valide, et quel formulaire afficher ? */
    #[Route('/api/inscription/verifier', name: 'membership_inscription_verifier', methods: ['POST'])]
    public function verifier(Request $requete): JsonResponse
    {
        return new JsonResponse($this->inscription->verifier($this->jeton($requete), $requete));
    }

    /**
     * Étape 1b — la recherche de rattachement.
     *
     * **Cette réponse n'a qu'une forme**, et le contrôleur n'a aucun moyen d'en produire
     * une autre : il n'existe ici ni `429`, ni `Retry-After`, ni en-tête de quota pour le
     * plafond par jeton. Le seul `429` possible vient du limiteur **par IP**, levé par le
     * service en {@see \App\Module\Core\Exception\TooManyRequestsException} et traduit par
     * le souscripteur d'exception de `Core`.
     */
    #[Route('/api/inscription/rattachement', name: 'membership_inscription_rattachement', methods: ['POST'])]
    public function rattachement(Request $requete): JsonResponse
    {
        $corps = $this->corpsJson($requete);

        return new JsonResponse($this->inscription->rattachement($this->jeton($requete), $corps, $requete));
    }

    /**
     * Étape 2 — création du compte, **et ouverture de la session**.
     *
     * « Le front n'a pas à enchaîner sur `POST /api/connexion` » (§ 4.3), et la réponse est
     * **exactement** la charge de session du § 2.2 : une seule forme à implémenter côté
     * front, un seul magasin à alimenter.
     *
     * La session est ouverte par `Security::login()` avec l'authentificateur `json_login`
     * — le même que la connexion ordinaire, pour que le jeton de sécurité, le gestionnaire
     * de succès et le journal d'audit voient un compte connecté de la même façon. La
     * réponse que rend le gestionnaire de succès est ignorée : c'est la nôtre qui doit
     * porter le `201`.
     *
     * **Deux encodages, un seul corps.** En JSON, comme toujours ; ou en `multipart` quand
     * la déclaration de famille porte un **message vocal** : le corps JSON voyage alors,
     * inchangé, dans le champ `donnees`, et l'enregistrement dans le champ `messageVocal`.
     * Le service reçoit le même tableau dans les deux cas — et le fichier à part.
     */
    #[Route('/api/inscription/compte', name: 'membership_inscription_compte', methods: ['POST'])]
    public function compte(
        Request $requete,
        Security $securite,
        SessionPayloadBuilder $charge,
    ): JsonResponse {
        $corps = $this->corpsJson($requete);

        $utilisateur = $this->inscription->creerLeCompte(
            $this->jeton($requete),
            $corps,
            $requete,
            $requete->files->get('messageVocal'),
        );

        $securite->login($utilisateur, 'json_login');

        return new JsonResponse($charge->construire($utilisateur), Response::HTTP_CREATED);
    }

    /**
     * Étape 3 — l'écran d'attente.
     *
     * Accessible dès que la session existe, donc `ATTENTE` : c'est précisément le régime
     * de ceux qui la consultent. Pas de voter ici — `access_control` place déjà `^/api`
     * derrière `ROLE_USER`, et cette route ne prend aucun identifiant en paramètre, donc
     * rien à contrôler côté IDOR : elle ne parle que du lecteur.
     */
    #[Route('/api/inscription/etat', name: 'membership_inscription_etat', methods: ['GET'])]
    public function etat(#[CurrentUser] ?User $utilisateur): JsonResponse
    {
        if (null === $utilisateur) {
            // `^/api/inscription` est `PUBLIC_ACCESS` : cette route-ci est la seule du
            // groupe à exiger une session, et `access_control` ne peut pas l'exprimer sans
            // rouvrir les trois autres. Un `404` plutôt qu'un `401` garde la famille
            // homogène — aucune de ces quatre routes ne dit jamais ce qu'elle protège.
            throw new NotFoundException();
        }

        return new JsonResponse($this->inscription->etat($utilisateur));
    }

    /**
     * Le jeton, extrait du corps.
     *
     * Un corps sans `jeton` rend `404`, pas `422` : c'est le même refus que pour un jeton
     * inventé, et pour la même raison. Un `422` distinguerait « tu as oublié le champ » de
     * « ton jeton ne vaut rien », ce qui apprend au porteur que le champ existe et que la
     * route le lit vraiment.
     */
    private function jeton(Request $requete): string
    {
        $jeton = $this->corpsJson($requete)['jeton'] ?? null;

        if (!\is_string($jeton) || '' === $jeton) {
            throw new NotFoundException();
        }

        return $jeton;
    }

    /**
     * Le corps, en JSON — ou, pour un envoi `multipart` (le message vocal), le JSON du
     * champ `donnees`. Les deux formes rendent le même tableau : le jeton et les champs se
     * lisent au même endroit, et le `404` d'un jeton absent est le même.
     *
     * @return array<string, mixed>
     */
    private function corpsJson(Request $requete): array
    {
        $brut = 'form' === $requete->getContentTypeFormat()
            ? $requete->request->get('donnees')
            : $requete->getContent();

        $decode = \is_string($brut) ? json_decode($brut, true) : null;

        if (!\is_array($decode)) {
            throw new ValidationException(['corps' => 'Un objet JSON est attendu.']);
        }

        /* @var array<string, mixed> $decode */
        return $decode;
    }
}
