<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Security\JetonDApiAuthentifie;
use App\Module\Core\Security\RequeteParJetonDApi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ce qu'un jeton d'API a le droit d'appeler : une **liste blanche de routes par portée**,
 * fermée par défaut (ADR 0021 § 2, § 1.9 du contrat d'API).
 *
 * ## Fermé par défaut
 *
 * Une requête authentifiée par jeton n'atteint son contrôleur que si sa route — par son
 * **nom** et sa **méthode**, jamais par son chemin brut — figure dans la liste de sa portée
 * ou d'une portée plus faible ({@see PorteeDeJeton::couvre()} : `validation` ⊃
 * `contribution` ⊃ `lecture`). Sinon `403 acces_refuse`, dans l'enveloppe du § 15.1. Une
 * route nouvelle n'est donc ouverte aux jetons que le jour où quelqu'un l'écrit ici, avec sa
 * raison. Une requête `HEAD` sur une route `GET` est refusée : aucun outil n'en envoie.
 *
 * La liste est celle des outils des agents et de la session principale (serveurs MCP
 * `heritage` et `heritage-decision`), ni plus ni moins : chaque ligne dit l'outil qui
 * l'appelle.
 *
 * ## La portée restreint, elle n'élargit jamais
 *
 * Priorité 7 : juste après le pare-feu (`kernel.request` priorité 8), donc avec le jeton de
 * sécurité posé **et** `access_control` déjà joué — une route `^/api/administration` reste
 * refusée à un compte sans `ROLE_ADMIN`, qu'elle figure ici ou non. Avant le contrôleur,
 * bien sûr : une route refusée n'exécute pas une ligne. Les voters jugent ensuite comme pour
 * une session — et `DemandeVoter` ajoute, sous un jeton, la règle de l'auteur (ADR 0021 § 5).
 *
 * ## Les exclusions, qu'aucune portée n'ouvre
 *
 * Comptes, réglages, incarnation, voir-comme, jetons, connexion et déconnexion, clés
 * d'accès, mot de passe, courriel : {@see self::EXCLUSIONS}, par motif de nom de route. Elles
 * sont jugées **avant** la liste blanche : une ligne ajoutée par erreur ci-dessous ne les
 * ouvre pas (`PorteeDuJetonTest` l'éprouve).
 *
 * ## Le cookie n'est pas concerné
 *
 * Une requête sans `Authorization: Bearer` repart sans qu'on lise son jeton de sécurité : le
 * lire chargerait la session et défairait le `lazy` du pare-feu `main`. Une requête au
 * Bearer dont le jeton de sécurité ne nomme aucun jeton d'API ne devrait pas exister
 * (`api_jetons` la juge, et la refuse ou la décrit) : elle est refusée, pas laissée passer.
 */
final readonly class PorteeDuJetonSubscriber implements EventSubscriberInterface
{
    /**
     * Par portée : nom de route => méthode. Chaque portée hérite des précédentes.
     *
     * @var array<string, array<string, string>>
     */
    public const array LISTE_BLANCHE = [
        PorteeDeJeton::Lecture->value => [
            // `qui()` : le compte et le cercle du jeton — et la vérification de production
            // (`200` sans `Set-Cookie`).
            'core_session' => 'GET',
            // `ascendance()` : calculée sur le graphe, gardé en cache avec son `ETag`.
            'tree_graphe' => 'GET',
            // `fiche()` : la personne, ses notes et ses citations.
            'tree_personne' => 'GET',
            // `famille()` : le couple et ses enfants.
            'tree_famille' => 'GET',
            // `chercher()`, l'anti-doublon dans l'arbre. Un `POST` qui n'écrit rien : la
            // saisie (souvent celle d'un vivant) reste hors de l'URL.
            'tree_ressemblances' => 'POST',
            // `chercher()`, l'anti-doublon dans les propositions en attente. Même `POST` qui
            // n'écrit rien.
            'proposal_demande_ressemblances' => 'POST',
            // `demandes()` : les demandes du compte.
            'proposal_demande_lister' => 'GET',
            // `demande(id)` : une demande, ses ops et son empreinte.
            'proposal_demande_voir' => 'GET',
            // `deposer()` résout `@clé#pN` en `resolvedPersonId` par cette route.
            'proposal_demande_brouillon' => 'GET',
            // `sources(q)`, et `deposer()` qui y cherche une `sourceACreer` avant de la créer.
            'tree_admin_sources' => 'GET',
            // `media` : les pièces déjà jointes à un sujet, pour ne pas téléverser deux fois.
            'media_lister' => 'GET',
            // `demarches` : les actes déjà demandés, pour ne pas les demander deux fois.
            'demarche_liste' => 'GET',
            // `suivi` : le fil entier d'une démarche, avant d'y ajouter le courrier suivant.
            'demarche_detail' => 'GET',
        ],
        PorteeDeJeton::Contribution->value => [
            // `deposer()` (sans `valider`) : une demande entre dans la file, rien n'est
            // décidé. B2 ajoutera ici `POST /api/demandes/verifier` (le dépôt à blanc).
            'proposal_demande_creer' => 'POST',
            // `televerser` : la preuve d'un acte, jointe à une fiche ou à une fiche proposée.
            'media_televerser' => 'POST',
            // `demarche_*` : une demande d'acte envoyée…
            'demarche_creer' => 'POST',
            // … un courrier envoyé ou reçu…
            'demarche_message_ajouter' => 'POST',
            // … et son statut (reçue, refusée, sans réponse).
            'demarche_suivre' => 'PUT',
        ],
        PorteeDeJeton::Validation->value => [
            // Le diff du décideur (valeurs courantes, `resolutionPrevue`), relu avant de
            // trancher. Même règle que `valider` : `DemandeVoter::DECIDER`.
            'proposal_admin_demande_voir' => 'GET',
            // `valider`, et `deposer(valider=True)`.
            'proposal_admin_demande_valider' => 'POST',
            // `refuser`.
            'proposal_admin_demande_refuser' => 'POST',
            // `deposer(valider=True)` crée la `sourceACreer` que `sources(q)` n'a pas
            // trouvée : une écriture directe, sans file, donc pas pour les agents.
            'tree_admin_source_creer' => 'POST',
        ],
    ];

    /**
     * Les routes qu'aucune portée n'ouvre, par motif de nom. Jugées avant la liste blanche.
     *
     * @var list<string>
     */
    public const array EXCLUSIONS = [
        // Les comptes : liste, fiche, statut, rôles, quotas et mot de passe d'un utilisateur.
        '/^core_admin_utilisateur/',
        // Les réglages, plafonds de quotas compris.
        '/^core_admin_reglage/',
        '/^core_admin_quotas/',
        // L'incarnation (ADR 0010) : un jeton n'emprunte pas un autre compte.
        '/incarnation/',
        // « Voir comme un membre » : une bascule de session, qu'un jeton sans état n'a pas.
        '/voir_comme/',
        // Les jetons eux-mêmes (ADR 0021 § 2) : aucune route aujourd'hui, et celle qui
        // viendra est fermée d'avance. Un jeton n'en crée ni n'en révoque.
        '/jeton/',
        // Connexion et déconnexion : un jeton ne se connecte pas et ne se déconnecte pas
        // (`POST /api/deconnexion` par jeton levait une `LogicException`).
        '/^core_connexion/',
        '/^core_deconnexion$/',
        // Ce que protège la preuve d'identité récente : clés d'accès, mot de passe, courriel.
        '/cles?_d_acces/',
        '/mot_de_passe/',
        '/courriel/',
        // Qui entre : inscription, invitations, activation et refus des membres.
        '/^membership_inscription/',
        '/^membership_admin_invitation/',
        '/^membership_admin_membres?_/',
        // Les droits du compte sur ses données (RGPD), et son portrait.
        '/rgpd/',
        '/du_compte$/',
        // Le journal d'audit : il nomme les jetons, il ne se lit pas par eux.
        '/^core_admin_journal$/',
    ];

    public function __construct(
        private TokenStorageInterface $jetons,
        private RequeteParJetonDApi $requeteParJeton,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['surRequete', 7]];
    }

    public function surRequete(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requete = $event->getRequest();

        if (!$this->requeteParJeton->matches($requete)) {
            return;
        }

        $jeton = JetonDApiAuthentifie::depuisLeJetonDeSecurite($this->jetons->getToken());
        $route = $requete->attributes->get('_route');

        if (null === $jeton || !self::permet($jeton->portee, \is_string($route) ? $route : null, $requete->getMethod())) {
            throw new ForbiddenException('acces_refuse', 'Ce jeton d’API n’ouvre pas cette route.');
        }
    }

    /**
     * La route `$route`, appelée en `$methode`, est-elle ouverte à `$portee` ?
     *
     * `$listeBlanche` n'est un paramètre que pour éprouver le garde-fou des exclusions ;
     * l'application appelle toujours la liste de cette classe.
     *
     * @param array<string, array<string, string>> $listeBlanche
     */
    public static function permet(PorteeDeJeton $portee, ?string $route, string $methode, array $listeBlanche = self::LISTE_BLANCHE): bool
    {
        if (null === $route) {
            return false;
        }

        foreach (self::EXCLUSIONS as $motif) {
            if (1 === preg_match($motif, $route)) {
                return false;
            }
        }

        foreach (PorteeDeJeton::cases() as $niveau) {
            if ($portee->couvre($niveau) && ($listeBlanche[$niveau->value][$route] ?? null) === $methode) {
                return true;
            }
        }

        return false;
    }
}
