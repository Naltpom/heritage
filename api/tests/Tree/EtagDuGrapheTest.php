<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Regime;
use App\Module\Tree\Serializer\EtagDuGraphe;
use App\Module\Tree\Serializer\FabriqueDuCercle;
use App\Module\Tree\Serializer\LecteurDeLArbre;
use App\Module\Tree\Serializer\PersonneDuLecteur;
use App\Module\Tree\Serializer\PopulationDeLArbre;
use App\Module\Tree\Service\TreeFingerprint;
use App\Tests\Tree\Double\AnnuaireEnMemoire;
use App\Tests\Tree\Double\CalqueEnMemoire;
use App\Tests\Tree\Double\CercleEnMemoire;
use App\Tests\Tree\Double\MiseEnPageEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  L'`ETAG` DU GRAPHE — LA FUITE DE CONFIDENTIALITÉ PAR LE JETON DE CACHE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * **Mesuré sur la pile, avant correction.** Les trois comptes réels recevaient trois
 * charges différentes de `GET /api/arbre/graphe` — 124 412, 124 092 et 122 954 octets,
 * trois `md5` distincts — sous **un seul et même `ETag` fort**. En présentant celui frappé
 * par l'administrateur, le compte `en_attente` recevait `304 Not Modified`.
 *
 * La cause tenait dans la formule : `sha256(empreinte : demandes)`, où l'empreinte ne
 * couvre que `tree_person`, `tree_family` et `tree_child`. Ni le régime, ni le cercle, ni
 * `cercle.generations` n'y entraient.
 *
 * Ce fichier garde la formule du § 5.2, à cinq composantes :
 *
 * ```
 * ETag = sha256( empreinte_de_l_arbre
 *        || ':' || empreinte_du_cercle_effectif_du_lecteur
 *        || ':' || empreinte_des_demandes_du_lecteur
 *        || ':' || point_de_vue_du_lecteur
 *        || ':' || empreinte_de_la_mise_en_page )
 * ```
 *
 * ## Ce qui est gardé ici, et ce qui l'est ailleurs
 *
 * Ici : la **composition** de l'`ETag`, sans base et sans conteneur — l'empreinte de
 * l'arbre est posée à la main, le cercle est un ensemble réglable. Ce qu'on vérifie n'est
 * pas comment ces valeurs se calculent, mais **ce qui entre dans le haché à côté d'elles**.
 *
 * Ailleurs : la même propriété **de bout en bout**, à travers le pare-feu, le vrai cercle
 * et la vraie base — {@see RoutesDeLArbreTest::testDeuxLecteursDeCerclesDifferentsRecoiventDeuxEtagsDifferents()}.
 * C'est la mise en œuvre du § 17.5 : *une propriété du produit se teste contre le produit.*
 * Les deux sont nécessaires ; celui-ci dit **où** ça casse, celui-là dit **que** ça casse.
 */
final class EtagDuGrapheTest extends TestCase
{
    private const string EMPREINTE = '9f2c4a1e7b0d5c83a6f1e2d9c4b7a0e5f3d8c1b6a9e4f7d2c5b8a1e6f3d0c9b2';

    /**
     * La population de l'arbre de ce test : c'est le **domaine** sur lequel le cercle est
     * interrogé, pas un cercle.
     */
    private const array POPULATION = [11, 12, 13, 14, 15];

