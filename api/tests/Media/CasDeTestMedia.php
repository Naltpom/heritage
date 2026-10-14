<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Enum\Regime;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Repository\MediaRepository;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Security\VisibiliteParLeCercle;
use App\Module\Media\Service\CatalogueMedia;
use App\Module\Media\Service\CircuitDeValidationMedia;
use App\Module\Media\Service\DetecteurDeType;
use App\Module\Media\Service\GenerateurDeVignette;
use App\Module\Media\Service\NormaliseurMedia;
use App\Module\Media\Service\PurgeDesMetadonnees;
use App\Module\Media\Service\ReglagesMedia;
use App\Module\Media\Service\ReglagesMediaInterface;
use App\Module\Media\Service\StockageMedia;
use App\Module\Media\Service\SuppressionMedia;
use App\Module\Media\Service\TeleversementMedia;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Le socle des tests du module : une base SQLite en mémoire, un stockage sous
 * `var/test-medias/`, et les services câblés à la main.
 *
 * **Pourquoi un `EntityManager` monté à la main plutôt qu'un `KernelTestCase`.** Deux
 * raisons, et la seconde a été payée le jour même où ces tests ont été écrits :
 *
 * 1. Ces tests n'ont besoin que de `med_media`. Monter le noyau entier pour une table,
 *    c'est payer le conteneur, la sécurité et le routeur à chaque cas.
 * 2. **Un module dont les tests dépendent du démarrage du conteneur dépend des fautes de
 *    tous les autres modules.** Pendant l'écriture de celui-ci, une signature de voter
 *    incompatible posée ailleurs a fait tomber `bin/console` en erreur fatale ; tout ce
 *    qui bootait le noyau tombait avec. Les tests de médias, eux, ont continué à tourner.
 *    C'est exactement le service qu'on attend d'un test unitaire.
 *
 * Le contrôle d'intégration — le mapping, la migration, les 23 tables — est fait une fois
 * pour tout le monde par `tests/Schema/SchemaSynchroniseTest.php`. Le refaire ici
 * n'apporterait rien.
 */
abstract class CasDeTestMedia extends TestCase
{
    protected EntityManager $em;
    protected StockageMedia $stockage;
    protected DoublureDeCercle $cercle;
    protected DoublureDeResolution $sujets;
    /**
     * Le droit de joindre une pièce à une personne proposée — **permissif** par défaut,
     * pour que les cas qui déposent sur « la demande 12, `p1` » éprouvent ce qu'ils
     * prétendent éprouver. Voir l'en-tête de {@see DoublureDeFichesProposees}.
     */
    protected DoublureDeFichesProposees $fichesProposees;
    protected VisibiliteParLeCercle $visibilite;
    protected TeleversementMedia $televersement;
    protected CatalogueMedia $catalogue;
    protected CircuitDeValidationMedia $circuit;
    protected SuppressionMedia $suppression;
    protected NormaliseurMedia $normaliseur;
    protected MediaRepository $medias;
    protected string $racine;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir().'/heritage-medias-'.bin2hex(random_bytes(6));

        $configuration = ORMSetup::createAttributeMetadataConfig(
            [
                \dirname(__DIR__, 2).'/src/Module/Media/Entity',
                // L'objet valeur incrusté `UncertainDate` vit dans `Core` : sans son
                // répertoire, le mapping du bloc `taken_*` est introuvable.
                \dirname(__DIR__, 2).'/src/Module/Core/ValueObject',
                // `Proposal` est là pour la couture `ajout_media` seule
                // (`tests/Media/CircuitDeValidationTest.php`) : le mapping est chargé
                // pour tous les cas, mais `SchemaTool` ne crée que les tables qu'on lui
                // nomme — les autres fichiers de test n'en voient aucune.
                \dirname(__DIR__, 2).'/src/Module/Proposal/Entity',
            ],
            isDevMode: true,
        );

