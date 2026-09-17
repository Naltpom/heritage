<?php

declare(strict_types=1);

namespace App\Tests\Almanach\Double;

use App\Module\Almanach\Contract\EvenementDeLArbre;
use App\Module\Almanach\Contract\FluxDEvenements;
use App\Module\Almanach\Contract\LieuResolu;
use App\Module\Almanach\Contract\PortEvenementsArbre;
use App\Module\Almanach\Contract\SujetDeLEvenement;
use App\Module\Almanach\Contract\TypeDEvenement;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * L'arbre, joué par un double réglable — il tient la place de l'adaptateur de `Tree`.
 *
 * Même arbitrage que `tests/Timeline/Double/ArbreDeChronologieEnMemoire.php`, et pour les
 * mêmes raisons. Ce qui se teste ici, ce n'est pas la façon dont `Tree` charge ses tables :
 * c'est la règle du jour d'anniversaire, le rapprochement avec les lieux, les compteurs, et
 * le fait que le cercle de visibilité soit obéi. Un double rend ces tests lisibles — cinq
 * personnes dont on voit la forme d'un coup d'œil — et surtout **indépendants de
 * PostgreSQL** : la base de test est SQLite et les tables `tree_*` ne sont pas montées ici.
 *
 * La traduction elle-même s'éprouve là où elle vit, sur une vraie base :
 * `tests/Tree/PortDesEvenementsPourAlmanachTest.php`.
 *
 * ## Pourquoi l'état est statique
 *
 * `KernelBrowser` relance le noyau **avant chaque requête** : un double peuplé par le test
 * serait remis à zéro entre deux appels HTTP, et le test verrait un arbre vide sans que
 * rien ne le dise. L'état vit donc dans une propriété statique, réinitialisée en `setUp`.
 *
 * ## Les dates se posent en GEDCOM, pas en années
 *
 * {@see self::date()} prend `11 MAR 1930`, `1887`, `FEB 1920`, `BET 1901 AND 1909` et rend
 * l'`UncertainDate` correspondante, bornes comprises. C'est délibérément plus verbeux
 * qu'un `int $annee` : un double qui n'accepterait que des années rendrait **impossible
 * d'écrire le cas qui compte le plus ici** — celui de la date-année qu'il ne faut pas
 * poser au 1<sup>er</sup> janvier.
 *
 * ## La clé d'un lieu se replie avec le vrai `CleDeRecherche`
 *
 * Et non avec une formule écrite pour le test. En production, `tree_place.raw_key` est
 * produite par ce même appel : recopier la formule ici prouverait que deux copies
 * concordent, pas que le rapprochement marche. C'est le même raisonnement que
 * `ArbreWebTestCase` tient pour `search_key` — « un test vert sur une fixture impossible
 * ne dit rien du produit ».
 */
final class FluxDEvenementsEnMemoire implements PortEvenementsArbre
{
    /** @var array<int, SujetDeLEvenement> */
    private static array $sujets = [];

    /** @var list<EvenementDeLArbre> */
    private static array $evenements = [];

    /** @var list<LieuResolu> */
    private static array $lieux = [];

    /** Les douze codes de la norme GEDCOM. */
    private const array MOIS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    public static function reinitialiser(): void
    {
        self::$sujets = [];
        self::$evenements = [];
        self::$lieux = [];
    }

    public static function ajouterSujet(int $id, string $displayName, Sex $sex, bool $vivant): void
    {
        self::$sujets[$id] = new SujetDeLEvenement($id, $displayName, $sex, $vivant);
    }

    /**
     * Ajoute un événement portant sur les personnes désignées.
     *
     * Un identifiant inconnu est **ignoré silencieusement**, ce qui permet d'écrire
     * l'événement sans sujet — le cas que `FluxVisible` doit fermer.
     *
     * @param list<int> $sujets
     */
    public static function ajouterEvenement(
        string $cle,
        TypeDEvenement $type,
        array $sujets,
        UncertainDate $date,
        ?string $libelle = null,
        ?int $familyId = null,
    ): void {
        $portes = [];

        foreach ($sujets as $id) {
            if (isset(self::$sujets[$id])) {
                $portes[] = self::$sujets[$id];
            }
        }

        self::$evenements[] = new EvenementDeLArbre($cle, $type, $portes, $date, $libelle, $familyId);
    }

