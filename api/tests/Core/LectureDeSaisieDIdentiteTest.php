<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Service\LectureDeSaisieDIdentite;
use PHPUnit\Framework\TestCase;

/**
 * Le corps commun des deux routes de ressemblance : les deux le lisent ici, pour refuser
 * les mêmes saisies avec les mêmes mots — le formulaire les appelle en parallèle.
 */
final class LectureDeSaisieDIdentiteTest extends TestCase
{
    private LectureDeSaisieDIdentite $lecture;

    protected function setUp(): void
    {
        $this->lecture = new LectureDeSaisieDIdentite();
    }

    public function testUneFicheCompleteDevientUneIdentiteAComparer(): void
    {
        $identite = $this->lecture->identite(['fiche' => [
            'givenNames' => 'Pyroxène',
            'surname' => 'Tilleule',
            'marriedName' => null,
            'nickname' => 'Pyro',
            'sex' => 'masculin',
            'deceased' => false,
            'birth' => ['raw' => 'ABT 1934', 'qualifier' => 'environ', 'min' => '1934-01-01', 'max' => '1934-12-31', 'place' => 'Sorbière'],
            'death' => null,
        ]]);

        self::assertSame('Pyroxène', $identite->prenoms);
        self::assertSame('Tilleule', $identite->nom);
        self::assertNull($identite->nomDUsage);
        self::assertSame('Pyro', $identite->surnom);
        self::assertSame(Sex::Masculin, $identite->sexe);
        self::assertNotNull($identite->naissance);
        self::assertSame(DateQualifier::Environ, $identite->naissance->getQualifier());
        self::assertSame('Sorbière', $identite->naissance->getPlace());
        self::assertNull($identite->deces);
    }

    public function testLesClesAbsentesValentRien(): void
    {
        $identite = $this->lecture->identite(['fiche' => ['givenNames' => 'Marne', 'marriedName' => 'Sarriette']]);

        self::assertSame('', $identite->nom);
        self::assertSame('Sarriette', $identite->nomDUsage);
        self::assertSame(Sex::Inconnu, $identite->sexe);
        self::assertNull($identite->naissance);
    }

    public function testSansFicheLeCorpsEstRefuse(): void
    {
        self::assertSame(['fiche'], array_keys($this->erreurs([])));
        self::assertSame(['fiche'], array_keys($this->erreurs(['fiche' => 'Tilleul'])));
    }

    public function testSansPrenomNiSurnomLaSaisieEstRefusee(): void
    {
        self::assertSame(
            ['fiche.givenNames'],
            array_keys($this->erreurs(['fiche' => ['surname' => 'Tilleul', 'givenNames' => ' - ']])),
        );
    }

    public function testUnNomDUneSeuleLettreEstRefuse(): void
    {
        self::assertSame(
            ['fiche.surname'],
            array_keys($this->erreurs(['fiche' => ['surname' => 'T.', 'marriedName' => '', 'givenNames' => 'Pyroxène']])),
        );

        // Le nom d'usage suffit à lui seul.
        $identite = $this->lecture->identite(['fiche' => ['surname' => '', 'marriedName' => 'Sarriette', 'givenNames' => 'Marne']]);
        self::assertSame('Sarriette', $identite->nomDUsage);
    }

    /** Un formulaire se corrige en une fois : toutes les erreurs partent ensemble. */
    public function testToutesLesErreursPartentDansLeMemeRefus(): void
    {
        $erreurs = $this->erreurs(['fiche' => [
            'givenNames' => 12,
            'surname' => 'Tilleul',
            'sex' => 'f',
            'deceased' => 'non',
            'birth' => ['qualifier' => 'entre', 'min' => '1934-01-01', 'max' => '1934-01-01'],
            'birht' => null,
        ]]);

        self::assertEqualsCanonicalizing(
            ['fiche', 'fiche.givenNames', 'fiche.sex', 'fiche.deceased', 'fiche.birth'],
            array_keys($erreurs),
        );
        self::assertStringContainsString('birht', $erreurs['fiche']);
    }

    /** Une clé mal orthographiée serait sinon une naissance silencieusement non comparée. */
    public function testUneCleInconnueDeLaFicheEstRefusee(): void
    {
        $erreurs = $this->erreurs(['fiche' => ['surname' => 'Tilleul', 'givenNames' => 'Pyroxène', 'note' => 'Livret de famille']]);

        self::assertSame(['fiche'], array_keys($erreurs));
    }

    public function testLaLimiteRaboteSansRefuser(): void
    {
        self::assertSame(5, $this->lecture->limite(null));
        self::assertSame(5, $this->lecture->limite('beaucoup'));
        self::assertSame(5, $this->lecture->limite(0));
        self::assertSame(5, $this->lecture->limite(true));
        self::assertSame(3, $this->lecture->limite(3));
        self::assertSame(10, $this->lecture->limite(200));
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return array<string, string>
     */
    private function erreurs(array $corps): array
    {
        try {
            $this->lecture->identite($corps);
        } catch (ValidationException $erreur) {
            self::assertSame(422, $erreur->statutHttp());

            /** @var array<string, string> $champs */
            $champs = (array) $erreur->champs();

            return $champs;
        }

        self::fail('Cette saisie aurait dû être refusée.');
    }
}
