<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\UserStatus;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Tests\Membership\Double\ArbreEnMemoire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * `POST /api/arbre/ressemblances` — « cette personne est peut-être déjà dans l'arbre »
 * (§ 5.5 bis), **de bout en bout** : pare-feu, contrôleur, cercle, réciprocité.
 *
 * Le petit arbre du socle ({@see ArbreWebTestCase}) suffit : un défunt dont on peut tout
 * comparer (Marne Dolomite Sureau, Émeri Sarriette), un vivant dont la naissance n'est pas
 * partagée (Pyroxène Tilleul, né vers 1934), et deux vivants qui portent le même nom
 * (Natron et Cobalt Romarin) pour le tri.
 *
 * Le test qui compte le plus est {@see testUnVivantDontLaNaissanceNEstPasPartageeRendLaMemeReponseQuelleQueSoitLaDateSaisie()} :
 * sans lui, rien n'empêcherait cette route de devenir un oracle de date de naissance.
 */
final class RessemblanceDansLArbreTest extends ArbreWebTestCase
{
    private const string MEMBRE = 'membre@heritage.test';

    /** Les clés d'une ressemblance, et pas une de plus. */
    private const array CLES_D_UN_ELEMENT = [
        'personId', 'displayName', 'sex', 'vivant', 'generation', 'branch',
        'ligneDates', 'lieuNaissance', 'criteres', 'raisons', 'score',
    ];

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();

