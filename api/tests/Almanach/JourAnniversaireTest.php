<?php

declare(strict_types=1);

namespace App\Tests\Almanach;

use App\Module\Almanach\Service\JourAnniversaire;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La porte du calendrier, éprouvée **qualificatif par qualificatif**.
 *
 * `JourAnniversaire` est statique et sans état : aucun noyau, aucune base, aucune horloge.
 * C'est ce qui permet de balayer ici les dix valeurs de {@see DateQualifier} plutôt que
 * d'en choisir deux ou trois — et le balayage est le point du test. La règle « seule une
 * date exacte produit un jour » n'a de valeur que si elle a été confrontée à **tous** les
 * cas qu'elle exclut ; vérifiée sur trois d'entre eux, elle laisserait passer le
 * quatrième, et ce serait celui qui compte.
 *
 * L'effet à l'échelle de l'arbre réel est dans l'en-tête de la classe testée : 119 des 620
 * dates poseraient au 1<sup>er</sup> janvier, 5 au 1<sup>er</sup> de leur mois.
 */
final class JourAnniversaireTest extends TestCase
{
    /**
     * **Seule une date exacte produit un anniversaire** — les neuf autres qualificatifs
     * rendent `null`, et le test affirme d'abord qu'il les a tous vus.
     *
     * @param array{mois: int, jour: int}|null $attendu
     */
    #[DataProvider('lesDixQualificatifs')]
    public function testSeuleUneDateExacteProduitUnAnniversaire(UncertainDate $date, ?array $attendu, string $pourquoi): void
    {
        self::assertSame($attendu, JourAnniversaire::depuis($date), $pourquoi);
    }

    /**
     * L'assiette de ce fichier : **les dix qualificatifs sont couverts**.
     *
     * Sans ce contrôle, l'ajout d'un onzième cas à `DateQualifier` passerait inaperçu, et
     * la porte du calendrier resterait « vérifiée » sur un ensemble devenu incomplet — un
     * test muet est pire qu'un test absent, puisqu'il occupe la place.
     */
    public function testLeBalayageCouvreTousLesQualificatifsDeDate(): void
    {
        $couverts = [];

        foreach (self::lesDixQualificatifs() as $cas) {
            $date = $cas[0];
            \assert($date instanceof UncertainDate);
            $couverts[$date->getQualifier()->value] = true;
        }

        $tous = array_map(static fn (DateQualifier $q): string => $q->value, DateQualifier::cases());
        sort($tous);
        $vus = array_keys($couverts);
        sort($vus);

        self::assertSame($tous, $vus, 'Chaque valeur de DateQualifier doit être éprouvée ici, sans exception.');
    }

    /**
     * Une date **marquée exacte mais dont les bornes divergent** ne produit aucun jour.
     *
     * C'est la seconde moitié de la double garde, et elle ferme une porte que le
     * qualificatif seul laisserait ouverte : aucune contrainte de base n'interdit cette
     * incohérence — `ck_tree_person_birth_intervalle` ne couvre que `entre` —, donc un
     * import approximatif ou une écriture ultérieure peut la produire. Le repli est le
     * même que partout ici : **pas de jour**, jamais un jour « au mieux ».
     */
    public function testUneDateExacteDontLesBornesDivergentNeProduitAucunJour(): void
    {
        $incoherente = new UncertainDate(
            '11 MAR 1930',
            DateQualifier::Exacte,
            new \DateTimeImmutable('1930-03-11'),
            new \DateTimeImmutable('1930-03-31'),
        );

        self::assertNull(
            JourAnniversaire::depuis($incoherente),
            'Une date exacte dont min ≠ max n’est pas un jour, quoi qu’en dise son qualificatif.',
        );
    }

    /**
     * Le jour et le mois sortent en **entiers**, jamais en chaînes zéro-préfixées.
     *
     * `format('d')` rendrait `"03"`, qui se trie alphabétiquement : le 10 du mois passerait
     * avant le 3. Le défaut ne se voit pas sur les neuf premiers jours, ce qui est
     * exactement ce qui le rend difficile à attraper à l'œil.
     */
    public function testLeMoisEtLeJourSontDesEntiers(): void
    {
        $jour = JourAnniversaire::depuis(self::exacte('1930-03-03'));

        self::assertSame(['mois' => 3, 'jour' => 3], $jour);
    }

