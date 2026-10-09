<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Service\CleDeDoublon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La clé de rapprochement des doublons — **et la panne de plateforme qu'elle portait**.
 *
 * ## Ce que ce fichier protège
 *
 * `CleDeDoublon` repliait les accents par `iconv('UTF-8', 'ASCII//TRANSLIT')`, dont le
 * résultat dépend de la bibliothèque du système. « Génévrolle » donnait `genevrolle` sous
 * glibc, `g en evrolle` sous la libiconv de Windows (mesuré sur le poste de
 * développement le 09/10/2026), et `g n vrolle` sous la musl d'Alpine — l'image de
 * production. Deux saisies d'une même personne qui ne différaient que d'un accent ne se
 * rapprochaient plus, et rien ne le montrait : les tests existants comparaient deux
 * saisies **identiques**, qui donnent la même clé sur toute plateforme.
 *
 * D'où trois familles de cas, et il faut les trois :
 *
 * - des **exemples** — « Génévrolle » doit donner `genevrolle|…`, sur n'importe quelle
 *   machine. Sous Windows comme sous Alpine, l'ancien code échouait à celui-ci ;
 * - une **propriété** — l'alphabet de la clé est `[a-z0-9 ]`, séparé par `|`, terminé
 *   par une année ou `?`. Elle attrape un `'e` ou un `?` qu'aucune liste d'exemples ne
 *   prévoit ;
 * - un **garde-fou** — plus aucun fichier de `src/` n'emploie `TRANSLIT`. Ce dépôt l'a
 *   déjà retiré deux fois ailleurs (`Membership\Service\NormalisationDeNom`, puis ici) :
 *   la troisième occurrence doit faire rougir un test, pas attendre une panne en
 *   production.
 */
final class CleDeDoublonTest extends TestCase
{
    /**
     * Le cas qui a motivé le correctif, tel quel.
     */
    public function testGenevrolleDonneGenevrolleSurToutePlateforme(): void
    {
        self::assertSame('genevrolle|marne|1904', $this->cle('Marne Dolomite', 'Génévrolle', 1904));
    }