        // PHP 8.4 sait faire des objets paresseux nativement ; sans ce réglage, Doctrine
        // réclame `symfony/var-exporter`, que ce projet n'a aucune raison d'ajouter.
        // `config/packages/doctrine.yaml` pose la même chose pour l'application.
        $configuration->enableNativeLazyObjects(true);

        // La même stratégie de nommage que `config/packages/doctrine.yaml`. Sans elle,
        // `subjectKind` devient la colonne `subjectkind` et les index de l'entité — qui
        // nomment `subject_kind` en toutes lettres — ne trouvent plus leurs colonnes.
        // Un test qui monte son propre `EntityManager` doit recopier ce réglage, faute
        // de quoi il teste un schéma qui n'est pas celui de l'application.
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());

        $connexion = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            $configuration,
        );

        $this->em = new EntityManager($connexion, $configuration);

        $outil = new SchemaTool($this->em);
        $outil->createSchema([$this->em->getClassMetadata(Media::class)]);

        // **Le piège SQLite.** `uniq_med_portrait_actif` est un index unique *partiel*
        // (`WHERE kind = 'portrait' AND is_active_portrait = true`). La plateforme SQLite
        // de DBAL ne transcrit pas la clause `WHERE` : l'index devient un UNIQUE plein
        // sur `(subject_kind, subject_id)`, qui interdirait un second média sur la même
        // personne — c'est-à-dire exactement le cas que ces tests doivent vérifier.
        // On le recrée avec sa condition ; SQLite gère les index partiels depuis 3.8.
        $connexion->executeStatement('DROP INDEX IF EXISTS uniq_med_portrait_actif');
        $connexion->executeStatement(
            'CREATE UNIQUE INDEX uniq_med_portrait_actif ON med_media (subject_kind, subject_id) '
            ."WHERE kind = 'portrait' AND is_active_portrait = 1"
        );

        $this->stockage = new StockageMedia($this->racine);
        $this->cercle = new DoublureDeCercle();
        $this->sujets = new DoublureDeResolution();
        // L'adaptateur **réel** : les tests exercent la vraie traduction
        // « sujet → personnes », pas un double de mon propre port.
        $this->visibilite = new VisibiliteParLeCercle($this->cercle, $this->sujets);
        $this->normaliseur = new NormaliseurMedia(new \App\Module\Media\Service\FlouDesPortraits(new \App\Tests\Core\Double\ReciprociteEnMemoire()));

        $medias = $this->medias = new MediaRepository(new DoublureDeRegistre($this->em));

        $this->circuit = new CircuitDeValidationMedia($this->em, $medias);
        $this->fichesProposees = new DoublureDeFichesProposees();

        $this->televersement = new TeleversementMedia(
            $this->em,
            $medias,
            $this->reglages(),
            new DetecteurDeType(),
            new PurgeDesMetadonnees(sys_get_temp_dir()),
            $this->stockage,
            new GenerateurDeVignette($this->stockage, new NullLogger()),
            $this->visibilite,
            $this->circuit,
            $this->fichesProposees,
        );

        // La même doublure que le téléversement : elle ne connaît aucun auteur tant qu'un cas
        // n'en déclare pas (`auteurDe()`), et le catalogue lit alors comme avant le
        // 10/10/2026 — déposant et administration.
        $this->catalogue = new CatalogueMedia($medias, $this->visibilite, $this->fichesProposees);
        $this->suppression = new SuppressionMedia($this->em, $this->visibilite, $this->stockage);
    }

    protected function tearDown(): void
    {
        $this->effacer($this->racine);
    }

    /**
     * Les réglages, en dur : ces tests n'ont pas de `core_setting`, et la question qu'ils
     * posent n'est pas « le réglage est-il bien lu » — c'est le travail de
     * {@see ReglagesMedia} et de son repli.
     */
    protected function reglages(int $tailleMax = ReglagesMedia::TAILLE_MAX_PAR_DEFAUT): ReglagesMediaInterface
    {
        return new class($tailleMax) implements ReglagesMediaInterface {
            public function __construct(private readonly int $tailleMax)
            {
            }

            public function tailleMaxOctets(): int
            {
                return $this->tailleMax;
            }

            public function mimesAutorises(): array
            {
                return ReglagesMedia::MIMES_PAR_DEFAUT;
            }
        };
    }

    /**
     * Construire un lecteur positionne aussi le **lecteur courant du cercle**.
     *
     * Le contrat du noyau ne passe pas le lecteur en paramètre : l'implémentation le
     * résout elle-même, session comprise. Ces trois fabriques transposent cela — la
     * « session », dans un test, c'est le dernier lecteur construit.
     */
    protected function membre(int $userId = 7, string $libelle = 'Cobalt Romarin'): LecteurMedia
    {
        $this->cercle->lecteurEst($userId)->regimeEst(Regime::Cercle);

        return new LecteurMedia($userId, $libelle, false);
    }

    /**
     * L'administrateur n'a pas de dérogation : il a un cercle **complet**. C'est la forme
     * que prend « l'administrateur voit tout » depuis qu'on a retiré de `CatalogueMedia`
     * le `if ($estAdmin) { return true; }` que le contrat du noyau interdit.
     */
    protected function administrateur(int $userId = 1): LecteurMedia
    {
        $this->cercle->lecteurEst($userId)->regimeEst(Regime::Cercle)->cercleComplet($userId);

        return new LecteurMedia($userId, 'Natron Romarin', true);
    }

    /** Un compte inscrit dont le rattachement n'est pas validé : pas de cercle du tout. */
    protected function enAttente(int $userId = 42): LecteurMedia
    {
        $this->cercle->lecteurEst($userId)->regimeEst(Regime::Aucun);

        return new LecteurMedia($userId, 'Compte en attente', false);
    }

    /**
     * Une personne **décédée** : un fait historique, visible de tout compte connecté,
     * régime `Aucun` compris (§ 6.2 du dossier).
     */
    protected function personneDecedee(int $id, string $libelle): void
    {
        $this->sujets->declarer(MediaSubject::Personne, $id, $libelle);
        $this->cercle->decedee($id);
    }

    /**
     * Une personne **vivante** : elle existe, mais elle n'est dans le cercle de personne
     * tant qu'on ne l'y met pas. C'est le cas par défaut, et c'est voulu — « un décès
     * inconnu vaut vivant, donc masqué ».
     */
    protected function personneVivante(int $id, string $libelle): void
    {
        $this->sujets->declarer(MediaSubject::Personne, $id, $libelle);
    }

    /**
     * Une citation, dont la visibilité suit la ou les personnes qu'elle étaye — c'est la
     * traduction que `Tree` fera, et que l'adaptateur applique.
     *
     * @param list<int> $personIds
     */
    protected function citation(int $id, string $libelle, array $personIds): void
    {
        $this->sujets->declarer(MediaSubject::Citation, $id, $libelle, $personIds);
    }

    /** Écrit un contenu dans un fichier temporaire et rend son chemin. */
    protected function fichierTemporaire(string $octets, string $suffixe = '.bin'): string
    {
        $chemin = sys_get_temp_dir().'/heritage-essai-'.bin2hex(random_bytes(6)).$suffixe;
        file_put_contents($chemin, $octets);

        return $chemin;
    }

    private function effacer(string $chemin): void
    {
        if (is_file($chemin)) {
            @unlink($chemin);

            return;
        }

        if (!is_dir($chemin)) {
            return;
        }

        foreach (scandir($chemin) ?: [] as $entree) {
            if ('.' !== $entree && '..' !== $entree) {
                $this->effacer($chemin.'/'.$entree);
            }
        }

        @rmdir($chemin);
    }
}
