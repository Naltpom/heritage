<?php

declare(strict_types=1);

namespace App\Tests\Timeline\Double;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Timeline\Contract\ArbreDeLaChronologie;
use App\Module\Timeline\Contract\FaitDeLaChronologie;
use App\Module\Timeline\Contract\PersonneDeLaChronologie;
use App\Module\Timeline\Contract\PortChronologieArbre;

/**
 * L'arbre, joué par un double réglable — il tient la place de l'adaptateur de `Tree`.
 *
 * Même arbitrage que `tests/Membership/Double/ArbreEnMemoire.php`, et pour les mêmes
 * raisons. Ce qui se teste ici, ce n'est pas la façon dont `Tree` charge ses tables :
 * c'est le découpage en bandes, la conservation des fourchettes, et le fait que le cercle
 * de visibilité soit obéi. Un double rend ces tests lisibles — quatre personnes dont on
 * voit la forme d'un coup d'œil — et surtout **indépendants de PostgreSQL** : la base de
 * test est SQLite et les tables `tree_*` ne sont pas montées.
 *
 * ## Pourquoi l'état est statique
 *
 * `KernelBrowser` relance le noyau **avant chaque requête** : un double peuplé par le test
 * serait remis à zéro entre deux appels HTTP, et le test verrait un arbre vide sans que
 * rien ne le dise. L'état vit donc dans une propriété statique, réinitialisée en `setUp`.
 *
 * ## Les dates se posent en GEDCOM, pas en années
 *
 * {@see self::date()} prend une chaîne `BET 1901 AND 1909` et rend l'`UncertainDate`
 * correspondante, bornes comprises. C'est délibérément plus verbeux qu'un `int $annee` :
 * un double qui n'accepterait que des années rendrait **impossible d'écrire le cas
 * Sureau**, et un jeu d'essai qui ne peut pas exprimer le cas de contrôle du projet est un
 * jeu d'essai qui ne prouve rien.
 */
final class ArbreDeChronologieEnMemoire implements PortChronologieArbre
{
    /** @var list<PersonneDeLaChronologie> */
    private static array $personnes = [];

    /** @var list<FaitDeLaChronologie> */
    private static array $faits = [];

    /** Les douze codes de la norme GEDCOM, pour {@see self::compacte()}. */
    private const array MOIS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    public static function reinitialiser(): void
    {
        self::$personnes = [];
        self::$faits = [];
    }

    public static function ajouterPersonne(
        int $id,
        string $displayName,
        Branch $branch,
        int $generation,
        Sex $sex,
        bool $vivant,
        ?UncertainDate $naissance = null,
        ?UncertainDate $deces = null,
    ): void {
        self::$personnes[] = new PersonneDeLaChronologie(
            $id,
            $displayName,
            $branch,
            $generation,
            $sex,
            $vivant,
            $naissance ?? UncertainDate::inconnue(),
            $deces ?? UncertainDate::inconnue(),
            self::compacte($naissance),
            self::compacte($deces),
        );
    }

    /** Une personne sans branche ni génération — le cas que `barres` écarte. */
    public static function ajouterPersonneOrpheline(int $id, string $displayName, ?UncertainDate $naissance = null): void
    {
        self::$personnes[] = new PersonneDeLaChronologie(
            $id,
            $displayName,
            null,
            null,
            Sex::Inconnu,
            false,
            $naissance ?? UncertainDate::inconnue(),
            UncertainDate::inconnue(),
        );
    }

    public static function ajouterFait(
        int $personId,
        string $type,
        string $typeLabel,
        ?string $label,
        UncertainDate $quand,
    ): void {
        self::$faits[] = new FaitDeLaChronologie($personId, $type, $typeLabel, $label, $quand);
    }