        parent::tearDown();
    }

    public function testUnAnonymeEstRefuse(): void
    {
        $this->ressemblances(['givenNames' => 'Marne', 'surname' => 'Sureau']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /** Un compte en attente ne lit rien de l'arbre — ni par la recherche, ni par ici. */
    public function testUnCompteEnAttenteEstRefuse(): void
    {
        $this->creerUtilisateur('attente@heritage.test', statut: UserStatus::EnAttente);
        $this->connexionDe('attente@heritage.test');

        $this->ressemblances(['givenNames' => 'Marne', 'surname' => 'Sureau']);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame('regime_en_attente', $this->charge()['erreur']['code']);
    }

    public function testUneFauteDeFrappeDansLeNomRetrouveLaFiche(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances(['givenNames' => 'Emeri', 'surname' => 'Sarriete']);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $elements = $this->charge()['elements'];
        self::assertSame([$this->idDe('aieul')], array_column($elements, 'personId'));
        self::assertSame(['nom proche : Sarriete / Sarriette', 'prénom Émeri en commun'], $elements[0]['raisons']);
        self::assertSame(['nom', 'prenom'], $elements[0]['criteres']);
    }

    /**
     * Un défunt est un fait historique : sa fiche se compare en entier, et la réponse le
     * dit avec ses dates, son lieu et toutes ses raisons.
     */
    public function testUnDefuntSortAvecSesDatesEtSesRaisons(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $this->requete('/api/arbre/recherche?q=sureau');
        $ligneDeRecherche = $this->charge()['elements'][0];

        $this->ressemblances([
            'givenNames' => 'Dolomite',
            'surname' => 'Sureau',
            'sex' => 'feminin',
            'deceased' => true,
            'birth' => self::bloc('annee', '1905', 'Saule'),
            'death' => self::bloc('annee', '1987'),
        ]);

        $charge = $this->charge();
        self::assertSame(['elements', 'tronque'], array_keys($charge), 'Pas de `total` : une recherche qui se compte est une énumération.');
        self::assertCount(1, $charge['elements']);

        $element = $charge['elements'][0];
        self::assertSame(self::CLES_D_UN_ELEMENT, array_keys($element));
        self::assertSame($this->idDe('aieule'), $element['personId']);
        self::assertSame('Marne Dolomite Sureau', $element['displayName']);
        self::assertSame('feminin', $element['sex']);
        self::assertFalse($element['vivant']);
        self::assertSame($ligneDeRecherche['ligneDates'], $element['ligneDates'], 'La ligne de dates est celle de la recherche.');
        self::assertSame('1901-1909 – 1987', $element['ligneDates'], 'L’exemple du § 5.5 bis.');
        self::assertSame('Saule, Ombrage, Sylvanie', $element['lieuNaissance']);
        self::assertSame(['nom', 'prenom', 'sexe', 'naissance', 'deces', 'lieu'], $element['criteres']);
        self::assertSame(6, $element['score']);
        self::assertSame([
            'même nom',
            'prénom Dolomite en commun',
            'même sexe',
            'naissance compatible : entre 1901 et 1909 dans la fiche',
            'décédée en 1987, comme dans la fiche',
            'même lieu de naissance : Saule',
        ], $element['raisons']);
        self::assertFalse($charge['tronque']);
    }

    public function testUneDateIncompatibleEcarteUnDefunt(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances(['givenNames' => 'Marne', 'surname' => 'Sureau', 'death' => self::bloc('annee', '1950')]);

        self::assertSame([], $this->charge()['elements']);
    }

    /**
     * **L'oracle de date de naissance, fermé.** Le membre voit Pyroxène Tilleul — vivant,
     * non confidentiel — mais pas sa naissance : ni proches, ni partage à « tous ». Saisir
     * 1934, 1990 ou rien doit rendre **la même réponse, octet pour octet** : sinon il
     * suffirait d'essayer les années une à une et de regarder la fiche disparaître.
     */
    public function testUnVivantDontLaNaissanceNEstPasPartageeRendLaMemeReponseQuelleQueSoitLaDateSaisie(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $reponses = [];

        foreach ([
            'vers 1934' => self::bloc('environ', '1934'),
            'en 1990' => self::bloc('annee', '1990'),
            'au jour près' => ['raw' => '11 MAR 1934', 'qualifier' => 'exacte', 'min' => '1934-03-11', 'max' => '1934-03-11', 'place' => null],
            'sans date' => null,
        ] as $cas => $naissance) {
            $this->ressemblances(['givenNames' => 'Pyroxène', 'surname' => 'Tilleul', 'birth' => $naissance]);
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $cas);
            $reponses[$cas] = (string) $this->client->getResponse()->getContent();
        }

        self::assertCount(1, array_unique($reponses), 'La réponse ne doit dépendre en rien de la date saisie.');

        $element = $this->charge()['elements'][0];
        self::assertSame($this->idDe('cousin'), $element['personId']);
        self::assertTrue($element['vivant']);
        self::assertSame(['nom', 'prenom'], $element['criteres'], 'Ni critère, ni raison sur une naissance non partagée.');
        self::assertStringNotContainsString('1934', $reponses['vers 1934']);
        // Le lieu, lui, sort : la fiche d'un vivant visible le montre (§ 5.6).
        self::assertSame('Sorbière, Fougerie, Florie', $element['lieuNaissance']);
    }

    /**
     * L'exemple du § 5.5 bis, rejoué tel qu'écrit : le nom à une faute près, le prénom, le
     * sexe et le lieu de naissance concordent ; la naissance, non partagée, ne dit rien —
     * et la ligne de dates non plus.
     */
    public function testLExempleDuContratPourUnVivantDontLaNaissanceEstMasquee(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances([
            'givenNames' => 'Pyroxène',
            'surname' => 'Tilleule',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            'deceased' => false,
            'birth' => ['raw' => 'ABT 1934', 'qualifier' => 'environ', 'min' => '1934-01-01', 'max' => '1934-12-31', 'place' => 'Sorbière'],
            'death' => null,
        ], limite: 5);

        self::assertSame(['elements' => [[
            'personId' => $this->idDe('cousin'),
            'displayName' => 'Pyroxène Tilleul',
            'sex' => 'masculin',
            'vivant' => true,
            'generation' => -2,
            'branch' => 'mm',
            'ligneDates' => '—',
            'lieuNaissance' => 'Sorbière, Fougerie, Florie',
            'criteres' => ['nom', 'prenom', 'sexe', 'lieu'],
            'raisons' => ['nom proche : Tilleule / Tilleul', 'prénom Pyroxène en commun', 'même sexe', 'même lieu de naissance : Sorbière'],
            'score' => 4,
        ]], 'tronque' => false], $this->charge());
    }

    /** Un `POST` qui n'écrit rien : la saisie reste hors de l'URL, et un `GET` n'existe pas. */
    public function testLaRouteNExisteQuEnPost(): void
    {
        $routeur = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $routeur);
        $route = $routeur->getRouteCollection()->get('tree_ressemblances');

        self::assertNotNull($route);
        self::assertSame('/api/arbre/ressemblances', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
    }

    /**
     * La contre-épreuve : la même fiche, pour un lecteur qui **voit** la naissance (l'option
     * d'administration « ignorer la restriction »). La date se compare alors — preuve que
     * l'égalité du test précédent vient du masque, et non d'une comparaison qui ne
     * marcherait jamais.
     */
    public function testUnLecteurQuiVoitLaNaissanceLaCompare(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $membre = $this->em()->getRepository(User::class)->findOneBy(['email' => self::MEMBRE]);
        self::assertInstanceOf(User::class, $membre);
        $membre->setIgnoreBirthRestriction(true);
        $this->em()->flush();

        $this->ressemblances(['givenNames' => 'Pyroxène', 'surname' => 'Tilleul', 'birth' => self::bloc('annee', '1990')]);
        self::assertSame([], $this->charge()['elements'], 'Une naissance vue et incompatible écarte la fiche.');

        $this->ressemblances(['givenNames' => 'Pyroxène', 'surname' => 'Tilleul', 'birth' => self::bloc('environ', '1934')]);
        $element = $this->charge()['elements'][0];
        self::assertSame(['nom', 'prenom', 'naissance'], $element['criteres']);
        self::assertContains('né vers 1934, comme dans la fiche', $element['raisons']);
    }

    /** Un vivant confidentiel hors du cercle est **absent** — comme à la recherche. */
    public function testUnVivantConfidentielHorsDuCercleEstAbsent(): void
    {
        $this->amorcerLArbre(cousinConfidentiel: true);
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances(['givenNames' => 'Pyroxène', 'surname' => 'Tilleul', 'birth' => self::bloc('environ', '1934')]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['elements' => [], 'tronque' => false], $this->charge());
        self::assertStringNotContainsString('Tilleul', (string) $this->client->getResponse()->getContent());
    }

    /**
     * **Un arbre par famille** (révision du 2026-10-04 de l'ADR 0003) : l'arbre d'un autre
     * compte n'existe pas pour le membre — ni ses vivants, ni sa défunte.
     */
    public function testUnePersonneDUnAutreArbreEstAbsente(): void
    {
        $this->amorcerLArbre();
        ArbreEnMemoire::ajouterFamille([$this->idDe('cousin'), $this->idDe('conjointe')], [$this->idDe('sansDate')]);
        $this->rattacher('ami@heritage.test', 'cousin');
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances(['givenNames' => 'Pyroxène', 'surname' => 'Tilleul']);
        self::assertSame([], $this->charge()['elements']);

        $this->ressemblances(['givenNames' => 'Lapis', 'surname' => 'Fenouil']);
        self::assertSame([], $this->charge()['elements'], 'Un défunt de l’autre arbre non plus.');
    }

    /**
     * Le tri : le nombre de critères d'abord, l'ordre alphabétique ensuite. La naissance de
     * Natron est vue (c'est le lecteur lui-même), celle de Cobalt ne l'est pas : à saisie
     * égale, Natron gagne un critère et passe devant, alors que l'alphabet l'aurait mis
     * derrière.
     */
    public function testLesRessemblancesSeTrientParNombreDeCriteres(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances(['givenNames' => 'Natron Cobalt', 'surname' => 'Romarin', 'birth' => self::bloc('annee', '2012')]);
        $elements = $this->charge()['elements'];
        self::assertSame([$this->idDe('ego'), $this->idDe('conjointe')], array_column($elements, 'personId'));
        self::assertSame([3, 2], array_column($elements, 'score'));

        $this->ressemblances(['givenNames' => 'Natron Cobalt', 'surname' => 'Romarin']);
        self::assertSame(
            [$this->idDe('conjointe'), $this->idDe('ego')],
            array_column($this->charge()['elements'], 'personId'),
            'À score égal, l’ordre de la clé : ROMARIN COBALT avant ROMARIN NATRON.',
        );
    }

    /** `tronque` se calcule sur le jeu filtré, et ne dit pas combien il en restait. */
    public function testLaLimiteCoupeEtLeDitSansCompter(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances(['givenNames' => 'Natron Cobalt', 'surname' => 'Romarin'], limite: 1);
        $charge = $this->charge();
        self::assertSame(['elements', 'tronque'], array_keys($charge));
        self::assertCount(1, $charge['elements']);
        self::assertTrue($charge['tronque']);

        $this->ressemblances(['givenNames' => 'Natron', 'surname' => 'Romarin'], limite: 1);
        self::assertFalse($this->charge()['tronque']);
    }

    public function testSansPrenomLaSaisieEstRefusee(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances(['surname' => 'Tilleul']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame('donnees_invalides', $this->charge()['erreur']['code']);
        self::assertSame(['fiche.givenNames'], array_keys($this->charge()['erreur']['champs']));

        $this->ressemblances(['givenNames' => 'Pyroxène', 'surname' => 'T']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame(['fiche.surname'], array_keys($this->charge()['erreur']['champs']));
    }

    /** La réponse dépend du lecteur et de sa saisie : aucun cache n'a à la garder. */
    public function testLaReponseNeSeMetPasEnCache(): void
    {
        $this->amorcerLArbre();
        $this->connexionDe(self::MEMBRE);

        $this->ressemblances(['givenNames' => 'Marne', 'surname' => 'Sureau']);

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * L'arbre tel que `Membership` le voit, et le membre rattaché à ego.
     *
     * Ego, sa conjointe et le cousin sont vivants et **non confidentiels** : depuis la
     * révision du 2026-10-01 de l'ADR 0003, un membre les voit en entier. Le cousin n'est
     * pas un proche d'ego — aucune famille ne les relie ici —, et sa naissance reste donc
     * au partage par défaut, « proches » : le membre ne la voit pas (ADR 0016).
     */
    private function amorcerLArbre(bool $cousinConfidentiel = false): void
    {
        ArbreEnMemoire::reinitialiser();

        foreach (['ego' => true, 'conjointe' => true, 'cousin' => true, 'aieule' => false, 'aieul' => false, 'sansDate' => false] as $cle => $vivante) {
            ArbreEnMemoire::ajouterPersonne($this->idDe($cle), $cle, '', vivante: $vivante, confidentielle: $cousinConfidentiel && 'cousin' === $cle);
        }

        ArbreEnMemoire::ajouterFamille([$this->idDe('aieul'), $this->idDe('aieule')], [$this->idDe('ego')]);

        $this->rattacher(self::MEMBRE, 'ego');
    }

    /** Un compte actif **réellement rattaché** à une fiche : c'est ce qui lui donne un cercle. */
    private function rattacher(string $courriel, string $personne): void
    {
        $utilisateur = $this->creerUtilisateur($courriel);
        $identifiant = $utilisateur->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $courriel, $this->idDe($personne), $this->personnes[$personne]->getDisplayName(), MatchKind::PreLie);
        $membre->activer();

        $this->em()->persist($membre);
        $this->em()->flush();
    }

    /** @param array<string, mixed> $fiche */
    private function ressemblances(array $fiche, ?int $limite = null): void
    {
        $corps = ['fiche' => $fiche];

        if (null !== $limite) {
            $corps['limite'] = $limite;
        }

        $this->client->request('POST', '/api/arbre/ressemblances', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode($corps, \JSON_THROW_ON_ERROR));
    }

    private function connexionDe(string $courriel): void
    {
        if (null === $this->em()->getRepository(User::class)->findOneBy(['email' => $courriel])) {
            $this->creerLecteurHorsCercle($courriel);
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }

    /** @return array{raw: string, qualifier: string, min: string, max: string, place: string|null} */
    private static function bloc(string $qualifier, string $annee, ?string $lieu = null): array
    {
        return [
            'raw' => ('environ' === $qualifier ? 'ABT ' : '').$annee,
            'qualifier' => $qualifier,
            'min' => $annee.'-01-01',
            'max' => $annee.'-12-31',
            'place' => $lieu,
        ];
    }
}
