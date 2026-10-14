<?php

declare(strict_types=1);

namespace App\Tests\Frontieres;

use PHPUnit\Framework\TestCase;

/**
 * La porte de la règle 3 : **un module ne référence un autre module que par sa
 * surface publique**, c'est-à-dire `Core\…` ou `<Autre>\Contract\…`.
 *
 * ## Pourquoi ce test existe
 *
 * La règle, sa liste d'exemptions et jusqu'au texte de son message d'échec
 * étaient **entièrement rédigés** — ADR 0002 § « Révision du 2026-09-15 », et
 * docs/arbitrages.md point **HH** — et **rien ne les exécutait**. La seule
 * mesure existante était un script hors dépôt que personne ne lançait. Une
 * frontière décrite dans un document est une intention ; tant qu'aucun test ne
 * la franchit à votre place, une violation nouvelle ne fait échouer aucune
 * porte et la dette nommée reste invisible à l'outillage.
 *
 * Deux chiffres disent le coût de cette invisibilité : **55** références
 * inter-modules hors `Core` au 2026-09-16, dont **46** illicites — et le total
 * avait bougé de 54 à 55 **en une heure** la veille, pendant qu'on le comptait.
 *
 * ## La forme du contrôle, et pourquoi ce n'est pas un seuil
 *
 * Il **échoue sur la règle, jamais sur un compte**. Le raisonnement complet est
 * dans {@see ExemptionsDeFrontiere} ; en deux lignes : un seuil figé sur un code
 * qui bouge est faux le lendemain, et surtout un seuil est **compensable** — une
 * violation ajoutée ici, une retirée là, le total ne bouge pas, le test reste
 * vert, aveugle à exactement ce qu'il devait voir.
 *
 * Le compte reste publié (voir `rapport()`), mais comme **mesure de
 * progression** : **24 aujourd'hui, 8 après les déplacements mécaniques, 0
 * ensuite.** Pas comme porte. (Il était de 46 le 2026-09-16 : les 22 lignes du
 * module `Gedcom` sont parties avec lui, ADR 0018.)
 *
 * > **Ces quatre nombres étaient faux, et ils le sont restés parce que rien ne
 * > les lisait.** Ce paragraphe annonçait « 55 → 11 → 1 → 0 ». Le 55 est le
 * > recensement **total** (dont 9 licites), pas le nombre d'illicites ; et 11 et
 * > 1 ne correspondaient à aucune ventilation par motif de la liste
 * > d'exemptions. La trajectoire part donc de **46**, et ses étapes sont
 * > désormais vérifiées à chaque exécution par
 * > {@see self::testLaTrajectoireDeLADRResteCelleQueLaListePorte()} — un chiffre
 * > écrit dans un commentaire ne prévient jamais qu'il a vieilli, et c'est la
 * > leçon que ce fichier-ci enseigne déjà pour les frontières elles-mêmes.
 *
 * ## Ce que ce test NE vérifie PAS — la borne de sa couverture
 *
 * - **Le câblage.** `config/services/*.yaml` cite des FQCN de module ; un
 *   service de `Tree` peut recevoir en argument un service de `Proposal` sans
 *   qu'aucun `use` ne le montre. Ce test lit du PHP, pas du YAML.
 * - **La qualité d'un contrat.** Une cible en `Contract\` est licite par son
 *   chemin. Qu'elle transporte un vocabulaire déguisé — un `string` là où une
 *   énumération était nécessaire — est une règle de conception qui s'applique en
 *   relecture (docs/arbitrages.md, point **II**), pas un chemin qu'un script
 *   lit. Ce qui EST vérifié ici, c'est qu'un `Contract/` ne transporte ni entité
 *   ni dépôt : sans cela, « la cible commence par `Contract\` » deviendrait une
 *   porte dérobée.
 * - **Les relations Doctrine.** Une annotation `targetEntity:` passe forcément
 *   par un `use`, donc elle est vue ; mais une relation déclarée par chaîne ne
 *   le serait pas. `testAucunNomDeModuleNEstEcritEnChaineDeCaracteres` ferme
 *   cette porte pour le code PHP.
 */
