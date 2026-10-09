<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Service\ComparaisonDIdentites;
use App\Module\Core\ValueObject\IdentiteComparee;
use App\Module\Core\ValueObject\Ressemblance;
use App\Module\Core\ValueObject\UncertainDate;
use PHPUnit\Framework\TestCase;

/**
 * La règle de ressemblance, **sans base ni conteneur** : ce qui écarte une fiche, ce qui la
 * rapproche, et ce qui ne doit rien dire.
 *
 * La fiche de référence est celle du socle de `Tree` : Pyroxène Tilleul, né vers 1934 à
 * Sorbière — le vivant « improbable mais non exclu » sur lequel le contrat compare ses
 * deux sérialisations.
 */
final class ComparaisonDIdentitesTest extends TestCase
{
    private ComparaisonDIdentites $comparaison;

    protected function setUp(): void
    {
        $this->comparaison = new ComparaisonDIdentites();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Le nom et les prénoms
    // ─────────────────────────────────────────────────────────────────────────────

    public function testUneFauteDeFrappeDansLeNomEstRetrouveeEtDite(): void
    {
        $ressemblance = $this->comparer($this->saisie(nom: 'Tilleule'));

        self::assertNotNull($ressemblance);
        self::assertSame(['nom', 'prenom'], $ressemblance->criteres);
        self::assertSame(['nom proche : Tilleule / Tilleul', 'prénom Pyroxène en commun'], $ressemblance->raisons);
        self::assertFalse($ressemblance->nomExact);
        self::assertSame(2, $ressemblance->score);
    }

    public function testUnNomIdentiqueAuxAccentsPresDitMemeNom(): void
    {
        $ressemblance = $this->comparer($this->saisie(nom: 'TILLEUL', prenoms: 'pyroxene'));

        self::assertNotNull($ressemblance);
        self::assertSame(['même nom', 'prénom Pyroxène en commun'], $ressemblance->raisons);
        self::assertTrue($ressemblance->nomExact);
    }

    public function testUnPrenomAUneFauteDeFrappePresSuffitEtSeDit(): void
    {
        $ressemblance = $this->comparer($this->saisie(prenoms: 'Pyroxenne'));

        self::assertNotNull($ressemblance);
        self::assertSame('prénom proche : Pyroxenne / Pyroxène', $ressemblance->raisons[1]);
    }

    /** Une femme mariée est souvent saisie sous le nom que sa famille emploie. */
    public function testLeNomDUsageCompteDansLesDeuxSens(): void
    {
        $fiche = new IdentiteComparee('Marne Dolomite', 'Sureau', 'Sarriette', null, Sex::Feminin);

        $parUsage = $this->comparaison->comparer(new IdentiteComparee('Marne', 'Sarriette'), $fiche);
        self::assertNotNull($parUsage);
        self::assertSame('même nom, porté comme nom d\'usage dans la fiche', $parUsage->raisons[0]);
        self::assertTrue($parUsage->nomExact);

        $parNaissance = $this->comparaison->comparer(new IdentiteComparee('Marne', 'Inconnu', 'Sureau'), $fiche);
        self::assertNotNull($parNaissance);
        self::assertSame('même nom, nom de naissance dans la fiche', $parNaissance->raisons[0]);

        $deuxUsages = $this->comparaison->comparer(new IdentiteComparee('Marne', '', 'Sariette'), $fiche);
        self::assertNotNull($deuxUsages);
        self::assertSame('nom d\'usage proche : Sariette / Sarriette', $deuxUsages->raisons[0]);
    }

    /** « Cobalt Sureau » s'affiche par son surnom : le saisir doit la retrouver. */
    public function testLeSurnomSeCompareAvecLesPrenoms(): void
    {
        $fiche = new IdentiteComparee('Opale Marne', 'Sureau', null, 'Cobalt', Sex::Feminin);

        $ressemblance = $this->comparaison->comparer(new IdentiteComparee('Cobalt', 'Sureau'), $fiche);

        self::assertNotNull($ressemblance);
        self::assertSame('surnom Cobalt en commun', $ressemblance->raisons[1]);
    }

    public function testUnNomTropEloigneEcarteLaFiche(): void
    {
        self::assertNull($this->comparer($this->saisie(nom: 'Tiloule')));
        self::assertNull($this->comparer($this->saisie(nom: 'Sureau')));
    }

    public function testSansPrenomEnCommunLaFicheEstEcartee(): void
    {
        self::assertNull($this->comparer($this->saisie(prenoms: 'Natron Cobalt')));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Le sexe
    // ─────────────────────────────────────────────────────────────────────────────

    public function testUnSexeOpposeEcarteLaFiche(): void
    {
        self::assertNull($this->comparer($this->saisie(sexe: Sex::Feminin)));
    }

    public function testUnMemeSexeAjouteUnCritere(): void
    {
        $ressemblance = $this->comparer($this->saisie(sexe: Sex::Masculin));

        self::assertNotNull($ressemblance);
        self::assertSame(['nom', 'prenom', 'sexe'], $ressemblance->criteres);
        self::assertContains('même sexe', $ressemblance->raisons);
    }

    public function testUnSexeInconnuNEcarteRienEtNeRapporteRien(): void
    {
        $inconnuDeLaSaisie = $this->comparer($this->saisie(sexe: Sex::Inconnu));
        $inconnuDeLaFiche = $this->comparaison->comparer(
            $this->saisie(sexe: Sex::Feminin),
            new IdentiteComparee('Pyroxène', 'Tilleul'),
        );

        self::assertNotNull($inconnuDeLaSaisie);
        self::assertNotNull($inconnuDeLaFiche);
        self::assertNotContains('sexe', $inconnuDeLaSaisie->criteres);
        self::assertNotContains('sexe', $inconnuDeLaFiche->criteres);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Les dates
    // ─────────────────────────────────────────────────────────────────────────────

    public function testUneNaissanceIncompatibleEcarteLaFiche(): void
    {
        self::assertNull($this->comparer($this->saisie(naissance: self::annee(1990))));
    }

    public function testUneNaissanceCompatibleAjouteUnCritereEtSeDitEnProse(): void
    {
        $ressemblance = $this->comparer($this->saisie(naissance: self::vers(1934)));

        self::assertNotNull($ressemblance);
        self::assertContains('naissance', $ressemblance->criteres);
        self::assertContains('né vers 1934, comme dans la fiche', $ressemblance->raisons);
    }

    public function testUneNaissanceCompatibleMaisAutrementEcriteCiteLaFiche(): void
    {
        $ressemblance = $this->comparer($this->saisie(naissance: self::annee(1935)));

        self::assertNotNull($ressemblance);
        self::assertContains('naissance compatible : vers 1934 dans la fiche', $ressemblance->raisons);
    }

    /**
     * **Le cœur de la confidentialité de la route.** Une naissance que le lecteur ne voit
     * pas arrive ici à `null` — ou en date inconnue qui ne garde que son lieu : elle
     * n'écarte rien et ne rapporte rien, quelle que soit la date saisie. Sinon, saisir
     * 1934 puis 1990 dirait laquelle est la bonne.
     */
    public function testUneNaissanceNonComparableNEcarteNiNeRapporte(): void
    {
        foreach ([null, UncertainDate::inconnue(), new UncertainDate('ABT', DateQualifier::Environ)] as $nonComparable) {
            $fiche = new IdentiteComparee('Pyroxène', 'Tilleul', null, null, Sex::Masculin, $nonComparable);

            $avec1934 = $this->comparaison->comparer($this->saisie(naissance: self::vers(1934)), $fiche);
            $avec1990 = $this->comparaison->comparer($this->saisie(naissance: self::annee(1990)), $fiche);
            $sansDate = $this->comparaison->comparer($this->saisie(), $fiche);

            self::assertNotNull($avec1934);
            self::assertEquals($sansDate, $avec1934, 'Une naissance non comparable ne rapporte rien.');
            self::assertEquals($sansDate, $avec1990, 'Une naissance non comparable n’écarte rien.');
            self::assertNotContains('naissance', $avec1934->criteres);
        }
    }

    /** « Vers 1934 » couvre 1932 à 1936, pas au-delà — dans un sens comme dans l'autre. */
    public function testVersAccepteDeuxAnsDEcartEtPasTrois(): void
    {
        foreach ([1932, 1933, 1935, 1936] as $annee) {
            self::assertNotNull($this->comparer($this->saisie(naissance: self::annee($annee))), (string) $annee);
        }

        foreach ([1931, 1937] as $annee) {
            self::assertNull($this->comparer($this->saisie(naissance: self::annee($annee))), (string) $annee);
        }

        // Le « vers » de la saisie s'élargit aussi : vers 1936 contre une fiche née en 1934.
        $fiche = new IdentiteComparee('Pyroxène', 'Tilleul', null, null, Sex::Masculin, self::annee(1934));
        self::assertNotNull($this->comparaison->comparer($this->saisie(naissance: self::vers(1936)), $fiche));
        self::assertNull($this->comparaison->comparer($this->saisie(naissance: self::vers(1937)), $fiche));
    }

    public function testUnDecesIncompatibleEcarteEtUnDecesCompatibleSeDit(): void
    {
        $fiche = new IdentiteComparee('Marne Dolomite', 'Sureau', null, null, Sex::Feminin, null, self::annee(1987));

        self::assertNull($this->comparaison->comparer(new IdentiteComparee('Marne', 'Sureau', deces: self::annee(1990)), $fiche));

        $ressemblance = $this->comparaison->comparer(new IdentiteComparee('Marne', 'Sureau', deces: self::annee(1987)), $fiche);
        self::assertNotNull($ressemblance);
        self::assertSame(['nom', 'prenom', 'deces'], $ressemblance->criteres);
        self::assertContains('décédée en 1987, comme dans la fiche', $ressemblance->raisons);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Le lieu et le score
    // ─────────────────────────────────────────────────────────────────────────────

    public function testLeLieuEstUnBonusJamaisUnFiltre(): void
    {
        $memeLieu = $this->comparer($this->saisie(naissance: UncertainDate::inconnue('sorbiere')));
        $autreLieu = $this->comparer($this->saisie(naissance: UncertainDate::inconnue('Charmille, Ombrage')));

        self::assertNotNull($memeLieu);
        self::assertSame(['nom', 'prenom', 'lieu'], $memeLieu->criteres);
        // Le premier segment **de la fiche**, tel qu'elle l'écrit.
        self::assertContains('même lieu de naissance : Sorbière', $memeLieu->raisons);

        self::assertNotNull($autreLieu, 'Un lieu différent ne prouve rien : la fiche reste.');
        self::assertSame(['nom', 'prenom'], $autreLieu->criteres);
    }

    public function testLeScoreEstLeNombreDeCriteresConcordants(): void
    {
        $complete = $this->comparer($this->saisie(
            sexe: Sex::Masculin,
            naissance: new UncertainDate('1934', DateQualifier::Annee, new \DateTimeImmutable('1934-01-01'), new \DateTimeImmutable('1934-12-31'), 'Sorbière'),
        ));

        self::assertNotNull($complete);
        self::assertSame(['nom', 'prenom', 'sexe', 'naissance', 'lieu'], $complete->criteres);
        self::assertSame(5, $complete->score);

        $minimale = $this->comparer($this->saisie());
        self::assertNotNull($minimale);
        self::assertSame(2, $minimale->score, 'Le nom et un prénom sont exigés : deux critères au moins.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  La forme discrète
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * La proposition d'un vivant faite par un autre membre : nom, prénoms et sexe, et des
     * raisons qui ne recopient **aucune** valeur de la fiche — ni son nom, ni son prénom,
     * ni sa date, ni son lieu.
     */
    public function testLaFormeDiscreteNeRecopieAucuneValeurDeLaFiche(): void
    {
        $ressemblance = $this->comparaison->comparer(
            $this->saisie(nom: 'Tilleul', sexe: Sex::Masculin, naissance: new UncertainDate('ABT 1934', DateQualifier::Environ, new \DateTimeImmutable('1934-01-01'), new \DateTimeImmutable('1934-12-31'), 'Sorbière')),
            self::ficheDeReference(),
            true,
        );

        self::assertNotNull($ressemblance);
        self::assertSame(['nom', 'prenom', 'sexe'], $ressemblance->criteres);
        self::assertSame(['nom semblable', 'un prénom en commun', 'même sexe'], $ressemblance->raisons);
        self::assertFalse($ressemblance->nomExact, 'Même l’ordre des réponses ne doit pas dire que le nom est exact.');

        $texte = implode(' ', $ressemblance->raisons);
        foreach (['Tilleul', 'Pyroxène', 'Pyroxene', '1934', 'Sorbière', 'vers'] as $valeur) {
            self::assertStringNotContainsStringIgnoringCase($valeur, $texte);
        }
    }

    /** En forme discrète, la date saisie ne change **rien** — ni l'écart, ni le score. */
    public function testLaFormeDiscreteNeDependPasDesDates(): void
    {
        $fiche = self::ficheDeReference();

        $sansDate = $this->comparaison->comparer($this->saisie(), $fiche, true);
        $avec1934 = $this->comparaison->comparer($this->saisie(naissance: self::vers(1934)), $fiche, true);
        $avec1990 = $this->comparaison->comparer($this->saisie(naissance: self::annee(1990), deces: self::annee(1991)), $fiche, true);

        self::assertNotNull($sansDate);
        self::assertEquals($sansDate, $avec1934);
        self::assertEquals($sansDate, $avec1990, 'Une date incompatible n’écarte pas une proposition discrète.');
    }

    // ─────────────────────────────────────────────────────────────────────────────

    private function comparer(IdentiteComparee $saisie): ?Ressemblance
    {
        return $this->comparaison->comparer($saisie, self::ficheDeReference());
    }

    private function saisie(
        string $prenoms = 'Pyroxène',
        string $nom = 'Tilleul',
        Sex $sexe = Sex::Inconnu,
        ?UncertainDate $naissance = null,
        ?UncertainDate $deces = null,
    ): IdentiteComparee {
        return new IdentiteComparee($prenoms, $nom, null, null, $sexe, $naissance, $deces);
    }

    private static function ficheDeReference(): IdentiteComparee
    {
        return new IdentiteComparee(
            'Pyroxène',
            'Tilleul',
            null,
            null,
            Sex::Masculin,
            new UncertainDate('ABT 1934', DateQualifier::Environ, new \DateTimeImmutable('1934-01-01'), new \DateTimeImmutable('1934-12-31'), 'Sorbière, Fougerie, Florie'),
        );
    }

    private static function annee(int $annee): UncertainDate
    {
        return new UncertainDate((string) $annee, DateQualifier::Annee, new \DateTimeImmutable($annee.'-01-01'), new \DateTimeImmutable($annee.'-12-31'));
    }

    private static function vers(int $annee): UncertainDate
    {
        return new UncertainDate('ABT '.$annee, DateQualifier::Environ, new \DateTimeImmutable($annee.'-01-01'), new \DateTimeImmutable($annee.'-12-31'));
    }
}
