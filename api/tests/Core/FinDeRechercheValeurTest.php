<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Enum\CoteDeRecherche;
use App\Module\Core\Enum\MotifDeFinDeRecherche;
use App\Module\Core\ValueObject\FinDeRecherche;
use PHPUnit\Framework\TestCase;

/**
 * Les types partagés de la fin de recherche (ADR 0023, § 2.8 du modèle) : le côté, le motif
 * et la valeur `{reason, beforeYear, body}`.
 *
 * Les libellés sont recopiés au caractère près dans `app/src/lib/finDeRecherche.ts` : ce
 * test les fige.
 */
final class FinDeRechercheValeurTest extends TestCase
{
    public function testLaValeurSortDansLOrdreCanonique(): void
    {
        $valeur = new FinDeRecherche(MotifDeFinDeRecherche::RegistresInexistants, 1668, 'Saint-Martin.');

        self::assertSame(['reason', 'beforeYear', 'body'], array_keys($valeur->versJson()));
        self::assertSame(
            ['reason' => 'registres_inexistants', 'beforeYear' => 1668, 'body' => 'Saint-Martin.'],
            $valeur->versJson(),
        );
        self::assertSame(
            ['reason' => 'lacune', 'beforeYear' => null, 'body' => null],
            (new FinDeRecherche(MotifDeFinDeRecherche::Lacune, null, null))->versJson(),
        );
    }

    public function testDepuisJsonReordonneEtResteTolerant(): void
    {
        $relue = FinDeRecherche::depuisJson(['body' => 'Texte', 'beforeYear' => null, 'reason' => 'autre']);

        self::assertInstanceOf(FinDeRecherche::class, $relue);
        self::assertSame(['reason' => 'autre', 'beforeYear' => null, 'body' => 'Texte'], $relue->versJson());

        // Une clé absente vaut `null` ; un motif inconnu ou absent rend `null`, jamais d'exception.
        $sansTexte = FinDeRecherche::depuisJson(['reason' => 'lacune']);
        self::assertInstanceOf(FinDeRecherche::class, $sansTexte);
        self::assertNull($sansTexte->body);

        self::assertNull(FinDeRecherche::depuisJson(['reason' => 'motif_retire', 'beforeYear' => null, 'body' => null]));
        self::assertNull(FinDeRecherche::depuisJson(['beforeYear' => 1668]));
        self::assertNull(FinDeRecherche::depuisJson(['reason' => 12]));
        self::assertNull(FinDeRecherche::depuisJson([]));
    }

    public function testEnClairAvecEtSansAnneeAvecEtSansTexte(): void
    {
        self::assertSame(
            'Registres inexistants avant 1668',
            (new FinDeRecherche(MotifDeFinDeRecherche::RegistresInexistants, 1668, null))->enClair(),
        );
        self::assertSame(
            'Registres inexistants avant 1668 — Saint-Martin commence en 1668',
            (new FinDeRecherche(MotifDeFinDeRecherche::RegistresInexistants, 1668, 'Saint-Martin commence en 1668'))->enClair(),
        );
        self::assertSame(
            'Registres inexistants',
            (new FinDeRecherche(MotifDeFinDeRecherche::RegistresInexistants, null, null))->enClair(),
            'Sans année, la phrase ne dit pas « avant ».',
        );
        self::assertSame(
            'Registres détruits',
            (new FinDeRecherche(MotifDeFinDeRecherche::RegistresDetruits, null, null))->enClair(),
        );
        self::assertSame(
            'Autre motif — Incendie de la mairie',
            (new FinDeRecherche(MotifDeFinDeRecherche::Autre, null, 'Incendie de la mairie'))->enClair(),
        );
    }

    public function testLesLibellesDesHuitMotifsAuCaracterePres(): void
    {
        $attendus = [
            'registres_inexistants' => 'Registres inexistants avant une année',
            'registres_detruits' => 'Registres détruits',
            'lacune' => 'Lacune dans les registres',
            'pere_inconnu' => 'Père inconnu',
            'enfant_trouve' => 'Enfant trouvé ou abandonné',
            'origine_inconnue' => 'Lieu d’origine inconnu',
            'homonymie' => 'Homonymie impossible à trancher',
            'autre' => 'Autre motif',
        ];

        self::assertCount(8, MotifDeFinDeRecherche::cases());
        foreach ($attendus as $valeur => $libelle) {
            $motif = MotifDeFinDeRecherche::from($valeur);
            self::assertSame($libelle, $motif->label());
        }
        self::assertStringContainsString('’', MotifDeFinDeRecherche::OrigineInconnue->label(), 'Apostrophe typographique.');
    }

    public function testLAnneeEtLeTexteObligatoiresSontLeFaitDUnSeulMotifChacun(): void
    {
        foreach (MotifDeFinDeRecherche::cases() as $motif) {
            self::assertSame(MotifDeFinDeRecherche::RegistresInexistants === $motif, $motif->avecAnnee(), $motif->value);
            self::assertSame(MotifDeFinDeRecherche::Autre === $motif, $motif->texteObligatoire(), $motif->value);
        }
    }

    public function testPereInconnuNEstAdmisQueDuCoteDuPere(): void
    {
        foreach (MotifDeFinDeRecherche::cases() as $motif) {
            self::assertTrue($motif->admisDuCote(CoteDeRecherche::Pere), $motif->value);
            self::assertSame(MotifDeFinDeRecherche::PereInconnu !== $motif, $motif->admisDuCote(CoteDeRecherche::Mere), $motif->value);
        }
    }

    public function testLeChampDUnCoteEtSonInverse(): void
    {
        self::assertSame('finDeRecherchePere', CoteDeRecherche::Pere->champ());
        self::assertSame('finDeRechercheMere', CoteDeRecherche::Mere->champ());
        self::assertSame(CoteDeRecherche::Pere, CoteDeRecherche::depuisChamp('finDeRecherchePere'));
        self::assertSame(CoteDeRecherche::Mere, CoteDeRecherche::depuisChamp('finDeRechercheMere'));
        self::assertNull(CoteDeRecherche::depuisChamp('surname'));
        self::assertSame(['pere', 'mere'], array_map(static fn (CoteDeRecherche $c): string => $c->value, CoteDeRecherche::cases()));
    }
}
