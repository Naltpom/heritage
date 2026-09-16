<?php

declare(strict_types=1);

namespace App\Tests\Frontieres;

/**
 * Lit `src/Module/` et rend les références inter-modules, avec leur ventilation.
 *
 * ## Pourquoi un analyseur de jetons et non une expression rationnelle
 *
 * La première mesure de cette dette a été faite par un script hors dépôt qui
 * cherchait `^use App\Module\…` ligne par ligne. Il donnait le bon total, et il
 * avait trois angles morts qu'aucune relecture ne voit :
 *
 *   1. `use App\Module\Tree\{Entity\Person, Enum\Sex};` — un `use` de groupe
 *      compte pour zéro ligne correspondante ;
 *   2. `\App\Module\Tree\Entity\Person::class` écrit en clair dans le corps du
 *      code — même franchissement, aucun `use` ;
 *   3. `@param \App\Module\Tree\Entity\Person` dans un bloc de documentation —
 *      compté à tort par une recherche de texte, alors qu'il ne dépend de rien.
 *
 * `PhpToken::tokenize()` tranche les trois du même geste : les commentaires sont
 * des jetons qu'on ignore, les `use` de groupe sont déroulés, et un nom
 * pleinement qualifié est un jeton nommé `T_NAME_FULLY_QUALIFIED` qu'on ne peut
 * pas rater. Sans cela le contrôle se contourne par accident, ce qui est pire
 * qu'un contrôle absent : il rassure.
 *
 * ## Ce que ce balayage ne peut PAS voir — la borne de sa couverture
 *
 * - **Une classe construite depuis une chaîne** : `new ('App\\Module\\'.$x)`,
 *   `class_exists('App\Module\Tree\Entity\Person')`, un nom de service en YAML.
 *   Le test `testAucunNomDeModuleNEstEcritEnChaineDeCaracteres` ferme cette porte
 *   pour le code PHP ; la configuration, elle, n'est pas balayée ici.
 * - **Un nom relatif** — `Entity\Person` dans un fichier de `Tree` — résout dans
 *   l'espace de noms courant, donc dans le module lui-même. Il ne peut pas
 *   franchir une frontière, et il n'est pas compté.
 * - **La configuration** : `config/services/*.yaml` cite des FQCN de module, et
 *   un câblage y traverse une frontière sans qu'aucun `use` ne le montre. C'est
 *   un autre contrôle, qui n'est pas écrit.
 */
final class BalayageDesFrontieres
{
    private const string PREFIXE = 'App\\Module\\';

    /** Le noyau partagé est en liste blanche : tout module peut le citer. */
    private const string NOYAU = 'Core';

    /** @var list<string> */
    private array $modules = [];

    /** @var list<ReferenceInterModules> */
    private array $references = [];

    /** @var array<string, int> */
    private array $fichiersParModule = [];

    /**
     * Toute référence partant d'un fichier situé dans un dossier `Contract/`,
     * y compris vers son propre module et vers `Core`. Sert au contrôle
     * « un contrat ne transporte ni entité ni dépôt ».
     *
     * @var list<array{fichier: string, ligne: int, cible: string}>
     */
    private array $depuisLesContrats = [];

    /**
     * Les chaînes de caractères qui nomment un module.
     *
     * `class_exists('App\Module\Tree\Entity\Person')` ou
     * `new ('App\\Module\\'.$module.'\\Service\\X')` franchissent la frontière
     * sans produire le moindre jeton de nom : aucun analyseur statique ne les
     * voit. On ne les résout pas — on interdit d'en écrire, ce qui est la seule
     * façon de garder le contrôle honnête.
     *
     * @var list<array{fichier: string, ligne: int, texte: string}>
     */
    private array $chainesNommantUnModule = [];

    private int $fichiersBalayes = 0;

    private int $fichiersDeContrat = 0;

