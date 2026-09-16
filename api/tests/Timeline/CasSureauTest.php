<?php

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Timeline\Service\IntervalleDAnnees;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Timeline\Double\ArbreDeChronologieEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * **Le cas de contrôle du projet : Marne Dolomite Sureau, `BET 1901 AND 1909`.**.
 *
 * Elle porte une fourchette de huit ans sur sa naissance, et elle doit traverser toute la
 * chaîne — port, sérialisation, JSON — **sans perdre sa fourchette**. C'est le bogue
 * nommé dans l'en-tête d'`UncertainDate` : *« c'est exactement le bogue qui faisait naître
 * Marne Dolomite Sureau en 1987, après ses propres enfants »*.
 *
 * Ce fichier la teste **à deux hauteurs**, et les deux comptent :
 *
 * 1. à l'unité, sur {@see IntervalleDAnnees}, là où la conversion se fait ;
 * 2. de bout en bout, sur `GET /api/chronologie`, parce qu'un objet valeur juste ne prouve
 *    pas qu'un aplatissement n'a pas lieu trois couches plus loin — dans le contrôleur,
 *    dans le sérialiseur JSON, ou dans un `array_map` écrit vite.
 *
 * ## Le piège que ce fichier garde vraiment
 *
 * `certain` **n'est pas** `min === max`. Le § 14.3 le dit en toutes lettres : `1930` donne
 * déjà `1930-01-01` / `1930-12-31`, et « un client qui déduirait “`min ≠ max` donc
 * fourchette” classerait les trois quarts de l'arbre en incertain ». La mort de Sureau en
 * **1987** est le contre-exemple parfait : `min` et `max` valent tous deux 1987 après
 * conversion en années, et `certain` vaut pourtant `false`, parce que le qualificatif est
 * `annee` et non `exacte`. Une implémentation qui comparerait les bornes rendrait
 * `certain: true` ici et passerait tous les autres cas de ce fichier.
 */
final class CasSureauTest extends CasDeTestTimeline
{
    private const int SUREAU = 17;
    private const int LECTEUR = 1;

    /**
     * La conversion elle-même : `BET 1901 AND 1909` → `{min: 1901, max: 1909, certain: false}`.
     */
    public function testLaFourchetteDeNaissanceSurvitALaConversionEnAnnees(): void
    {
        $intervalle = (new IntervalleDAnnees())->pour(ArbreDeChronologieEnMemoire::date('BET 1901 AND 1909'));

        self::assertSame(
            ['min' => 1901, 'max' => 1909, 'certain' => false],
            $intervalle,
            'La fourchette de huit ans de Marne Dolomite Sureau a été aplatie.',
        );
    }

    /**
     * `1987` — année pleine : `min === max` et pourtant `certain: false`.
     *
     * C'est le piège 1 du § 14.3, et le seul cas de ce fichier qui distingue une
     * implémentation correcte d'une implémentation qui compare les bornes.
     */
    public function testUneAnneePleineNEstPasUneDateCertaineMalgreDesBornesEgales(): void
    {
        $intervalle = (new IntervalleDAnnees())->pour(ArbreDeChronologieEnMemoire::date('1987'));

        self::assertSame(
            ['min' => 1987, 'max' => 1987, 'certain' => false],
            $intervalle,
            "`certain` se lit sur le qualificatif (`exacte`), jamais sur l'égalité de `min` et `max` (§ 14.3).",
        );
    }

    /** Et le seul cas où `certain` vaut `true` : un jour connu. */
    public function testSeuleUneDateExacteEstCertaine(): void
    {
        $intervalle = (new IntervalleDAnnees())->pour(ArbreDeChronologieEnMemoire::date('11 MAR 1930'));

        self::assertSame(['min' => 1930, 'max' => 1930, 'certain' => true], $intervalle);
    }

    /** `ABT 1934` n'est jamais élargi à ±n années — § 14.3, piège 2. */
    public function testUneDateApproximativeNEstPasElargie(): void
    {
        $intervalle = (new IntervalleDAnnees())->pour(ArbreDeChronologieEnMemoire::date('ABT 1934'));

        self::assertSame(
            ['min' => 1934, 'max' => 1934, 'certain' => false],
            $intervalle,
            'La source dit « vers 1934 », elle ne dit pas « entre 1929 et 1939 ».',
        );
    }