final class FrontieresInterModulesTest extends TestCase
{
    /**
     * Planchers d'assiette, mesurés le 2026-09-16. Ce sont des planchers et non
     * des égalités : le code grandit tous les jours. Les abaisser demande de
     * dire pourquoi.
     *
     * Règle : **tout contrôle qui itère sur un ensemble découvert doit affirmer
     * la taille de cet ensemble.** Sans ces trois nombres, un chemin de racine
     * changé, un `glob` cassé ou un analyseur qui ne reconnaît plus rien
     * rendraient ce test muet — et un test muet est pire qu'absent, puisqu'il
     * occupe la place.
     */
    private const int FICHIERS_ATTENDUS_AU_MOINS = 300;      // 326 mesurés

    private const int NOMS_APPLICATIFS_ATTENDUS_AU_MOINS = 700; // 865 mesurés

    private const int FICHIERS_DE_CONTRAT_ATTENDUS_AU_MOINS = 15; // 18 mesurés

    private static ?BalayageDesFrontieres $balayage = null;

    /**
     * Le balayage coûte 326 fichiers analysés en jetons ; il est fait une fois
     * pour toute la classe.
     */
    private static function balayage(): BalayageDesFrontieres
    {
        return self::$balayage ??= BalayageDesFrontieres::depuis(\dirname(__DIR__, 2).'/src/Module');
    }

    public static function tearDownAfterClass(): void
    {
        self::$balayage = null;
    }

    /**
     * L'ASSIETTE, d'abord. Tout ce qui suit itère sur ce que ce test affirme.
     *
     * Trois planchers, et une correspondance croisée : les modules trouvés sur
     * le disque doivent être exactement ceux qui ont un fichier de services. On
     * évite ainsi de recopier ici la liste des huit modules — une liste recopiée
     * ne prévient jamais qu'elle a vieilli. Ajouter un dossier de module sans
     * son `config/services/<module>.yaml`, ou l'inverse, fait rougir ce test.
     */
    public function testLeBalayageVoitTousLesModulesEtTousLeursFichiers(): void
    {
        $balayage = self::balayage();

        self::assertGreaterThanOrEqual(
            self::FICHIERS_ATTENDUS_AU_MOINS,
            $balayage->fichiersBalayes(),
            \sprintf(
                "Le balayage n'a vu que %d fichiers .php sous src/Module, or on en attend au moins %d.\n"
                ."Un balayage devenu vide passerait au vert en ne vérifiant plus rien.\nDétail : %s",
                $balayage->fichiersBalayes(),
                self::FICHIERS_ATTENDUS_AU_MOINS,
                json_encode($balayage->fichiersParModule(), \JSON_THROW_ON_ERROR),
            ),
        );

        self::assertGreaterThanOrEqual(
            self::NOMS_APPLICATIFS_ATTENDUS_AU_MOINS,
            $balayage->nomsApplicatifsLus(),
            \sprintf(
                "L'analyseur de jetons n'a reconnu que %d noms « App\\Module\\… », or on en attend au moins %d.\n"
                ."C'est le témoin de vie du balayage : le nombre de références INTER-modules, lui, a vocation\n"
                .'à tomber à zéro et ne peut donc pas servir de plancher.',
                $balayage->nomsApplicatifsLus(),
                self::NOMS_APPLICATIFS_ATTENDUS_AU_MOINS,
            ),
        );

        self::assertGreaterThanOrEqual(
            self::FICHIERS_DE_CONTRAT_ATTENDUS_AU_MOINS,
            $balayage->fichiersDeContrat(),
            'Les dossiers Contract/ des modules doivent contenir au moins '
            .self::FICHIERS_DE_CONTRAT_ATTENDUS_AU_MOINS.' fichiers : '
            ."c'est l'assiette du contrôle « un contrat ne transporte ni entité ni dépôt ».",
        );

        $modulesSurLeDisque = array_map(strtolower(...), $balayage->modules());
        sort($modulesSurLeDisque);

        $fichiersDeServices = glob(\dirname(__DIR__, 2).'/config/services/*.yaml');
        self::assertIsArray($fichiersDeServices);

        $modulesConfigures = [];
        foreach ($fichiersDeServices as $fichier) {
            $nom = basename($fichier, '.yaml');

            // `_autoload.yaml` est le prototype `App\:`, pas un module.
            if (str_starts_with($nom, '_')) {
                continue;
            }

            $modulesConfigures[] = $nom;
        }
        sort($modulesConfigures);

        self::assertSame(
            $modulesConfigures,
            $modulesSurLeDisque,
            "Les dossiers de src/Module/ et les fichiers de config/services/ ne désignent pas le même\n"
            .'ensemble de modules. Un module sans fichier de services n\'est câblé nulle part ; '
            ."un fichier de services sans dossier casse le chargement du conteneur.\n"
            .'Disque : '.implode(', ', $modulesSurLeDisque)."\n"
            .'Config : '.implode(', ', $modulesConfigures),
        );

        // Huit depuis le 2026-09-17 : `Almanach` (carte des lieux, anniversaires) rejoint
        // les sept d'origine. Le plancher monte avec eux — il n'a d'intérêt que s'il dit
        // le compte réel, et un plancher qu'on laisse en dessous ne garde plus rien.
        self::assertGreaterThanOrEqual(8, \count($modulesSurLeDisque), 'Les huit modules sont un contrat entre agents.');
    }

