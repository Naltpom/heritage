<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Serializer\NormaliseurDeDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  `ligneDates` SUIT LA GRAMMAIRE COMPACTE, ET RIEN D'AUTRE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Le § 14.4 du contrat distingue **trois** formes d'une même date, et le défaut que ce
 * fichier garde fermé est de servir la mauvaise :
 *
 * | Forme | Clé | Producteur |
 * |---|---|---|
 * | Prose | `texte`, **dans** le bloc date | `UncertainDate::format()` |
 * | **Compacte** | **`ligneDates`** | `Tree\Service\EtiquetteDeDateGedcom` |
 * | Étiquette mesurée | `etiquetteDates` | `MesureDeFiche::ligneDates()` |
 *
 * `ligneDates` a rendu la prose — « entre 1901 et 1909 – 1987 » au lieu de
 * « 1901-1909 – 1987 » — sur la fiche **et** sur la recherche, parce qu'une seule méthode
 * les sert toutes deux. Le squelette d'assemblage était juste ; seul le formateur par date
 * était le mauvais.
 *
 * ## Pourquoi ce test affirme une grammaire et non des chaînes
 *
 * Un test qui aurait figé « 1901-1909 – 1987 » aurait attrapé ce cas-là et **manqué les
 * autres** : `BEF`, `AFT`, `ABT`, un mois seul, une date exacte. La prose se reconnaît à
 * ses marqueurs — des mots français, des noms de mois — et la forme compacte à leur
 * absence. On vérifie donc la **propriété**, sur les sept formes du § 14.3, ce qui couvre
 * aussi les cas limites que personne ne pense à inscrire dans un jeu d'essai.
 *
 * Sans base et sans conteneur : le normaliseur est un objet valeur, et c'est ce qui rend
 * ces sept cas écrivables un par un.
 */
