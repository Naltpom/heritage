<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\Sex;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Service\DepotDeDemande;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Proposal\Service\ValidationDeDemande;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Service\EcritureDeLArbrePourProposal;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * LE SOCLE DES TESTS QUI FONT PASSER UNE DEMANDE ENTIÈRE DANS LE VRAI ARBRE.
 *
 * ## Le trou qu'il existe pour combler
 *
 * Toute la suite de `Proposal` tourne sur `ArbreEnMemoire`, un double déclaré en
 * `when@test` dans `config/services/proposal.yaml`. C'est le bon choix pour éprouver la
 * logique de validation — le plan, les deux verrous, les révisions — sans traîner un
 * schéma. La conséquence est qu'**aucun** de ces tests ne touche une table `tree_*` : ils
 * passeraient à l'identique si le port n'écrivait rien.
 *
 * Les classes qui héritent d'ici comblent exactement ce trou, chacune pour sa nature : le
 * **vrai** port, une **vraie** base, et une ligne qu'on relit par une requête.
 *
 * ## La substitution du port, et pourquoi elle vise le service concret
 *
 * `EcritureDeLArbrePourProposal` est déclaré `public: true` en `when@test` dans
 * `config/services/tree.yaml`, pour ces tests-ci. On le récupère et on le **pose à la
 * place du double** avant de demander quoi que ce soit à `Proposal` : le conteneur
 * construit alors `DepotDeDemande` et `ValidationDeDemande` avec lui, pendant que tout le
 * reste — les tags, les itérateurs, les constructeurs, les applicateurs — reste celui de
 * la production. Un applicateur qui ne serait pas découvert ferait donc tomber ces tests
 * comme les autres.
 *
 * On substitue l'identifiant du **service concret** et non celui de l'interface : l'alias
 * `PortArbre` pointe sur `ArbreEnMemoire`, et c'est cet identifiant-là que les fabriques
 * du conteneur compilé vont chercher. Remplacer l'alias laisserait les services se
 * construire avec le double sans qu'aucune erreur ne le dise — le genre de silence que ce
 * projet paie cher. La substitution est donc **vérifiée**, pas supposée : « l'ordre de
 * précédence du conteneur ne se déduit pas, il se constate ».
 *
 * L'ordre n'est pas négociable non plus : un service déjà construit garde la dépendance
 * qu'il a reçue, d'où la substitution en dernier geste de `setUp()`.
 *
 * ## La base est SQLite, et ce qu'elle ne prouve pas est écrit ici
 *
 * `SchemaTool` ne pose que ce que le mapping exprime : ni les contraintes `CHECK` de la
 * migration — `ck_tree_citation_sujet_unique`, `ck_tree_note_sujet_unique` —, ni les
 * index partiels. Ces tests prouvent que le port écrit ce qu'il annonce ; c'est
 * PostgreSQL qui prouve que le schéma le tolère.
 */
abstract class BaseDeDemandeSurArbreReel extends KernelTestCase
{
    private const string BASE = 'sqlite:///:memory:';

    /** Le contributeur qui dépose, et l'administrateur qui tranche. */
    protected const int OPALE = 11;
    protected const int ADMIN = 1;

    private ?string $baseDOrigine = null;

    protected function setUp(): void
    {
        ArbreEnMemoire::oublierTout();

        $this->baseDOrigine = \is_string($_ENV['DATABASE_URL'] ?? null) ? $_ENV['DATABASE_URL'] : null;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::BASE;

        self::bootKernel();

        $em = $this->em();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        $em->persist(new Setting('tree.personne_racine_id', '0', SettingType::Entier, 'Personne racine'));
        $em->persist(new Setting('tree.seuil_vivant_annees', '110', SettingType::Entier, 'Seuil vivant'));
        $em->flush();
        $em->clear();

        $this->brancherLeVraiPort();
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->em()->getConnection()->close();
        }

        self::ensureKernelShutdown();

        if (null === $this->baseDOrigine) {
            unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $this->baseDOrigine;
        }

        parent::tearDown();
    }

    /** Pose l'implémentation réelle à la place du double, et **vérifie** que ça a pris. */
    private function brancherLeVraiPort(): void
    {
        $conteneur = self::getContainer();
        $conteneur->set(ArbreEnMemoire::class, $this->portReel());

        self::assertInstanceOf(
            EcritureDeLArbrePourProposal::class,
            $conteneur->get(PortArbre::class),
            'La substitution du port n\'a pas pris : ce test écrirait dans un double et ne prouverait rien.',
        );
    }

    protected function portReel(): EcritureDeLArbrePourProposal
    {
        $port = self::getContainer()->get(EcritureDeLArbrePourProposal::class);
        self::assertInstanceOf(EcritureDeLArbrePourProposal::class, $port);

        return $port;
    }

    protected function depot(): DepotDeDemande
    {
        $depot = self::getContainer()->get(DepotDeDemande::class);
        self::assertInstanceOf(DepotDeDemande::class, $depot);

        return $depot;
    }

    protected function validation(): ValidationDeDemande
    {
        $validation = self::getContainer()->get(ValidationDeDemande::class);
        self::assertInstanceOf(ValidationDeDemande::class, $validation);

        return $validation;
    }

    /** @param array<string, mixed> $corps */
    protected function deposerCorps(array $corps): ChangeRequest
    {
        return $this->depot()->deposer($corps, $this->membre());
    }

    protected function membre(): Lecteur
    {
        return new Lecteur(self::OPALE, 'Compte contributeur', administrateur: false);
    }

    protected function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }

    protected function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    protected function poserUnePersonne(string $prenoms, string $nom, Sex $sexe = Sex::Feminin): int
    {
        $personne = new Person(trim($prenoms.' '.$nom));
        $personne->setGivenNames($prenoms)->setSurname($nom)->setSex($sexe);

        $this->em()->persist($personne);
        $this->em()->flush();

        $id = $personne->getId();
        \assert(null !== $id);

        return $id;
    }

    protected function personne(int $id): Person
    {
        $personne = $this->em()->find(Person::class, $id);
        self::assertInstanceOf(Person::class, $personne);

        return $personne;
    }
}