    /**
     * ── § 17.5 DU CONTRAT, LA RÈGLE QUI N'AVAIT PAS DE GARDIEN ────────────────────
     *
     * *Deux lecteurs de cercles différents, même empreinte d'arbre, aucune demande en
     * cours, doivent recevoir deux `ETag` différents.*
     *
     * Les trois lecteurs sont ceux qui ont été mesurés sur la pile. Le troisième n'est pas
     * du luxe : le compte en attente et le membre actif voient **le même ensemble de
     * personnes** — les décédées — pour deux charges de tailles différentes. C'est le
     * `regime`, et lui seul, qui les sépare dans l'empreinte du cercle.
     */
    public function testDeuxLecteursDeCerclesDifferentsRecoiventDeuxEtagsDifferents(): void
    {
        $lecteur = new LecteurDeLArbre(42, 'Peu importe', false, Regime::Cercle);

        $etags = [
            'administrateur' => $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, self::POPULATION))->pour($lecteur),
            'membre actif' => $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, [11, 12]))->pour($lecteur),
            'compte en attente' => $this->etags(cercle: CercleEnMemoire::sansCercle())->pour($lecteur),
        ];

        // La taille est affirmée, et non lue : un jour où deux de ces `ETag` se
        // confondraient, `assertCount` le dirait — un `foreach` sur les valeurs uniques,
        // non.
        self::assertCount(
            3,
            array_unique($etags),
            \sprintf(
                'Trois cercles effectifs différents, trois charges différentes : trois `ETag` différents (§ 5.2, § 17.5). Obtenu : %s',
                json_encode($etags, \JSON_THROW_ON_ERROR),
            ),
        );
    }

    /**
     * ── LE TROISIÈME CAS MESURÉ : `cercle.generations` ────────────────────────────
     *
     * Le réglage vaut 1 à 6 et un administrateur le change en production. Il entre dans la
     * clé de cache **serveur** du cercle (§ 9.3 du modèle) mais ne touche **aucune** table
     * `tree_*` : resserrer la confidentialité de 3 à 2 générations ne change ni l'empreinte
     * de l'arbre, ni celle des demandes.
     *
     * Avec l'ancienne formule, l'`ETag` ne bougeait donc pas : chaque navigateur de membre
     * revalidait, recevait `304`, et continuait d'afficher le cercle **large**. *Le
     * durcissement n'atteignait jamais le client.*
     *
     * Il n'y a rien de particulier à faire pour fermer ce cas, et c'est le point : un `N`
     * plus petit donne un cercle plus petit, donc une liste d'identifiants différente, donc
     * une empreinte différente. Le cas se ferme parce qu'on hache le cercle **résolu**, et
     * non les réglages qui le produisent — et il resterait fermé pour n'importe quelle
     * autre cause de rétrécissement du cercle : un rattachement recalé, un membre
     * désactivé, une correction de parenté.
     */
    public function testResserrerLeCercleChangeLEtagMemeSansToucherALArbre(): void
    {
        $lecteur = new LecteurDeLArbre(42, 'Membre', false, Regime::Cercle);

        // `N = 3` : le membre, ses parents, ses grands-parents.
        $large = $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, [11, 12, 13, 14]))->pour($lecteur);
        // `N = 2` : les arrière-grands-parents sortent.
        $resserre = $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, [11, 12, 13]))->pour($lecteur);

        self::assertNotSame(
            $large,
            $resserre,
            'Un cercle resserré doit changer l’`ETag`, sinon le client revalide, reçoit 304 et garde le cercle large à l’écran.',
        );
    }

    /**
     * ── LA BASCULE « VOIR COMME UN MEMBRE », DÉSORMAIS PAR CONSÉQUENCE ────────────
     *
     * Sous la bascule (§ 10.4), ni l'empreinte de l'arbre ni celle des demandes ne bougent.
     * Avec les deux seules composantes d'origine, l'administrateur revalidait, recevait
     * `304`, et regardait **son propre graphe non filtré** sous l'identité qu'il croyait
     * tester — *un outil de vérification qui donne une fausse assurance est pire que pas
     * d'outil du tout*.
     *
     * Ce qui a changé n'est pas la propriété, c'est sa **nature** : elle n'est plus un cas
     * particulier écrit dans le calcul de l'`ETag`, mais une conséquence de « le cercle
     * effectif entre dans la clé ». Le cercle effectif sous la bascule **est** celui du
     * membre emprunté — c'est le contrat du noyau qui le résout, jamais `Tree`. Une
     * exception qu'on retient s'oublie au premier remaniement ; une conséquence, non.
     */
    public function testLEtagChangeQuandLAdministrateurBasculeEnVoirCommeUnMembre(): void
    {
        $administrateur = new LecteurDeLArbre(1, 'Admin', true, Regime::Cercle);
        $surLeMembre7 = new LecteurDeLArbre(1, 'Admin', true, Regime::Cercle, membreEmprunteId: 3, membreEmprunteUserId: 7);
        $surLeMembre9 = new LecteurDeLArbre(1, 'Admin', true, Regime::Cercle, membreEmprunteId: 4, membreEmprunteUserId: 9);

        // Hors bascule, l'administrateur passe par un **cercle complet** — un ensemble,
        // jamais un court-circuit (ADR 0003).
        $horsBascule = $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, self::POPULATION))->pour($administrateur);

        $vuDuMembre7 = $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, [11, 12]))->pour($surLeMembre7);
        $vuDuMembre9 = $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, [13, 14]))->pour($surLeMembre9);

        self::assertNotSame($horsBascule, $vuDuMembre7, 'Sous la bascule, l’`ETag` doit changer : sinon la revalidation rend 304 et l’administrateur regarde son propre graphe.');
        self::assertNotSame($vuDuMembre7, $vuDuMembre9, 'Deux membres empruntés de cercles différents donnent deux graphes différents, donc deux `ETag` différents.');
        self::assertSame(
            $horsBascule,
            $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, self::POPULATION))->pour($administrateur),
            'Quitter la bascule rend l’`ETag` d’origine : le front retrouve sa revalidation normale.',
        );
    }

    /**
     * L'empreinte de l'arbre **seule** ne peut pas servir d'`ETag` : déposer un brouillon
     * ne touche aucune table `tree_*`, donc ne la change pas — ni le cercle. L'auteur ne
     * verrait jamais son propre nœud en attente, c'est-à-dire précisément la seule chose
     * que ce calque existe pour produire (ADR 0005).
     */
    public function testLEtagChangeQuandLeLecteurDeposeUneDemande(): void
    {
        $auteur = new LecteurDeLArbre(7, 'Opale', false, Regime::Cercle);
        $cercle = new CercleEnMemoire(Regime::Cercle, [11, 12]);

        $sansDemande = $this->etags(new CalqueEnMemoire(7), $cercle)->pour($auteur);
        $avecDemande = $this->etags((new CalqueEnMemoire(7))->poserEmpreinte('14:2026-09-14T11:02:00'), $cercle)->pour($auteur);

        self::assertNotSame($sansDemande, $avecDemande);
    }

    /**
     * **Ce que la correction ne coûte pas.** Deux lecteurs du **même** cercle, sans demande
     * en cours, partagent toujours leur `ETag` — et c'est la propriété dont dépend la
     * revalidation : un même lecteur, d'une requête à l'autre, retrouve la sienne tant que
     * ni l'arbre, ni son cercle, ni ses demandes n'ont changé.
     *
     * Ce n'est pas une fuite : leurs charges sont identiques. L'arbitrage du § 5.2 est
     * asymétrique et il tranche dans ce sens — deux `ETag` différents pour deux charges
     * identiques coûtent une revalidation ; un `ETag` identique pour deux charges
     * différentes est une fuite. **Quand on hésite, on distingue** ; ici il n'y a rien à
     * distinguer.
     */
    public function testDeuxLecteursDuMemeCercleSansDemandePartagentLeMemeEtag(): void
    {
        $cercle = new CercleEnMemoire(Regime::Cercle, [11, 12]);

        self::assertSame(
            $this->etags(new CalqueEnMemoire(7), $cercle)->pour(new LecteurDeLArbre(42, 'Membre A', false, Regime::Cercle)),
            $this->etags(new CalqueEnMemoire(7), $cercle)->pour(new LecteurDeLArbre(43, 'Membre B', false, Regime::Cercle)),
        );
    }

    /**
     * **L'ordre des identifiants ne doit pas entrer dans le haché.** Le cercle est une
     * liste triée — sans quoi un cercle identique rendu dans un autre ordre par le moteur
     * donnerait un `ETag` différent à chaque `VACUUM`, et le client retéléchargerait
     * 124 Ko pour rien.
     */
    public function testLOrdreDesIdentifiantsDuCercleNeChangePasLEtag(): void
    {
        $lecteur = new LecteurDeLArbre(42, 'Membre', false, Regime::Cercle);

        self::assertSame(
            $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, [11, 12, 13]))->pour($lecteur),
            $this->etags(cercle: new CercleEnMemoire(Regime::Cercle, [13, 11, 12]))->pour($lecteur),
        );
    }

    /**
     * ════════════════════════════════════════════════════════════════════════════
     *  DEUX LECTEURS DE MÊME CERCLE, MAIS PAS LE MÊME POINT DE VUE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * La mise en page est calculée **par lecteur** : `génération 0` est sa rangée, et les
     * quatre couleurs de branche disent son quart d'ascendance. Deux administrateurs —
     * même cercle, aucune demande — reçoivent donc des charges dont **toutes les
     * coordonnées** diffèrent, et qui ne différaient par aucune des trois autres
     * composantes.
     *
     * C'est le défaut mesuré en tête de ce fichier, sur une autre dimension : un `ETag`
     * identique pour deux charges différentes. « Quand on hésite, on distingue ».
     */
    public function testDeuxLecteursDeMemeCercleMaisDePointsDeVueDifferentsNePartagentPasLEtag(): void
    {
        $etags = $this->etags(personneParCompte: [42 => 100, 43 => 200]);

        self::assertNotSame(
            $etags->pour(new LecteurDeLArbre(42, 'Membre', false, Regime::Cercle)),
            $etags->pour(new LecteurDeLArbre(43, 'Membre', false, Regime::Cercle)),
            'Deux mises en page différentes ne peuvent pas porter le même `ETag`.',
        );
    }

    /**
     * Le pendant : **un même lecteur retrouve son `ETag`**, d'une requête à l'autre.
     *
     * C'est la propriété dont dépend la revalidation, et ajouter une composante ne doit
     * pas la coûter.
     */
    public function testUnMemeLecteurRetrouveSonEtag(): void
    {
        $lecteur = new LecteurDeLArbre(42, 'Membre', false, Regime::Cercle);

        self::assertSame(
            $this->etags(personneParCompte: [42 => 100])->pour($lecteur),
            $this->etags(personneParCompte: [42 => 100])->pour($lecteur),
        );
    }

    /**
     * Deux lecteurs **rattachés à personne** regardent depuis la racine, donc partagent
     * leur mise en page — et peuvent partager leur `ETag`.
     *
     * Distinguer ici ne coûterait qu'une revalidation, mais ce serait dire que les charges
     * diffèrent alors qu'elles sont identiques. La règle est « distinguer ce qui distingue
     * la représentation », pas « distinguer tout le monde ».
     */
    public function testDeuxLecteursSansRattachementPartagentLEtag(): void
    {
        $etags = $this->etags();

        self::assertSame(
            $etags->pour(new LecteurDeLArbre(42, 'Membre', false, Regime::Cercle)),
            $etags->pour(new LecteurDeLArbre(43, 'Membre', false, Regime::Cercle)),
        );
    }

    /** `ETag` **fort**, jamais faible : deux représentations qui diffèrent d'un champ diffèrent d'une donnée personnelle. */
    public function testLEtagEstFortEtEntreGuillemets(): void
    {
        $etag = $this->etags()->pour(new LecteurDeLArbre(42, 'Membre', false, Regime::Cercle));

        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $etag);
        self::assertStringStartsNotWith('W/', $etag);
    }

    public function testLaComparaisonAccepteLesFormesQuUnNavigateurEmet(): void
    {
        $etags = $this->etags();
        $etag = $etags->pour(new LecteurDeLArbre(42, 'Membre', false, Regime::Cercle));

        self::assertTrue($etags->correspond($etag, $etag));
        self::assertTrue($etags->correspond('*', $etag), 'Un `If-None-Match: *` vaut « n’importe quelle représentation ».');
        self::assertTrue($etags->correspond('"aaa", '.$etag, $etag), 'Un navigateur peut émettre plusieurs valeurs séparées par des virgules.');
        self::assertFalse($etags->correspond(null, $etag));
        self::assertFalse($etags->correspond('"aaa"', $etag));
    }

    /**
     * @param array<int, int> $personneParCompte le point de vue de chaque compte, quand le
     *                                           cas en a besoin — vide par défaut : un
     *                                           lecteur rattaché à personne regarde depuis
     *                                           la racine
     */
    private function etags(
        ?CalqueEnMemoire $propositions = null,
        ?CercleEnMemoire $cercle = null,
        array $personneParCompte = [],
        ?MiseEnPageEnMemoire $miseEnPage = null,
    ): EtagDuGraphe {
        return new EtagDuGraphe(
            $this->empreinteFigee(),
            $propositions ?? new CalqueEnMemoire(7),
            new FabriqueDuCercle($cercle ?? new CercleEnMemoire(Regime::Cercle, [11, 12])),
            $this->populationFigee(),
            new PersonneDuLecteur(new AnnuaireEnMemoire($personneParCompte)),
            $miseEnPage ?? new MiseEnPageEnMemoire(),
        );
    }

    /**
     * ════════════════════════════════════════════════════════════════════════════
     * **LE CODE DE MISE EN PAGE CHANGE, L'ARBRE NON — L'`ETAG` DOIT BOUGER.**.
     *
     * Le quatrième cas de la série, et le seul qui ne vienne pas de la base. Les quatre
     * autres composantes décrivent l'état des tables et le lecteur ; aucune ne remue
     * quand on réécrit les règles de placement. Or la route répond
     * `Cache-Control: private, no-cache` : le navigateur garde la charge et revalide.
     *
     * Sans cette composante, un déploiement qui change la mise en page laisse chaque
     * lecteur revenu présenter son ancien `ETag`, recevoir `304`, et **redessiner
     * l'ancienne image** — pendant que le serveur en calcule une nouvelle que personne
     * ne reçoit. C'est le défaut de `cercle.generations`, sur une cinquième dimension.
     * ════════════════════════════════════════════════════════════════════════════
     */
    public function testUneMiseEnPageDifferenteDonneUnEtagDifferent(): void
    {
        $lecteur = new LecteurDeLArbre(42, 'Membre', false, Regime::Cercle);

        $avant = $this->etags(miseEnPage: new MiseEnPageEnMemoire('regles-de-2026-09'));
        $apres = $this->etags(miseEnPage: new MiseEnPageEnMemoire('regles-affinees'));

        self::assertNotSame(
            $avant->pour($lecteur),
            $apres->pour($lecteur),
            'À arbre, cercle, demandes et point de vue identiques, deux images différentes doivent donner deux `ETag` différents.',
        );

        self::assertSame(
            $avant->pour($lecteur),
            $this->etags(miseEnPage: new MiseEnPageEnMemoire('regles-de-2026-09'))->pour($lecteur),
            'Et la même image doit rendre le même `ETag`, sans quoi plus rien ne revalide.',
        );
    }

    /**
     * Un {@see TreeFingerprint} dont la valeur mémorisée est posée à la main.
     *
     * `TreeFingerprint` est `final` — délibérément : c'est une règle de cache, pas un
     * point d'extension. On construit donc l'objet réel et on renseigne sa mémoire de
     * requête par réflexion, plutôt que d'introduire dans le code de production une
     * couture qui n'existerait que pour les tests.
     */
    private function empreinteFigee(): TreeFingerprint
    {
        $empreinte = new TreeFingerprint($this->gestionnaireInerte());

        $memoire = new \ReflectionProperty(TreeFingerprint::class, 'memoire');
        $memoire->setValue($empreinte, self::EMPREINTE);

        return $empreinte;
    }

    /** Même procédé, et pour la même raison, sur la population de l'arbre. */
    private function populationFigee(): PopulationDeLArbre
    {
        $population = new PopulationDeLArbre($this->gestionnaireInerte());

        $memoire = new \ReflectionProperty(PopulationDeLArbre::class, 'memoire');
        $memoire->setValue($population, self::POPULATION);

        return $population;
    }

    /**
     * Un gestionnaire dont la connexion n'est jamais ouverte : la mémoire posée juste
     * après court-circuite la requête. Il existe parce que le constructeur le réclame,
     * rien de plus — et c'est aussi une assertion en creux : si un jour `EtagDuGraphe` se
     * mettait à toucher la base, ce test le dirait en essayant d'interroger un SQLite en
     * mémoire dépourvu de la moindre table.
     *
     * `TreeFingerprint` ne prend plus une `Connection` mais l'`EntityManager` dont elle
     * est tirée : il lui faut les métadonnées pour découvrir les colonnes à hacher.
     */
    private function gestionnaireInerte(): EntityManagerInterface
    {
        return FabriqueDEntityManager::enMemoire();
    }
}
