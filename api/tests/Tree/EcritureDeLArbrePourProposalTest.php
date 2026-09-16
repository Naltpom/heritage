<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Service\Port\PersonneAcreer;
use App\Module\Proposal\Service\Port\PortArbre;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Citation;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Source;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Service\EcritureDeLArbrePourProposal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'écriture de `Tree` par une proposition validée, contre une vraie base.
 *
 * **Pourquoi ce test existe alors que `tests/Proposal` compte 89 tests verts.** Toute
 * cette suite tourne sur `ArbreEnMemoire`, un double déclaré en `when@test` : elle éprouve
 * la logique de validation — le plan, les deux verrous, les révisions, les notifications —
 * et c'est le bon choix, elle n'a pas besoin d'un schéma pour cela. Mais la conséquence
 * est qu'**aucun de ces 89 tests ne touche `tree_person`** : ils passaient déjà quand le
 * port levait à chaque appel. Le trou est exactement ici, et ce fichier le comble.
 *
 * La base est une SQLite **en mémoire**, bâtie par `SchemaTool` : la CI ne monte aucun
 * service de base de données. Conséquence à connaître — `SchemaTool` ne pose que ce que
 * le mapping exprime, donc ni les contraintes `CHECK` de la migration, ni
 * `uniq_tree_family_couple` en `NULLS NOT DISTINCT`. Ce que ce test prouve, c'est que le
 * port écrit ce qu'il annonce ; c'est PostgreSQL qui prouve que le schéma le tolère.
 */
final class EcritureDeLArbrePourProposalTest extends KernelTestCase
{
    private const string BASE = 'sqlite:///:memory:';

    private ?string $baseDOrigine = null;

    protected function setUp(): void
    {
        $this->baseDOrigine = \is_string($_ENV['DATABASE_URL'] ?? null) ? $_ENV['DATABASE_URL'] : null;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = self::BASE;

        self::bootKernel();

        $em = $this->em();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        $em->persist(new Setting('tree.personne_racine_id', '0', SettingType::Entier, 'Personne racine'));
        $em->persist(new Setting('tree.seuil_vivant_annees', '110', SettingType::Entier, 'Seuil vivant'));
        $em->flush();
        $em->clear();
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

    // ------------------------------------------------------------------ lecture

    public function testUneCibleAbsenteRendNullEtNeLevePas(): void
    {
        // `cible_disparue` est une **caducité prévue**, pas une panne : lever ici ferait
        // passer pour une erreur un événement que le § 6.8 nomme et attend.
        self::assertNull($this->port()->chargerCible(SubjectKind::Personne, 4242));
    }

    public function testLEtatCibleRendTousLesChampsEditables(): void
    {
        $id = $this->poserUnePersonne('Marne Dolomite', 'Sureau');

        $etat = $this->port()->chargerCible(SubjectKind::Personne, $id);

        self::assertNotNull($etat);
        self::assertSame('Marne Dolomite Sureau', $etat->libelle);
        self::assertSame(1, $etat->version);
        // Tous les champs, jamais un sous-ensemble : le verrou n° 2 compare ces valeurs
        // aux `old_value` des ops, et ce qu'il ne voit pas, il ne peut pas refuser.
        self::assertSame(
            ['givenNames', 'surname', 'marriedName', 'nickname', 'sex', 'deceased', 'birth', 'death', 'forcePrivacy', 'privacyNote'],
            array_keys($etat->valeurs),
        );
        // Les énumérations traversent le port en **chaîne** : `Proposal` ne peut pas
        // nommer `Tree\Enum\…` sans enfreindre la règle 3 de l'ADR 0002.
        self::assertSame('feminin', $etat->valeurs['sex']);
        self::assertInstanceOf(UncertainDate::class, $etat->valeurs['birth']);
    }

    /**
     * La même obligation pour les deux autres cibles versionnées.
     *
     * Les trois listes doivent correspondre **exactement** à
     * `Proposal\Service\SurfaceEditable` : un champ que la surface autorise et que le port
     * ne rend pas est un champ sur lequel le verrou n° 2 ne peut rien détecter — une
     * demande caduque passerait pour valide, en silence.
     */
    public function testLEtatCibleRendTousLesChampsDesFamillesEtDesCitations(): void
    {
        $famille = $this->port()->trouverOuCreerFamille(
            $this->poserUnePersonne('Julien', 'Persil', Sex::Masculin),
            null,
        )->id;

        $etatFamille = $this->port()->chargerCible(SubjectKind::Famille, $famille);
        self::assertNotNull($etatFamille);
        self::assertSame(['marriage', 'divorced', 'divorce'], array_keys($etatFamille->valeurs));
        self::assertSame('Julien Persil', $etatFamille->libelle);

        $citation = $this->poserUneCitation($this->poserUnePersonne('Onyx', 'Thym'), CitationQuality::ActeOuRegistre);

        $etatCitation = $this->port()->chargerCible(SubjectKind::Citation, $citation);
        self::assertNotNull($etatCitation);
        self::assertSame(['page', 'quay', 'dataText'], array_keys($etatCitation->valeurs));
        // `quay` traverse en chaîne comme toute énumération au bord du port, bien qu'il
        // soit adossé à `int` — l'exception assumée du § 2.7 du modèle.
        self::assertSame(2, $etatCitation->valeurs['quay']);
    }

    /** Les cibles que ce port ne sert pas se signalent franchement, plutôt que de rendre `null`. */
    public function testUneCibleHorsDesTroisTablesVersionneesEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->port()->chargerCible(SubjectKind::Media, 1);
    }

