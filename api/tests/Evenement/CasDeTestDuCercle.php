<?php

declare(strict_types=1);

namespace App\Tests\Evenement;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Tree\Entity\Person;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Tree\ArbreWebTestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un arbre de famille **avec ses vivants, ses comptes et son cercle**, et les outils HTTP qui
 * vont avec : le socle de {@see CasDeTestEvenements}.
 *
 * Il est né pour les tests du module `Tirage` (retiré le 2026-10-20, ADR 0009 remplacé), dont
 * les événements héritaient déjà plutôt que de recopier deux cents lignes. Il a survécu au
 * module, sous ce nom neutre : rien de ce qu'il sème ne parle de tirage.
 *
 * ## Pourquoi quatre personnes de plus
 *
 * L'arbre de {@see ArbreWebTestCase} compte trois vivants — `ego`, `conjointe`, `cousin` — et
 * trois personnes que la règle unique déclare **non vivantes** : `aieule` et `aieul`
 * (décès enregistrés) et `sansDate` (repli par génération). Une invitation n'a de sens que
 * pour des vivants, avec et sans compte. On sème donc, ici seulement :
 *
 * | | qui | génération | rôle dans les tests |
 * |---|---|---|---|
 * | `oncle` | Basalte Romarin, né 1965 | −1 | vivant **sans compte** |
 * | `enfant` | Mica Romarin, née 2019 | 1 | **sans compte**, fille d'`ego` |
 * | `tante` | Ardoise Tilleul, née 1968 | −1 | avec un compte, souvent tenue à l'écart |
 * | `voisine` | Galène Tilleul, née 1970 | −1 | vivante **hors du cercle** d'`ego` |
 *
 * Les trois générations qu'ils occupent n'ont que des vivants : ils ne déplacent aucune
 * médiane dont dépend l'état des six personnes du harnais.
 *
 * ## Le cercle d'`ego`, et qui en est
 *
 * Un module qui enregistre des noms n'inscrit que des personnes **du cercle** de qui les
 * désigne (révision du 2026-10-01 de l'ADR 0003 : le nom d'un vivant hors cercle ne sort
 * plus). **Le cercle est le vrai**, `Membership\Service\CercleDeVisibilite`, qui lit en test
 * l'arbre par `ArbreEnMemoire` — comme les tests des démarches. On y pose une parenté où
 * `cousin` et `oncle` sont les frères d'`ego` (enfants d'`aieul` et d'`aieule`), et
 * `enfant` la fille d'`ego` et de `conjointe` : tous dans le cercle d'`ego`. `tante` est
 * l'épouse d'`oncle`, donc du cercle elle aussi (conjointe d'un collatéral). `voisine`
 * n'est rattachée à personne : vivante **hors** du cercle d'`ego`. Cette parenté n'existe
 * qu'en mémoire : la base, et donc le graphe, n'en savent rien — seuls le cercle et ses
 * refus en dépendent.
 *
 * ## Le limiteur
 *
 * Le pool `cache.rate_limiter` est vidé à chaque test : les compteurs des liens publics
 * (et ceux de la connexion) survivraient sinon d'un test à l'autre dans le même processus.
 */
abstract class CasDeTestDuCercle extends ArbreWebTestCase
{
    protected const string OPALE = 'opale@example.invalid';   // ego, organisateur
    protected const string JASPE = 'jaspe@example.invalid';   // conjointe
    protected const string ONYX = 'onyx@example.invalid';     // cousin
    protected const string AMBRE = 'ambre@example.invalid';   // tante
    protected const string GRENAT = 'grenat@example.invalid'; // aieul, décédé — un rattachement impossible en vrai
    protected const string TUF = 'tuf@example.invalid';       // aucun rattachement

    protected function setUp(): void
    {
        parent::setUp();

        $pool = self::getContainer()->get('cache.rate_limiter');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }

        $this->semerLesVivants();
        $this->semerLeCercle();

        $this->rattacher(self::OPALE, 'ego');
        $this->rattacher(self::JASPE, 'conjointe');
        $this->rattacher(self::ONYX, 'cousin');
        $this->rattacher(self::AMBRE, 'tante');
        $this->rattacher(self::GRENAT, 'aieul');
        $this->creerUtilisateur(self::TUF);
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();

        parent::tearDown();
    }

    /** @param array<string, mixed>|null $corps */
    protected function envoyer(string $methode, string $chemin, ?array $corps = null): void
    {
        $this->client->request($methode, $chemin, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $corps ? '' : json_encode($corps, \JSON_THROW_ON_ERROR));
    }

    protected function statut(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function codeDErreur(): mixed
    {
        $erreur = $this->charge()['erreur'] ?? null;
        self::assertIsArray($erreur);

        return $erreur['code'] ?? null;
    }

    protected function userIdDe(string $courriel): int
    {
        $utilisateur = $this->em()->getRepository(User::class)->findOneBy(['email' => $courriel]);
        self::assertNotNull($utilisateur);

        return (int) $utilisateur->getId();
    }

    protected function connexionDe(string $courriel): void
    {
        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->statut(), 'La connexion de test doit réussir.');
    }

    /** Ferme la session : la requête suivante part **sans** cookie. */
    protected function deconnexion(): void
    {
        $this->client->getCookieJar()->clear();
    }

    protected function rattacher(string $courriel, string $personne): void
    {
        $identifiant = $this->creerUtilisateur($courriel)->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $courriel, $this->idDe($personne), $this->personnes[$personne]->getDisplayName(), MatchKind::PreLie);
        $membre->activer();

        $this->em()->persist($membre);
        $this->em()->flush();
    }

    /**
     * Voir l'en-tête : le cercle d'`ego` couvre tout le monde, sauf `voisine` — posée
     * **confidentielle** par défaut, puisque depuis la révision du 2026-10-01 de l'ADR 0003
     * un membre voit tout vivant non confidentiel, hors cercle compris.
     */
    protected function semerLeCercle(bool $voisineConfidentielle = true): void
    {
        ArbreEnMemoire::reinitialiser();

        foreach ([
            'ego' => true, 'conjointe' => true, 'cousin' => true, 'oncle' => true, 'enfant' => true, 'tante' => true, 'voisine' => true,
            'aieule' => false, 'aieul' => false, 'sansDate' => false,
        ] as $cle => $vivante) {
            $nom = $this->personnes[$cle]->getDisplayName();
            ArbreEnMemoire::ajouterPersonne($this->idDe($cle), $nom, '', $vivante, libelle: $nom, confidentielle: $voisineConfidentielle && 'voisine' === $cle);
        }

        ArbreEnMemoire::ajouterFamille([$this->idDe('aieul'), $this->idDe('aieule')], [$this->idDe('ego'), $this->idDe('cousin'), $this->idDe('oncle')]);
        ArbreEnMemoire::ajouterFamille([$this->idDe('ego'), $this->idDe('conjointe')], [$this->idDe('enfant')]);
        ArbreEnMemoire::ajouterFamille([$this->idDe('oncle'), $this->idDe('tante')]);

        // Un cercle se met en cache (`cache.app`, 24 h) sous une clé que l'empreinte de
        // l'arbre en mémoire ne fait pas bouger d'un test à l'autre.
        $pool = self::getContainer()->get('cache.app');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }
    }

    private function semerLesVivants(): void
    {
        $em = $this->em();

        foreach ([
            'oncle' => ['Basalte', 'Romarin', Sex::Masculin, -1, 1965],
            'enfant' => ['Mica', 'Romarin', Sex::Feminin, 1, 2019],
            'tante' => ['Ardoise', 'Tilleul', Sex::Feminin, -1, 1968],
            'voisine' => ['Galène', 'Tilleul', Sex::Feminin, -1, 1970],
        ] as $cle => [$prenom, $nom, $sexe, $generation, $annee]) {
            $personne = new Person($prenom.' '.$nom);
            $personne
                ->setGivenNames($prenom)
                ->setSurname($nom)
                ->setSearchKey((new CleDeRecherche())->cleDeNom($prenom, $nom))
                ->setSex($sexe)
                ->setGeneration($generation)
                ->setBranch(Branch::Tr)
                ->setBirth(new UncertainDate((string) $annee, DateQualifier::Annee, new \DateTimeImmutable($annee.'-01-01'), new \DateTimeImmutable($annee.'-12-31')))
                ->setDeath(UncertainDate::inconnue());

            $em->persist($personne);
            $this->personnes[$cle] = $personne;
        }

        $em->flush();
    }
}
