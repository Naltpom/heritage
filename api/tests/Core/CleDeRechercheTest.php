<?php

declare(strict_types=1);

namespace App\Tests\Core;

use App\Module\Core\Service\CleDeRecherche;
use App\Module\Tree\Serializer\NormaliseurDeRequete;
use App\Module\Tree\Service\NormalisateurNom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le repliement d'accents — **une seule définition, vérifiée des deux côtés**.
 *
 * Deux familles de tests, et il faut les deux :
 *
 * - la **propriété de sortie** : la clé ne contient que `[A-Z0-9 ]`. Elle attrape les cas
 *   qu'aucune liste d'exemples ne couvre, et c'est elle qui verrait rouge si un `'e` ou
 *   un `?` s'invitait — c'est-à-dire si quelqu'un remplaçait la table par `iconv
 *   //TRANSLIT`, dont le résultat dépend de la bibliothèque du système ;
 * - l'**aller-retour** : ce qu'écrit `Tree` en base et ce que cherche l'arbre sont la même
 *   chaîne. C'est la panne que la remontée dans `Core` supprime, et la seule qui compte
 *   vraiment : une clé juste des deux côtés mais calculée deux fois se met à diverger un
 *   jour, et personne ne le voit.
 */
final class CleDeRechercheTest extends TestCase
{
    /**
     * L'aller-retour, sur les noms accentués de l'arbre.
     *
     * Écrire avec `NormalisateurNom`, chercher avec `NormaliseurDeRequete`, et retrouver la même chose. Les deux
     * classes sont instanciées telles qu'elles le sont en production.
     */
    #[DataProvider('nomsDeLArbre')]
    public function testEcrirePuisChercherDonneLaMemeCle(string $prenoms, string $nom): void
    {
        $ecrite = (new NormalisateurNom(new CleDeRecherche()))->cleDeRecherche($prenoms, $nom);
        // Ce que tape un membre dans la barre de recherche : le nom, avec ses accents.
        $cherchee = (new NormaliseurDeRequete())->cle($nom);

        self::assertNotSame('', $ecrite);
        self::assertStringStartsWith(
            $cherchee,
            $ecrite,
            \sprintf('« %s » doit retrouver la fiche de %s %s — `search_key` est un index de préfixe.', $nom, $prenoms, $nom),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function nomsDeLArbre(): iterable
    {
        // Les noms accentués présents dans l'arbre : ce sont eux qui
        // disparaîtraient d'une recherche si les deux tables divergeaient.
        yield 'Épine' => ['Jade Bérylite', 'Épine'];
        yield 'Génévrolle' => ['Marne', 'Génévrolle'];
        yield 'Sarriette' => ['Émeraude', 'Sarriette'];
        yield 'Chardon' => ['Jargon', 'Chardon'];
        yield 'nom composé' => ['Anne Sophie', 'Le Gall-Étienne'];
        yield 'ligature' => ['Lætitia', 'Bœuf'];
        yield 'tréma' => ['Loïc', 'Noël'];
        yield 'cédille' => ['François', 'Alençon'];
    }

    /**
     * La propriété de sortie : `[A-Z0-9 ]`, rien d'autre, jamais.
     *
     * C'est le test qui survit à un changement d'implémentation. Un `'e` (libiconv,
     * Windows) ou un `?` (musl, Alpine) le ferait tomber immédiatement.
     */
    #[DataProvider('corpusDeCaracteres')]
    public function testLaCleNeContientQueDesMajusculesDesChiffresEtDesEspaces(string $entree): void
    {
        $cle = (new CleDeRecherche())->replier($entree);

        self::assertMatchesRegularExpression(
            '/^[A-Z0-9 ]*$/',
            $cle,
            \sprintf('« %s » a produit « %s », qui sort de [A-Z0-9 ].', $entree, $cle),
        );
        self::assertSame(trim($cle), $cle, 'Ni espace de tête, ni espace de queue.');
        self::assertStringNotContainsString('  ', $cle, 'Jamais deux espaces de suite.');
    }

    /** @return iterable<string, array{string}> */
    public static function corpusDeCaracteres(): iterable
    {
        yield 'accents français' => ['àâäéèêëîïôöùûüÿç ÀÂÄÉÈÊËÎÏÔÖÙÛÜŸÇ'];
        yield 'ligatures' => ['Æ æ Œ œ ß Ĳ ĳ Ð ð Þ þ'];
        yield 'diacritiques d’Europe centrale' => ['ŠšŽžĀāĒēĪīŌōŪūŁłĆćŚśŹźŻż'];
        yield 'ponctuation' => ['d’Arc, Jean-Baptiste (dit « le Grand »).'];
        yield 'chiffres' => ['Louis XIV 1638'];
        yield 'espaces multiples' => ["  Jean   \t Baptiste \n "];
        yield 'vide' => [''];
        yield 'ponctuation seule' => ['--- ... ///'];
        yield 'caractères hors table' => ['Владимир 漢字 🙂'];
    }

    /**
     * Le cas que la propriété seule ne dirait pas : un caractère hors table ne doit pas
     * *survivre*, il doit devenir un espace. Sinon une clé garderait des octets
     * indexables mais introuvables au clavier.
     */
    public function testUnCaractereInconnuNeSurvitPas(): void
    {
        $cle = (new CleDeRecherche())->replier('Мария Romarin');

        self::assertSame('ROMARIN', $cle);
    }

    public function testLeNomPasseAvantLesPrenoms(): void
    {
        // `idx_tree_person_search` est un index de **préfixe** : l'ordre inverse le
        // rendrait inutilisable pour les `LIKE 'BOURRACHE%'` du rattachement.
        self::assertSame(
            'BOURRACHE MARNE DOLOMITE',
            (new CleDeRecherche())->cleDeNom('Marne Dolomite', 'Bourrache'),
        );
    }

    public function testLaCleEstBorneeALaLongueurDeLaColonne(): void
    {
        $cle = (new CleDeRecherche())->replier(str_repeat('Émeraude ', 60));

        self::assertLessThanOrEqual(CleDeRecherche::LONGUEUR_MAXIMALE, mb_strlen($cle));
    }

    /**
     * Le **nom d'usage** entre dans la clé, en complément et jamais en remplacement.
     *
     * 97 fiches de la base de travail en portent un, et 96 étaient introuvables sous ce
     * nom-là — celui que la famille emploie, et celui que l'écran nomme désormais « Nom
     * d'usage ». Le défaut est celui du surnom, à trente fois l'échelle.
     */
    public function testLeNomDUsageEntreDansLaCle(): void
    {
        $cle = (new CleDeRecherche())->cleDeNom('Marne Dolomite', 'Bourrache', null, 'Sureau');

        self::assertSame('BOURRACHE MARNE DOLOMITE SUREAU', $cle);
    }

    /**
     * **Le nom de naissance reste en tête.** C'est lui que portent les actes, et
     * `idx_tree_person_search` est un index de préfixe : le déloger casserait la
     * recherche par patronyme, qui est le cas courant. Ajouter n'est pas remplacer.
     */
    public function testLeNomDeNaissanceResteEnTeteMalgreLeNomDUsage(): void
    {
        $cle = (new CleDeRecherche())->cleDeNom('Marne', 'Bourrache', null, 'Sureau');

        self::assertStringStartsWith('BOURRACHE', $cle);
        // La personne reste trouvable par son nom de naissance **et** par son nom d'usage.
        self::assertStringContainsString('BOURRACHE', $cle);
        self::assertStringContainsString('SUREAU', $cle);
    }

    /** Le surnom et le nom d'usage coexistent, chacun à sa place, sans se chasser. */
    public function testLeSurnomEtLeNomDUsageTiennentEnsemble(): void
    {
        $cle = (new CleDeRecherche())->cleDeNom('Marne', 'Bourrache', 'Cobalt', 'Sureau');

        self::assertSame('BOURRACHE MARNE COBALT SUREAU', $cle);
    }

    /**
     * La déduplication vaut pour lui comme pour le surnom : un nom d'usage qui reprend
     * un mot déjà présent n'allonge pas la clé. Ce n'est pas une économie d'octets —
     * `Person` porte un `#[ORM\Version]`, et toute ligne réécrite périme les demandes en
     * attente qui la visaient (§ 9.6).
     */
    public function testUnNomDUsageDejaPresentNeDedoublePasLaCle(): void
    {
        $cle = (new CleDeRecherche())->cleDeNom('Marne', 'Bourrache', null, 'Bourrache');

        self::assertSame('BOURRACHE MARNE', $cle);
    }

    /** Une fiche sans nom d'usage rend exactement ce qu'elle rendait avant. */
    public function testSansNomDUsageLaCleEstInchangee(): void
    {
        $cles = new CleDeRecherche();

        self::assertSame(
            $cles->cleDeNom('Marne Dolomite', 'Bourrache', 'Cobalt'),
            $cles->cleDeNom('Marne Dolomite', 'Bourrache', 'Cobalt', null),
        );
        self::assertSame(
            $cles->cleDeNom('Marne Dolomite', 'Bourrache', 'Cobalt'),
            $cles->cleDeNom('Marne Dolomite', 'Bourrache', 'Cobalt', ''),
        );
    }

    /**
     * La garantie structurelle : les deux consommateurs passent par la **même** classe.
     *
     * Une liste d'exemples ne prouve que les exemples ; cette assertion-ci prouve qu'il
     * n'existe plus deux tables à faire diverger, ce qui est l'objet du déplacement.
     */
    public function testLesDeuxConsommateursNontPlusDeTableAEux(): void
    {
        foreach ([NormalisateurNom::class, NormaliseurDeRequete::class] as $classe) {
            $source = (string) file_get_contents((new \ReflectionClass($classe))->getFileName() ?: '');

            self::assertStringNotContainsString('ACCENTUES', $source, $classe.' ne doit plus porter de table de repliement.');
            self::assertStringNotContainsString('LIGATURES', $source, $classe.' ne doit plus porter de table de ligatures.');
            self::assertStringContainsString('CleDeRecherche', $source, $classe.' doit passer par le repliement de Core.');
        }
    }
}