    /**
     * **La régression qui comptait** : la même personne saisie avec et sans le second
     * accent. Sous l'ancien code, en production, `g n vrolle` contre `g nevrolle` — deux
     * clés, aucun doublon signalé à l'administrateur.
     */
    public function testUnAccentOublieNEmpechePasLeRapprochement(): void
    {
        self::assertSame(
            $this->cle('Marne', 'Génévrolle', 1904),
            $this->cle('Marne', 'Génevrolle', 1904),
        );
        self::assertSame(
            $this->cle('Marne', 'Génévrolle', 1904),
            $this->cle('Marne', 'GENEVROLLE', 1904),
            'Les majuscules d’état civil, sans un seul accent, désignent la même personne.',
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function nomsAccentues(): iterable
    {
        yield 'aigu et grave' => ['Émeraude', 'Bérylite', 'berylite|emeraude|1900'];
        yield 'tréma' => ['Loïc', 'Noël', 'noel|loic|1900'];
        yield 'cédille' => ['François', 'Alençon', 'alencon|francois|1900'];
        yield 'circonflexe' => ['Jérôme', 'Pâquerette', 'paquerette|jerome|1900'];
        yield 'Europe centrale' => ['Łucja', 'Šimková', 'simkova|lucja|1900'];
        yield 'ligature œ' => ['Œillet', 'Bœuf', 'boeuf|oeillet|1900'];
        yield 'ligature æ' => ['Lætitia', 'Cæsar', 'caesar|laetitia|1900'];
        yield 'eszett' => ['Gretel', 'Strauß', 'strauss|gretel|1900'];
    }

    /**
     * Les accents et les ligatures que porte un arbre français, repliés **par la table
     * explicite de `Core`**. Les ligatures sont celles de `CleDeRecherche::LIGATURES` :
     * `œ` et `æ` donnent deux lettres, et c'est ce qu'un lecteur tape.
     */
    #[DataProvider('nomsAccentues')]
    public function testLesAccentsEtLesLigaturesSontReplies(string $prenoms, string $nom, string $attendue): void
    {
        self::assertSame($attendue, $this->cle($prenoms, $nom, 1900));
    }

    /**
     * Une naissance inconnue donne `?` : la clé existe quand même — deux propositions
     * sans date d'une même personne doivent se rapprocher.
     */
    public function testUnNomSansDateDeNaissanceDonneUnPointDInterrogation(): void
    {
        self::assertSame('sureau|marne|?', $this->cle('Marne Dolomite', 'Sureau', null));
    }

    /** L'année est la **borne basse** : `BET 1901 AND 1909` donne `1901` (en-tête de la classe). */
    public function testLAnneeEstLaBorneBasseDeLaNaissance(): void
    {
        $brouillon = $this->brouillon('Marne', 'Sureau');
        $brouillon->setBirth(new UncertainDate(
            'BET 1901 AND 1909',
            DateQualifier::Entre,
            new \DateTimeImmutable('1901-01-01'),
            new \DateTimeImmutable('1909-12-31'),
        ));

        self::assertSame('sureau|marne|1901', (new CleDeDoublon())->pour($brouillon));
    }

    /**
     * Le **premier** prénom seulement — et un prénom composé à trait d'union en est un
     * seul : le découpage se fait sur les espaces de la saisie, **avant** le repliement.
     */
    public function testSeulLePremierPrenomCompteEtUnPrenomComposeResteEntier(): void
    {
        self::assertSame('sureau|marne|1904', $this->cle('Marne Dolomite Augustine', 'Sureau', 1904));
        self::assertSame('sureau|jean baptiste|1904', $this->cle('Jean-Baptiste Louis', 'Sureau', 1904));
        self::assertSame('le gall etienne|anne|1904', $this->cle('  Anne   Sophie ', 'Le Gall-Étienne', 1904));
    }

    /**
     * Une clé vide regrouperait entre elles toutes les fiches sans nom : `null`, pas `||?`.
     * Un nom fait **entièrement** de caractères hors table (cyrillique) se replie en vide,
     * et tombe dans le même cas — on ne rapproche pas sur rien.
     */
    public function testUneFicheSansNomRepliableNARienDeCle(): void
    {
        self::assertNull($this->cle('', '', 1904));
        self::assertNull($this->cle('Мария', 'Иванова', 1904));
        self::assertSame('|marne|?', $this->cle('Marne', '', null), 'Un prénom seul suffit.');
    }

    /**
     * La propriété de sortie, sur un corpus : `[a-z0-9 ]` par morceau, `|` entre eux, une
     * année ou `?` à la fin. C'est le test qui verrait un `'e` (libiconv) ou un `?`
     * (musl) s'inviter **dans le nom** — le `?` de l'année inconnue, lui, est à sa place.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function corpus(): iterable
    {
        yield 'accents français' => ['àâäéèêëîïôöùûüÿç', 'ÀÂÄÉÈÊËÎÏÔÖÙÛÜŸÇ'];
        yield 'ligatures' => ['Æ æ Œ œ ß', 'Ĳ ĳ Ð ð Þ þ'];
        yield 'ponctuation' => ['d’Arc', 'Jean-Baptiste (dit « le Grand »).'];
        yield 'chiffres' => ['Louis', 'XIV 1638'];
        yield 'mélange hors table' => ['Владимир', 'Romarin 漢字 🙂'];
        yield 'espaces' => ["  Jean \t", "  de   La   Rue \n"];
    }

    #[DataProvider('corpus')]
    public function testLaCleNePorteQueDesMinusculesDesChiffresEtDesEspaces(string $prenoms, string $nom): void
    {
        foreach ([1904, null] as $annee) {
            $cle = $this->cle($prenoms, $nom, $annee);

            self::assertNotNull($cle);
            self::assertMatchesRegularExpression(
                '/^[a-z0-9 ]*\|[a-z0-9 ]*\|(\d{4}|\?)$/',
                $cle,
                \sprintf('« %s %s » a produit « %s », qui sort de l’alphabet de la clé.', $prenoms, $nom, $cle),
            );

            foreach (\array_slice(explode('|', $cle), 0, 2) as $morceau) {
                self::assertSame(trim($morceau), $morceau, 'Ni espace de tête ni de queue dans un morceau.');
                self::assertStringNotContainsString('  ', $morceau, 'Jamais deux espaces de suite.');
            }
        }
    }

    /** `prp_change_request.dedup_key` fait 160 caractères. */
    public function testLaCleEstBorneeALaLongueurDeLaColonne(): void
    {
        $cle = $this->cle('Émeraude', str_repeat('Génévrolle-', 30), 1904);

        self::assertNotNull($cle);
        self::assertLessThanOrEqual(160, mb_strlen($cle));
    }

    /**
     * **La clé d'une demande** est celle de sa première fiche qui en a une — la règle de
     * `ConstructeurDeCreation::verifierAvantSoumission()`, extraite pour que la reprise
     * (`heritage:recalculer-les-cles-de-doublon`) la partage au lieu de la recopier.
     */
    public function testLaCleDUneDemandeEstCelleDeSaPremiereFicheNommee(): void
    {
        $cles = new CleDeDoublon();

        self::assertSame(
            'genevrolle|marne|1904',
            $cles->pourLesBrouillons([
                $this->brouillon('', ''),
                $this->brouillon('Marne', 'Génévrolle', 1904),
                $this->brouillon('Opale', 'Sureau', 1930),
            ]),
            'Une fiche sans nom est sautée, la suivante donne la clé.',
        );
        self::assertNull($cles->pourLesBrouillons([$this->brouillon('', '')]));
        self::assertNull($cles->pourLesBrouillons([]));
    }

    /**
     * Le repliement est **celui de `Core`**, injecté — pas une seconde table. La clé se
     * lit donc comme `CleDeRecherche::replier()` en minuscules, et le jour où la table de
     * `Core` gagne une lettre, cette clé la gagne avec elle.
     */
    public function testLeRepliementEstCeluiDeCore(): void
    {
        $nom = 'Œillet-Génévrolle d’Alençon';

        self::assertSame(
            strtolower((new CleDeRecherche())->replier($nom)).'|marne|1904',
            $this->cle('Marne', $nom, 1904),
        );

        $source = (string) file_get_contents((string) (new \ReflectionClass(CleDeDoublon::class))->getFileName());
        self::assertStringContainsString('CleDeRecherche', $source, 'CleDeDoublon doit passer par le repliement de Core.');
        self::assertStringNotContainsString('ACCENTUES', $source, 'CleDeDoublon ne doit pas porter sa propre table de repliement.');
    }

    /**
     * **Le garde-fou.** Aucun fichier de `src/` n'emploie `TRANSLIT` hors d'un
     * commentaire.
     *
     * `iconv(…, 'ASCII//TRANSLIT')` rend `e`, `'e` ou `?` selon la bibliothèque du
     * système : le développement se fait sous Windows, la production tourne sous Alpine,
     * et la panne ne se voit que là-bas. Le projet a déjà retiré cet appel de deux
     * fichiers, chaque fois après coup ; ce test fait de la troisième fois une erreur de
     * relecture plutôt qu'une panne de production. Le remède est toujours le même :
     * `Core\Service\CleDeRecherche::replier()`.
     *
     * On lit les **jetons** PHP, pas le texte : les commentaires qui racontent pourquoi
     * `TRANSLIT` est proscrit — il y en a plusieurs, et ils doivent rester — ne comptent
     * pas. Une chaîne, une constante ou un nom qui le porte, si.
     */
    public function testAucunFichierDeSrcNEmploieIconvTranslit(): void
    {
        $racine = \dirname(__DIR__, 2).'/src';
        $balayes = 0;
        $fautes = [];

        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));

        foreach ($fichiers as $fichier) {
            \assert($fichier instanceof \SplFileInfo);

            if ('php' !== $fichier->getExtension()) {
                continue;
            }

            ++$balayes;

            foreach (\PhpToken::tokenize((string) file_get_contents($fichier->getPathname())) as $jeton) {
                if ($jeton->is([\T_COMMENT, \T_DOC_COMMENT])) {
                    continue;
                }

                if (false !== stripos($jeton->text, 'TRANSLIT')) {
                    $fautes[] = \sprintf(
                        '%s:%d  %s',
                        substr($fichier->getPathname(), \strlen($racine) + 1),
                        $jeton->line,
                        trim($jeton->text),
                    );
                }
            }
        }

        // L'assiette : un balayage devenu vide — racine déplacée, extension changée —
        // passerait au vert sans rien vérifier. 666 fichiers mesurés le 09/10/2026.
        self::assertGreaterThan(500, $balayes, \sprintf('Le balayage n’a vu que %d fichiers .php sous src/.', $balayes));

        self::assertSame(
            [],
            $fautes,
            "`TRANSLIT` hors commentaire dans src/ : le résultat d'iconv dépend du système (`e` sous glibc,\n"
            ."`'e` sous Windows, `?` sous l'Alpine de production). Repliez par Core\\Service\\CleDeRecherche::replier().\n\n  "
            .implode("\n  ", $fautes),
        );
    }

    private function cle(string $prenoms, string $nom, ?int $annee): ?string
    {
        return (new CleDeDoublon())->pour($this->brouillon($prenoms, $nom, $annee));
    }

    private function brouillon(string $prenoms, string $nom, ?int $annee = null): DraftPerson
    {
        $brouillon = new DraftPerson(new ChangeRequest(RequestKind::CreationPersonne, 11, 'Compte 11'), '#p1');
        $brouillon->setGivenNames($prenoms)->setSurname($nom);

        if (null !== $annee) {
            $brouillon->setBirth(new UncertainDate(
                (string) $annee,
                DateQualifier::Annee,
                new \DateTimeImmutable($annee.'-01-01'),
                new \DateTimeImmutable($annee.'-12-31'),
            ));
        }

        return $brouillon;
    }
}
