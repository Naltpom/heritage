<?php

declare(strict_types=1);

namespace App\Tests\Membership;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Membership\Service\CriteresDeRattachement;
use App\Module\Membership\Service\NormalisationDeNom;
use App\Module\Membership\Service\Port\PersonneDeLArbre;
use App\Module\Membership\Service\Port\VueDeLArbre;
use App\Module\Membership\Service\RechercheDeRattachement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La recherche de rattachement et son **affichage minimal**, sans HTTP.
 *
 * Le libellé est fixé au caractère près par le § 4.2 du contrat — « la forme est fixée ici
 * au caractère près pour qu'aucune implémentation ne *complète utilement* ». Ce fichier est
 * la recopie de cette phrase en assertions.
 */
final class RechercheDeRattachementTest extends TestCase
{
    /**
     * @return iterable<string, array{0: PersonneDeLArbre, 1: string}>
     */
    public static function libellesMinimaux(): iterable
    {
        yield 'initiale, nom, année' => [
            self::personne('Pyrite', 'Romarin', 1980),
            'P. Romarin, 1980',
        ];

        yield 'plusieurs prénoms : seul le premier compte' => [
            self::personne('Marne Jaspine Malachite', 'Tilleul', 1934),
            'M. Tilleul, 1934',
        ];

        yield 'année inconnue : le libellé s’arrête au nom' => [
            self::personne('Pyrite', 'Romarin', null),
            'P. Romarin',
        ];

        yield 'prénom inconnu : pas d’initiale orpheline' => [
            self::personne('?', 'Romarin', 1980),
            'Romarin, 1980',
        ];

        yield 'prénom accentué : l’initiale reste celle du nom d’origine' => [
            self::personne('Émeri', 'Thym', 1912),
            'É. Thym, 1912',
        ];
    }

    #[DataProvider('libellesMinimaux')]
    public function testLeLibelleMinimalEstFixeAuCaracterePres(PersonneDeLArbre $personne, string $attendu): void
    {
        self::assertSame($attendu, $personne->libelleMinimal());
    }

    /**
     * Le libellé ne porte **jamais** la date exacte, même quand la fiche la connaît : c'est
     * ce qui sépare « je me reconnais » de « je documente quelqu'un d'autre ».
     */
    public function testLeLibelleNePorteJamaisLaDateExacte(): void
    {
        $personne = new PersonneDeLArbre(202, 'Pyroxène', 'Tilleul', 'Pyroxène Tilleul', 1934, '4.5.1934', true);

        self::assertSame('P. Tilleul, 1934', $personne->libelleMinimal());
        self::assertStringNotContainsString('4.5', $personne->libelleMinimal());
    }

    /**
     * La casse et les accents ne font pas échouer la recherche : un candidat qui tape son
     * propre nom ne reproduit pas les conventions d'état civil du GEDCOM.
     */
    public function testLaRechercheToleraLaCasseLesAccentsEtUneFauteDeFrappe(): void
    {
        $arbre = $this->arbre([
            self::personne('Pyroxène', 'Tilleul', 1934, '~1934'),
        ]);

        foreach (['TILLEUL', 'tilleul', 'Tilleul', 'Tilleule'] as $saisie) {
            self::assertCount(
                1,
                $this->chercher($arbre, $saisie, 'Pyroxène', '1934-06-01'),
                \sprintf('« %s » doit correspondre à « Tilleul ».', $saisie),
            );
        }
    }

    /**
     * **Les accents se replient par la table explicite de `Core\Service\CleDeRecherche`**,
     * et surtout pas par `iconv` avec `//TRANSLIT`.
     *
     * Le résultat d'`iconv` dépend de la bibliothèque du système : `é` devient `e` sous la
     * glibc de Linux, `'e` sous la libiconv de Windows, et **`?` sous la musl d'Alpine** —
     * l'image Docker de ce projet, alors que le développement se fait sous Windows. Une
     * recherche qui aurait marché ici aurait cessé de trouver `Génévrolle` en production,
     * **sans erreur et sans test rouge** : juste une personne qu'on ne retrouve plus.
     *
     * L'arbre réel est plein de `Épine`, `Sarriette`, `Génévrolle` et `Chardon` : ce test
     * est celui qui verrait rouge si quelqu'un réintroduisait une seconde définition du
     * repliement.
     */
    public function testLesAccentsSeReplientDeLaMemeFaconSurToutesLesMachines(): void
    {
        $arbre = $this->arbre([self::personne('Émeri', 'Génévrolle', 1912, '1912')]);

        foreach (['Génévrolle', 'GÉNÉVROLLE', 'Genevrolle', 'genevrolle'] as $saisie) {
            self::assertCount(
                1,
                $this->chercher($arbre, $saisie, 'Emeri', '1912-01-01'),
                \sprintf('« %s » doit correspondre à « Génévrolle ».', $saisie),
            );
        }

        // Et dans l'autre sens : le prénom accentué de la fiche se replie aussi.
        self::assertCount(1, $this->chercher($arbre, 'Genevrolle', 'Émeri', '1912-01-01'));

        // Le repliement ne doit laisser filtrer ni apostrophe (libiconv) ni point
        // d'interrogation (musl) : les deux casseraient la comparaison en silence.
        $replie = (new NormalisationDeNom(new CleDeRecherche()))->normaliser('Génévrolle');
        self::assertSame('GENEVROLLE', $replie);
        self::assertSame(1, preg_match('/^[A-Z0-9 ]+$/', $replie));
    }