    /**
     * Tout nom commençant par `App\Module\` rencontré, y compris vers son propre
     * module et vers `Core`.
     *
     * C'est le **témoin de vie** du balayage. Le nombre de références
     * inter-modules a vocation à tomber à zéro : s'en servir comme plancher
     * reviendrait à casser le test le jour où il a réussi. Ce compteur-ci, lui,
     * ne descend pas — il mesure que l'analyseur lit encore quelque chose.
     */
    private int $nomsApplicatifsLus = 0;

    private function __construct(private readonly string $racine)
    {
    }

    public static function depuis(string $racineDesModules): self
    {
        $balayage = new self(rtrim(str_replace('\\', '/', $racineDesModules), '/'));
        $balayage->executer();

        return $balayage;
    }

    /** @return list<string> */
    public function modules(): array
    {
        return $this->modules;
    }

    /** @return list<ReferenceInterModules> */
    public function references(): array
    {
        return $this->references;
    }

    /** @return list<array{fichier: string, ligne: int, cible: string}> */
    public function referencesDepuisLesContrats(): array
    {
        return $this->depuisLesContrats;
    }

    /** @return list<array{fichier: string, ligne: int, texte: string}> */
    public function chainesNommantUnModule(): array
    {
        return $this->chainesNommantUnModule;
    }

    public function fichiersBalayes(): int
    {
        return $this->fichiersBalayes;
    }

    public function fichiersDeContrat(): int
    {
        return $this->fichiersDeContrat;
    }

    public function nomsApplicatifsLus(): int
    {
        return $this->nomsApplicatifsLus;
    }

    /** @return array<string, int> */
    public function fichiersParModule(): array
    {
        return $this->fichiersParModule;
    }

    /**
     * La ventilation demandée par l'ADR : **par couple de modules ET par couche
     * ciblée**. C'est la seconde qui rend un écart diagnosticable — « Gedcom
     * cite Tree 22 fois » n'apprend rien, « dont 7 fois ses entités » désigne le
     * fichier à ouvrir.
     *
     * @param list<ReferenceInterModules> $nonExemptees
     */
    public function rapport(array $nonExemptees = []): string
    {
        $licites = array_values(array_filter($this->references, static fn (ReferenceInterModules $r): bool => $r->estLicite()));
        $illicites = array_values(array_filter($this->references, static fn (ReferenceInterModules $r): bool => !$r->estLicite()));

        $lignes = [
            'Balayage des frontières inter-modules — src/Module',
            \sprintf('  fichiers .php balayés            : %d', $this->fichiersBalayes),
            \sprintf('  par module                       : %s', $this->decrireLesFichiersParModule()),
            \sprintf('  noms « App\\Module\\… » lus         : %d  (toutes cibles, y compris son propre module et Core)', $this->nomsApplicatifsLus),
            '',
            \sprintf('  références inter-modules hors Core : %d', \count($this->references)),
            \sprintf('      licites (cible « Contract\\ »)   : %d', \count($licites)),
            \sprintf('      illicites                       : %d', \count($illicites)),
            \sprintf('          exemptées nommément         : %d', \count($illicites) - \count($nonExemptees)),
            \sprintf('          NON exemptées               : %d', \count($nonExemptees)),
            '',
            'Ventilation par couple de modules et par couche ciblée',
        ];

        /** @var array<string, list<ReferenceInterModules>> $parCouple */
        $parCouple = [];
        foreach ($this->references as $reference) {
            $parCouple[$reference->couple()][] = $reference;
        }

        uasort($parCouple, static fn (array $a, array $b): int => \count($b) <=> \count($a));

        foreach ($parCouple as $couple => $groupe) {
            /** @var array<string, int> $couches */
            $couches = [];
            $licitesDuCouple = 0;
            foreach ($groupe as $reference) {
                $couches[$reference->coucheCible] = ($couches[$reference->coucheCible] ?? 0) + 1;
                if ($reference->estLicite()) {
                    ++$licitesDuCouple;
                }
            }
            arsort($couches);

            $detail = [];
            foreach ($couches as $couche => $nombre) {
                $detail[] = $couche.' '.$nombre;
            }

            $lignes[] = \sprintf(
                '  %-24s : %2d   %-46s  licites %d / illicites %d',
                $couple,
                \count($groupe),
                implode(', ', $detail),
                $licitesDuCouple,
                \count($groupe) - $licitesDuCouple,
            );
        }

        return implode("\n", $lignes);
    }

