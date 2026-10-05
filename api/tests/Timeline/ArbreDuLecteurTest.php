<?php

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Tests\Core\Double\ArbreDuLecteurEnMemoire;

/**
 * Décision du 05/10/2026 : la chronologie ne montre que **l'arbre du lecteur**. Sarriette,
 * défunte, est ici une fiche sans lien connu : elle ne sort que pour l'administration,
 * sous `?portee=tout`.
 */
final class ArbreDuLecteurTest extends CasDeTestTimeline
{
    private const int LECTEUR = 1;
    private const int SUREAU = 17;
    private const int SARRIETTE = 18;

    protected function setUp(): void
    {
        parent::setUp();
        ArbreDuLecteurEnMemoire::reinitialiser();

        $this->poserPersonne(self::LECTEUR, 'Natron Romarin', Branch::Tr, 0, Sex::Masculin, vivant: true, naissance: '1990');
        $this->poserPersonne(self::SUREAU, 'Marne Dolomite Sureau', Branch::Pm, -3, Sex::Feminin, vivant: false, naissance: '1901', deces: '1987');
        $this->poserPersonne(self::SARRIETTE, 'Émeri Sarriette', Branch::Pp, -3, Sex::Masculin, vivant: false, naissance: '1899', deces: '1960');

        ArbreDuLecteurEnMemoire::restreindreA([self::LECTEUR, self::SUREAU]);
    }

    protected function tearDown(): void
    {
        ArbreDuLecteurEnMemoire::reinitialiser();
        parent::tearDown();
    }

    public function testUnMembreNeVoitQueSonArbreMemeEnDemandantTout(): void
    {
        $utilisateur = $this->creerUtilisateur('membre@example.test');
        $this->creerMembre($utilisateur, self::LECTEUR, 'Natron Romarin');
        $this->connexion('membre@example.test');

        foreach (['/api/chronologie', '/api/chronologie?portee=tout'] as $chemin) {
            $this->requete('GET', $chemin);
            $barres = $this->barresParPersonne();

            self::assertArrayHasKey(self::SUREAU, $barres, $chemin);
            self::assertArrayNotHasKey(self::SARRIETTE, $barres, $chemin.' : hors de son arbre.');
        }
    }

    public function testLAdministrateurBasculeSurToutLaBase(): void
    {
        $this->creerUtilisateur('admin@example.test', ['ROLE_ADMIN']);
        $this->connexion('admin@example.test');

        $this->requete('GET', '/api/chronologie');
        self::assertArrayNotHasKey(self::SARRIETTE, $this->barresParPersonne(), 'Par défaut : son arbre.');

        $this->requete('GET', '/api/chronologie?portee=tout');
        self::assertArrayHasKey(self::SARRIETTE, $this->barresParPersonne(), 'Toute la base.');
    }
}
