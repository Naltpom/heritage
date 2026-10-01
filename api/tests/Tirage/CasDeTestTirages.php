<?php

declare(strict_types=1);

namespace App\Tests\Tirage;

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
 * Le socle des tests du module `Tirage` : l'arbre du harnais, **plus quatre vivants**, et les
 * outils HTTP que tous ces tests partagent.
 *
 * ## Pourquoi quatre personnes de plus
 *
 * L'arbre de {@see ArbreWebTestCase} compte trois vivants — `ego`, `conjointe`, `cousin` — et
 * trois personnes que la règle unique déclare **non vivantes** : `aieule` et `aieul`
 * (décès enregistrés) et `sansDate` (repli par génération). Or un tirage n'accepte plus que
 * des vivants (ADR 0009, révision du 2026-10-01), et il en faut cinq pour éprouver le secret
 * avec un participant accompagné. On sème donc, ici seulement :
 *
 * | | qui | génération | rôle dans les tests |
 * |---|---|---|---|
 * | `oncle` | Basalte Romarin, né 1965 | −1 | participant ordinaire |
 * | `enfant` | Mica Romarin, née 2019 | 1 | **sans compte**, accompagné par `ego` |
 * | `tante` | Ardoise Tilleul, née 1968 | −1 | hors du « Noël côté papa » |
 * | `voisine` | Galène Tilleul, née 1970 | −1 | vivante **hors du cercle** d'`ego` |
 *
 * Les trois générations qu'ils occupent n'ont que des vivants : ils ne déplacent aucune
 * médiane dont dépend l'état des six personnes du harnais.
 *
 * ## Le cercle d'`ego`, et pourquoi `tante` en est exclue
 *
 * Un tirage n'inscrit que des personnes **du cercle** de qui les désigne (révision du
 * 2026-10-01 de l'ADR 0003 : le nom d'un vivant hors cercle ne sort plus, et un tirage
 * enregistre des noms). **Le cercle est le vrai**, `Membership\Service\CercleDeVisibilite`,
 * qui lit en test l'arbre par `ArbreEnMemoire` — comme les tests des démarches. On y pose
 * une parenté où `cousin` et `oncle` sont les frères d'`ego` (enfants d'`aieul` et
 * d'`aieule`), et `enfant` la fille d'`ego` et de `conjointe` : tous dans le cercle d'`ego`.
 * `tante` est l'épouse d'`oncle`, donc du cercle elle aussi (conjointe d'un collatéral).
 * `voisine` n'est rattachée à personne : vivante **hors** du cercle d'`ego`, c'est le cas que
 * {@see RoutesDesTiragesTest::testUnVivantHorsDuCercleNEntrePasDansUnTirageEtSonNomNeSortPas()}
 * éprouve. Cette parenté n'existe qu'en mémoire : la base, et donc le graphe, n'en savent
 * rien — seuls le cercle et ses refus en dépendent.
 *
 * ## Le limiteur
 *
 * Le pool `cache.rate_limiter` est vidé à chaque test : les compteurs des liens publics
 * (et ceux de la connexion) survivraient sinon d'un test à l'autre dans le même processus.
 */
abstract class CasDeTestTirages extends ArbreWebTestCase
{
    protected const string OPALE = 'opale@example.invalid';   // ego, organisateur
    protected const string JASPE = 'jaspe@example.invalid';   // conjointe
    protected const string ONYX = 'onyx@example.invalid';     // cousin
    protected const string AMBRE = 'ambre@example.invalid';   // tante, hors du tirage
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

    /**
     * « Noël côté papa » : ego, conjointe, cousin et oncle, puis `enfant` — qui n'a pas de
     * compte — accompagné par ego.
     */
    protected function tirageDeNoel(bool $ouvert = false): int
    {
        $this->connexionDe(self::OPALE);
        $this->envoyer('POST', '/api/tirages', ['ouvert' => $ouvert] + $this->regles() + ['participants' => [
            $this->idDe('ego'),
            $this->idDe('conjointe'),
            $this->idDe('cousin'),
            $this->idDe('oncle'),
        ]]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        $id = $this->charge()['id'];
        self::assertIsInt($id);

        $this->envoyer('POST', "/api/tirages/{$id}/participants", [
            'personId' => $this->idDe('enfant'),
            'referentPersonId' => $this->idDe('ego'),
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statut(), (string) $this->client->getResponse()->getContent());

        return $id;
    }

    /** @return array<string, mixed> */
    protected function regles(): array
    {
        return ['nom' => 'Noël côté papa', 'jour' => 24, 'mois' => 12, 'lieu' => 'Chez les grands-parents', 'memoire' => 2, 'exclureConjoints' => true];
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
