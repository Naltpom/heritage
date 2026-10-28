<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\EventSubscriber\PorteeDuJetonSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * La liste blanche des portées tient au routeur (ADR 0021 § 2) : chaque route qu'elle nomme
 * existe, avec sa méthode ; aucune n'est exclue ; et les exclusions tiennent même contre une
 * ligne ajoutée par erreur.
 *
 * Une route renommée sans que la liste suive fermerait son outil en silence — un `403` sur un
 * outil qui marchait la veille. Une route exclue glissée dans la liste ouvrirait un compte, un
 * réglage ou une incarnation à un jeton. Les deux se voient ici, avant la production.
 *
 * Le comportement en HTTP — refus avant le contrôleur, cookie inchangé, règle de l'auteur —
 * est dans {@see PorteesDesJetonsEnHttpTest}.
 */
final class PorteeDuJetonTest extends KernelTestCase
{
    /**
     * Les deux `POST` de la portée `lecture`, qui n'écrivent rien : le verbe garde la saisie
     * d'un vivant hors de l'URL. Tout autre verbe que `GET` en lecture serait une écriture.
     */
    private const array POST_SANS_ECRITURE = ['tree_ressemblances', 'proposal_demande_ressemblances'];

    public function testChaqueRouteDeLaListeExisteDansLeRouteurAvecSaMethode(): void
    {
        $routes = $this->routes();
        $vues = 0;

        foreach (self::entrees() as [$portee, $nom, $methode]) {
            $route = $routes->get($nom);
            self::assertNotNull($route, \sprintf('Portée « %s » : la route « %s » n’existe pas dans le routeur.', $portee, $nom));
            self::assertContains($methode, $route->getMethods(), \sprintf('Portée « %s » : « %s » ne répond pas en %s.', $portee, $nom, $methode));
            ++$vues;
        }

        // L'assiette : une liste devenue vide passerait ce test sans rien vérifier.
        self::assertGreaterThanOrEqual(20, $vues);
        self::assertSame(
            array_map(static fn (PorteeDeJeton $portee): string => $portee->value, PorteeDeJeton::cases()),
            array_keys(PorteeDuJetonSubscriber::LISTE_BLANCHE),
            'Une liste par portée, dans l’ordre des portées.',
        );
    }

    public function testAucuneRouteDeLaListeNEstExclueNiNommeeDeuxFois(): void
    {
        $nommees = [];

        foreach (self::entrees() as [$portee, $nom]) {
            foreach (PorteeDuJetonSubscriber::EXCLUSIONS as $motif) {
                self::assertDoesNotMatchRegularExpression($motif, $nom, \sprintf('Portée « %s » : « %s » est exclue pour toutes les portées.', $portee, $nom));
            }
            self::assertNotContains($nom, $nommees, \sprintf('« %s » est nommée deux fois : les portées sont cumulatives, une ligne suffit.', $nom));
            $nommees[] = $nom;
        }
    }

    public function testLaLectureNEcritRien(): void
    {
        foreach (PorteeDuJetonSubscriber::LISTE_BLANCHE[PorteeDeJeton::Lecture->value] as $nom => $methode) {
            if ('GET' !== $methode) {
                self::assertContains($nom, self::POST_SANS_ECRITURE, \sprintf('« %s » (%s) n’est pas une lecture.', $nom, $methode));
            }
        }
    }

    /**
     * Les familles que l'ADR 0021 et le plan excluent, route par route : refusées même au
     * jeton `validation`, quelle que soit leur méthode.
     */
    public function testLesRoutesDesComptesEtDeLaSecuriteSontExcluesPourToutesLesPortees(): void
    {
        $routes = $this->routes();
        $exclues = [
            // Utilisateurs.
            'core_admin_utilisateurs', 'core_admin_utilisateurs_compteurs', 'core_admin_utilisateur',
            'core_admin_utilisateur_modifier', 'core_admin_utilisateur_quotas', 'core_admin_utilisateur_mot_de_passe',
            // Réglages.
            'core_admin_reglages', 'core_admin_reglage_modifier', 'core_admin_quotas', 'core_admin_quotas_modifier',
            // Incarnation et voir-comme.
            'core_admin_incarnation', 'core_session_incarnation_quitter', 'core_admin_voir_comme', 'core_admin_voir_comme_quitter',
            // Connexion, déconnexion, clés d'accès, mot de passe, courriel.
            'core_connexion', 'core_connexion_cle_d_acces', 'core_connexion_google', 'core_deconnexion',
            'core_cles_d_acces_enregistrer', 'core_cles_d_acces_supprimer', 'core_session_mot_de_passe',
            'core_session_courriel', 'core_session_courriel_confirmer',
            // Qui entre, les droits du compte, le journal.
            'membership_inscription_compte', 'membership_admin_invitation_creer', 'membership_admin_membre_activer',
            'membership_admin_membres_lister', 'membership_demandes_rgpd_deposer', 'media_portrait_du_compte', 'core_admin_journal',
        ];

        foreach ($exclues as $nom) {
            $route = $routes->get($nom);
            self::assertNotNull($route, \sprintf('« %s » n’existe plus : mettre ce test à jour.', $nom));

            foreach ($route->getMethods() as $methode) {
                self::assertFalse(PorteeDuJetonSubscriber::permet(PorteeDeJeton::Validation, $nom, $methode), \sprintf('« %s » doit rester fermée aux jetons.', $nom));
            }
        }

        // Les jetons eux-mêmes n'ont pas encore de route : celle qui viendra est fermée d'avance.
        foreach (['core_jetons_api_lister', 'core_admin_jeton_api_creer', 'core_jeton_api_revoquer'] as $nom) {
            self::assertFalse(PorteeDuJetonSubscriber::permet(PorteeDeJeton::Validation, $nom, 'POST'));
        }
    }