    /** Une borne ouverte reste ouverte : `BEF 1920` n'a pas de `min` à inventer. */
    public function testUneBorneOuverteRendNullEtNonUneBorneInventee(): void
    {
        $intervalle = (new IntervalleDAnnees())->pour(ArbreDeChronologieEnMemoire::date('BEF 1920'));

        self::assertSame(['min' => null, 'max' => 1919, 'certain' => false], $intervalle);
    }

    /**
     * **De bout en bout** : la barre servie par `GET /api/chronologie` est celle du § 9.1,
     * clé par clé.
     *
     * L'assertion porte sur le tableau **entier** et non sur quelques clés : une comparaison
     * partielle laisserait passer une clé en trop — et une clé en trop, dans ce module, est
     * la forme que prend une fuite.
     */
    public function testLaBarreServieEstCelleDuParagraphe91(): void
    {
        $this->poserPersonne(self::LECTEUR, 'Natron Romarin', Branch::Tr, 0, Sex::Masculin, vivant: true, naissance: '26 SEP 2012');
        $this->poserPersonne(
            self::SUREAU,
            'Marne Dolomite Sureau',
            Branch::Pm,
            -3,
            Sex::Feminin,
            vivant: false,
            naissance: 'BET 1901 AND 1909',
            deces: '1987',
        );

        $utilisateur = $this->creerUtilisateur('lecteur@example.test');
        $this->creerMembre($utilisateur, self::LECTEUR, 'Natron Romarin');

        $this->connexion('lecteur@example.test');
        $this->requete('GET', '/api/chronologie?bande=25&orientation=colonnes&branches=pm,tr');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $barres = $this->barresParPersonne();
        self::assertArrayHasKey(self::SUREAU, $barres, 'Une personne décédée est visible de tout compte connecté.');

        self::assertSame(
            [
                'personId' => self::SUREAU,
                'displayName' => 'Marne Dolomite Sureau',
                'branch' => 'pm',
                'generation' => -3,
                'sex' => 'feminin',
                'vivant' => false,
                'debut' => ['min' => 1901, 'max' => 1909, 'certain' => false],
                'fin' => ['min' => 1987, 'max' => 1987, 'certain' => false],
                'ouverte' => false,
                // Forme **compacte** (§ 14.4), pas la prose : `1901-1909` et non
                // « entre 1901 et 1909 ». L'intervalle survit, simplement écrit court.
                'ligneDates' => '1901-1909 – 1987',
            ],
            $barres[self::SUREAU],
            'La barre du § 9.1 doit être reproduite clé pour clé, `ligneDates` comprise.',
        );
    }

    /**
     * `ligneDates` est la forme **compacte** du § 14.4, jamais la prose.
     *
     * La distinction a coûté un arbitrage, parce qu'une seule clé désignait trois objets.
     * Ce cas fige le bon des trois, sur une personne **vivante** — celle qui sépare le plus
     * nettement `ligneDates` d'`etiquetteDates` :
     *
     * - `ligneDates` (ici) : `"n. 26.9.2012"`. Elle ne court-circuite **jamais** sur
     *   `vivant`, parce qu'elle est filtrée en aval — une personne vivante hors cercle est
     *   absente de `barres`, donc celle qui reste expose légitimement ses dates ;
     * - `etiquetteDates` (le nœud du graphe, § 5.2) : `"vivant·e"`. Elle est **mesurée**, à
     *   l'étage 1 du cache de placement partagé et sans filtre : y laisser les vraies dates
     *   encoderait leur longueur pour tous les lecteurs.
     *
     * **La même personne porte donc deux chaînes différentes dans la même réponse, et c'est
     * correct.** Un relecteur qui les unifierait casserait l'un des deux invariants sans
     * qu'aucun test d'affichage ne s'en plaigne — d'où ce cas, qui le dit par écrit.
     */
    public function testLigneDatesEstLaFormeCompacteEtNeCourtCircuitePasSurVivant(): void
    {
        $this->poserPersonne(self::LECTEUR, 'Natron Romarin', Branch::Tr, 0, Sex::Masculin, vivant: true, naissance: '26 SEP 2012');

        $utilisateur = $this->creerUtilisateur('lecteur@example.test');
        $this->creerMembre($utilisateur, self::LECTEUR, 'Natron Romarin');

        $this->connexion('lecteur@example.test');
        $this->requete('GET', '/api/chronologie');

        $ligne = $this->barresParPersonne()[self::LECTEUR]['ligneDates'];

        self::assertSame('n. 26.9.2012', $ligne, 'Forme compacte du § 14.4, préfixe `n. ` pour une naissance seule.');
        self::assertNotSame('vivant·e', $ligne, '`ligneDates` ne court-circuite jamais : c\'est `etiquetteDates` qui le fait.');
        self::assertStringNotContainsString('septembre', $ligne, 'La prose (`26 septembre 2012`) appartient à la clé `texte` du bloc date.');
    }