final class GrammaireDesLignesDeDatesTest extends TestCase
{
    /**
     * Les marqueurs de la **prose**. Aucun n'a le droit d'apparaître dans `ligneDates` :
     * les mots de liaison de `UncertainDate::format()`, et les douze mois en toutes
     * lettres.
     */
    private const array MARQUEURS_DE_PROSE = [
        'entre ', 'vers ', 'avant ', 'après ', 'depuis ', "jusqu'",
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    private function normaliseur(): NormaliseurDeDate
    {
        return new NormaliseurDeDate();
    }

    private function date(string $brut, DateQualifier $qualifier, ?string $min, ?string $max): UncertainDate
    {
        return new UncertainDate(
            $brut,
            $qualifier,
            null === $min ? null : new \DateTimeImmutable($min),
            null === $max ? null : new \DateTimeImmutable($max),
        );
    }

    /**
     * Les sept formes datées du § 14.3, avec leur rendu compact du § 14.4. C'est la table du
     * contrat, recopiée — si elle change, ce test doit changer avec elle et pas l'inverse.
     *
     * @return iterable<string, array{string, DateQualifier, string|null, string|null, string}>
     */
    public static function fournirLesFormes(): iterable
    {
        yield 'exacte' => ['11 MAR 1930', DateQualifier::Exacte, '1930-03-11', '1930-03-11', '11.3.1930'];
        yield 'année' => ['1987', DateQualifier::Annee, '1987-01-01', '1987-12-31', '1987'];
        yield 'mois' => ['JAN 1920', DateQualifier::Mois, '1920-01-01', '1920-01-31', '1/1920'];
        yield 'environ' => ['ABT 1878', DateQualifier::Environ, '1878-01-01', '1878-12-31', '~1878'];
        yield 'fourchette' => ['BET 1901 AND 1909', DateQualifier::Entre, '1901-01-01', '1909-12-31', '1901-1909'];
        yield 'avant' => ['BEF 1920', DateQualifier::Avant, null, '1919-12-31', '<1920'];
        yield 'après' => ['AFT 1900', DateQualifier::Apres, '1900-01-01', null, '>1900'];
    }

    /**
     * La naissance seule, pour que chaque forme soit vérifiée **isolée** : une erreur sur
     * le formateur d'un seul côté se noierait dans une ligne à deux dates.
     */
    #[DataProvider('fournirLesFormes')]
    public function testChaqueFormeSeRendEnCompact(string $brut, DateQualifier $qualifier, ?string $min, ?string $max, string $attendu): void
    {
        $ligne = $this->normaliseur()->ligneDeFiche(
            $this->date($brut, $qualifier, $min, $max),
            UncertainDate::inconnue(),
        );

        self::assertSame('n. '.$attendu, $ligne);
        self::assertNeContientAucuneProse($ligne);
    }

    /**
     * Le cas de contrôle du projet : Marne Dolomite Sureau, `BET 1901 AND 1909` → 1987.
     * C'est la fourchette qui a survécu à toute la chaîne — GEDCOM, import, base, moteur —
     * et qui mourait à l'étage de la sérialisation.
     */
    public function testLeCasDeControleSureauSeRendEnCompact(): void
    {
        $ligne = $this->normaliseur()->ligneDeFiche(
            $this->date('BET 1901 AND 1909', DateQualifier::Entre, '1901-01-01', '1909-12-31'),
            $this->date('1987', DateQualifier::Annee, '1987-01-01', '1987-12-31'),
        );

        self::assertSame('1901-1909 – 1987', $ligne);
        self::assertNeContientAucuneProse($ligne);
    }

    /**
     * L'assemblage du § 14.4, identique à `ligne_dates()` de `graphe.py` — les quatre
     * formes, dont celle qu'on oublie : **« — » quand rien n'est connu**, jamais la chaîne
     * vide.
     */
    public function testLesQuatreAssemblages(): void
    {
        $naissance = $this->date('1901', DateQualifier::Annee, '1901-01-01', '1901-12-31');
        $deces = $this->date('1978', DateQualifier::Annee, '1978-01-01', '1978-12-31');
        $inconnue = UncertainDate::inconnue();
        $n = $this->normaliseur();

        self::assertSame('1901 – 1978', $n->ligneDeFiche($naissance, $deces));
        self::assertSame('n. 1901', $n->ligneDeFiche($naissance, $inconnue));
        self::assertSame('† 1978', $n->ligneDeFiche($inconnue, $deces));
        self::assertSame('—', $n->ligneDeFiche($inconnue, $inconnue));
    }

    /**
     * **La prose, elle, reste là où elle doit être** : dans la clé `texte` du bloc date.
     *
     * Ce cas est le pendant du précédent, et il est indispensable : un correctif qui se
     * contenterait de faire taire la prose partout casserait `texte`, que le § 5.3 sert et
     * que le calque « en attente » compare. Les deux formes coexistent **dans la même
     * réponse**, sur la même date.
     */
    public function testLaProseResteDansLaCleTexteDuBlocDate(): void
    {
        $bloc = $this->normaliseur()->pourFiche(
            $this->date('BET 1901 AND 1909', DateQualifier::Entre, '1901-01-01', '1909-12-31'),
        );

        self::assertSame('entre 1901 et 1909', $bloc['texte'], 'La prose est la valeur légitime de `texte`.');
        self::assertSame('BET 1901 AND 1909', $bloc['raw'], 'La provenance GEDCOM part verbatim, jamais reconstruite.');
        self::assertSame('1901-01-01', $bloc['min']);
        self::assertSame('1909-12-31', $bloc['max'], 'La fourchette n’est jamais aplatie en date ferme.');
    }

    /**
     * **Un test qui itère sur un ensemble doit affirmer la taille de cet ensemble.**.
     *
     * La règle vaut ici alors même que l'ensemble est une constante : le jour où quelqu'un
     * vide `MARQUEURS_DE_PROSE` — en la remaniant, en la déplaçant, en la remplaçant par
     * un appel — la boucle ci-dessous tourne à vide et **chaque cas de ce fichier reste
     * vert en ne vérifiant plus rien**. Six mots de liaison, douze mois.
     */
    private static function assertNeContientAucuneProse(string $ligne): void
    {
        self::assertCount(18, self::MARQUEURS_DE_PROSE, 'Six mots de liaison et douze mois : une liste raccourcie laisserait passer la prose qu’elle est censée interdire.');

        foreach (self::MARQUEURS_DE_PROSE as $marqueur) {
            self::assertStringNotContainsString(
                $marqueur,
                $ligne,
                \sprintf('« %s » trahit `UncertainDate::format()` : `ligneDates` veut la forme compacte (§ 14.4).', $marqueur),
            );
        }
    }
}