    // ------------------------------------------------------------------ écriture

    /**
     * Le cas du § 9.6 : Marne Dolomite Sureau, que MyHeritage faisait naître en 1987,
     * après ses propres enfants. La correction porte une **fourchette**, et elle doit
     * arriver en base en fourchette.
     */
    public function testAppliquerDesChampsEcritEtIncrementeLaVersion(): void
    {
        $id = $this->poserUnePersonne('Marne Dolomite', 'Sureau');

        $applique = $this->port()->appliquerChamps(SubjectKind::Personne, $id, [
            'birth' => new UncertainDate(
                'BET 1901 AND 1909',
                DateQualifier::Entre,
                new \DateTimeImmutable('1901-01-01'),
                new \DateTimeImmutable('1909-12-31'),
            ),
        ]);

        self::assertSame(1, $applique->versionAvant);
        self::assertSame(2, $applique->versionApres, 'Le #[ORM\Version] doit s’exercer, donc le flush est obligatoire.');

        $this->em()->clear();
        $personne = $this->personne($id);

        self::assertSame(DateQualifier::Entre, $personne->getBirth()->getQualifier());
        self::assertSame('1901-01-01', $personne->getBirth()->getMin()?->format('Y-m-d'));
        self::assertSame('1909-12-31', $personne->getBirth()->getMax()?->format('Y-m-d'));
        self::assertSame('entre 1901 et 1909', $personne->getBirth()->format());
    }

    /**
     * `display_name` et `search_key` suivent les noms — sinon une fiche renommée reste
     * introuvable sous son nouveau nom, et l'historique la désigne par un nom qu'elle
     * n'a plus.
     *
     * ── ASSERTION RETOURNÉE : `BREVAL HELENE` → `BREVAL HELENE LENOU` ───────────────
     *
     * Ce test **épinglait le défaut en vert**. Il affirmait `BREVAL HELENE` après une op
     * `nickname`, c'est-à-dire exactement la phrase de son propre en-tête à l'envers :
     * la fiche s'affiche « Lénou Bréval » et la clé ne portait pas « LENOU », donc la
     * fiche **était** « introuvable sous son nouveau nom ». Le test décrivait le bon
     * invariant et vérifiait son contraire.
     *
     * Mesuré sur la base de travail avant correction : 12 fiches portent un surnom, **3**
     * l'avaient hors de leur clé. `EcritureDeLArbrePourProposal` passe désormais les
     * **quatre** champs de nom aux deux colonnes, et non deux à l'une et trois à l'autre.
     *
     * Le surnom vient **en dernier** : le patronyme doit rester en tête, sans quoi
     * `CleDeRechercheTest::testLeNomPasseAvantLesPrenoms()` tomberait à juste titre et
     * `idx_tree_person_search` — un index de préfixe — deviendrait inutilisable.
     */
    public function testLesColonnesDenormaliseesSuiventLesNoms(): void
    {
        $id = $this->poserUnePersonne('Hélène', 'Thym');

        $applique = $this->port()->appliquerChamps(SubjectKind::Personne, $id, [
            'surname' => 'Bréval',
            'nickname' => 'Lénou',
        ]);

        self::assertSame('Lénou Bréval', $applique->libelle, 'La révision doit porter le libellé d’après.');

        $this->em()->clear();
        $personne = $this->personne($id);

        self::assertSame('Lénou Bréval', $personne->getDisplayName());
        self::assertSame(
            'BREVAL HELENE LENOU',
            $personne->getSearchKey(),
            'Le surnom pilote le nom d’affichage : il doit donc entrer dans la clé, sinon la fiche est affichée sous un nom que la recherche ignore.',
        );
    }

