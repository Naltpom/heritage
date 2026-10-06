<?php

declare(strict_types=1);

namespace App\Tests\Almanach;

use App\Module\Almanach\Contract\TypeDEvenement;
use App\Module\Core\Enum\Sex;
use App\Tests\Core\Double\ArbreDuLecteurEnMemoire;

/**
 * Décision du 05/10/2026 : la carte et le calendrier ne montrent que **l'arbre du
 * lecteur**. Sarriette, défunte et visible de tous, est ici hors de cet arbre (une fiche
 * sans lien connu) : elle ne sort que pour l'administration, sous `?portee=tout`.
 */
final class ArbreDuLecteurTest extends CasDeTestAlmanach
{
    protected function setUp(): void
    {
        parent::setUp();
        ArbreDuLecteurEnMemoire::reinitialiser();

        $this->poserPersonne(self::LECTEUR, 'Natron Romarin', vivant: true, sexe: Sex::Masculin);
        $this->poserPersonne(self::SUREAU, 'Marne Dolomite Sureau', vivant: false);
        $this->poserPersonne(self::SARRIETTE, 'Émeri Sarriette', vivant: false, sexe: Sex::Masculin);
        $this->poserLieu(1, 'Saule, Ombrage, Sylvanie', 46.12, 2.56);

        $this->poserEvenement(self::SUREAU, TypeDEvenement::Naissance, '11 MAR 1887', 'Saule, Ombrage, Sylvanie');
        $this->poserEvenement(self::SARRIETTE, TypeDEvenement::Naissance, '12 MAR 1899', 'Saule, Ombrage, Sylvanie');

        ArbreDuLecteurEnMemoire::restreindreA([self::LECTEUR, self::SUREAU]);
    }

    protected function tearDown(): void
    {
        ArbreDuLecteurEnMemoire::reinitialiser();
        parent::tearDown();
    }

    public function testUnMembreNeVoitQueSonArbreMemeEnDemandantTout(): void
    {
        $this->connecterUnMembre();

        foreach (['/api/carte', '/api/carte?portee=tout'] as $chemin) {
            $this->requete('GET', $chemin);
            $evenements = $this->evenementsDeLaCarte();

            self::assertArrayHasKey('naissance:'.self::SUREAU, $evenements, $chemin);
            self::assertArrayNotHasKey('naissance:'.self::SARRIETTE, $evenements, $chemin.' : hors de son arbre.');
        }

        $this->requete('GET', '/api/anniversaires?portee=tout');
        self::assertStringNotContainsString('Sarriette', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->resume()['parGenre']['naissance']);
    }

    public function testLAdministrateurBasculeSurToutLaBase(): void
    {
        $this->creerUtilisateur('admin@example.test', ['ROLE_ADMIN']);
        $this->requete('POST', '/api/connexion', ['email' => 'admin@example.test', 'password' => 'motdepasse-de-test']);

        $this->requete('GET', '/api/carte');
        self::assertArrayNotHasKey('naissance:'.self::SARRIETTE, $this->evenementsDeLaCarte(), 'Par défaut : son arbre.');

        $this->requete('GET', '/api/carte?portee=tout');
        self::assertArrayHasKey('naissance:'.self::SARRIETTE, $this->evenementsDeLaCarte(), 'Toute la base.');

        $this->requete('GET', '/api/anniversaires?portee=tout');
        self::assertSame(2, $this->resume()['parGenre']['naissance']);
    }
}