    private function decrireLesFichiersParModule(): string
    {
        $morceaux = [];
        foreach ($this->fichiersParModule as $module => $nombre) {
            $morceaux[] = $module.' '.$nombre;
        }

        return implode(', ', $morceaux);
    }

    private function executer(): void
    {
        $dossiers = glob($this->racine.'/*', \GLOB_ONLYDIR);

        if (false === $dossiers) {
            throw new \RuntimeException(\sprintf('Le dossier des modules est illisible : %s', $this->racine));
        }

        sort($dossiers);

        foreach ($dossiers as $dossier) {
            $module = basename($dossier);
            $this->modules[] = $module;
            $this->fichiersParModule[$module] = 0;

            $entrees = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($entrees as $entree) {
                \assert($entree instanceof \SplFileInfo);

                if (!$entree->isFile() || 'php' !== $entree->getExtension()) {
                    continue;
                }

                $chemin = str_replace('\\', '/', $entree->getPathname());
                $relatif = substr($chemin, \strlen($this->racine) + 1);

                ++$this->fichiersBalayes;
                ++$this->fichiersParModule[$module];

                if (str_contains($relatif, '/Contract/')) {
                    ++$this->fichiersDeContrat;
                }

                $code = file_get_contents($chemin);

                if (false === $code) {
                    throw new \RuntimeException(\sprintf('Fichier illisible : %s', $chemin));
                }

                $this->analyser(
                    $relatif,
                    $module,
                    self::PREFIXE.str_replace('/', '\\', substr($relatif, 0, -4)),
                    $code,
                );
            }
        }
    }

