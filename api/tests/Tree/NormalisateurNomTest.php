<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Tree\Service\NormalisateurNom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les deux colonnes dénormalisées de `tree_person`, côté `Tree`.
 *
 * Le repliement des accents n'est **pas** vérifié ici : il appartient à
 * {@see CleDeRecherche} et c'est `tests/Core/CleDeRechercheTest.php` qui l'éprouve, y
 * compris sur l'aller-retour écriture/recherche qui est la seule garantie qui compte.
 * Ce test-ci ne porte que sur ce que `Tree` ajoute — l'ordre des morceaux et la
 * composition du nom d'affichage.
 */
final class NormalisateurNomTest extends TestCase
{
    /**
     * `display_name` : `nickname ?? given_names`, puis `surname`, puis `?` si les deux
     * sont vides (§ 3.1). Le `?` n'est pas un échec de lecture — c'est ce que `NOT NULL`
     * impose pour un ancêtre réellement inconnu.
     */
    public function testLeNomDAffichagePrefereLeSurnom(): void
    {
        $noms = $this->normalisateur();

        self::assertSame('Granite Chardon', $noms->composerAffichage('Jade Pyrite Granite Mica', 'Chardon', 'Granite'));
        self::assertSame('Jade Pyrite Chardon', $noms->composerAffichage('Jade Pyrite', 'Chardon', null));
        self::assertSame('Chardon', $noms->composerAffichage('', 'Chardon', null));
        self::assertSame('?', $noms->composerAffichage('', '', null));
        self::assertSame('?', $noms->composerAffichage('   ', '  ', '  '));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function clesDeRecherche(): iterable
    {
        yield 'nom d’abord' => ['Jade Pyrite', 'Romarin', 'ROMARIN JADE PYRITE'];
        yield 'accents repliés' => ['Hélène', 'Bréval', 'BREVAL HELENE'];
        yield 'sans prénom' => ['', 'Romarin', 'ROMARIN'];
        yield 'sans rien' => ['', '', ''];
    }

    /**
     * Le nom passe **avant** les prénoms, et ce n'est pas esthétique : `idx_tree_person_search`
     * est un index de **préfixe**, et c'est le patronyme qui doit l'attaquer. C'est aussi
     * ce qui fixe la place du surnom — en fin de clé, jamais en tête.
     *
     * (Ce commentaire parlait de « la recherche de rattachement ». Il avait tort : celle-ci
     * ne lit jamais `search_key`. La lectrice de la colonne est `GET /api/arbre/recherche`.)
     */
    #[DataProvider('clesDeRecherche')]
    public function testLaCleDeRechercheMetLeNomEnTete(string $prenoms, string $nom, string $attendu): void
    {
        self::assertSame($attendu, $this->normalisateur()->cleDeRecherche($prenoms, $nom));
    }

    /**
     * La délégation est le fond du sujet : `Tree` n'a plus de table à lui, donc plus rien
     * à faire diverger de ce que la recherche emploie.
     */
    public function testLaCleEstExactementCelleDeCore(): void
    {
        $cles = new CleDeRecherche();

        self::assertSame(
            $cles->cleDeNom('Françoise', 'Noël'),
            (new NormalisateurNom($cles))->cleDeRecherche('Françoise', 'Noël'),
        );
    }

    /** Les colonnes sont bornées : 255 pour `display_name`, 200 pour `search_key`. */
    public function testLesLongueursSontBornees(): void
    {
        $noms = $this->normalisateur();
        $long = str_repeat('Bartholomé ', 40);

        self::assertLessThanOrEqual(255, mb_strlen($noms->composerAffichage($long, $long, null)));
        self::assertLessThanOrEqual(CleDeRecherche::LONGUEUR_MAXIMALE, mb_strlen($noms->cleDeRecherche($long, $long)));
    }

    private function normalisateur(): NormalisateurNom
    {
        return new NormalisateurNom(new CleDeRecherche());
    }
}