    /**
     * Le repli de l'assemblage est `—`, pas la chaîne vide.
     *
     * C'est ce qu'écrit `ligne_dates()` de `graphe.py`, repris au § 14.4 : une barre sans
     * aucune date reste une barre à étiqueter. Le cas ne peut se produire que sur une
     * personne dont **une seule** des deux dates est connue côté bornes mais dont aucune
     * étiquette compacte n'est composable — situation de donnée dégradée, et l'assemblage
     * doit rendre quelque chose de lisible plutôt qu'un libellé vide.
     */
    public function testLAssemblageReplieSurUnCadratinEtNonSurUneChaineVide(): void
    {
        ArbreDeChronologieEnMemoire::ajouterPersonne(
            self::SUREAU,
            'Sans Étiquette',
            Branch::Pm,
            -3,
            Sex::Feminin,
            vivant: false,
            // Des bornes analysées mais aucune chaîne GEDCOM verbatim : la barre est
            // plaçable, l'étiquette compacte ne l'est pas.
            naissance: new UncertainDate(
                null,
                DateQualifier::Annee,
                new \DateTimeImmutable('1900-01-01'),
                new \DateTimeImmutable('1900-12-31'),
            ),
        );
        ArbreEnMemoire::ajouterPersonne(self::SUREAU, 'Sans', 'Étiquette', vivante: false);

        $utilisateur = $this->creerUtilisateur('lecteur@example.test');
        $this->creerMembre($utilisateur, self::SUREAU, 'Sans Étiquette');

        $this->connexion('lecteur@example.test');
        $this->requete('GET', '/api/chronologie');

        $barre = $this->barresParPersonne()[self::SUREAU];

        self::assertSame(['min' => 1900, 'max' => 1900, 'certain' => false], $barre['debut'], 'La barre reste plaçable.');
        self::assertSame('—', $barre['ligneDates']);
    }

    /**
     * Une personne vivante n'expose **jamais** de fin, même si la donnée en portait une.
     *
     * « L'API n'invente pas une date de fin » (§ 9.1) ; écrit comme il l'est, le
     * normaliseur rend ce cas **impossible**, et non seulement improbable. Un import
     * approximatif qui poserait une date de décès sur une personne calculée vivante ne peut
     * pas la faire sortir par cette route.
     */
    public function testUnePersonneVivanteNExposeAucuneFinMemeSiLaDonneeEnPorte(): void
    {
        ArbreDeChronologieEnMemoire::ajouterPersonne(
            self::LECTEUR,
            'Natron Romarin',
            Branch::Tr,
            0,
            Sex::Masculin,
            vivant: true,
            naissance: ArbreDeChronologieEnMemoire::date('26 SEP 2012'),
            // Incohérent à dessein : vivant ET porteur d'une date de décès.
            deces: ArbreDeChronologieEnMemoire::date('2031'),
        );
        ArbreEnMemoire::ajouterPersonne(self::LECTEUR, 'Natron', 'Romarin', vivante: true);

        $utilisateur = $this->creerUtilisateur('lecteur@example.test');
        $this->creerMembre($utilisateur, self::LECTEUR, 'Natron Romarin');

        $this->connexion('lecteur@example.test');
        $this->requete('GET', '/api/chronologie');

        $barre = $this->barresParPersonne()[self::LECTEUR];

        self::assertNull($barre['fin'], 'Une personne vivante a une barre ouverte, sans fin (§ 9.1).');
        self::assertTrue($barre['ouverte']);
        self::assertStringNotContainsString('2031', $barre['ligneDates'], 'La date de décès ne doit pas non plus ressortir par `ligneDates`.');
    }
}