    /**
     * Les dix qualificatifs, avec les bornes que l'import produit réellement pour chacun
     * (§ 0.5 du modèle de données, § 14.3 du contrat d'API).
     *
     * @return iterable<string, array{UncertainDate, array{mois: int, jour: int}|null, string}>
     */
    public static function lesDixQualificatifs(): iterable
    {
        yield 'exacte — le seul cas qui donne un jour' => [
            self::exacte('1887-03-14'),
            ['mois' => 3, 'jour' => 14],
            'Une date au jour près est un anniversaire.',
        ];

        yield 'annee — LE piège : min vaut le 1er janvier' => [
            new UncertainDate('1887', DateQualifier::Annee, new \DateTimeImmutable('1887-01-01'), new \DateTimeImmutable('1887-12-31')),
            null,
            'Une date-année encode min = AAAA-01-01 : la poser sur min ferait du jour de l’An '
            .'le jour le plus chargé de l’histoire de la famille — 119 des 620 dates de l’arbre réel.',
        ];

        yield 'mois — le même piège, au 1er du mois' => [
            new UncertainDate('FEB 1920', DateQualifier::Mois, new \DateTimeImmutable('1920-02-01'), new \DateTimeImmutable('1920-02-29')),
            null,
            'Une date-mois encode min = AAAA-MM-01. Cinq lignes seulement sur l’arbre réel — '
            .'c’est précisément pourquoi personne ne le verrait jamais.',
        ];

        yield 'environ — ABT 1934, jamais élargi ni resserré' => [
            new UncertainDate('ABT 1934', DateQualifier::Environ, new \DateTimeImmutable('1934-01-01'), new \DateTimeImmutable('1934-12-31')),
            null,
            '« Vers 1934 » ne désigne aucun jour.',
        ];

        yield 'entre — la vraie fourchette' => [
            new UncertainDate('BET 1901 AND 1909', DateQualifier::Entre, new \DateTimeImmutable('1901-01-01'), new \DateTimeImmutable('1909-12-31')),
            null,
            'Une fourchette de huit ans ne se pose pas sur un jour.',
        ];

        yield 'avant — borne haute seule' => [
            new UncertainDate('BEF 1920', DateQualifier::Avant, null, new \DateTimeImmutable('1919-12-31')),
            null,
            'Une borne ouverte à gauche ne désigne pas un jour, même si max en porte un.',
        ];

        yield 'apres — borne basse seule' => [
            new UncertainDate('AFT 1920', DateQualifier::Apres, new \DateTimeImmutable('1920-01-01'), null),
            null,
            'Une borne ouverte à droite non plus.',
        ];

        yield 'depuis — début de période ouverte' => [
            new UncertainDate('FROM 15 JUN 1900', DateQualifier::Depuis, new \DateTimeImmutable('1900-06-15'), null),
            null,
            'Le début d’une période n’est pas l’anniversaire de l’événement : c’est le cas le plus '
            .'tentant de la liste, parce que min y porte un vrai jour — et c’est celui qu’une '
            .'règle écrite sur isRange() seul laisserait passer si les bornes se refermaient.',
        ];

        yield 'jusqu_a — fin de période ouverte' => [
            new UncertainDate('TO 1920', DateQualifier::JusquA, null, new \DateTimeImmutable('1920-12-31')),
            null,
            'Symétrique du précédent.',
        ];

        yield 'inconnue — rien du tout' => [
            UncertainDate::inconnue('Saule, Cher, France'),
            null,
            'Un lieu connu sans date ne fabrique pas un jour.',
        ];
    }

    private static function exacte(string $jour): UncertainDate
    {
        $date = new \DateTimeImmutable($jour);

        return new UncertainDate($jour, DateQualifier::Exacte, $date, $date);
    }
}
