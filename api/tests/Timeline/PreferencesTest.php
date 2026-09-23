<?php

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Module\Timeline\Entity\Preference;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET` et `PUT /api/chronologie/preferences` — § 9.2.
 *
 * ## Ce que ces cas gardent
 *
 * 1. **`PUT` est un remplacement complet, pas un `PATCH`.** Quatre champs, tous
 *    obligatoires — « aucune ambiguïté sur ce que “absent” veut dire ». Un corps partiel
 *    rend `422`, et ne fusionne pas silencieusement avec l'existant ;
 * 2. **`bandYears` hors de 1–500 rend `422`**, et le refus vient de la validation, pas de
 *    `ck_tml_preference_bande`. Laisser la contrainte parler remonterait une violation en
 *    `500` — en panne — là où c'est une saisie invalide ;
 * 3. **`showUncertainty` vaut `true` par défaut**, « parce que masquer l'incertitude est
 *    précisément ce que le § 4 du dossier interdit ». Ce n'est pas un réglage d'ergonomie
 *    réversible par distraction : c'est une règle de fond écrite en valeur par défaut ;
 * 4. **une préférence appartient à un compte**, et l'unicité `uniq_tml_preference_user`
 *    signifie qu'un second `PUT` met à jour la ligne, il n'en crée pas une deuxième.
 */
final class PreferencesTest extends CasDeTestTimeline
{
    private const int LECTEUR = 1;

    private const array CORPS_VALIDE = [
        'bandYears' => 25,
        'orientation' => 'colonnes',
        'branches' => ['pm', 'mm'],
        'showUncertainty' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->poserPersonne(self::LECTEUR, 'Natron Romarin', Branch::Tr, 0, Sex::Masculin, vivant: true, naissance: '26 SEP 2012');

        $utilisateur = $this->creerUtilisateur('membre@example.test');
        $this->creerMembre($utilisateur, self::LECTEUR, 'Natron Romarin');
        $this->connexion('membre@example.test');
    }

    // ── Lecture ──────────────────────────────────────────────────────────────────

    /**
     * Sans ligne enregistrée, le `GET` rend le défaut du projet — **sans créer la ligne**.
     *
     * Le second bout est celui qui compte : une implémentation qui créerait la ligne au
     * premier `GET` servirait exactement les mêmes valeurs et passerait une assertion qui
     * ne regarderait que la réponse.
     */
    public function testSansLigneLeGetRendLeDefautSansRienEcrire(): void
    {
        $this->requete('GET', '/api/chronologie/preferences');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            [
                'bandYears' => 10,
                'orientation' => 'lignes',
                'branches' => ['pp', 'pm', 'mp', 'mm', 'tr', 'bf', 'cpp', 'cpm', 'cmp', 'cmm'],
                'showUncertainty' => true,
            ],
            $this->charge(),
        );

        self::assertSame(
            0,
            (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tml_preference'),
            'Un GET ne crée pas la ligne manquante : sinon la lecture deviendrait une écriture.',
        );
    }

    /**
     * Le défaut de `bandYears` vient de `timeline.bande_annees_defaut`, qu'un
     * administrateur change **sans redéploiement** (§ 10.2).
     *
     * Sans ce cas, une constante `10` écrite en dur passerait le cas précédent.
     */
    public function testLeDefautDeBandeSuitLeReglageDAdministration(): void
    {
        $this->reglerEntier('timeline.bande_annees_defaut', 50, 1, 200);

        $this->requete('GET', '/api/chronologie/preferences');
        self::assertSame(50, $this->charge()['bandYears']);

        $this->requete('GET', '/api/chronologie');
        self::assertSame(50, $this->charge()['bande'], 'La vue part du même défaut que la préférence.');
    }

    // ── Écriture ─────────────────────────────────────────────────────────────────

    public function testUnPutValideEnregistreEtRendLaPreference(): void
    {
        $this->requete('PUT', '/api/chronologie/preferences', self::CORPS_VALIDE);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::CORPS_VALIDE, $this->charge());

        $ligne = $this->em()->getRepository(Preference::class)->findOneBy([]);
        self::assertInstanceOf(Preference::class, $ligne);
        self::assertSame(25, $ligne->getBandYears());
        self::assertSame([Branch::Pm, Branch::Mm], $ligne->getBranches());
        self::assertTrue($ligne->isShowUncertainty());
    }

    /** Un second `PUT` met la ligne à jour ; il n'en crée pas une seconde. */
    public function testUnSecondPutMetAJourLaMemeLigne(): void
    {
        $this->requete('PUT', '/api/chronologie/preferences', self::CORPS_VALIDE);
        $this->requete('PUT', '/api/chronologie/preferences', [
            'bandYears' => 5,
            'orientation' => 'lignes',
            'branches' => ['tr'],
            'showUncertainty' => false,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            1,
            (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tml_preference'),
            'uniq_tml_preference_user : une ligne par compte.',
        );

        $this->requete('GET', '/api/chronologie/preferences');
        self::assertSame(
            ['bandYears' => 5, 'orientation' => 'lignes', 'branches' => ['tr'], 'showUncertainty' => false],
            $this->charge(),
        );
    }

    /**
     * `showUncertainty` peut être éteint — le front en a le droit — mais il ne vaut jamais
     * `false` **par défaut** : le cas précédent l'éteint explicitement, celui-ci vérifie
     * qu'il revient à `true` sur une ligne qui n'a jamais été écrite.
     *
     * Les deux ensemble disent la règle du § 9.2 : « le front peut l'éteindre ; il ne doit
     * pas le faire silencieusement au premier rendu ».
     */
    public function testLesHachuresSontAllumeesParDefautEtEteignablesALaDemande(): void
    {
        $this->requete('GET', '/api/chronologie');
        self::assertTrue($this->charge()['showUncertainty'], 'Le défaut est `true` (§ 9.2).');

        $this->requete('PUT', '/api/chronologie/preferences', [...self::CORPS_VALIDE, 'showUncertainty' => false]);
        $this->requete('GET', '/api/chronologie');

        self::assertFalse($this->charge()['showUncertainty']);
    }

    // ── Refus ────────────────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function corpsInvalides(): iterable
    {
        // Le remplacement est complet : chacun des quatre champs manquants est une erreur,
        // et non une valeur conservée.
        foreach (['bandYears', 'orientation', 'branches', 'showUncertainty'] as $manquant) {
            $corps = self::CORPS_VALIDE;
            unset($corps[$manquant]);

            yield 'champ manquant : '.$manquant => [$corps, $manquant];
        }

        yield 'bandYears à zéro' => [[...self::CORPS_VALIDE, 'bandYears' => 0], 'bandYears'];
        yield 'bandYears au-delà de 500' => [[...self::CORPS_VALIDE, 'bandYears' => 501], 'bandYears'];
        yield 'bandYears négatif' => [[...self::CORPS_VALIDE, 'bandYears' => -25], 'bandYears'];
        // Typé, pas coercé : accepter « 25 » obligerait à décider un jour si « 25ans » vaut 25.
        yield 'bandYears en chaîne' => [[...self::CORPS_VALIDE, 'bandYears' => '25'], 'bandYears'];
        yield 'orientation inconnue' => [[...self::CORPS_VALIDE, 'orientation' => 'diagonale'], 'orientation'];
        yield 'branche inconnue' => [[...self::CORPS_VALIDE, 'branches' => ['pm', 'xx']], 'branches'];
        yield 'branches vides' => [[...self::CORPS_VALIDE, 'branches' => []], 'branches'];
        yield 'branches en chaîne' => [[...self::CORPS_VALIDE, 'branches' => 'pm,mm'], 'branches'];
        yield 'showUncertainty non booléen' => [[...self::CORPS_VALIDE, 'showUncertainty' => 'oui'], 'showUncertainty'];
    }

    /**
     * @param array<string, mixed> $corps
     */
    #[DataProvider('corpsInvalides')]
    public function testUnCorpsInvalideRend422SansRienEcrire(array $corps, string $champAttendu): void
    {
        $this->requete('PUT', '/api/chronologie/preferences', $corps);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());

        $charge = $this->charge();
        self::assertIsArray($charge['erreur']);
        self::assertSame('donnees_invalides', $charge['erreur']['code']);
        self::assertIsArray($charge['erreur']['champs']);
        self::assertArrayHasKey(
            $champAttendu,
            $charge['erreur']['champs'],
            'Le 422 doit nommer le champ fautif, sinon le front ne sait pas quoi corriger.',
        );

        self::assertSame(
            0,
            (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tml_preference'),
            'Un corps refusé n\'écrit rien, même partiellement.',
        );
    }

    /**
     * Une clé inconnue est une erreur, pas un silence.
     *
     * C'est ce qui rend la promesse du § 9.2 vraie : un front qui poste `bandeYears` au
     * lieu de `bandYears` recevrait sinon `200` et verrait son réglage ignoré — la pire
     * des réponses, parce qu'elle a l'air d'un succès.
     */
    public function testUneCleInconnueEstRefusee(): void
    {
        $this->requete('PUT', '/api/chronologie/preferences', [...self::CORPS_VALIDE, 'bandeYears' => 25]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());

        // Le détail va dans `champs`, pas dans `message` : c'est la convention du § 15.1,
        // où `message` est le résumé lisible et `champs` porte le grief par champ. Même
        // forme que `Media\Http\BlocDate` pour son propre refus de clé inattendue.
        $champs = $this->charge()['erreur']['champs'];
        self::assertIsArray($champs);
        self::assertStringContainsString(
            'bandeYears',
            $champs['corps'] ?? '',
            'Le 422 doit nommer la clé fautive, sinon le front cherche sa faute de frappe à l\'aveugle.',
        );
    }

    /** Les bornes de `ck_tml_preference_bande` sont inclusives des deux côtés. */
    public function testLesBornesDeBandeSontInclusives(): void
    {
        foreach ([1, 500] as $bande) {
            $this->requete('PUT', '/api/chronologie/preferences', [...self::CORPS_VALIDE, 'bandYears' => $bande]);

            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'bandYears='.$bande);
            self::assertSame($bande, $this->charge()['bandYears']);
        }
    }

    public function testUnCorpsIllisibleRend422EtNonUne500(): void
    {
        $this->client->request(
            'PUT',
            '/api/chronologie/preferences',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{ceci n\'est pas du JSON',
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }
}