    /** Un nom voisin mais différent **ne** correspond pas : la tolérance s'arrête à une faute. */
    public function testDeuxNomsVoisinsNeSeConfondentPas(): void
    {
        $arbre = $this->arbre([self::personne('Onyx', 'Thym', 1912, '1912')]);

        self::assertCount(0, $this->chercher($arbre, 'Renard', 'Onyx', '1912-01-01'));
        // Quatre lettres : en deçà du seuil, aucune tolérance — « Thym » et « Thyn » sont
        // deux familles, pas une faute de frappe. Chaque cran de tolérance élargit ce qu'un
        // porteur de jeton peut faire correspondre, donc ce qu'il peut apprendre.
        self::assertCount(0, $this->chercher($arbre, 'Thyn', 'Onyx', '1912-01-01'));
    }

    /**
     * **La date se compare à la granularité dont la fiche dispose**, et jamais en
     * aplatissant une fourchette en date ferme (§ 4 du dossier).
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function datesEtEtiquettes(): iterable
    {
        yield 'date exacte, jour juste' => ['22.5.1949', '1949-05-22', true];
        yield 'date exacte, jour faux' => ['22.5.1949', '1949-08-10', false];
        yield 'date exacte, année juste mais jour faux' => ['22.5.1949', '1949-01-01', false];
        yield 'mois et année, mois juste' => ['5/1949', '1949-05-20', true];
        yield 'mois et année, mois faux' => ['5/1949', '1949-06-20', false];
        yield 'année seule' => ['1949', '1949-12-31', true];
        yield 'année seule, autre année' => ['1949', '1950-01-01', false];
        yield 'environ : l’année suffit' => ['~1934', '1934-04-12', true];
        yield 'fourchette : une année dedans' => ['1901-1909', '1905-03-02', true];
        yield 'fourchette : une année dehors' => ['1901-1909', '1910-03-02', false];
        yield 'aucune date : rien ne confirme' => ['', '1949-05-22', false];
    }

    #[DataProvider('datesEtEtiquettes')]
    public function testLaDateSeCompareALaGranulariteDeLaFiche(string $etiquette, string $soumise, bool $attendu): void
    {
        $arbre = $this->arbre([self::personne('Pyrite', 'Romarin', null, $etiquette)]);

        self::assertSame($attendu, [] !== $this->chercher($arbre, 'Romarin', 'Pyrite', $soumise));
    }

    /**
     * **Le mode confirmation ne vérifie que la date**, et ne rend qu'un candidat.
     *
     * Le formulaire demande quand même le nom et les prénoms — « un formulaire qui ne
     * demanderait que la date dirait au porteur *nous savons déjà ton nom* » — mais exiger
     * en plus l'orthographe d'un nom de jeune fille refuserait la bonne personne sans
     * jamais lui dire pourquoi.
     */
    public function testLeModeConfirmationNeVerifieQueLaDate(): void
    {
        $recherche = new RechercheDeRattachement(new NormalisationDeNom(new CleDeRecherche()));
        $arbre = $this->arbre([self::personne('Zircon', 'Tilleul', 1929, '5.7.1929')]);

        $trouves = $recherche->confirmer($arbre, 1, $this->criteres('Nom-Depuis-Mariage', 'Zircon', '1929-07-05'));
        self::assertCount(1, $trouves, 'Un nom d’épouse ne doit pas faire échouer la confirmation.');

        $trouves = $recherche->confirmer($arbre, 1, $this->criteres('Tilleul', 'Zircon', '1929-07-06'));
        self::assertCount(0, $trouves, 'Une date fausse, elle, ferme la porte.');

        $trouves = $recherche->confirmer($arbre, 999, $this->criteres('Tilleul', 'Zircon', '1929-07-05'));
        self::assertCount(0, $trouves, 'Une personne pré-liée disparue de l’arbre ne confirme rien.');
    }

