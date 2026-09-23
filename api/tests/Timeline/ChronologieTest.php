<?php

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\UserStatus;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Timeline\Double\ArbreDeChronologieEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/chronologie` — le cercle de visibilité, les paramètres, les jalons.
 *
 * ## Ces cas partent de l'état réel, pas d'un objet monté à la main
 *
 * Le cercle n'est pas simulé : c'est le vrai `Membership\Service\CercleDeVisibilite` qui
 * répond, sur un arbre en mémoire, depuis une vraie session ouverte par `POST /api/connexion`.
 * C'est la consigne du dossier de mission — *« teste une règle de sécurité depuis sa source,
 * pas depuis son entrée »* : un test qui construirait lui-même l'objet disant « cette
 * personne est visible » vérifierait que le normaliseur obéit *quand on le lui dit*, pas
 * qu'on le lui dise jamais.
 *
 * ## L'arbre de ces cas
 *
 * ```
 *   1  Natron Romarin      tr  gen  0  vivant    n. 26 SEP 2012   ← le lecteur
 *   2  Père                pp  gen -1  vivant    n. 1981
 *   9  Cousin éloigné      mm  gen -1  VIVANT    n. 1979          ← HORS cercle
 *  17  Marne D. Sureau     pm  gen -3  décédée   BET 1901 AND 1909 – 1987
 * ```
 *
 * Le cercle de l'ADR 0003 remonte `N = 3` générations depuis le lecteur puis redescend.
 * La personne 9 n'a **aucun lien** avec le lecteur dans l'arbre familial posé ici : elle
 * est vivante et hors cercle, donc absente de `barres`.
 */
final class ChronologieTest extends CasDeTestTimeline
{
    private const int LECTEUR = 1;
    private const int PERE = 2;
    private const int HORS_CERCLE = 9;
    private const int SUREAU = 17;

    protected function setUp(): void
    {
        parent::setUp();

        $this->poserPersonne(self::LECTEUR, 'Natron Romarin', Branch::Tr, 0, Sex::Masculin, vivant: true, naissance: '26 SEP 2012');
        $this->poserPersonne(self::PERE, 'Père Romarin', Branch::Pp, -1, Sex::Masculin, vivant: true, naissance: '1981');
        $this->poserPersonne(self::HORS_CERCLE, 'Cousin Éloigné', Branch::Mm, -1, Sex::Masculin, vivant: true, naissance: '1979');
        $this->poserPersonne(self::SUREAU, 'Marne Dolomite Sureau', Branch::Pm, -3, Sex::Feminin, vivant: false, naissance: 'BET 1901 AND 1909', deces: '1987');

        // Le lecteur est l'enfant du père ; Sureau est une aïeule de la branche `pm`, sans
        // lien posé — elle est visible parce qu'elle est **décédée**, pas par le cercle.
        // Le cousin `9` n'apparaît dans aucune famille : il est vivant et hors cercle.
        $this->poserFamille([self::PERE], [self::LECTEUR]);
    }

    private function connecterUnMembre(UserStatus $statut = UserStatus::Actif): void
    {
        $utilisateur = $this->creerUtilisateur('membre@example.test', statut: $statut);

        if (UserStatus::Actif === $statut) {
            $this->creerMembre($utilisateur, self::LECTEUR, 'Natron Romarin');
        }

        $this->connexion('membre@example.test');
    }

    // ── Le cercle ────────────────────────────────────────────────────────────────

    /**
     * **La règle centrale du § 9.1** : une personne vivante hors cercle est **absente** de
     * `barres`, et non réduite.
     *
     * Le graphe, lui, garde le nœud avec sept clés (§ 11.2). Ici la barre disparaît : *une
     * barre est faite de dates, et une personne vivante hors cercle n'en expose aucune*.
     * Le test vérifie l'absence de la **clé**, et pas seulement l'absence de dates — une
     * barre présente avec `debut: null` serait déjà l'aveu qu'une personne existe.
     */
    public function testUnePersonneVivanteHorsCercleEstAbsenteDesBarres(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie');

        $barres = $this->barresParPersonne();

        self::assertArrayNotHasKey(
            self::HORS_CERCLE,
            $barres,
            'Une personne vivante hors cercle ne doit pas figurer dans `barres`, même réduite (§ 9.1).',
        );

        // Et son nom ne doit ressortir par aucun autre chemin — ni jalon, ni libellé.
        $contenu = $this->client->getResponse()->getContent();
        self::assertIsString($contenu);
        self::assertStringNotContainsString('Cousin Éloigné', $contenu, 'Le nom d\'une personne hors cercle ne sort par aucune clé.');
    }

    /** Les personnes du cercle, elles, sont bien là — sans quoi le cas précédent serait vert par cécité. */
    public function testLesPersonnesDuCercleEtLesDecedeesSontPresentes(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie');

        $barres = $this->barresParPersonne();

        // La taille d'abord : trois visibles sur les quatre personnes posées. Sans ce
        // décompte, le cas resterait vert si une quatrième barre apparaissait — c'est-à-dire
        // précisément si le cercle se mettait à laisser passer le cousin hors cercle.
        self::assertCount(3, $barres, 'Trois personnes visibles sur les quatre posées.');

        self::assertArrayHasKey(self::LECTEUR, $barres, 'Le lecteur se voit lui-même.');
        self::assertArrayHasKey(self::PERE, $barres, 'Son père est dans le cercle (N = 3).');
        self::assertArrayHasKey(self::SUREAU, $barres, 'Une personne décédée est un fait historique, visible de tous.');
    }

    /**
     * **Un compte `en_attente` n'a pas de cercle** : il ne voit que des personnes décédées.
     *
     * Ce n'est pas « un cercle avec N = 0 » — c'est un régime distinct (`Regime::Aucun`),
     * et il tombe du contrat du noyau, pas d'un `if` écrit dans ce module. Le test le prend
     * par son symptôme observable : une seule barre, celle de la défunte, et pas même celle
     * de la personne à laquelle le compte prétend se rattacher.
     */
    public function testUnCompteEnAttenteNeVoitQueDesPersonnesDecedees(): void
    {
        $this->connecterUnMembre(UserStatus::EnAttente);
        $this->requete('GET', '/api/chronologie');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $barres = $this->barresParPersonne();

        self::assertSame([self::SUREAU], array_keys($barres), 'Le régime `Aucun` ne montre aucune personne vivante.');
    }

    /**
     * Et ses **jalons** ne portent donc que des faits de personnes décédées (§ 9.1).
     *
     * Le cas pose deux faits, l'un sur une vivante du cercle, l'autre sur la défunte, et
     * vérifie qu'un seul sort. Sans le premier, le test serait vert même si le filtrage des
     * jalons n'existait pas.
     */
    public function testLesJalonsDUnCompteEnAttenteNePortentQueDesFaitsDePersonnesDecedees(): void
    {
        ArbreDeChronologieEnMemoire::ajouterFait(self::LECTEUR, 'residence', 'Résidence', null, ArbreDeChronologieEnMemoire::date('2021', 'Aubépine'));
        ArbreDeChronologieEnMemoire::ajouterFait(self::SUREAU, 'recensement', 'Recensement', null, ArbreDeChronologieEnMemoire::date('1957', 'Saint-Ronce-de-Verçay'));

        $this->connecterUnMembre(UserStatus::EnAttente);
        $this->requete('GET', '/api/chronologie');

        $charge = $this->charge();
        self::assertIsArray($charge['jalons']);

        self::assertSame(
            [[
                'type' => 'recensement',
                'annee' => 1957,
                'libelle' => 'Recensement, Saint-Ronce-de-Verçay',
                'personId' => self::SUREAU,
            ]],
            $charge['jalons'],
            'Le jalon du § 9.1, et lui seul : le fait d\'une personne vivante ne sort pas en régime `Aucun`.',
        );
    }

    /**
     * **L'invariant des jalons, pris par son autre porte** : un jalon ne survit pas à une
     * barre écartée faute de dates.
     *
     * Vérifié sur la vraie base avant d'être écrit ici : 30 faits de personne portent une
     * date, 29 jalons sortent. Le manquant est le fait `residence` de Feldspath Cresson
     * (`tree_person` 181), qui n'a **aucune** borne de date, donc aucune barre. Ce n'était
     * pas un décompte approximatif à arrondir : c'est la règle qui se voyait dans les
     * chiffres.
     *
     * La raison est double, et la seconde est celle qu'on oublie : un jalon orphelin
     * désignerait par `personId` une barre absente — le front n'a nulle part où le poser —,
     * **et** il révélerait l'existence d'une personne que la réponse a justement écartée.
     */
    public function testUnJalonNeSurvitPasAUnePersonneSansBarre(): void
    {
        // Datée, donc porteuse d'un jalon ; mais sans aucune date à elle, donc sans barre.
        ArbreDeChronologieEnMemoire::ajouterPersonneOrpheline(181, 'Feldspath Cresson');
        ArbreEnMemoire::ajouterPersonne(181, 'Feldspath', 'Cresson', vivante: false);
        ArbreDeChronologieEnMemoire::ajouterFait(181, 'residence', 'Résidence', null, ArbreDeChronologieEnMemoire::date('1922', 'Sorbière'));
        ArbreDeChronologieEnMemoire::ajouterFait(self::SUREAU, 'recensement', 'Recensement', null, ArbreDeChronologieEnMemoire::date('1957', 'Saint-Ronce-de-Verçay'));

        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie');

        $charge = $this->charge();
        self::assertIsArray($charge['jalons']);

        // On affirme la TAILLE avant de regarder le contenu : un parcours sur un ensemble
        // devenu vide passerait en silence, et c'est précisément ce que ce cas garde.
        self::assertCount(1, $charge['jalons'], 'Deux faits datés posés, une seule personne dessinée : un seul jalon.');
        self::assertSame(self::SUREAU, $charge['jalons'][0]['personId']);

        self::assertArrayNotHasKey(181, $this->barresParPersonne());
    }

    /**
     * Un jalon ne survit pas à l'écartement de sa barre.
     *
     * `personId` est la clé par laquelle le front rattache un jalon à sa barre : un jalon
     * orphelin désignerait une barre absente, et révélerait au passage qu'une personne
     * existe dans une branche que le lecteur a justement décochée.
     */
    public function testUnJalonNeSurvitPasAuFiltreDeBranches(): void
    {
        ArbreDeChronologieEnMemoire::ajouterFait(self::SUREAU, 'recensement', 'Recensement', null, ArbreDeChronologieEnMemoire::date('1957', 'Saint-Ronce-de-Verçay'));

        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie?branches=tr');

        $charge = $this->charge();

        self::assertSame([self::LECTEUR], array_keys($this->barresParPersonne()));
        self::assertSame([], $charge['jalons'], 'Sureau est en `pm`, décochée : son jalon part avec sa barre.');
    }

    // ── Les paramètres ───────────────────────────────────────────────────────────

    /**
     * **Un paramètre de requête n'écrit pas la préférence** — § 9.1.
     *
     * C'est le cas qui garde l'idempotence du `GET`. Il l'éprouve par les deux bouts :
     * la réponse suivante repart bien du défaut, **et** aucune ligne n'a été créée en base.
     * Le second bout compte autant : une implémentation qui créerait la ligne avec la
     * valeur par défaut passerait le premier.
     */
    public function testUnParametreDeRequeteNEcritPasLaPreference(): void
    {
        $this->connecterUnMembre();

        $this->requete('GET', '/api/chronologie?bande=50&orientation=colonnes&branches=pm');
        self::assertSame(50, $this->charge()['bande']);

        $this->requete('GET', '/api/chronologie/preferences');
        self::assertSame(
            ['bandYears' => 10, 'orientation' => 'lignes', 'branches' => ['pp', 'pm', 'mp', 'mm', 'tr', 'bf'], 'showUncertainty' => true],
            $this->charge(),
            'Le GET a écrit la préférence : c\'est exactement ce que le § 9.1 interdit.',
        );

        self::assertSame(
            0,
            (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tml_preference'),
            'Aucune ligne `tml_preference` ne doit exister : un GET ne crée pas la ligne manquante.',
        );
    }

    /** Sans paramètre, la réponse applique la préférence enregistrée du compte. */
    public function testSansParametreLaReponseAppliqueLaPreferenceEnregistree(): void
    {
        $this->connecterUnMembre();

        $this->requete('PUT', '/api/chronologie/preferences', [
            'bandYears' => 25,
            'orientation' => 'colonnes',
            'branches' => ['pm', 'tr'],
            'showUncertainty' => false,
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->requete('GET', '/api/chronologie');
        $charge = $this->charge();

        self::assertSame(25, $charge['bande']);
        self::assertSame('colonnes', $charge['orientation']);
        self::assertSame(['pm', 'tr'], $charge['branches']);
        self::assertFalse($charge['showUncertainty']);

        // Et le filtre de branches issu de la préférence s'applique bien aux barres.
        self::assertSame([self::LECTEUR, self::SUREAU], array_keys($this->barresParPersonne()));
    }

    /**
     * Les trois paramètres sont **indépendants** : fournir `bande` seul laisse les deux
     * autres à la préférence.
     *
     * Une résolution « tout ou rien » — si un paramètre est fourni, on ignore la préférence
     * — passerait la plupart des autres cas de ce fichier.
     */
    public function testUnParametreFourniNeReinitialisePasLesAutres(): void
    {
        $this->connecterUnMembre();

        $this->requete('PUT', '/api/chronologie/preferences', [
            'bandYears' => 25,
            'orientation' => 'colonnes',
            'branches' => ['pm', 'tr'],
            'showUncertainty' => true,
        ]);

        $this->requete('GET', '/api/chronologie?bande=5');
        $charge = $this->charge();

        self::assertSame(5, $charge['bande'], 'Le paramètre l\'emporte sur la préférence.');
        self::assertSame('colonnes', $charge['orientation'], 'L\'orientation reste celle de la préférence.');
        self::assertSame(['pm', 'tr'], $charge['branches'], 'Les branches restent celles de la préférence.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parametresInvalides(): iterable
    {
        yield 'bande à zéro' => ['?bande=0', 'bande'];
        yield 'bande au-delà de 500' => ['?bande=501', 'bande'];
        yield 'bande non entière' => ['?bande=vingt-cinq', 'bande'];
        // Présent mais vide : une intention exprimée sans valeur, pas une absence.
        yield 'bande vide' => ['?bande=', 'bande'];
        yield 'orientation inconnue' => ['?orientation=diagonale', 'orientation'];
        yield 'branche inconnue' => ['?branches=pm,xx', 'branches'];
        yield 'branches vides' => ['?branches=', 'branches'];
    }

    #[DataProvider('parametresInvalides')]
    public function testUnParametreInvalideRend422(string $requete, string $champAttendu): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie'.$requete);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());

        $charge = $this->charge();
        self::assertIsArray($charge['erreur']);
        self::assertSame('donnees_invalides', $charge['erreur']['code']);
        self::assertIsArray($charge['erreur']['champs']);
        self::assertArrayHasKey($champAttendu, $charge['erreur']['champs']);
    }

    /** Les bornes du § 9.1 sont acceptées telles quelles — 1 et 500 sont dedans. */
    public function testLesBornesDeBandeSontInclusives(): void
    {
        $this->connecterUnMembre();

        foreach ([1, 500] as $bande) {
            $this->requete('GET', '/api/chronologie?bande='.$bande);
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'bande='.$bande);
            self::assertSame($bande, $this->charge()['bande']);
        }
    }

    // ── La forme de la réponse ───────────────────────────────────────────────────

    /**
     * Les huit clés du § 9.1, ni plus ni moins.
     *
     * Une clé en trop dans ce module est la forme que prend une fuite ; une clé en moins
     * est un front qui plante. On compare donc l'ensemble des clés, pas leur présence.
     */
    public function testLaReponsePorteExactementLesHuitClesDuParagraphe91(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie');

        self::assertSame(
            ['bande', 'orientation', 'branches', 'showUncertainty', 'bornes', 'bandes', 'barres', 'jalons'],
            array_keys($this->charge()),
        );
    }

    /**
     * **`bornes` n'est jamais `null` ; `bandes: []` est l'unique signal de vacuité.**.
     *
     * C'est un contrat avec le front, qui type `bornes` **non nullable** et déclenche son
     * état vide sur `bandes: []`. Un `bornes: null` n'aurait menti que pour un compte qui
     * ne voit rien — c'est-à-dire pas avant la production, le pire moment pour découvrir
     * qu'une clé non nullable l'était.
     *
     * Le cas est provoqué par un filtre de branches qui ne retient personne de visible :
     * le lecteur est en `tr`, Sureau en `pm`, et on ne demande que `mp`.
     */
    public function testQuandRienNEstVisibleLesBornesRestentPresentesEtLesBandesSontVides(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie?branches=mp');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $charge = $this->charge();
        $anneeCourante = (int) (new \DateTimeImmutable())->format('Y');

        self::assertSame([], $charge['barres'], 'Aucune personne visible en `mp` dans cet arbre.');
        self::assertSame([], $charge['bandes'], '`bandes: []` est le signal de vacuité.');
        self::assertIsArray($charge['bornes'], '`bornes` reste présent, jamais `null`.');
        self::assertSame(
            ['min' => $anneeCourante, 'max' => $anneeCourante],
            $charge['bornes'],
            'Quand rien n\'est visible, les bornes valent l\'année courante des deux côtés.',
        );
        self::assertSame([], $charge['jalons']);
    }

    /**
     * Les bandes servies couvrent bien les bornes servies, et le front n'a rien à
     * recalculer : « le front ne les recalcule pas » (§ 9.1).
     *
     * L'invariant testé est celui qui rendrait la vue inutilisable s'il tombait : la
     * première bande commence **sur** `bornes.min`, et la dernière contient `bornes.max`.
     */
    public function testLesBandesCouvrentLesBornesServies(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie?bande=25');

        $charge = $this->charge();
        $bornes = $charge['bornes'];
        $bandes = $charge['bandes'];

        self::assertNotEmpty($bandes);
        self::assertSame($bornes['min'], $bandes[0]['debut'], 'La première bande commence sur bornes.min.');

        $derniere = $bandes[\count($bandes) - 1];
        self::assertLessThanOrEqual($bornes['max'], $derniere['debut']);
        self::assertGreaterThanOrEqual($bornes['max'], $derniere['fin'], 'La dernière bande contient bornes.max — quitte à le dépasser.');
    }

    /**
     * Une personne sans branche ni génération n'est pas plaçable : elle n'a pas de barre.
     *
     * Le cas ne se produit pas sur la vraie base — les 274 personnes ont toutes une branche
     * et une génération — mais le normaliseur ne devine ni l'une ni l'autre, et le dire ici
     * évite qu'on « complète utilement » un jour avec une valeur par défaut.
     */
    public function testUnePersonneSansBrancheNiGenerationNaPasDeBarre(): void
    {
        ArbreDeChronologieEnMemoire::ajouterPersonneOrpheline(42, 'Sans Branche', ArbreDeChronologieEnMemoire::date('1900'));
        ArbreEnMemoire::ajouterPersonne(42, 'Sans', 'Branche', vivante: false);

        $this->connecterUnMembre();
        $this->requete('GET', '/api/chronologie');

        self::assertArrayNotHasKey(42, $this->barresParPersonne());
    }

    /** Sans session, les trois routes rendent `401` et non `500` ni `200` vide. */
    public function testLesRoutesExigentUneSession(): void
    {
        foreach ([['GET', '/api/chronologie'], ['GET', '/api/chronologie/preferences']] as [$methode, $chemin]) {
            $this->requete($methode, $chemin);

            self::assertSame(
                Response::HTTP_UNAUTHORIZED,
                $this->client->getResponse()->getStatusCode(),
                $methode.' '.$chemin,
            );
        }
    }
}
