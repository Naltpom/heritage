<?php

declare(strict_types=1);

namespace App\Tests\Statistiques\Double;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Statistiques\Contract\AppuiDuReleve;
use App\Module\Statistiques\Contract\FaitDuReleve;
use App\Module\Statistiques\Contract\FamilleDuReleve;
use App\Module\Statistiques\Contract\LieuDuReleve;
use App\Module\Statistiques\Contract\NatureDuFait;
use App\Module\Statistiques\Contract\PersonneDuReleve;
use App\Module\Statistiques\Contract\PorteeDeLAppui;
use App\Module\Statistiques\Contract\PortStatistiquesArbre;
use App\Module\Statistiques\Contract\ReleveDeLArbre;

/**
 * Le relevé de l'arbre, en mémoire : chaque test le peuple en quelques lignes, sans base
 * `tree_*` — même choix que `Almanach\Double\FluxDEvenementsEnMemoire`. L'état est
 * statique (le conteneur de test recrée le service entre deux requêtes) et remis à zéro
 * par `reinitialiser()` dans `setUp()`.
 *
 * Le jeton change à chaque ajout : le cache du relevé est donc éprouvé tel qu'en
 * production — un arbre modifié n'est jamais servi depuis une entrée périmée.
 */
final class ReleveEnMemoire implements PortStatistiquesArbre
{
    /** @var array<int, PersonneDuReleve> */
    private static array $personnes = [];

    /** @var list<FamilleDuReleve> */
    private static array $familles = [];

    /** @var list<FaitDuReleve> */
    private static array $faits = [];

    /** @var list<AppuiDuReleve> */
    private static array $appuis = [];

    /** @var list<LieuDuReleve> */
    private static array $lieux = [];

    private static ?int $racine = null;

    private static int $version = 0;

    private const array MOIS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    public static function reinitialiser(): void
    {
        self::$personnes = [];
        self::$familles = [];
        self::$faits = [];
        self::$appuis = [];
        self::$lieux = [];
        self::$racine = null;
        ++self::$version;
    }

    public static function ajouterPersonne(
        int $id,
        string $prenoms,
        string $nom,
        Sex $sexe,
        string $naissance = '',
        string $deces = '',
        bool $vivant = false,
        ?Branch $branche = null,
        ?int $generation = null,
        ?int $bestQuay = null,
        int $citations = 0,
        ?string $lieuNaissance = null,
        ?string $lieuDeces = null,
        string $creeLe = '2026-09-15',
    ): void {
        self::$personnes[$id] = new PersonneDuReleve(
            id: $id,
            displayName: trim($prenoms.' '.$nom),
            prenoms: $prenoms,
            nom: $nom,
            sex: $sexe,
            naissance: self::date($naissance, $lieuNaissance),
            deces: self::date($deces, $lieuDeces),
            vivant: $vivant,
            decede: !$vivant,
            branch: $branche,
            generation: $generation,
            bestQuay: $bestQuay,
            citationCount: $citations,
            creeLe: new \DateTimeImmutable($creeLe),
        );
        ++self::$version;
    }

    /** @param list<int> $enfants */
    public static function ajouterFamille(int $id, ?int $mari, ?int $epouse, array $enfants = [], string $mariage = '', ?int $generation = null): void
    {
        self::$familles[] = new FamilleDuReleve($id, $mari, $epouse, self::date($mariage), false, UncertainDate::inconnue(), $enfants, $generation);
        ++self::$version;
    }

    public static function ajouterMetier(int $personId, string $libelle, string $date = ''): void
    {
        self::$faits[] = new FaitDuReleve(NatureDuFait::Profession, $personId, null, $libelle, self::date($date));
        ++self::$version;
    }

    public static function ajouterAppui(int $personId, int $quay, string $creeLe, PorteeDeLAppui $portee = PorteeDeLAppui::Generale): void
    {
        self::$appuis[] = new AppuiDuReleve($personId, null, $portee, $quay, new \DateTimeImmutable($creeLe));
        ++self::$version;
    }

    public static function ajouterLieu(string $brut, string $libelle, ?string $commune, ?string $subdivision, ?string $pays, ?float $lat = null, ?float $lon = null, ?string $nomDuPays = null): void
    {
        self::$lieux[] = new LieuDuReleve((new CleDeRecherche())->replier($brut), $libelle, $commune, $subdivision, $pays, $nomDuPays, $lat, $lon);
        ++self::$version;
    }

    public static function racine(int $id): void
    {
        self::$racine = $id;
        ++self::$version;
    }

    /**
     * Les formes GEDCOM dont les tests ont besoin : `14 MAR 1850` (exacte), `MAR 1850`
     * (mois), `1850` (année seule), `ABT 1850` (environ), `BEF 1850` (avant),
     * `BET 1850 AND 1860` (entre), `''` (inconnue).
     */
    public static function date(string $gedcom, ?string $lieu = null): UncertainDate
    {
        if (1 === preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/', $gedcom, $m)) {
            $jour = new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', (int) $m[3], self::MOIS[$m[2]], (int) $m[1]));

            return new UncertainDate($gedcom, DateQualifier::Exacte, $jour, $jour, $lieu);
        }

        if (1 === preg_match('/^([A-Z]{3}) (\d{4})$/', $gedcom, $m) && isset(self::MOIS[$m[1]])) {
            $debut = new \DateTimeImmutable(\sprintf('%04d-%02d-01', (int) $m[2], self::MOIS[$m[1]]));

            return new UncertainDate($gedcom, DateQualifier::Mois, $debut, $debut->modify('last day of this month'), $lieu);
        }

        if (1 === preg_match('/^(\d{4})$/', $gedcom, $m)) {
            return new UncertainDate($gedcom, DateQualifier::Annee, new \DateTimeImmutable($m[1].'-01-01'), new \DateTimeImmutable($m[1].'-12-31'), $lieu);
        }

        if (1 === preg_match('/^ABT (\d{4})$/', $gedcom, $m)) {
            return new UncertainDate($gedcom, DateQualifier::Environ, new \DateTimeImmutable($m[1].'-01-01'), new \DateTimeImmutable($m[1].'-12-31'), $lieu);
        }

        if (1 === preg_match('/^BEF (\d{4})$/', $gedcom, $m)) {
            return new UncertainDate($gedcom, DateQualifier::Avant, null, new \DateTimeImmutable($m[1].'-01-01'), $lieu);
        }

        if (1 === preg_match('/^BET (\d{4}) AND (\d{4})$/', $gedcom, $m)) {
            return new UncertainDate($gedcom, DateQualifier::Entre, new \DateTimeImmutable($m[1].'-01-01'), new \DateTimeImmutable($m[2].'-12-31'), $lieu);
        }

        if ('' === $gedcom) {
            return UncertainDate::inconnue($lieu);
        }

        throw new \InvalidArgumentException('Forme GEDCOM non gérée par le double : '.$gedcom);
    }

    public function jeton(): string
    {
        return 'memoire-'.self::$version;
    }

    public function releve(): ReleveDeLArbre
    {
        return new ReleveDeLArbre(array_values(self::$personnes), self::$familles, self::$faits, self::$appuis, self::$lieux, self::$racine);
    }
}