    /**
     * LA PORTE.
     *
     * Elle réaffirme l'assiette elle-même plutôt que de déléguer à
     * `#[Depends]` : une dépendance entre tests transforme un balayage cassé en
     * test SAUTÉ, et un test sauté sort en code 0. Un contrôle qui ne garde plus
     * rien doit rougir, pas disparaître.
     */
    public function testAucuneReferenceInterModulesHorsContratNiExemption(): void
    {
        $balayage = self::balayage();
        $this->garantirLAssiette($balayage);

        $exemptees = [];
        foreach (ExemptionsDeFrontiere::toutes() as $exemption) {
            $exemptees[$exemption['source'].' => '.$exemption['cible']] = true;
        }

        $nonExemptees = [];
        foreach ($balayage->references() as $reference) {
            if ($reference->estLicite() || isset($exemptees[$reference->cle()])) {
                continue;
            }

            $nonExemptees[] = $reference;
        }

        $rapport = $balayage->rapport($nonExemptees);
        $this->publierLeRapport($rapport);

        self::assertSame(
            [],
            array_map(static fn (ReferenceInterModules $r): string => $r->decrire(), $nonExemptees),
            $this->messageDeViolation($nonExemptees, $rapport),
        );
    }

    /**
     * **La trajectoire publiée par l'ADR 0002 est-elle encore celle que la liste
     * porte ?**.
     *
     * Ce test existe parce que la réponse a été « non » pendant une livraison
     * entière, sans que rien ne puisse le dire. L'ADR, le docblock de
     * {@see ExemptionsDeFrontiere} et l'en-tête de ce fichier annonçaient tous les
     * trois « 55 → 11 → 1 → 0 ». Le recensement réel donnait **46 → 18 → 8 → 0** :
     * le 55 était le total (dont 9 références **licites**, qui n'ont jamais eu
     * vocation à disparaître), et les deux étapes intermédiaires ne
     * correspondaient à aucune ventilation par motif. Depuis la suppression du
     * module `Gedcom` (ADR 0018), qui portait la dette datée et 12 lignes de
     * vocabulaire, la trajectoire est **24 → 8 → 0**.
     *
     * **Ce qu'on vérifie n'est pas le total.** Un total est compensable — c'est
     * l'argument même de {@see ExemptionsDeFrontiere}, et il vaut aussi contre
     * ce test-ci. On vérifie la **ventilation par motif**, puis on reconstruit la
     * trajectoire à partir d'elle : une exemption qui changerait de motif sans
     * changer le total ferait rougir ce test.
     *
     * Les nombres ci-dessous ne sont donc pas une cible à tenir : ils sont l'état
     * **constaté** que les trois documents doivent citer. Solder une ligne les
     * fait bouger, et c'est alors la ventilation **et** les documents qu'on met à
     * jour ensemble — ce qui est précisément le geste qu'aucun outil ne
     * réclamait jusqu'ici.
     */
    public function testLaTrajectoireDeLADRResteCelleQueLaListePorte(): void
    {
        $parMotif = [];

        foreach (ExemptionsDeFrontiere::toutes() as $exemption) {
            $parMotif[$exemption['motif']] = ($parMotif[$exemption['motif']] ?? 0) + 1;
        }

        $attendue = [
            ExemptionsDeFrontiere::VOCABULAIRE_A_MONTER_DANS_CORE => 6,
            ExemptionsDeFrontiere::PORT_A_DESCENDRE_DANS_CONTRACT => 10,
            ExemptionsDeFrontiere::SURFACE_PUBLIQUE_A_DECLARER => 8,
        ];

        ksort($parMotif);
        $attendueTriee = $attendue;
        ksort($attendueTriee);

        self::assertSame(
            $attendueTriee,
            $parMotif,
            "La ventilation par motif des exemptions a changé.\n"
            ."Mettez à jour, DANS LE MÊME GESTE : ce tableau, le docblock d'ExemptionsDeFrontiere,\n"
            ."l'en-tête de ce fichier, et docs/adr/0002-monolithe-modulaire-et-frontieres.md.\n"
            .'Constaté : '.json_encode($parMotif, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );

        // L'assiette : la somme de la ventilation EST le nombre d'exemptions. Sans cette
        // ligne, un motif inconnu s'ajouterait sans faire rougir le tableau ci-dessus.
        $total = \count(ExemptionsDeFrontiere::toutes());
        self::assertSame(
            $total,
            array_sum($parMotif),
            'La ventilation par motif doit couvrir toutes les exemptions, sans reste.',
        );

        // La trajectoire de l'ADR, RECONSTRUITE depuis la ventilation plutôt que recopiée.
        $mecaniques = $attendue[ExemptionsDeFrontiere::VOCABULAIRE_A_MONTER_DANS_CORE]
            + $attendue[ExemptionsDeFrontiere::PORT_A_DESCENDRE_DANS_CONTRACT];

        $apresMecaniques = $total - $mecaniques;

        self::assertSame(
            [24, 8, 0],
            [$total, $apresMecaniques, $apresMecaniques - $attendue[ExemptionsDeFrontiere::SURFACE_PUBLIQUE_A_DECLARER]],
            "La trajectoire « 24 → 8 → 0 » est citée par l'ADR 0002 et par deux docblocks.\n"
            .'Si elle change ici, elle doit changer là-bas — c’est tout l’objet de ce test.',
        );
    }

    /**
     * Une exemption périmée est une porte laissée ouverte.
     *
     * C'est ce test qui rend le solde de la dette **obligatoirement lisible** :
     * on ne peut pas déplacer un fichier vers `Contract/` ou monter une
     * énumération dans `Core` sans retirer la ligne correspondante, et l'on ne
     * peut pas retirer une ligne « au cas où » puisque la porte rougirait. La
     * liste ne peut ni devancer ni retarder le code.
     */
    public function testAucuneExemptionNEstDevenueInutile(): void
    {
        $this->garantirLAssiette(self::balayage());

        $reelles = [];
        foreach (self::balayage()->references() as $reference) {
            if (!$reference->estLicite()) {
                $reelles[$reference->cle()] = true;
            }
        }

        $inutiles = [];
        foreach (ExemptionsDeFrontiere::toutes() as $exemption) {
            $cle = $exemption['source'].' => '.$exemption['cible'];

            if (!isset($reelles[$cle])) {
                $inutiles[] = $cle.'   ['.$exemption['motif'].']';
            }
        }

        self::assertSame(
            [],
            $inutiles,
            "Ces exemptions ne correspondent plus à aucune référence réelle : la dette a été soldée,\n"
            ."ou la classe a été renommée. Retirez ces lignes de tests/Frontieres/ExemptionsDeFrontiere.php.\n"
            ."Une exemption qu'on laisse traîner couvrira un jour une violation qu'elle n'a jamais visée.\n\n  "
            .implode("\n  ", $inutiles),
        );
    }

    /**
     * Chaque extrémité d'une exemption doit désigner un fichier qui existe.
     *
     * PHPStan le dit déjà à sa façon — `::class` sur une classe absente est un
     * `class.notFound` au niveau 6, et la CI s'arrête là. Ce contrôle-ci donne
     * le même diagnostic dans le langage de la liste : quel fichier, quelle
     * ligne d'exemption, quel motif.
     *
     * Il vérifie aussi qu'aucune ligne n'est écrite deux fois : un doublon
     * n'empêche rien mais fausse le compte publié, et le compte est la seule
     * mesure de progression qui reste.
     */
    public function testChaqueExemptionDesigneDesFichiersQuiExistent(): void
    {
        $racine = \dirname(__DIR__, 2).'/src';
        $absents = [];
        $vues = [];
        $doublons = [];

        foreach (ExemptionsDeFrontiere::toutes() as $exemption) {
            $cle = $exemption['source'].' => '.$exemption['cible'];

            if (isset($vues[$cle])) {
                $doublons[] = $cle;
            }
            $vues[$cle] = true;

            foreach ([$exemption['source'], $exemption['cible']] as $classe) {
                $chemin = $racine.'/'.str_replace('\\', '/', substr($classe, \strlen('App\\'))).'.php';

                if (!is_file($chemin)) {
                    $absents[] = $classe.'  (attendu en '.$chemin.')';
                }
            }
        }

        self::assertSame([], $absents, "Exemptions qui désignent une classe absente du dépôt :\n  ".implode("\n  ", $absents));
        self::assertSame([], $doublons, "Exemptions écrites deux fois :\n  ".implode("\n  ", $doublons));

        // L'assiette : la liste n'est pas vide, et elle ne l'a pas été par
        // accident. Une liste d'exemptions vidée par erreur rendrait les deux
        // tests ci-dessus verts sans que rien ne soit gardé.
        self::assertGreaterThanOrEqual(
            1,
            \count(ExemptionsDeFrontiere::toutes()),
            "La liste d'exemptions est vide. Si la dette est réellement soldée, c'est une excellente "
            .'nouvelle : retirez alors ce plancher en même temps que la dernière ligne.',
        );
    }

    /**
     * `Core` est le noyau partagé : il est en liste blanche pour tous les
     * modules, et c'est précisément pourquoi il ne doit dépendre d'aucun.
     *
     * La règle générale ne le dirait pas : `Core` citant `Tree\Contract\…`
     * passerait la porte « la cible commence par Contract\ ». Ce serait pourtant
     * le mécanisme exact par lequel `Tree` était devenu un second noyau sans que
     * personne ne l'ait décidé (arbitrage E). D'où un contrôle à part, plus
     * strict : depuis `Core`, aucune référence vers un module, pas même vers un
     * contrat.
     */
    public function testLeNoyauNeReferenceAucunModule(): void
    {
        $fautes = [];
        foreach (self::balayage()->references() as $reference) {
            if ('Core' === $reference->moduleSource) {
                $fautes[] = $reference->decrire();
            }
        }

        self::assertSame(
            [],
            $fautes,
            "Core ne doit dépendre d'aucun module — pas même de leur Contract\\. Un noyau qui connaît "
            ."ses modules cesse d'être minimal, et devient le point par lequel tout se tient.\n\n  "
            .implode("\n  ", $fautes),
        );
    }

    /**
     * La qualification qui empêche `Contract/` d'être une porte dérobée.
     *
     * « La cible commence par `Contract\` » est licite **parce qu'un contrat ne
     * transporte ni entité ni dépôt**. Sans ce second contrôle, il suffirait de
     * déclarer `Tree\Contract\LecteurDePersonnes::lire(): Tree\Entity\Person`
     * pour rendre licites, d'un trait de plume, toutes les références aux
     * entités de `Tree`.
     */
    public function testUnContratNeTransportePasDEntiteNiDeDepot(): void
    {
        $autorises = [];
        foreach (ExemptionsDeFrontiere::contratsAutorisesATransporterUneEntite() as $autorise) {
            $autorises[$autorise['fichier'].' => '.$autorise['cible']] = true;
        }

        $fautes = [];
        foreach (self::balayage()->referencesDepuisLesContrats() as $reference) {
            $reste = substr($reference['cible'], \strlen('App\\Module\\'));
            $segments = explode('\\', $reste);

            if (\count($segments) < 2 || !\in_array($segments[1], ['Entity', 'Repository'], true)) {
                continue;
            }

            if (isset($autorises[$reference['fichier'].' => '.$reference['cible']])) {
                continue;
            }

            $fautes[] = \sprintf('%s:%d -> %s', $reference['fichier'], $reference['ligne'], $reference['cible']);
        }

        self::assertSame(
            [],
            $fautes,
            "Un dossier Contract/ est la surface publique d'un module : il ne transporte ni entité ni dépôt.\n"
            ."Sinon « la cible commence par Contract\\ » devient une porte dérobée qui rend licite n'importe\n"
            ."quel accès aux entrailles du module.\nRemplacez l'entité par un objet de transfert déclaré "
            ."dans le contrat lui-même.\n\n  "
            .implode("\n  ", $fautes),
        );
    }

    /**
     * L'angle mort qu'on refuse d'avoir.
     *
     * `class_exists('App\Module\Tree\Entity\Person')` ou
     * `new ('App\\Module\\'.$module.'\\Service\\X')` franchissent la frontière
     * sans produire le moindre jeton de nom : ce contrôle-ci, comme PHPStan, et
     * comme n'importe quel analyseur statique, ne les voit pas. On ne cherche
     * donc pas à les résoudre — on interdit d'en écrire.
     *
     * C'est la seule façon de pouvoir dire que la porte est fermée plutôt que
     * « fermée sauf si quelqu'un la contourne ».
     */
    public function testAucunNomDeModuleNEstEcritEnChaineDeCaracteres(): void
    {
        $tolerees = ExemptionsDeFrontiere::chainesTolerees();
        $servies = array_fill(0, \count($tolerees), false);

        $chaines = [];
        foreach (self::balayage()->chainesNommantUnModule() as $chaine) {
            $toleree = false;

            foreach ($tolerees as $index => $entree) {
                if ($entree['fichier'] === $chaine['fichier'] && str_contains($chaine['texte'], $entree['extrait'])) {
                    $servies[$index] = true;
                    $toleree = true;

                    break;
                }
            }

            if (!$toleree) {
                $chaines[] = \sprintf('%s:%d  %s', $chaine['fichier'], $chaine['ligne'], $chaine['texte']);
            }
        }

        self::assertSame(
            [],
            $chaines,
            "Un nom de classe de module écrit en chaîne de caractères est une dépendance qu'aucun analyseur\n"
            ."statique ne voit — ni ce contrôle, ni PHPStan, ni votre éditeur au moment de renommer.\n"
            ."Écrivez `Machin::class` : c'est la même valeur, vérifiée.\n\n  "
            .implode("\n  ", $chaines),
        );

        // Symétrique du contrôle sur les exemptions : une tolérance qui ne sert
        // plus doit être retirée, sinon elle couvrira un jour autre chose.
        $inutiles = [];
        foreach ($tolerees as $index => $entree) {
            if (!$servies[$index]) {
                $inutiles[] = $entree['fichier'].'  ~  '.$entree['extrait'];
            }
        }

        self::assertSame(
            [],
            $inutiles,
            "Ces chaînes tolérées n'existent plus : retirez-les de ExemptionsDeFrontiere::chainesTolerees().\n\n  "
            .implode("\n  ", $inutiles),
        );
    }

    /**
     * La ventilation demandée par l'ADR est réellement produite — par couple de
     * modules ET par couche ciblée.
     *
     * Ce n'est pas un ornement : « Media cite Proposal 10 fois » n'apprend rien,
     * « dont 2 fois ses entités et 5 fois ses énumérations » désigne les deux
     * gestes à faire et leur ordre. Un rapport qui perdrait la seconde
     * ventilation rendrait tout écart mystérieux, donc discutable, donc
     * discuté.
     */
    public function testLeRapportVentileParCoupleEtParCoucheCiblee(): void
    {
        $rapport = self::balayage()->rapport();

        self::assertStringContainsString('Ventilation par couple de modules et par couche ciblée', $rapport);
        self::assertStringContainsString('fichiers .php balayés', $rapport);

        $couples = [];
        $couches = [];
        foreach (self::balayage()->references() as $reference) {
            $couples[$reference->couple()] = true;
            $couches[$reference->coucheCible] = true;
        }

        foreach (array_keys($couples) as $couple) {
            self::assertStringContainsString(
                $couple,
                $rapport,
                \sprintf('Le couple %s est mesuré mais absent du rapport.', $couple),
            );
        }

        foreach (array_keys($couches) as $couche) {
            self::assertStringContainsString(
                $couche,
                $rapport,
                \sprintf('La couche ciblée %s est mesurée mais absente du rapport.', $couche),
            );
        }
    }

    /**
     * L'assiette minimale, réaffirmée par chaque test qui itère sur le balayage.
     *
     * `testLeBalayageVoitTousLesModulesEtTousLeursFichiers` la vérifie en
     * détail ; ici on ne garde que les deux nombres sans lesquels un parcours
     * devenu vide passerait au vert.
     */
    private function garantirLAssiette(BalayageDesFrontieres $balayage): void
    {
        self::assertGreaterThanOrEqual(
            self::FICHIERS_ATTENDUS_AU_MOINS,
            $balayage->fichiersBalayes(),
            'Balayage vide ou tronqué : '.$balayage->fichiersBalayes().' fichiers seulement. '
            ."Le résultat de ce test ne veut rien dire tant que ce nombre n'est pas rétabli.",
        );

        self::assertGreaterThanOrEqual(
            self::NOMS_APPLICATIFS_ATTENDUS_AU_MOINS,
            $balayage->nomsApplicatifsLus(),
            "L'analyseur de jetons ne reconnaît plus les noms « App\\Module\\… » : "
            .$balayage->nomsApplicatifsLus().' seulement.',
        );
    }

    /**
     * @param list<ReferenceInterModules> $nonExemptees
     */
    private function messageDeViolation(array $nonExemptees, string $rapport): string
    {
        $lignes = [];
        foreach ($nonExemptees as $reference) {
            $lignes[] = '  '.$reference->decrire();
        }

        return \sprintf(
            "%d référence(s) inter-modules franchissent une frontière sans être exemptées.\n\n"
            ."LA RÈGLE (ADR 0002 § « La surface publique d'un module ») : une référence d'un module vers\n"
            ."un autre ne vise que `Core\\…` ou `<Autre>\\Contract\\…`. Tout le reste — Entity\\, Service\\,\n"
            ."Enum\\, ValueObject\\, Security\\, Serializer\\, y compris Service\\Port\\ — est l'intérieur\n"
            ."d'un module.\n\n"
            ."CE QU'IL FAUT FAIRE, dans cet ordre :\n"
            ."  1. la référence est une faute -> retirez-la. C'est le cas le plus fréquent ;\n"
            ."  2. le module CIBLE doit exposer ce besoin -> il déclare `<Cible>\\Contract\\…`.\n"
            ."     Attention : le fichier se déplace chez le module qui DÉCLARE, jamais chez celui\n"
            ."     qui cite. Router ce geste au module qui cite envoie son auteur chercher le travail\n"
            ."     dans le seul module où il n'est pas ;\n"
            ."  3. le besoin est un VOCABULAIRE partagé par deux modules -> il monte dans `Core`\n"
            ."     (arbitrage E : UncertainDate, DateQualifier, Sex, Branch ont fait ce chemin) ;\n"
            ."  4. en dernier recours -> une ligne dans tests/Frontieres/ExemptionsDeFrontiere.php,\n"
            ."     avec son motif. Une tolérance sans date, sans propriétaire et sans geste de levée\n"
            ."     n'est pas une dette : c'est un renoncement.\n\n"
            ."LES RÉFÉRENCES EN CAUSE :\n%s\n\n%s",
            \count($nonExemptees),
            implode("\n", $lignes),
            $rapport,
        );
    }

    /**
     * Le rapport est écrit sur disque à chaque exécution : l'ADR demande que le
     * recensement soit « rejouable à la main », et un chiffre qu'on ne peut lire
     * qu'en faisant échouer le test n'est pas une mesure de progression.
     *
     * `var/` est ignoré par Git. Une écriture qui échoue — disque plein, droits,
     * deux suites concurrentes — ne doit pas faire tomber la porte : ce n'est
     * pas ce que ce test garde.
     */
    private function publierLeRapport(string $rapport): void
    {
        $dossier = \dirname(__DIR__, 2).'/var/log';

        if (!is_dir($dossier) && !@mkdir($dossier, 0777, true) && !is_dir($dossier)) {
            return;
        }

        @file_put_contents($dossier.'/frontieres-inter-modules.txt', $rapport."\n");
    }
}
