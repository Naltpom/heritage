<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Tests\Membership\Double\ArbreEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * « CETTE PERSONNE EXISTE DÉJÀ DANS L'ARBRE » — par le vrai cercle, sur un vrai arbre.
 *
 * `POST /api/demandes/doublons-probables` est une **porte de sortie** au sens de
 * l'ADR 0003 : elle rend des fiches à partir d'un nom tapé, c'est-à-dire une recherche.
 * Ces tests la tiennent aux règles de la recherche (§ 5.5), et à celle de la réciprocité
 * des vivants (ADR 0016) :
 *
 * 1. une fiche **confidentielle hors du cercle** est **absente** — ni résultat, ni nom,
 *    ni indice : la réponse brute ne la contient pas ;
 * 2. la naissance d'un vivant n'est **ni servie ni comparée** au-delà de ce que la
 *    réciprocité accorde — un membre qui ne partage rien ne lit pas « 1934 », et le
 *    rapprochement ne s'en sert pas ;
 * 3. un défunt sort en entier, avec ses proches, et une date incompatible l'écarte.
 */
final class DoublonsProbablesDansLArbreTest extends ArbreWebTestCase
{
    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();
        parent::tearDown();
    }

    public function testUnDefuntDeLArbreSortEnEntierAvecSesProches(): void
    {
        $this->amorcer(cousinConfidentiel: false);
        $this->membreConnecte();

        $this->chercher('Marne', 'Sureau', 'feminin', true, ['raw' => '1905', 'qualifier' => 'annee', 'min' => '1905-01-01', 'max' => '1905-12-31', 'place' => null]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $personnes = $this->charge()['personnes'];
        self::assertIsArray($personnes);
        self::assertCount(1, $personnes);
        self::assertSame($this->idDe('aieule'), $personnes[0]['personId']);
        self::assertSame('entre 1901 et 1909', $personnes[0]['birth']['texte'], 'La fourchette se sert telle quelle.');
        self::assertSame(['nom', 'prenom', 'naissance', 'sexe'], $personnes[0]['criteres']);
        self::assertSame([$this->idDe('aieul')], array_column($personnes[0]['conjoints'], 'personId'));
        self::assertSame([$this->idDe('ego')], array_column($personnes[0]['enfants'], 'personId'));
    }

    public function testUneDateIncompatibleEcarteLeDefunt(): void
    {
        $this->amorcer(cousinConfidentiel: false);
        $this->membreConnecte();

        // Une homonyme née un demi-siècle plus tard : une autre personne.
        $this->chercher('Marne', 'Sureau', 'feminin', true, ['raw' => '1960', 'qualifier' => 'annee', 'min' => '1960-01-01', 'max' => '1960-12-31', 'place' => null]);
        self::assertSame([], $this->charge()['personnes']);
    }

    /**
     * Un vivant **visible** sort — c'est la révision du 2026-10-01 de l'ADR 0003 —, mais sa
     * naissance passe par la réciprocité : ce membre ne partage rien, il ne lit donc pas
     * l'année, et le rapprochement ne la compare pas (« naissance » absente des critères,
     * alors que la saisie porte la bonne année).
     */
    public function testUnVivantVisibleSortSansSaNaissance(): void
    {
        $this->amorcer(cousinConfidentiel: false);
        $this->membreConnecte();

        $this->chercher('Pyroxène', 'Tilleul', 'masculin', false, ['raw' => '1934', 'qualifier' => 'annee', 'min' => '1934-01-01', 'max' => '1934-12-31', 'place' => null]);

        $personnes = $this->charge()['personnes'];
        self::assertIsArray($personnes);
        self::assertCount(1, $personnes);
        self::assertSame($this->idDe('cousin'), $personnes[0]['personId']);
        self::assertSame('masquee', $personnes[0]['birth']['qualifier']);
        self::assertNotContains('naissance', $personnes[0]['criteres']);
        self::assertStringNotContainsString('1934', (string) $this->client->getResponse()->getContent());
    }

    /**
     * **La règle de l'ADR 0003.** La même fiche, confidentielle et hors du cercle de ce
     * membre : absente — et sur la réponse brute, pas seulement dans la liste.
     */
    public function testUneFicheConfidentielleHorsDuCercleNeSortPas(): void
    {
        $this->amorcer(cousinConfidentiel: true);
        $this->membreConnecte();

        $this->chercher('Pyroxène', 'Tilleul', 'masculin', false, null);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->charge()['personnes']);

        $brut = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString((string) $this->idDe('cousin'), $brut);
        self::assertStringNotContainsString('Sorbière', $brut);
    }

    /**
     * Un proche confidentiel hors du cercle reste **cité, sous le masque** — comme sur la
     * fiche d'un proche : la conjointe Cobalt est visible, son conjoint ne l'est pas.
     */
    public function testUnProcheConfidentielEstCiteSousLeMasque(): void
    {
        $this->amorcer(cousinConfidentiel: true);
        $this->membreConnecte();

        $this->chercher('Cobalt', 'Romarin', 'feminin', false, null);

        $personnes = $this->charge()['personnes'];
        self::assertIsArray($personnes);
        self::assertCount(1, $personnes);
        self::assertSame(
            [['personId' => $this->idDe('cousin'), 'displayName' => CercleDeVisibiliteInterface::NOM_MASQUE]],
            $personnes[0]['conjoints'],
        );
        self::assertStringNotContainsString('Tilleul', (string) $this->client->getResponse()->getContent());
    }

    public function testUnCompteEnAttenteNeLitRien(): void
    {
        $this->amorcer(cousinConfidentiel: false);
        $this->connexionDe('attente@heritage.test', rattache: false);

        $this->chercher('Marne', 'Sureau', 'feminin', true, null);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * L'arbre du socle, tel que `Membership` le voit : seul le cousin peut être posé
     * confidentiel. Le membre est rattaché à une fiche hors de l'arbre — son cercle
     * généalogique est vide, c'est le drapeau qui décide.
     */
    private function amorcer(bool $cousinConfidentiel): void
    {
        ArbreEnMemoire::reinitialiser();

        foreach (['ego' => true, 'conjointe' => true, 'cousin' => true, 'aieule' => false, 'aieul' => false, 'sansDate' => false] as $cle => $vivante) {
            ArbreEnMemoire::ajouterPersonne($this->idDe($cle), $cle, '', vivante: $vivante, confidentielle: $cousinConfidentiel && 'cousin' === $cle);
        }

        ArbreEnMemoire::ajouterFamille([$this->idDe('aieul'), $this->idDe('aieule')], [$this->idDe('ego')]);
        ArbreEnMemoire::ajouterFamille([$this->idDe('cousin'), $this->idDe('conjointe')], [$this->idDe('sansDate')]);
    }

    private function membreConnecte(): void
    {
        $this->connexionDe('membre@heritage.test', rattache: true);
    }

    private function connexionDe(string $courriel, bool $rattache): void
    {
        if ($rattache) {
            $this->creerLecteurHorsCercle($courriel);
        } else {
            $utilisateur = $this->creerUtilisateur($courriel);
            self::assertNotNull($utilisateur->getId());
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }

    /** @param array<string, mixed>|null $naissance */
    private function chercher(string $prenom, string $nom, string $sexe, bool $decede, ?array $naissance): void
    {
        $this->client->request('POST', '/api/demandes/doublons-probables', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode([
            'kind' => 'creation_personne',
            'soumettre' => true,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => $prenom,
                'surname' => $nom,
                'sex' => $sexe,
                'deceased' => $decede,
                'birth' => $naissance,
            ]],
            'liens' => [],
        ], \JSON_THROW_ON_ERROR));
    }
}