    /**
     * Un lieu résolu. `$brut` est la chaîne `PLAC` telle qu'un événement la porte — sa clé
     * s'en déduit par le **vrai** repliement, exactement comme `tree_place.raw_key`.
     */
    public static function ajouterLieu(
        int $placeId,
        string $brut,
        float $lat,
        float $lon,
        string $precision = 'commune',
        int $confiance = 100,
        string $source = 'communes-fr',
        ?string $libelle = null,
    ): void {
        self::$lieux[] = new LieuResolu(
            placeId: $placeId,
            cle: (new CleDeRecherche())->replier($brut),
            libelle: $libelle ?? $brut,
            lat: $lat,
            lon: $lon,
            precision: $precision,
            confiance: $confiance,
            source: $source,
            brut: [$brut],
        );
    }

    /**
     * Les formes GEDCOM dont ces tests ont besoin, avec les bornes du § 14.3 du contrat
     * d'API — recopiées de sa table, pas déduites.
     *
     * Les deux formes qui décident de tout sont `1887` (année : `min` au 1<sup>er</sup>
     * janvier) et `FEB 1920` (mois : `min` au 1<sup>er</sup> du mois). Ce sont elles que
     * `JourAnniversaire` doit refuser, et elles sont écrites ici telles que l'import les
     * produit.
     */
    public static function date(string $gedcom, ?string $lieu = null): UncertainDate
    {
        // `11 MAR 1930` — jour connu, `min = max`, et le SEUL cas qui donne un jour.
        if (1 === preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/', $gedcom, $m)) {
            $jour = self::jour((int) $m[3], $m[2], (int) $m[1]);

            return new UncertainDate($gedcom, DateQualifier::Exacte, $jour, $jour, $lieu);
        }

        // `FEB 1920` — le mois. `min = 1920-02-01` : le piège du 1er du mois.
        //
        // La reconnaissance du code de mois est **dans la condition**, et non après. Le
        // motif `^([A-Z]{3}) (\d{4})$` attrape aussi `ABT 1980` et `BEF 1920`, qui sont des
        // qualificatifs et non des mois : sans cette garde, une date « environ » se posait
        // au 1er d'un mois inexistant et le double levait au lieu de rendre la date.
        if (1 === preg_match('/^([A-Z]{3}) (\d{4})$/', $gedcom, $m) && isset(self::MOIS[$m[1]])) {
            $mois = self::MOIS[$m[1]];
            $debut = new \DateTimeImmutable(\sprintf('%04d-%02d-01', (int) $m[2], $mois));

            return new UncertainDate($gedcom, DateQualifier::Mois, $debut, $debut->modify('last day of this month'), $lieu);
        }

        // `BET 1901 AND 1909` — la vraie fourchette, celle qu'on n'aplatit jamais.
        if (1 === preg_match('/^BET (\d{4}) AND (\d{4})$/', $gedcom, $m)) {
            return new UncertainDate($gedcom, DateQualifier::Entre, self::debutDAnnee((int) $m[1]), self::finDAnnee((int) $m[2]), $lieu);
        }

        // `ABT 1934` — jamais élargi à ±n années (§ 14.3, piège 2).
        if (1 === preg_match('/^ABT (\d{4})$/', $gedcom, $m)) {
            return new UncertainDate($gedcom, DateQualifier::Environ, self::debutDAnnee((int) $m[1]), self::finDAnnee((int) $m[1]), $lieu);
        }

        // `1887` — l'année seule. `min = 1887-01-01` : **le piège du 1er janvier**, celui
        // qui poserait 119 des 620 dates de l'arbre réel sur le jour de l'An.
        if (1 === preg_match('/^(\d{4})$/', $gedcom, $m)) {
            return new UncertainDate($gedcom, DateQualifier::Annee, self::debutDAnnee((int) $m[1]), self::finDAnnee((int) $m[1]), $lieu);
        }

        // Aucune date, un lieu quand même : « je sais où, pas quand ».
        if ('' === $gedcom) {
            return UncertainDate::inconnue($lieu);
        }

        throw new \InvalidArgumentException(\sprintf('Forme GEDCOM non gérée par le double : %s. Ajouter le cas plutôt que de contourner.', $gedcom));
    }

    public function flux(): FluxDEvenements
    {
        return new FluxDEvenements(self::$evenements, self::$lieux);
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