    private function analyser(string $relatif, string $moduleSource, string $classeSource, string $code): void
    {
        /** @var list<\PhpToken> $jetons */
        $jetons = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $jeton): bool => !$jeton->isIgnorable(),
        ));

        $nombre = \count($jetons);

        for ($i = 0; $i < $nombre; ++$i) {
            $jeton = $jetons[$i];

            // La déclaration d'espace de noms n'est pas une dépendance.
            if ($jeton->is(\T_NAMESPACE)) {
                while ($i < $nombre && ';' !== $jetons[$i]->text && '{' !== $jetons[$i]->text) {
                    ++$i;
                }

                continue;
            }

            if ($jeton->is(\T_USE)) {
                $i = $this->lireImports($jetons, $i, $relatif, $moduleSource, $classeSource);

                continue;
            }

            // `\App\Module\Tree\Entity\Person::class` écrit en clair : même
            // franchissement qu'un `use`, et invisible à une recherche de `use`.
            if ($jeton->is(\T_NAME_FULLY_QUALIFIED)) {
                $this->enregistrer(ltrim($jeton->text, '\\'), $jeton->line, true, $relatif, $moduleSource, $classeSource);

                continue;
            }

            if ($jeton->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])
                && str_contains(str_replace('\\\\', '\\', $jeton->text), self::PREFIXE)
            ) {
                $this->chainesNommantUnModule[] = ['fichier' => $relatif, 'ligne' => $jeton->line, 'texte' => $jeton->text];
            }
        }
    }

    /**
     * Lit une instruction `use` à partir du mot-clé et rend l'indice du dernier
     * jeton consommé.
     *
     * Traite les quatre formes : simple, multiple (`A, B`), aliasée (`A as B`) et
     * de groupe (`A\{B, C as D}`), ainsi que `use function` / `use const`. Deux
     * formes qui ne sont PAS des imports sont écartées : la clause `use ($var)`
     * d'une fonction anonyme, et l'insertion d'un trait dans un corps de classe
     * — cette dernière est traitée comme un import, ce qui est correct : un trait
     * d'un autre module franchit la frontière comme n'importe quelle classe.
     *
     * @param list<\PhpToken> $jetons
     */
    private function lireImports(array $jetons, int $depart, string $relatif, string $moduleSource, string $classeSource): int
    {
        $nombre = \count($jetons);
        $i = $depart + 1;

        if ($i < $nombre && '(' === $jetons[$i]->text) {
            return $i; // clause `use ($var)` d'une fonction anonyme
        }

        if ($i < $nombre && $jetons[$i]->is([\T_FUNCTION, \T_CONST])) {
            ++$i;
        }

        while ($i < $nombre) {
            $jeton = $jetons[$i];

            if (';' === $jeton->text) {
                return $i;
            }

            // Une accolade rencontrée hors d'un `use` de groupe est le bloc
            // d'adaptation d'un trait : l'import est terminé.
            if ('{' === $jeton->text) {
                return $i;
            }

            if ($jeton->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_STRING])) {
                $nom = ltrim($jeton->text, '\\');

                // `use App\Module\Tree\{Entity\Person, Enum\Sex};`
                if ($i + 2 < $nombre && $jetons[$i + 1]->is(\T_NS_SEPARATOR) && '{' === $jetons[$i + 2]->text) {
                    $i += 3;

                    while ($i < $nombre && '}' !== $jetons[$i]->text) {
                        $partie = $jetons[$i];

                        if ($partie->is([\T_NAME_QUALIFIED, \T_STRING])) {
                            $this->enregistrer($nom.'\\'.$partie->text, $partie->line, false, $relatif, $moduleSource, $classeSource);
                        }

                        if ($partie->is(\T_AS)) {
                            ++$i; // saute l'alias
                        }

                        ++$i;
                    }

                    ++$i; // l'accolade fermante

                    continue;
                }

                $this->enregistrer($nom, $jeton->line, false, $relatif, $moduleSource, $classeSource);
                ++$i;

                continue;
            }

            if ($jeton->is(\T_AS)) {
                $i += 2; // `as` puis l'alias

                continue;
            }

            ++$i; // virgules et `function` / `const` internes à un groupe
        }

        return $nombre - 1;
    }

    private function enregistrer(string $nomComplet, int $ligne, bool $pleinementQualifie, string $relatif, string $moduleSource, string $classeSource): void
    {
        if (!str_starts_with($nomComplet, self::PREFIXE)) {
            return;
        }

        ++$this->nomsApplicatifsLus;

        if (str_contains($relatif, '/Contract/')) {
            $this->depuisLesContrats[] = ['fichier' => $relatif, 'ligne' => $ligne, 'cible' => $nomComplet];
        }

        $reste = substr($nomComplet, \strlen(self::PREFIXE));
        $coupure = strpos($reste, '\\');

        if (false === $coupure) {
            return; // `App\Module\Quelquechose` : pas un module
        }

        $moduleCible = substr($reste, 0, $coupure);
        $chemin = substr($reste, $coupure + 1);

        if ($moduleCible === $moduleSource || self::NOYAU === $moduleCible) {
            return;
        }

        $separateur = strpos($chemin, '\\');

        $this->references[] = new ReferenceInterModules(
            moduleSource: $moduleSource,
            moduleCible: $moduleCible,
            fichier: $relatif,
            classeSource: $classeSource,
            classeCible: $nomComplet,
            coucheCible: false === $separateur ? '(racine du module)' : substr($chemin, 0, $separateur),
            ligne: $ligne,
            nomPleinementQualifie: $pleinementQualifie,
        );
    }
}