    /**
     * `ck_tree_person_privacy_motivee` refuse un caviardage sans motif. Les deux champs
     * arrivant comme deux ops indépendantes, les appliquer dans l'ordre reçu produirait
     * une ligne refusée une fois sur deux.
     */
    public function testLaConfidentialiteEtSonMotifSePosentEnsemble(): void
    {
        $id = $this->poserUnePersonne('Camille', 'Persil');
        $port = $this->port();

        $port->appliquerChamps(SubjectKind::Personne, $id, [
            'privacyNote' => 'Personne vivante, consignée pour ne pas être redéfaite.',
            'forcePrivacy' => true,
        ]);

        $this->em()->clear();
        self::assertTrue($this->personne($id)->isForcePrivacy());
        self::assertNotNull($this->personne($id)->getPrivacyNote());

        $this->expectException(\InvalidArgumentException::class);
        $port->appliquerChamps(SubjectKind::Personne, $this->poserUnePersonne('Sans', 'Motif'), ['forcePrivacy' => true]);
    }

    /** Changer un `QUAY` déplace la fiche entre « appuyée sur un acte » et « recopie non vérifiée ». */
    public function testModifierUneCitationRafraichitLesAgregatsDeLaPersonne(): void
    {
        $id = $this->poserUnePersonne('Julien', 'Persil');
        $citation = $this->poserUneCitation($id, CitationQuality::RecopieNonVerifiee);

        $this->em()->clear();
        self::assertSame(0, $this->personne($id)->getBestQuay());

        $this->port()->appliquerChamps(SubjectKind::Citation, $citation, ['quay' => '2']);

        $this->em()->clear();
        self::assertSame(2, $this->personne($id)->getBestQuay());
        self::assertSame(1, $this->personne($id)->getCitationCount());
    }

    public function testUnQuayHorsNormeEstRefuse(): void
    {
        $citation = $this->poserUneCitation($this->poserUnePersonne('Onyx', 'Thym'), null);

        $this->expectException(\InvalidArgumentException::class);
        $this->port()->appliquerChamps(SubjectKind::Citation, $citation, ['quay' => '7']);
    }

    // ------------------------------------------------------------------ création

    /**
     * La passe 2 du § 9.5 : `Tree` copie les colonnes, compose les dénormalisées, et pose
     * `gedcom_xref` **après** le `flush` — un identifiant GEDCOM se dérive de la clé
     * primaire, qui n'existe pas avant (§ 9.8).
     */
    public function testCreerUnePersonnePoseSonIdentifiantGedcomApresLeFlush(): void
    {
        $creee = $this->port()->creerPersonne(new PersonneAcreer(
            givenNames: 'Adèle',
            surname: 'Bréval',
            marriedName: null,
            nickname: null,
            sex: 'feminin',
            deceased: false,
            birth: UncertainDate::inconnue(),
            death: UncertainDate::inconnue(),
            note: 'Proposée par un membre.',
        ));

        self::assertTrue($creee->creee);
        self::assertSame('Adèle Bréval', $creee->libelle);

        $this->em()->clear();
        $personne = $this->personne($creee->id);

        // Le préfixe `9` distingue au premier coup d'œil une fiche créée dans
        // l'application de la plage `@I5xxxxx@` de MyHeritage, dans un fichier exporté.
        self::assertSame(\sprintf('@I9%05d@', $creee->id), $personne->getGedcomXref());
        self::assertSame('BREVAL ADELE', $personne->getSearchKey());
        self::assertSame(Sex::Feminin, $personne->getSex());

        // La note du demandeur devient une note de recherche, sans auteur applicatif.
        $notes = $this->em()->getRepository(Note::class)->findAll();
        self::assertCount(1, $notes);
        self::assertSame('Proposée par un membre.', $notes[0]->getBody());
    }

