<?php

declare(strict_types=1);

namespace App\Tests\Gedcom;

use App\Module\Gedcom\Dto\ConstatDeGarde;
use App\Module\Gedcom\Service\GardeDesComptes;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Le garde-fou qui refuse de repeupler l'arbre sous des comptes vivants.
 *
 * **Une garde qu'on n'a jamais vue refuser est une garde dont on ne sait pas si elle
 * regarde.** Ces tests la font refuser, la font laisser passer, et — le cas qui compte le
 * plus — vérifient qu'elle **dit qu'elle n'a pas pu regarder** plutôt que de compter zéro
 * sur des tables absentes.
 */
final class GardeDesComptesTest extends TestCase
{
    /**
     * Le cas nominal d'un ré-import : l'arbre est peuplé, rien ne s'y oppose.
     */
    public function testUnArbrePeupleNeDeclencheRien(): void
    {
        self::assertNull($this->refusPour(comptes: 14, rattachements: 3, personnes: 274));
    }

    /** Une base entièrement vierge : ni comptes, ni arbre. Le premier import passe. */
    public function testUneBaseViergeNeDeclencheRien(): void
    {
        self::assertNull($this->refusPour(comptes: 0, rattachements: 0, personnes: 0));
    }

    /**
     * **Le cas qu'on protège** : l'arbre est vide alors que des comptes existent.
     *
     * C'est ce qu'on obtient après un `DROP SCHEMA` suivi d'un `migrations:migrate` —
     * et c'est exactement ce qui est arrivé ce soir, par ma propre procédure de recette.
     */
    public function testUnArbreVideSousDesComptesEstRefuse(): void
    {
        $refus = $this->refusPour(comptes: 14, rattachements: 3, personnes: 0);

        self::assertNotNull($refus);
        // Le message **chiffre** ce qu'il refuse de laisser faire : un refus qui
        // n'explique pas se contourne par un drapeau sans réfléchir.
        self::assertStringContainsString('14 compte(s)', $refus);
        self::assertStringContainsString('3 rattachement(s)', $refus);
        self::assertStringContainsString('--premier-import', $refus);
    }

    /**
     * Des comptes sans rattachement : c'est l'amorçage nominal — `creer-administrateur`
     * puis l'import. On refuse quand même, mais **sans** l'avertissement sur les
     * identifiants, qui ne s'applique pas.
     */
    public function testSansRattachementLAvertissementSurLesIdentifiantsNEstPasDonne(): void
    {
        $refus = $this->refusPour(comptes: 1, rattachements: 0, personnes: 0);

        self::assertNotNull($refus);
        self::assertStringContainsString('1 compte(s)', $refus);
        self::assertStringNotContainsString('désignera quelqu’un d’autre', $refus);
    }

    /**
     * Des rattachements présents : l'avertissement est donné, et il nomme le mécanisme —
     * les identifiants sont réattribués dans l'ordre du fichier.
     */
    public function testAvecDesRattachementsLeMecanismeEstNomme(): void
    {
        $refus = $this->refusPour(comptes: 2, rattachements: 5, personnes: 0);

        self::assertNotNull($refus);
        self::assertStringContainsString('désignera quelqu’un d’autre', $refus);
    }

    /**
     * **Le cas le plus important de ce fichier.** Si les tables ne sont pas là, la garde
     * ne doit pas compter 0 et laisser passer : elle doit dire qu'elle n'a pas su
     * regarder. C'est la forme de panne que ce projet a rencontrée neuf fois — un
     * dispositif de contrôle qui ne voit plus rien et reste vert.
     */
    public function testUneGardeQuiNePeutPasRegarderLeDitAuLieuDeLaisserPasser(): void
    {
        $constat = new ConstatDeGarde(0, 0, 0, aPuRegarder: false);

        self::assertFalse($constat->arbreVideSousDesComptes());

        $refus = (new GardeDesComptes($this->connexionAveugle()))->refus($constat);

        self::assertNotNull($refus, 'Ne pas savoir regarder n’est pas un feu vert.');
        self::assertStringContainsString('n’a pas pu regarder', $refus);
    }

    /** Le constat sur une base dépourvue des tables attendues rend bien « je n'ai pas regardé ». */
    public function testLeConstatSurUneBaseSansTablesNAffirmeRien(): void
    {
        $constat = (new GardeDesComptes($this->connexionAveugle()))->constater();

        self::assertFalse($constat->aPuRegarder);
    }

    private function refusPour(int $comptes, int $rattachements, int $personnes): ?string
    {
        $constat = new ConstatDeGarde($comptes, $rattachements, $personnes, aPuRegarder: true);

        return (new GardeDesComptes($this->connexionVoyante()))->refus($constat);
    }

    /**
     * Une vraie connexion SQLite en mémoire, **sans aucune table** — et non un mock.
     *
     * Deux raisons. `refus()` n'interroge pas la connexion : il n'y a rien à simuler, il
     * faut seulement de quoi construire le service. Et pour `constater()`, une base
     * réellement vide éprouve le vrai `tablesExist()` de Doctrine plutôt qu'une réponse
     * qu'on lui aurait dictée — ce qui est exactement ce qu'on veut d'une garde dont tout
     * l'intérêt est de savoir si elle voit.
     */
    private function connexionVoyante(): Connection
    {
        return $this->connexionAveugle();
    }

    private function connexionAveugle(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