    /**
     * La troncature a lieu **dans le service**, avant de rendre : l'appelant n'a pas le
     * moyen de divulguer le total. « Ni un décompte, ni un *et 7 autres* : ce serait un
     * oracle de cardinalité, qui permet de tester une hypothèse sans même lire un nom. ».
     */
    public function testLaTroncatureALieuAvantDeRendreEtLeTotalNeSortPas(): void
    {
        $arbre = $this->arbre([
            self::personne('Marbre', 'Romarin', 1966, '1966', 1),
            self::personne('Marbre', 'Romarin', 1966, '1966', 2),
            self::personne('Marbre', 'Romarin', 1966, '1966', 3),
            self::personne('Marbre', 'Romarin', 1966, '1966', 4),
        ]);

        $trouves = $this->chercher($arbre, 'Romarin', 'Marbre', '1966-06-01', 3);

        self::assertCount(3, $trouves);
        // Ordre stable et **sans score** : un classement par pertinence dirait au porteur
        // du jeton lequel de ses essais approche le but.
        self::assertSame([1, 2, 3], array_map(static fn (PersonneDeLArbre $p): int => $p->id, $trouves));
    }

    /**
     * Une personne **décédée** n'est plus proposée (ADR 0013).
     *
     * Elle l'a été, au nom d'un « oracle inverse ». L'argument est tombé avec l'inscription
     * sans fiche : tout porteur du lien peut créer un compte en attente, et un compte en
     * attente voit tous les défunts. Reste ce qui décide : un compte ancré sur un mort n'a
     * pas de sens. Le vivant homonyme, lui, est toujours trouvé.
     */
    public function testUnePersonneDecedeeNEstPlusProposee(): void
    {
        $arbre = $this->arbre([
            new PersonneDeLArbre(1, 'Azurite', 'Romarin', 'Azurite Romarin', 1901, '1901', false),
            new PersonneDeLArbre(2, 'Azurite', 'Romarin', 'Azurite Romarin', 1998, '1998', true),
        ]);

        self::assertSame([], $this->chercher($arbre, 'Romarin', 'Azurite', '1901-01-01'));
        self::assertSame(
            [2],
            array_map(static fn (PersonneDeLArbre $p): int => $p->id, $this->chercher($arbre, 'Romarin', 'Azurite', '1998-03-04')),
        );
    }

    /**
     * En mode confirmation, un pré-lien vers un défunt ne se confirme pas, **même avec la
     * bonne date** : même réponse qu'une date fausse, et la tentative est consommée par
     * l'appelant de la même façon.
     */
    public function testUnPreLienVersUnDefuntNeSeConfirmePas(): void
    {
        $arbre = $this->arbre([
            new PersonneDeLArbre(1, 'Azurite', 'Romarin', 'Azurite Romarin', 1901, '1.2.1901', false),
            new PersonneDeLArbre(2, 'Jet', 'Romarin', 'Jet Romarin', 2009, '26.9.2009', true),
        ]);

        $recherche = new RechercheDeRattachement(new NormalisationDeNom(new CleDeRecherche()));

        self::assertSame([], $recherche->confirmer($arbre, 1, $this->criteres('Romarin', 'Azurite', '1901-02-01')));
        self::assertCount(1, $recherche->confirmer($arbre, 2, $this->criteres('Romarin', 'Jet', '2009-09-26')));
    }

    /** @param list<PersonneDeLArbre> $personnes */
    private function arbre(array $personnes): VueDeLArbre
    {
        return new VueDeLArbre($personnes, []);
    }

    /** @return list<PersonneDeLArbre> */
    private function chercher(VueDeLArbre $arbre, string $nom, string $prenoms, string $naissance, int $max = 3): array
    {
        return (new RechercheDeRattachement(new NormalisationDeNom(new CleDeRecherche())))
            ->chercher($arbre, $this->criteres($nom, $prenoms, $naissance), $max);
    }

    private function criteres(string $nom, string $prenoms, string $naissance): CriteresDeRattachement
    {
        return CriteresDeRattachement::depuisLeCorps([
            'surname' => $nom,
            'givenNames' => $prenoms,
            'birthDate' => $naissance,
        ]);
    }

    private static function personne(
        string $prenoms,
        string $nom,
        ?int $annee,
        string $etiquette = '',
        int $id = 1,
    ): PersonneDeLArbre {
        return new PersonneDeLArbre($id, $prenoms, $nom, trim($prenoms.' '.$nom), $annee, $etiquette, true);
    }
}