    /**
     * Le couple se range **selon le sexe**, pas selon l'ordre des arguments.
     */
    public function testLeCoupleEstRangeSelonLeSexeEtNonSelonLOrdre(): void
    {
        $femme = $this->poserUnePersonne('Hélène', 'Thym', Sex::Feminin);
        $homme = $this->poserUnePersonne('Julien', 'Persil', Sex::Masculin);

        // La femme est donnée en premier : c'est le sexe qui doit ranger.
        $creee = $this->port()->trouverOuCreerFamille($femme, $homme);

        $this->em()->clear();
        $famille = $this->em()->find(Family::class, $creee->id);
        self::assertInstanceOf(Family::class, $famille);

        self::assertSame($homme, $famille->getHusband()?->getId());
        self::assertSame($femme, $famille->getWife()?->getId());
        self::assertSame(\sprintf('@F9%05d@', $creee->id), $famille->getGedcomXref());
    }

    /** Une personne de sexe inconnu prend l'emplacement libre. */
    public function testUnSexeInconnuPrendLaPlaceRestante(): void
    {
        $inconnu = $this->poserUnePersonne('', '', Sex::Inconnu);
        $femme = $this->poserUnePersonne('Marthe', 'Bréval', Sex::Feminin);

        $creee = $this->port()->trouverOuCreerFamille($inconnu, $femme);

        $this->em()->clear();
        $famille = $this->em()->find(Family::class, $creee->id);
        self::assertInstanceOf(Family::class, $famille);
        self::assertSame($inconnu, $famille->getHusband()?->getId());
        self::assertSame($femme, $famille->getWife()?->getId());
    }

    /**
     * **Une famille retrouvée ne produit pas de révision.** Écrire « famille 104 créée »
     * alors qu'elle existe depuis l'import mettrait dans l'historique d'une fiche un
     * événement qui n'a pas eu lieu.
     */
    public function testUneFamilleDejaPresenteEstRetrouveeEtNonRecreee(): void
    {
        $homme = $this->poserUnePersonne('Gustave', 'Bréval', Sex::Masculin);
        $femme = $this->poserUnePersonne('Silice', 'Origan', Sex::Feminin);
        $port = $this->port();

        $premiere = $port->trouverOuCreerFamille($homme, $femme);
        $seconde = $port->trouverOuCreerFamille($femme, $homme);

        self::assertTrue($premiere->creee);
        self::assertFalse($seconde->creee, 'Le couple existe déjà, quel que soit l’ordre des arguments.');
        self::assertSame($premiere->id, $seconde->id);
        self::assertSame(1, $this->compter(Family::class));
    }

    /**
     * Une famille de parent seul est légitime — 3 cas dans le fichier réel. La retrouver
     * exige un `IS NULL` explicite : en SQL, `x = NULL` n'est jamais vrai, et on en
     * créerait une seconde à chaque validation.
     */
    public function testUneFamilleDeParentSeulEstRetrouvee(): void
    {
        $mere = $this->poserUnePersonne('Marthe', 'Bréval', Sex::Feminin);
        $port = $this->port();

        $premiere = $port->trouverOuCreerFamille($mere, null);
        $seconde = $port->trouverOuCreerFamille(null, $mere);

        self::assertTrue($premiere->creee);
        self::assertFalse($seconde->creee);
        self::assertSame(1, $this->compter(Family::class));
    }

    public function testUneFamilleSansAucunEpouxEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->port()->trouverOuCreerFamille(null, null);
    }

    // ------------------------------------------------------------------ liens

    public function testPorterUneUnionEcritSesDates(): void
    {
        $famille = $this->port()->trouverOuCreerFamille(
            $this->poserUnePersonne('Julien', 'Persil', Sex::Masculin),
            $this->poserUnePersonne('Hélène', 'Thym', Sex::Feminin),
        )->id;

        $this->port()->porterUnion(
            $famille,
            new UncertainDate('4 JUL 1988', DateQualifier::Exacte, new \DateTimeImmutable('1988-07-04'), new \DateTimeImmutable('1988-07-04')),
            true,
            new UncertainDate('1998', DateQualifier::Annee, new \DateTimeImmutable('1998-01-01'), new \DateTimeImmutable('1998-12-31')),
        );

        $this->em()->clear();
        $entite = $this->em()->find(Family::class, $famille);
        self::assertInstanceOf(Family::class, $entite);

        self::assertSame('4 juillet 1988', $entite->getMarriage()->format());
        self::assertTrue($entite->isDivorced());
        self::assertSame('1998', $entite->getDivorce()->format());
    }

    /**
     * Rejouer un lien que l'arbre porte déjà ne doit pas lever : refuser la demande ici,
     * ce serait la refuser pour avoir eu raison.
     */
    public function testRattacherDeuxFoisLeMemeEnfantNeLevePas(): void
    {
        $famille = $this->port()->trouverOuCreerFamille(
            $this->poserUnePersonne('Gustave', 'Bréval', Sex::Masculin),
            null,
        )->id;
        $enfant = $this->poserUnePersonne('Marthe', 'Bréval', Sex::Feminin);

        $this->port()->rattacherEnfant($famille, $enfant, null);
        $this->port()->rattacherEnfant($famille, $enfant, null);

        self::assertSame(1, $this->compter(Child::class));
    }

    /** Sans rang connu, l'enfant se place à la fin — `Tree` seul sait combien il y en a déjà. */
    public function testUnEnfantSansRangSePlaceALaFin(): void
    {
        $famille = $this->port()->trouverOuCreerFamille(
            $this->poserUnePersonne('Gustave', 'Bréval', Sex::Masculin),
            null,
        )->id;

        $this->port()->rattacherEnfant($famille, $this->poserUnePersonne('Aîné', 'Bréval'), null);
        $this->port()->rattacherEnfant($famille, $this->poserUnePersonne('Cadet', 'Bréval'), null);

        $this->em()->clear();
        $rangs = array_map(
            static fn (Child $enfant): int => $enfant->getSiblingOrder(),
            $this->em()->getRepository(Child::class)->findBy(['family' => $famille], ['siblingOrder' => 'ASC']),
        );

        self::assertSame([0, 1], $rangs);
    }

    /**
     * **Les colonnes dérivées suivent une écriture structurante** (§ 3.1) : une personne
     * rattachée reçoit sa génération et sa branche sans que personne ne pense à les poser.
     */
    public function testRattacherUnEnfantRecalculeLesColonnesDerivees(): void
    {
        $ego = $this->poserUnePersonne('Camille', 'Persil', Sex::Feminin);
        $this->poserLaRacine($ego);

        $pere = $this->poserUnePersonne('Julien', 'Persil', Sex::Masculin);
        $famille = $this->port()->trouverOuCreerFamille($pere, null)->id;
        $this->port()->rattacherEnfant($famille, $ego, null);

        $this->em()->clear();

        self::assertSame(0, $this->personne($ego)->getGeneration(), 'L’ego est à 0 par définition.');
        self::assertSame(-1, $this->personne($pere)->getGeneration());

        $entite = $this->em()->find(Family::class, $famille);
        self::assertInstanceOf(Family::class, $entite);
        self::assertSame(-1, $entite->getGeneration());
        // Rangs entrelacés : `rang = 2 × génération + 1`.
        self::assertSame(-1, $entite->getRank());
    }

    /**
     * **Sans racine, aucune branche n'est posée** — et surtout, rien n'échoue. C'est
     * l'état d'une base migrée mais pas encore importée.
     */
    public function testSansRacineLesColonnesDeriveesRestentNulles(): void
    {
        $creee = $this->port()->creerPersonne($this->fiche('Isolée', 'Sansracine'));

        $this->em()->clear();
        self::assertNull($this->personne($creee->id)->getBranch());
    }

    // ------------------------------------------------------------------ empreinte

    /**
     * L'empreinte est **mémoïsée** pour la durée de la requête. Sans l'oublier avant de
     * la relire, la révision porterait celle d'**avant** l'écriture — donc une clé de
     * cache déjà servie, donc un graphe périmé rendu comme s'il était neuf.
     */
    public function testLEmpreinteChangeApresUneEcriture(): void
    {
        $port = $this->port();
        $avant = $port->empreinteDeLArbre();

        $port->creerPersonne($this->fiche('Nouvelle', 'Personne'));

        self::assertNotSame($avant, $port->empreinteDeLArbre());
    }

    /**
     * **`OptimisticLockException` doit remonter telle quelle**, et c'est la garantie la
     * plus facile à casser sans s'en apercevoir : un `try/catch` bien intentionné autour
     * du `flush` la transformerait en erreur générique, et le front perdrait la
     * distinction entre `409 verrou_concurrent` — « réessaye » — et `demande_caduque`,
     * qui dit exactement l'inverse.
     *
     * On simule le writer concurrent par un `UPDATE` direct : c'est ce qui se passe
     * quand une autre requête valide une demande sur la même fiche entre le chargement du
     * plan et son application.
     */
    public function testLeVerrouOptimisteRemonteTelQuel(): void
    {
        $id = $this->poserUnePersonne('Marne Dolomite', 'Sureau');
        $port = $this->port();

        // La fiche est chargée dans l'unité de travail, à la version 1.
        $port->chargerCible(SubjectKind::Personne, $id);

        // Une autre transaction passe et l'écrit : la version en base avance, celle que
        // Doctrine tient en mémoire non.
        $this->em()->getConnection()->executeStatement(
            'UPDATE tree_person SET version = version + 1 WHERE id = ?',
            [$id],
        );

        $this->expectException(OptimisticLockException::class);
        $port->appliquerChamps(SubjectKind::Personne, $id, ['surname' => 'Sarriette']);
    }

    /** L'implémentation réelle est bien celle que le conteneur injecte hors test. */
    public function testLeConteneurExposeBienUneImplementationDePortArbre(): void
    {
        self::assertInstanceOf(PortArbre::class, $this->port());
    }

    // ------------------------------------------------------------------ outillage

    private function port(): EcritureDeLArbrePourProposal
    {
        $port = self::getContainer()->get(EcritureDeLArbrePourProposal::class);
        self::assertInstanceOf(EcritureDeLArbrePourProposal::class, $port);

        return $port;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function poserUnePersonne(string $prenoms, string $nom, Sex $sexe = Sex::Feminin): int
    {
        $personne = new Person(trim($prenoms.' '.$nom) ?: '?');
        $personne->setGivenNames($prenoms)->setSurname($nom)->setSex($sexe);

        $this->em()->persist($personne);
        $this->em()->flush();

        $id = $personne->getId();
        \assert(null !== $id);

        return $id;
    }

    private function poserLaRacine(int $personneId): void
    {
        $reglage = $this->em()->getRepository(Setting::class)->findOneBy(['settingKey' => 'tree.personne_racine_id']);
        self::assertInstanceOf(Setting::class, $reglage);

        $reglage->setValue((string) $personneId);
        $this->em()->flush();
    }

    private function poserUneCitation(int $personneId, ?CitationQuality $quay): int
    {
        $source = new Source('Registres d’état civil');
        $this->em()->persist($source);

        $citation = (new Citation($source))->setPerson($this->personne($personneId))->setQuay($quay);
        $this->em()->persist($citation);

        $this->personne($personneId)->setBestQuay($quay?->value)->setCitationCount(1);
        $this->em()->flush();

        $id = $citation->getId();
        \assert(null !== $id);

        return $id;
    }

    private function fiche(string $prenoms, string $nom): PersonneAcreer
    {
        return new PersonneAcreer(
            givenNames: $prenoms,
            surname: $nom,
            marriedName: null,
            nickname: null,
            sex: 'inconnu',
            deceased: false,
            birth: UncertainDate::inconnue(),
            death: UncertainDate::inconnue(),
        );
    }

    private function personne(int $id): Person
    {
        $personne = $this->em()->find(Person::class, $id);
        self::assertInstanceOf(Person::class, $personne);

        return $personne;
    }

    /**
     * @param class-string $entite
     */
    private function compter(string $entite): int
    {
        $nombre = $this->em()->createQuery(\sprintf('SELECT COUNT(e.id) FROM %s e', $entite))->getSingleScalarResult();

        return \is_numeric($nombre) ? (int) $nombre : 0;
    }
}