    /** Un motif d'exclusion qui ne vise aucune route est une faute de frappe, qui n'exclut rien. */
    public function testChaqueExclusionViseAuMoinsUneRouteSaufCelleDesJetons(): void
    {
        $noms = array_keys($this->routes()->all());

        foreach (PorteeDuJetonSubscriber::EXCLUSIONS as $motif) {
            if ('/jeton/' === $motif) {
                self::assertSame([], preg_grep($motif, $noms), 'Une route de jetons est née : vérifier qu’elle est bien celle que ce motif ferme.');

                continue;
            }

            self::assertNotSame([], preg_grep($motif, $noms), \sprintf('Le motif %s ne vise aucune route.', $motif));
        }
    }

    /** Le garde-fou : une ligne ajoutée par erreur n'ouvre pas une route exclue. */
    public function testUneRouteExclueAjouteeParErreurResteFermee(): void
    {
        $listeFautive = PorteeDuJetonSubscriber::LISTE_BLANCHE;
        $listeFautive[PorteeDeJeton::Lecture->value] += [
            'core_admin_voir_comme' => 'POST',
            'core_deconnexion' => 'POST',
            'core_admin_utilisateur_modifier' => 'PATCH',
        ];

        foreach (PorteeDeJeton::cases() as $portee) {
            self::assertFalse(PorteeDuJetonSubscriber::permet($portee, 'core_admin_voir_comme', 'POST', $listeFautive));
            self::assertFalse(PorteeDuJetonSubscriber::permet($portee, 'core_deconnexion', 'POST', $listeFautive));
            self::assertFalse(PorteeDuJetonSubscriber::permet($portee, 'core_admin_utilisateur_modifier', 'PATCH', $listeFautive));
            // La même liste ouvre toujours ce qu'elle ouvrait : seules les exclusions tombent.
            self::assertTrue(PorteeDuJetonSubscriber::permet($portee, 'tree_graphe', 'GET', $listeFautive));
        }
    }

    public function testLesPorteesSontCumulativesEtFermeesParDefaut(): void
    {
        $lecture = PorteeDeJeton::Lecture;
        $contribution = PorteeDeJeton::Contribution;
        $validation = PorteeDeJeton::Validation;

        // Une route de lecture est ouverte aux trois.
        foreach (PorteeDeJeton::cases() as $portee) {
            self::assertTrue(PorteeDuJetonSubscriber::permet($portee, 'tree_graphe', 'GET'));
        }

        // Déposer : contribution et au-dessus.
        self::assertFalse(PorteeDuJetonSubscriber::permet($lecture, 'proposal_demande_creer', 'POST'));
        self::assertTrue(PorteeDuJetonSubscriber::permet($contribution, 'proposal_demande_creer', 'POST'));
        self::assertTrue(PorteeDuJetonSubscriber::permet($validation, 'proposal_demande_creer', 'POST'));

        // Le dépôt à blanc (ADR 0022) : la portée du dépôt, puisqu'il le prédit.
        self::assertFalse(PorteeDuJetonSubscriber::permet($lecture, 'proposal_demande_verifier', 'POST'));
        self::assertTrue(PorteeDuJetonSubscriber::permet($contribution, 'proposal_demande_verifier', 'POST'));
        self::assertTrue(PorteeDuJetonSubscriber::permet($validation, 'proposal_demande_verifier', 'POST'));

        // Décider : validation seulement.
        foreach (['proposal_admin_demande_valider', 'proposal_admin_demande_refuser'] as $decider) {
            self::assertFalse(PorteeDuJetonSubscriber::permet($lecture, $decider, 'POST'));
            self::assertFalse(PorteeDuJetonSubscriber::permet($contribution, $decider, 'POST'));
            self::assertTrue(PorteeDuJetonSubscriber::permet($validation, $decider, 'POST'));
        }

        // La méthode compte : la bonne route sous un autre verbe est refusée, `HEAD` compris.
        self::assertFalse(PorteeDuJetonSubscriber::permet($validation, 'tree_graphe', 'HEAD'));
        self::assertFalse(PorteeDuJetonSubscriber::permet($validation, 'tree_graphe', 'POST'));

        // Sans route (un chemin que le routeur n'a pas résolu), rien n'est ouvert.
        self::assertFalse(PorteeDuJetonSubscriber::permet($validation, null, 'GET'));

        // Fermé par défaut : de toutes les routes du routeur, le jeton `validation` n'ouvre
        // que celles de la liste, pas une de plus.
        $ouvertes = [];
        foreach ($this->routes()->all() as $nom => $route) {
            foreach ($route->getMethods() as $methode) {
                if (PorteeDuJetonSubscriber::permet($validation, $nom, $methode)) {
                    $ouvertes[] = $nom.' '.$methode;
                }
            }
        }
        $attendues = array_map(static fn (array $entree): string => $entree[1].' '.$entree[2], self::entrees());
        sort($ouvertes);
        sort($attendues);
        self::assertSame($attendues, $ouvertes);
    }

    /**
     * La liste blanche à plat, pour la parcourir sans réécrire la double boucle à chaque test.
     *
     * @return list<array{string, string, string}> [portée, route, méthode]
     */
    private static function entrees(): array
    {
        $entrees = [];
        foreach (PorteeDuJetonSubscriber::LISTE_BLANCHE as $portee => $routes) {
            foreach ($routes as $nom => $methode) {
                $entrees[] = [$portee, $nom, $methode];
            }
        }

        return $entrees;
    }

    private function routes(): RouteCollection
    {
        $routeur = self::getContainer()->get('router');
        \assert($routeur instanceof RouterInterface);

        return $routeur->getRouteCollection();
    }
}