    /**
     * Les formes GEDCOM dont ces tests ont besoin, avec les bornes du § 14.3 du contrat
     * d'API — recopiées de sa table, pas déduites.
     *
     * `raw` est conservé verbatim parce que c'est la provenance et que `format()` s'en
     * sert en repli. Les bornes sont **inclusives** des deux côtés.
     */
    public static function date(string $gedcom, ?string $lieu = null): UncertainDate
    {
        // `11 MAR 1930` — jour connu, `min = max`, et c'est le SEUL cas `certain: true`.
        if (1 === preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/', $gedcom, $m)) {
            $jour = self::jour((int) $m[3], $m[2], (int) $m[1]);

            return new UncertainDate($gedcom, DateQualifier::Exacte, $jour, $jour, $lieu);
        }

        // `BET 1901 AND 1909` — la vraie fourchette, celle qu'on n'aplatit jamais.
        if (1 === preg_match('/^BET (\d{4}) AND (\d{4})$/', $gedcom, $m)) {
            return new UncertainDate(
                $gedcom,
                DateQualifier::Entre,
                self::debutDAnnee((int) $m[1]),
                self::finDAnnee((int) $m[2]),
                $lieu,
            );
        }

        // `ABT 1934` — jamais élargi à ±n années (§ 14.3, piège 2).
        if (1 === preg_match('/^ABT (\d{4})$/', $gedcom, $m)) {
            return new UncertainDate(
                $gedcom,
                DateQualifier::Environ,
                self::debutDAnnee((int) $m[1]),
                self::finDAnnee((int) $m[1]),
                $lieu,
            );
        }

        // `BEF 1920` — pas de borne basse. Stocké sur la veille du 1er janvier.
        if (1 === preg_match('/^BEF (\d{4})$/', $gedcom, $m)) {
            return new UncertainDate(
                $gedcom,
                DateQualifier::Avant,
                null,
                self::finDAnnee((int) $m[1] - 1),
                $lieu,
            );
        }

        // `1930` — l'année seule. `min ≠ max` et pourtant `certain: false` : c'est le
        // piège 1 du § 14.3, et c'est exactement la `fin` de Marne Dolomite Sureau.
        if (1 === preg_match('/^(\d{4})$/', $gedcom, $m)) {
            return new UncertainDate(
                $gedcom,
                DateQualifier::Annee,
                self::debutDAnnee((int) $m[1]),
                self::finDAnnee((int) $m[1]),
                $lieu,
            );
        }

        throw new \InvalidArgumentException(\sprintf('Forme GEDCOM non gérée par le double : %s. Ajouter le cas plutôt que de contourner.', $gedcom));
    }

    /**
     * L'étiquette **compacte** du § 14.4 — ce que `Tree` fait traverser le port.
     *
     * Recopiée de la table du § 14.4, **pas** déléguée à `Tree\Service\EtiquetteDeDateGedcom` :
     * un double qui appellerait le vrai producteur ne prouverait plus que le port transporte
     * bien la valeur, il prouverait que deux chemins mènent au même service. Ici la chaîne
     * est écrite à la main, et la barre servie doit la retrouver **telle quelle** — c'est ce
     * qui fait du test un test du transport, et non de la grammaire.
     *
     * La grammaire elle-même appartient à `Tree` et s'éprouve chez lui.
     */
    private static function compacte(?UncertainDate $date): string
    {
        $brut = $date?->getRaw();

        return match (true) {
            null === $brut => '',
            // `11 MAR 1930` → `11.3.1930`
            1 === preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/', $brut, $m) => \sprintf(
                '%d.%d.%d',
                (int) $m[1],
                self::MOIS[$m[2]] ?? 0,
                (int) $m[3],
            ),
            // `BET 1901 AND 1909` → `1901-1909` — l'intervalle reste un intervalle, écrit court
            1 === preg_match('/^BET (\d{4}) AND (\d{4})$/', $brut, $m) => $m[1].'-'.$m[2],
            // `ABT 1889` → `~1889`
            1 === preg_match('/^ABT (\d{4})$/', $brut, $m) => '~'.$m[1],
            // `BEF 1920` → `<1920`
            1 === preg_match('/^BEF (\d{4})$/', $brut, $m) => '<'.$m[1],
            // `1987` → `1987`
            1 === preg_match('/^\d{4}$/', $brut) => $brut,
            default => throw new \InvalidArgumentException('Forme sans étiquette compacte connue : '.$brut),
        };
    }

    public function vue(): ArbreDeLaChronologie
    {
        return new ArbreDeLaChronologie(self::$personnes, self::$faits);
    }

    private static function jour(int $annee, string $moisGedcom, int $jour): \DateTimeImmutable
    {
        $mois = self::MOIS[$moisGedcom]
            ?? throw new \InvalidArgumentException('Mois GEDCOM inconnu : '.$moisGedcom);

        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $annee, $mois, $jour));
    }

    private static function debutDAnnee(int $annee): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('%04d-01-01', $annee));
    }

    private static function finDAnnee(int $annee): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('%04d-12-31', $annee));
    }
}
