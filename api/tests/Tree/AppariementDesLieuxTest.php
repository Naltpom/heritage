<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Tree\Entity\Place;
use App\Module\Tree\Service\AppariementDesLieux;
use App\Module\Tree\Service\NomsDesPays;
use PHPUnit\Framework\TestCase;

/**
 * Les six passes d'appariement, éprouvées **sans base, sans noyau et sans console**.
 *
 * C'est tout l'objet de la séparation entre {@see AppariementDesLieux} et
 * `Tree\Command\ResoudreLesLieuxCommand` : les cas qui comptent ici — « ce triplet se
 * résout », « cet homonyme ne se résout pas au hasard » — n'ont besoin que de quatre
 * fichiers texte de quelques lignes. Les charger depuis le vrai référentiel de 114 838
 * communes rendrait chaque cas illisible et le test lent pour rien.
 *
 * ## Les fixtures sont écrites au format réel
 *
 * Séparateur `;`, en-tête de provenance en `#`, colonnes dans l'ordre du fichier livré. Un
 * jeu d'essai qui simplifierait le format ne prouverait rien du produit — c'est la même
 * leçon que `ArbreWebTestCase` a tirée sur les clés de recherche : « un test vert sur une
 * fixture impossible ne dit rien du produit ».
 */
final class AppariementDesLieuxTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = \dirname(__DIR__, 2).'/var/gazetteer_test_'.getmypid().'_'.uniqid();

        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0777, true) && !is_dir($this->dossier)) {
            self::fail('Impossible de créer le dossier de fixtures : '.$this->dossier);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }

        @rmdir($this->dossier);

        parent::tearDown();
    }

    /**
     * **La passe A** : commune + département cité, donc confiance 100.
     *
     * Le triplet complet est la forme que MyHeritage exporte, et c'est celle sur laquelle
     * la reconnaissance est la plus sûre : le département lève toute homonymie.
     *
     * Le cas vérifie aussi que `subdivision` porte le **nom** et non le code INSEE — c'est
     * ce qu'un lecteur lit dans une bulle, et la table ne sert pas à faire de la géographie
     * administrative.
     */
    public function testLaCommandeResoutUnTripletCommuneDepartementRegion(): void
    {
        $appariement = $this->appariement(
            communes: [
                'Saule;18;24;46.12;2.56;540;0',
                // Une homonyme dans un autre département : sans le « Cher » de la chaîne,
                // ce cas serait ambigu. C'est ce qui prouve que le département a servi.
                'Saule;62;32;50.41;2.11;310;0',
            ],
            departements: ['18;Cher', '62;Pas-de-Calais'],
            regions: ['24;Centre-Val de Loire', '32;Hauts-de-France'],
        );

        $trouve = $appariement->apparier('Saule, Cher, Centre-Val de Loire, France');

        self::assertSame(AppariementDesLieux::PASSE_COMMUNE_DEPARTEMENT, $trouve['passe']);
        self::assertSame(100, $trouve['confiance']);
        self::assertSame('Saule', $trouve['commune']);
        self::assertSame('Cher', $trouve['subdivision'], 'Le nom du département, pas son code.');
        self::assertSame('FR', $trouve['pays']);
        self::assertSame(46.12, $trouve['lat']);
        self::assertSame(2.56, $trouve['lon']);
        self::assertSame(Place::SOURCE_COMMUNES, $trouve['source']);
        self::assertSame('Saule (18)', $trouve['libelle']);
    }

    /**
     * La passe B : la région seule suffit, mais elle vaut **90** et non 100.
     *
     * Une région retient plusieurs départements : la reconnaissance est plus large, donc
     * moins sûre. Le rang le dit, et c'est lui qui donne l'ordre de relecture quand on
     * corrige à la main.
     */
    public function testUneRegionCiteeSansDepartementVautQuatreVingtDix(): void
    {
        $appariement = $this->appariement(
            communes: ['Saule;18;24;46.12;2.56;540;0', 'Saule;62;32;50.41;2.11;310;0'],
            departements: ['18;Cher', '62;Pas-de-Calais'],
            regions: ['24;Centre-Val de Loire', '32;Hauts-de-France'],
        );

        $trouve = $appariement->apparier('Saule, Hauts-de-France, France');

        self::assertSame(AppariementDesLieux::PASSE_COMMUNE_REGION, $trouve['passe']);
        self::assertSame(90, $trouve['confiance']);
        self::assertSame(50.41, $trouve['lat']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LA RAISON D'ÊTRE DE TOUT CE FICHIER.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Plusieurs dizaines de communes portent chacun des noms *Saint-Martin*,
     * *Sainte-Marie*, *La Chapelle*. Prendre la plus peuplée, ou la première venue, donnerait
     * une carte **plausible et fausse** : une bulle bien placée dans le Pas-de-Calais pour
     * un ancêtre du Cher, que rien ne signale et que personne ne vérifie.
     *
     * On ne résout donc **rien**, et l'on dit **entre quoi** il faut choisir. C'est le même
     * arbitrage que `ReglePersonneVivante` tient pour la confidentialité — se tromper dans
     * le sens prudent est rattrapable, l'autre non — et que `UncertainDate::format()` tient
     * pour les dates : « montrer la provenance vaut mieux qu'inventer une lecture ».
     *
     * Le cas vérifie **aussi** que la même chaîne, département cité, se résout : sans quoi
     * « ne rien résoudre jamais » passerait ce test.
     */
    public function testUnNomDeCommuneAmbiguNEstPasResoluAuHasard(): void
    {
        $appariement = $this->appariement(
            communes: [
                'Saint-Martin;18;24;46.90;2.30;1200;0',
                'Saint-Martin;62;32;50.60;2.05;38000;0',
                'Saint-Martin;15;84;45.04;2.88;410;0',
            ],
            departements: ['18;Cher', '62;Pas-de-Calais', '15;Cantal'],
            regions: ['24;Centre-Val de Loire', '32;Hauts-de-France', '84;Auvergne-Rhône-Alpes'],
        );

        $ambigu = $appariement->apparier('Saint-Martin');

        self::assertSame(AppariementDesLieux::PASSE_AMBIGU, $ambigu['passe']);
        self::assertSame(0, $ambigu['confiance']);
        self::assertNull($ambigu['lat'], 'Aucune coordonnée : on ne choisit pas à la place du lecteur.');
        self::assertNull($ambigu['lon']);
        self::assertSame(Place::SOURCE_AUCUNE, $ambigu['source']);
        self::assertSame(
            ['Saint-Martin (18)', 'Saint-Martin (62)', 'Saint-Martin (15)'],
            $ambigu['candidats'],
            'Les candidats sont nommés : c’est la seule information juste dont on dispose.',
        );

        // La plus peuplée est celle du Pas-de-Calais : c'est elle qu'un départage par
        // population aurait servie en silence. Le cas le pose exprès.
        self::assertNotSame(50.60, $ambigu['lat'], 'Surtout pas la plus peuplée.');

        $leve = $appariement->apparier('Saint-Martin, Cantal, France');
        self::assertSame(45.04, $leve['lat'], 'Le département cité lève l’ambiguïté.');
        self::assertSame(100, $leve['confiance']);
    }

    /**
     * **Une commune l'emporte sur un hameau homonyme**, et l'ambiguïté se juge dans le rang
     * retenu.
     *
     * `communes-fr.csv` mêle deux natures : `rang = 0` pour les 34 742 communes, `rang = 1`
     * pour les lieux habités — hameaux, lieux-dits, écarts. Une chaîne `PLAC` de GEDCOM
     * nomme une commune dans l'immense majorité des cas, et deux hameaux homonymes ne
     * doivent pas rendre ambigu un nom parfaitement clair.
     */
    public function testUneCommuneLEmporteSurDesHameauxHomonymes(): void
    {
        $appariement = $this->appariement(
            communes: [
                'Charmille;58;27;47.03;3.11;820;0',
                'Charmille;58;27;47.31;3.40;0;1',
                'Charmille;21;27;47.55;4.62;0;1',
            ],
            departements: ['58;Nièvre', '21;Côte-d’Or'],
            regions: ['27;Bourgogne-Franche-Comté'],
        );

        $trouve = $appariement->apparier('Charmille');

        self::assertSame(AppariementDesLieux::PASSE_COMMUNE_UNIQUE, $trouve['passe']);
        self::assertSame(70, $trouve['confiance']);
        self::assertSame(47.03, $trouve['lat'], 'La commune, et non l’un des deux hameaux.');
    }

    /**
     * La passe E : une ville du monde **et son pays**.
     *
     * Le pays doit être cité. Sans lui, « Springfield » désignerait n'importe laquelle des
     * dizaines de villes de ce nom : c'est la passe D transposée hors de France, avec la
     * même réponse — on ne choisit pas.
     */
    public function testUneVilleDuMondeSeResoutAvecSonPaysEtPasSansLui(): void
    {
        $appariement = $this->appariement(
            communes: ['Saule;18;24;46.12;2.56;540;0'],
            villes: ['Oran;DZ;35.69;-0.64;850000', 'Springfield;US;39.80;-89.64;114000'],
            departements: ['18;Cher'],
            regions: ['24;Centre-Val de Loire'],
        );

        $avecPays = $appariement->apparier('Oran, Algérie');

        self::assertSame(AppariementDesLieux::PASSE_VILLE_DU_MONDE, $avecPays['passe']);
        self::assertSame(60, $avecPays['confiance']);
        self::assertSame('DZ', $avecPays['pays']);
        self::assertSame(35.69, $avecPays['lat']);
        self::assertSame(Place::SOURCE_VILLES, $avecPays['source']);

        // Le code ISO2 écrit tel quel est accepté au même titre que le nom français.
        self::assertSame(60, $appariement->apparier('Oran, DZ')['confiance']);

        $sansPays = $appariement->apparier('Springfield');
        self::assertSame(AppariementDesLieux::PASSE_INCONNU, $sansPays['passe']);
        self::assertNull($sansPays['lat'], 'Sans pays cité, une ville du monde ne se résout pas.');
        self::assertSame('Springfield', $sansPays['libelle'], 'Le libellé reste ce que la donnée dit.');
    }

    /**
     * **Un fichier de référence vide fait échouer le chargement** — et non « 0 résolu ».
     *
     * C'est le cas du mauvais séparateur, du fichier tronqué, du chemin qui pointe sur un
     * en-tête seul. Un « 0 résolu » présenté proprement dans un tableau est exactement le
     * genre de panne qu'on déploie sans la voir ; une exception au chargement ne laisse
     * aucun doute sur ce qu'il faut regarder.
     *
     * Le message nomme le séparateur et le nombre de colonnes attendus, parce que c'est ce
     * qu'on veut lire quand on se trompe de fichier.
     */
    public function testUnFichierDeReferenceVideFaitEchouerLaCommande(): void
    {
        $this->ecrire('communes-fr.csv', ['# en-tête de provenance, et rien d’autre']);
        $this->ecrire('villes-monde.csv', ['Oran;DZ;35.69;-0.64;850000']);
        $this->ecrire('departements.csv', ['18;Cher']);
        $this->ecrire('regions.csv', ['24;Centre-Val de Loire']);

        $appariement = new AppariementDesLieux();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/aucune ligne exploitable/');

        $appariement->charger($this->dossier.'/communes-fr.csv', $this->dossier.'/villes-monde.csv');
    }

    /**
     * Le **mauvais séparateur** tombe dans le même cas, et c'est voulu : une ligne qui ne
     * porte pas le bon nombre de colonnes n'est pas exploitable, et un fichier dont aucune
     * ligne ne l'est n'est pas un référentiel.
     */
    public function testUnFichierAuMauvaisSeparateurEchoueAussi(): void
    {
        $this->ecrire('communes-fr.csv', ['Saule,18,24,46.12,2.56,540,0']);
        $this->ecrire('villes-monde.csv', ['Oran;DZ;35.69;-0.64;850000']);
        $this->ecrire('departements.csv', ['18;Cher']);
        $this->ecrire('regions.csv', ['24;Centre-Val de Loire']);

        $this->expectException(\RuntimeException::class);

        (new AppariementDesLieux())->charger($this->dossier.'/communes-fr.csv', $this->dossier.'/villes-monde.csv');
    }

    /**
     * **Le repliement d'accents est celui du produit**, et il est employé des deux côtés.
     *
     * `Saint-Étienne` écrit dans le GEDCOM doit retrouver `Saint-Etienne` du référentiel,
     * et inversement. En écrire une seconde définition ici — même juste, même testée — est
     * le défaut que `Core\Service\CleDeRecherche` existe pour supprimer : « le jour où elles
     * auraient divergé d'un seul caractère », un lieu cesserait d'être retrouvé sans qu'aucune
     * erreur ne se produise.
     */
    public function testLeRepliementDAccentsRapprocheLesDeuxGraphies(): void
    {
        $appariement = $this->appariement(
            communes: ['Saint-Étienne-des-Ronces;42;84;45.43;4.38;9100;0'],
            departements: ['42;Loire'],
            regions: ['84;Auvergne-Rhône-Alpes'],
        );

        foreach (['Saint-Etienne-des-Ronces, Loire', 'SAINT-ÉTIENNE-DES-RONCES, Loire', 'saint étienne des ronces, Loire'] as $graphie) {
            self::assertSame(
                45.43,
                $appariement->apparier($graphie)['lat'],
                'Trois graphies, une seule commune : '.$graphie,
            );
        }
    }

    /**
     * Rien de reconnu : confiance 0, aucune coordonnée, et un libellé qui reste **ce que la
     * donnée dit**.
     *
     * Une ligne de `tree_place` sans libellé ne dirait rien à qui vient la corriger à la
     * main, et c'est précisément le geste que ces lignes-là appellent.
     */
    public function testUnLieuInconnuGardeSaProvenanceCommeLibelle(): void
    {
        $trouve = $this->appariement()->apparier('Hameau introuvable, Nulle-Part');

        self::assertSame(AppariementDesLieux::PASSE_INCONNU, $trouve['passe']);
        self::assertSame(0, $trouve['confiance']);
        self::assertNull($trouve['lat']);
        self::assertSame('Hameau introuvable', $trouve['libelle']);
    }

    /**
     * Apparier avant d'avoir chargé est une **erreur de programmation**, pas un cas métier :
     * elle lève, elle ne rend pas « inconnu ». Un appariement silencieusement vide donnerait
     * une carte vide et un rapport vert.
     */
    public function testApparierSansAvoirChargeLeveUneErreurDeProgrammation(): void
    {
        $this->expectException(\LogicException::class);

        (new AppariementDesLieux())->apparier('Saule, Cher');
    }

    // ── La passe G : reculer d'un cran ───────────────────────────────────────────

    /**
     * **Le défaut mesuré sur l'arbre réel.** Les six premières passes lisent le premier
     * morceau comme le nom du lieu. C'est faux de tout ce qui nomme d'abord un lieu-dit —
     * un hameau, un cimetière, un arrondissement —, et ce premier morceau n'est dans aucun
     * référentiel ni ne le sera jamais : personne ne publie la liste des cimetières.
     *
     * Onze lieux, vingt occurrences, tombaient en passe F pour cette seule raison.
     */
    public function testUnHameauEstSitueDansLaCommuneQuiLeSuit(): void
    {
        $resultat = $this->appariement(['Rampieux;24;75;44.71;0.81;180;0'])
            ->apparier('Village du Colombier, Rampieux, Dordogne, France');

        self::assertSame(44.71, $resultat['lat']);
        self::assertSame('Rampieux', $resultat['commune']);
    }

    /**
     * **Le libellé reste celui que le GEDCOM écrit.** On a situé le contenant, pas le lieu
     * nommé : afficher « Rampieux » là où l'acte dit « Village du Colombier » effacerait ce
     * que la source porte, et personne ne saurait plus quoi corriger.
     */
    public function testLeLibelleResteCeluiQueLaSourceEcrit(): void
    {
        $resultat = $this->appariement(['Rampieux;24;75;44.71;0.81;180;0'])
            ->apparier('Village du Colombier, Rampieux, Dordogne, France');

        self::assertSame('Village du Colombier', $resultat['libelle']);
    }

    /**
     * **La confiance baisse, et c'est exact.** Le Colombier est *à* Rampieux, il n'*est*
     * pas Rampieux. La colonne `confiance` existe pour dire cette nuance ; la taire
     * rendrait un rapprochement approximatif indistinguable d'un rapprochement exact.
     */
    public function testReculerDUnCranCouteDixPointsDeConfiance(): void
    {
        $appariement = $this->appariement(['Rampieux;24;75;44.71;0.81;180;0']);

        $direct = $appariement->apparier('Rampieux, Dordogne');
        $recule = $appariement->apparier('Village du Colombier, Rampieux, Dordogne');

        self::assertSame($direct['confiance'] - 10, $recule['confiance']);
    }

    /**
     * **L'ordre est la garantie.** On ne recule que faute de mieux : si le premier morceau
     * se rapproche, il gagne, même quand un morceau suivant se rapprocherait aussi. Sans
     * cela, « Saule, Cher » pourrait se retrouver situé sur le département.
     */
    public function testUnRapprochementSurLePremierMorceauLEmporteToujours(): void
    {
        $appariement = $this->appariement([
            'Saule;18;24;46.12;2.56;540;0',
            'Rampieux;24;75;44.71;0.81;180;0',
        ]);

        $resultat = $appariement->apparier('Saule, Rampieux');

        self::assertSame(46.12, $resultat['lat'], 'Le premier morceau gagne.');
        self::assertSame(
            $appariement->apparier('Saule')['confiance'],
            $resultat['confiance'],
            'Et il ne paie aucune décote : la décote ne frappe que ce qu’on a dû aller chercher plus loin.',
        );
    }

    /**
     * **Une ambiguïté n'arrête pas la recherche.** Mieux vaut une commune située qu'une
     * liste d'homonymes : si un morceau suivant tranche, on le prend. L'ambiguïté n'est
     * rendue que si rien ne suit.
     */
    public function testUnMorceauSuivantTrancheUneAmbiguiteDuPremier(): void
    {
        $resultat = $this->appariement([
            'Sainte-Colombe;18;24;47.10;2.90;300;0',
            'Sainte-Colombe;58;27;47.30;3.50;280;0',
            'Rampieux;24;75;44.71;0.81;180;0',
        ])->apparier('Sainte-Colombe, Rampieux');

        self::assertSame(44.71, $resultat['lat'], 'Faute de trancher, on situe sur ce qui suit.');
    }

    /**
     * Et quand rien ne suit, l'ambiguïté est bien celle du morceau le plus précis — celui
     * qui nomme le lieu réellement cherché, et non un contenant.
     */
    public function testSansRecoursLAmbiguiteDuPremierMorceauEstRendue(): void
    {
        $resultat = $this->appariement([
            'Sainte-Colombe;18;24;47.10;2.90;300;0',
            'Sainte-Colombe;58;27;47.30;3.50;280;0',
        ])->apparier('Sainte-Colombe, Nulle-Part');

        self::assertSame(AppariementDesLieux::PASSE_AMBIGU, $resultat['passe']);
        self::assertCount(2, $resultat['candidats']);
    }

    // ── Le Canada ────────────────────────────────────────────────────────────────

    /**
     * **Pourquoi un fichier pays entier pour le Canada.** Cet arbre porte douze naissances
     * au Canada, dont huit à Castor (Alberta) et sept autour de Trochu — moins de mille
     * habitants chacun. Vérifié : absents de `cities15000` **et** de `cities500`. Le
     * fichier pays les a, et pèse six fois moins que `cities500`.
     */
    public function testUnVillageCanadienSousLeSeuilDesGrandesVillesEstSitue(): void
    {
        $resultat = $this->appariement()->apparier('Trochu, Alberta, Canada');

        self::assertSame(51.83, $resultat['lat']);
        self::assertSame('CA', $resultat['pays']);
    }

    /** Et le cimetière canadien se situe sur la ville qui le suit — les deux passes
     *  ensemble, sur le cas qui les a fait écrire. */
    public function testUnCimetiereCanadienEstSitueSurSaVille(): void
    {
        $resultat = $this->appariement()
            ->apparier('St. Anne of the Prairies Catholic Cemetery, Trochu, Alberta, Canada');

        self::assertSame(51.83, $resultat['lat']);
        self::assertSame('St. Anne of the Prairies Catholic Cemetery', $resultat['libelle']);
    }

    /**
     * **Tous les pays en français**, et plus seulement cinquante.
     *
     * Le Pérou n'était pas dans l'ancienne table : « Lima, Pérou » tombait en passe F alors
     * que Lima est dans le référentiel mondial. La table est désormais celle du CLDR, et le
     * pays se reconnaît **par le même repliement** que tout le reste — accents, casse et
     * espaces parasites compris.
     */
    public function testUnPaysAbsentDeLAncienneTableEstReconnuSousSonNomFrancais(): void
    {
        $appariement = $this->appariement(villes: ['Lima;PE;-12.04;-77.03;7737002', 'Lima;US;40.74;-84.11;38000']);

        foreach (['Lima, Pérou', 'Lima, PEROU', 'Lima ,  pérou '] as $chaine) {
            $trouve = $appariement->apparier($chaine);

            self::assertSame(AppariementDesLieux::PASSE_VILLE_DU_MONDE, $trouve['passe'], $chaine);
            self::assertSame('PE', $trouve['pays'], $chaine.' : c’est le pays cité qui départage, pas la population.');
            self::assertSame(-12.04, $trouve['lat'], $chaine);
        }

        // Et le nom composé, avec son trait d'union et son accent : « États-Unis ».
        self::assertSame('US', $appariement->apparier('Lima, Ohio, États-Unis')['pays']);
    }

    /**
     * **Les alias historiques survivent** à l'arrivée du CLDR, qui ne les connaît pas.
     *
     * « Écosse » et « Angleterre » ne sont pas des États ; un GEDCOM français les écrit
     * pourtant. Le CLDR écrit « Viêt Nam » ; l'ancienne table disait « Vietnam » — les
     * deux doivent marcher. Et « Myanmar (Birmanie) » se reconnaît aussi sans sa
     * parenthèse.
     */
    public function testLesAliasHistoriquesEtLesGraphiesDUsageSontReconnus(): void
    {
        $appariement = $this->appariement(villes: [
            'Edinburgh;GB;55.95;-3.19;464990',
            'Hanoi;VN;21.02;105.84;8053663',
            'Mandalay;MM;21.97;96.08;1208099',
            'Kinshasa;CD;-4.33;15.31;7785965',
        ]);

        self::assertSame('GB', $appariement->apparier('Edinburgh, Écosse')['pays']);
        self::assertSame('GB', $appariement->apparier('Edinburgh, Royaume-Uni')['pays']);
        self::assertSame('VN', $appariement->apparier('Hanoi, Vietnam')['pays']);
        self::assertSame('VN', $appariement->apparier('Hanoi, Viêt Nam')['pays']);
        self::assertSame('MM', $appariement->apparier('Mandalay, Myanmar')['pays']);
        self::assertSame('MM', $appariement->apparier('Mandalay, Birmanie')['pays']);
        self::assertSame('CD', $appariement->apparier('Kinshasa, Zaïre')['pays']);
        self::assertSame('CD', $appariement->apparier('Kinshasa, Congo-Kinshasa')['pays']);
    }

    /**
     * L'index des pays : les deux sources réunies, et **l'alias l'emporte**.
     *
     * « Congo » seul désignait le Congo-Brazzaville dans l'ancienne table ; le CLDR ne
     * connaît pas ce nom nu. Le garder tel quel, c'est ne rien changer à ce que la
     * commande résolvait hier. Et « Saint-Martin » reste la partie française : la
     * parenthèse de « Saint-Martin (partie néerlandaise) » ne lui vole pas son nom.
     */
    public function testLIndexDesPaysReunitLeCldrEtLesAlias(): void
    {
        $index = NomsDesPays::index();

        self::assertGreaterThan(249, \count($index), 'Les 249 territoires du CLDR, plus les alias et les formes sans parenthèse.');
        self::assertSame('CG', $index['CONGO']);
        self::assertSame('MF', $index['SAINT MARTIN']);
        self::assertSame('SX', $index['SAINT MARTIN PARTIE NEERLANDAISE']);
        self::assertSame('FR', $index['FRANCE']);
        self::assertSame('PL', $index['POLOGNE']);
        self::assertSame('CI', $index['COTE D IVOIRE'], 'L’apostrophe typographique du CLDR se replie comme une droite.');

        foreach ($index as $cle => $code) {
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $code, $cle);
            self::assertSame((new CleDeRecherche())->replier($cle), $cle, 'Chaque clé doit être déjà repliée : '.$cle);
        }
    }

    /**
     * Monte les quatre fichiers et rend un appariement prêt à l'emploi.
     *
     * @param list<string> $communes
     * @param list<string> $villes
     * @param list<string> $departements
     * @param list<string> $regions
     * @param list<string> $canada
     */
    private function appariement(
        array $communes = ['Saule;18;24;46.12;2.56;540;0'],
        array $villes = ['Oran;DZ;35.69;-0.64;850000'],
        array $departements = ['18;Cher'],
        array $regions = ['24;Centre-Val de Loire'],
        array $canada = ['Trochu;CA;51.83;-113.21;1000'],
    ): AppariementDesLieux {
        $this->ecrire('communes-fr.csv', $communes, '# nom;departement;region;lat;lon;population;rang');
        $this->ecrire('villes-monde.csv', $villes, '# nom;pays_iso2;lat;lon;population');
        $this->ecrire('villes-canada.csv', $canada, '# nom;pays_iso2;lat;lon;population');
        $this->ecrire('departements.csv', $departements, '# code;nom');
        $this->ecrire('regions.csv', $regions, '# code;nom');

        $appariement = new AppariementDesLieux();
        $appariement->charger($this->dossier.'/communes-fr.csv', $this->dossier.'/villes-monde.csv');

        return $appariement;
    }

    /** @param list<string> $lignes */
    private function ecrire(string $nom, array $lignes, ?string $entete = null): void
    {
        $contenu = null === $entete ? '' : $entete."\n";

        file_put_contents($this->dossier.'/'.$nom, $contenu.implode("\n", $lignes)."\n");
    }
}
