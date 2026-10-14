<?php

declare(strict_types=1);

namespace App\Tests\Contrat;

use PHPUnit\Framework\TestCase;

/**
 * Relit **tout le texte du dépôt** — documentation, sources, tests, fixtures —
 * et vérifie qu'aucune fourchette d'années n'y est écrite à l'envers.
 *
 * ## Pourquoi ce test existe
 *
 * Une fourchette s'écrit dans ce projet sous quatre formes, et les quatre
 * portent **deux bornes** que rien, jusqu'ici, n'obligeait à rester ordonnées :
 *
 * | forme            | exemple                | où                                    |
 * |------------------|------------------------|---------------------------------------|
 * | GEDCOM           | `BET 1901 AND 1909`    | fixtures, contrat, saisie (`raw`)     |
 * | compacte (§ 14.4)| `1901-1909`            | `ligneDates`, `etiquetteDates`        |
 * | prose            | « entre 1901 et 1909 » | `UncertainDate::format()`, les docs   |
 * | ligne complète   | `1901-1909 – 1987`     | fiche, recherche, barre de chronologie|
 *
 * Le défaut qu'il attrape est celui d'une **édition qui ne touche qu'une des
 * deux bornes**. C'est exactement ce qui arrive quand on décale les millésimes
 * d'un corpus : la passe remplace la borne basse partout, et si elle rate la
 * borne haute — ou l'atteint deux fois — la fourchette se referme à l'envers, ou
 * la personne meurt avant de finir de naître.
 *
 * (Ce commentaire n'écrit exprès aucune fourchette inversée en toutes lettres :
 * ce test relit le dépôt entier, **le fichier où il est écrit compris**, et la
 * première version de ce docblock l'a fait rougir sur son propre exemple. On a
 * gardé le contrôle et réécrit la phrase, pas l'inverse — s'exclure soi-même de
 * son assiette, c'est se ménager le seul angle mort qu'on est sûr d'occuper.)
 *
 * Aucune relecture ne voit ça : les deux nombres sont
 * plausibles, seul leur **ordre** est faux, et l'œil lit une fourchette sans la
 * comparer. Un rendu ne rougit pas non plus — le moteur écrit consciencieusement
 * l'intervalle inversé sur l'étiquette.
 *
 * ## Pourquoi un test, et pas un script sous `bin/`
 *
 * Parce qu'un script s'exécute quand quelqu'un y pense. Le contrôle qui a
 * manqué, la nuit où ces fourchettes ont bougé, n'était pas un contrôle absent :
 * c'était un balayage lancé à la main, tronqué à `head -120`, qui examinait un
 * huitième du corpus et rendait le même verdict qu'un balayage complet. **Un
 * contrôle qui dépend qu'on se souvienne de lui ne contrôle rien**, et un
 * contrôle qui ne dit pas quelle assiette il a parcourue ment par omission.
 *
 * D'où la forme d'ici : l'assiette est **découverte** (personne ne maintient une
 * liste de fichiers à la main), et sa taille est **affirmée** (le jour où le
 * motif ne trouve plus rien, ce test tombe au lieu de passer au vert sur le
 * vide). Chacun des trois tests porte son propre plancher, pour qu'aucun ne
 * puisse devenir muet sans que les autres s'en aperçoivent.
 *
 * ## Ce qu'il ne peut pas voir — la borne de sa couverture
 *
 * **Il vérifie un ordre, jamais une valeur.** `1901-1909` et `1801-1809` lui sont
 * indistinguables : les deux sont ordonnées. Il ne sait pas qu'une fourchette a
 * été décalée, ni de combien, ni si elle correspond à la base ; il sait
 * seulement qu'elle n'a pas été décalée **à moitié**. La moitié est le cas
 * fréquent ; ce n'est pas le seul.
 *
 * **Il ne voit que ce qui est écrit en clair.** Une fourchette construite à
 * l'exécution — `sprintf('BET %s AND %s', ...)`, un gabarit JS — est ignorée : il
 * n'y a pas d'années à comparer. C'est la même lacune que celle de
 * `InvariantsDuContratTest`, et elle appelle le même remède : **ajouter
 * l'exemple littéral, pas documenter le trou** — un trou documenté reste un
 * trou, et le commentaire qui le décrit ne rougira jamais.
 *
 * Le remède a été appliqué, pas seulement recommandé. Deux fonctions du moteur
 * composaient une fourchette à l'exécution sans qu'aucun littéral de leur sortie
 * n'existe nulle part :
 *
 * - `jolie_date()`, `docs/moteur-retrouve/build_data.py` — assemble la prose
 *   « entre … et … » par substitution ;
 * - `ligne_dates()`, `docs/moteur-retrouve/graphe.py` — assemble la forme
 *   complète `naissance – décès`, c'est-à-dire précisément ce que garde le
 *   troisième test d'ici.
 *
 * Les deux portent désormais un exemple littéral en tête, sur le modèle de
 * `jolie()` qui en avait déjà un. Le contrôle a prise sur elles ; il ne l'avait
 * pas. Le côté front (`dates.ts`, qui recompose `BET … AND …` depuis deux
 * bornes) était déjà couvert par les littéraux de ses propres tests.
 *
 * **Il ne connaît pas les dates hors années.** `11.3.1930`, `1/1920`, `~1878`,
 * `<1920`, `>1900` n'ont qu'une borne ; il n'y a rien à ordonner et il les laisse
 * passer. Leur grammaire est gardée ailleurs, par `InvariantsDuContratTest`
 * (invariant 2) et `GrammaireDesLignesDeDatesTest`.
 *
 * @see InvariantsDuContratTest pour la grammaire des mêmes étiquettes
 */
final class CoherenceDesFourchettesTest extends TestCase
{
    /**
     * Planchers d'assiette. Tout contrôle qui itère sur un ensemble découvert
     * doit **affirmer la taille de cet ensemble** : sans cela, un motif cassé,
     * un dossier déplacé ou une extension oubliée le rendraient muet au lieu de
     * le faire tomber — et un contrôle muet est pire qu'absent, puisqu'il occupe
     * la place.
     *
     * Valeurs constatées au 2026-09-16, **mesurées par ce test lui-même** et non
     * par un balayage annexe : 607 fichiers explorés, 116 fourchettes GEDCOM,
     * 77 compactes, 82 en prose, 36 lignes naissance–décès. Les planchers sont
     * posés à peu près un cinquième en dessous, pour qu'ajouter ou retirer un
     * exemple ne casse rien ; les abaisser demande de dire pourquoi.
     *
     * Pour les relire après une évolution du corpus, il suffit de porter ces
     * cinq constantes à une valeur inatteignable : les deux messages d'échec
     * impriment le relevé complet, forme par forme.
     */
    private const MINIMUM_FICHIERS_EXPLORES = 450;
    private const MINIMUM_FOURCHETTES_GEDCOM = 95;
    private const MINIMUM_FOURCHETTES_COMPACTES = 60;
    private const MINIMUM_FOURCHETTES_EN_PROSE = 65;
    private const MINIMUM_LIGNES_NAISSANCE_DECES = 28;

    /**
     * Fenêtre de plausibilité d'un millésime. Elle sert à **écarter le bruit**,
     * pas à valider une donnée : `ISO-8859-?`, `CVE-2021-1234`, un numéro de
     * cote ou un identifiant à tirets tombent dehors et ne sont jamais comparés.
     *
     * 1600 couvre largement l'arbre — son premier millésime est 1644 — et 2099
     * laisse le siècle courant tranquille.
     */
    private const ANNEE_MINIMALE = 1600;
    private const ANNEE_MAXIMALE = 2099;

    /** Racines explorées, avec les extensions qui y portent du texte à contrôler. */
    private const RACINES = [
        'docs' => ['md', 'py'],
        'api/src' => ['php'],
        'api/tests' => ['php'],
        'app/src' => ['ts', 'vue'],
    ];

    /** Fichiers isolés à la racine du dépôt. */
    private const FICHIERS_ISOLES = ['README.md', 'compose.yaml', 'compose.prod.yaml'];

    /** Jamais descendus : ce n'est pas notre texte, et les licences y sont pleines de « 2014-2017 ». */
    private const DOSSIERS_EXCLUS = ['node_modules', 'vendor', 'var', 'dist', 'build', '__pycache__', '.git', '.venv'];

    /** @var list<array{fichier: string, ligne: int, forme: string, brut: string, basse: int, haute: int}> */
    private array $fourchettes = [];

    /** @var list<array{fichier: string, ligne: int, brut: string, naissance: int, deces: int}> */
    private array $lignesDeDates = [];

    private int $fichiersExplores = 0;

    protected function setUp(): void
    {
        foreach ($this->corpus() as $chemin) {
            $texte = @file_get_contents($chemin);
            if (!\is_string($texte)) {
                continue;
            }

            ++$this->fichiersExplores;
            $relatif = $this->relatif($chemin);

            foreach (preg_split('/\R/', $texte) ?: [] as $index => $ligne) {
                $numero = $index + 1;
                $this->releverGedcom($relatif, $numero, $ligne);
                $this->releverCompactes($relatif, $numero, $ligne);
                $this->releverProse($relatif, $numero, $ligne);
                $this->releverLignesDeDates($relatif, $numero, $ligne);
            }
        }
    }

    /**
     * Garde-fou d'assiette. Si la découverte ne trouve plus le corpus, ce test
     * tombe ici, et les deux suivants ne mentent pas en passant sur du vide.
     */
    public function testLeCorpusEstDecouvertEtChaqueFormeEstRepresentee(): void
    {
        self::assertGreaterThanOrEqual(
            self::MINIMUM_FICHIERS_EXPLORES,
            $this->fichiersExplores,
            \sprintf(
                "Seulement %d fichiers explorés, plancher %d.\n".
                "Racines : %s ; fichiers isolés : %s.\n".
                'Soit une racine a bougé, soit une extension a changé — dans les deux cas '.
                'les contrôles ci-dessous deviendraient muets.',
                $this->fichiersExplores,
                self::MINIMUM_FICHIERS_EXPLORES,
                implode(', ', array_keys(self::RACINES)),
                implode(', ', self::FICHIERS_ISOLES),
            ),
        );

        $attendus = [
            'gedcom' => self::MINIMUM_FOURCHETTES_GEDCOM,
            'compacte' => self::MINIMUM_FOURCHETTES_COMPACTES,
            'prose' => self::MINIMUM_FOURCHETTES_EN_PROSE,
        ];

        // On relève **toutes** les formes avant d'affirmer, plutôt que de tomber
        // sur la première : quand un motif casse, savoir si les trois sont
        // muettes ou une seule est la moitié du diagnostic.
        $maigres = [];
        $releve = [];

        foreach ($attendus as $forme => $plancher) {
            $trouvees = \count(array_filter($this->fourchettes, static fn (array $f): bool => $forme === $f['forme']));
            $releve[] = \sprintf('%s : %d (plancher %d)', $forme, $trouvees, $plancher);

            if ($trouvees < $plancher) {
                $maigres[] = \sprintf('  forme « %s » : %d relevées, plancher %d', $forme, $trouvees, $plancher);
            }
        }

        self::assertSame(
            [],
            $maigres,
            \sprintf(
                "Assiette insuffisante sur %d fichiers explorés.\n\n%s\n\n".
                "Relevé complet — %s ; lignes naissance–décès : %d.\n".
                'Un motif ne trouve plus ce qu\'il trouvait : il est cassé, ou la forme a disparu '.
                'du corpus. Une forme que rien ne relève n\'est gardée par personne.',
                $this->fichiersExplores,
                implode("\n", $maigres),
                implode(' ; ', $releve),
                \count($this->lignesDeDates),
            ),
        );
    }

    /**
     * L'invariant : dans toute fourchette écrite en clair, la borne basse est
     * antérieure ou égale à la borne haute.
     *
     * L'égalité est permise, et elle est attestée — `BET 1930 AND 1930`,
     * `BET 11 MAR 1930 AND 11 MAR 1930` : le GEDCOM écrit une fourchette d'un
     * jour comme une fourchette. Ce n'est pas une anomalie, c'est une source qui
     * datait au jour près sans le dire.
     */
    public function testAucuneFourchetteNEstInversee(): void
    {
        $inversees = [];

        foreach ($this->fourchettes as $f) {
            if ($f['basse'] > $f['haute']) {
                $inversees[] = \sprintf(
                    '  %s:%d — fourchette %s inversée : « %s » (borne basse %d postérieure à la borne haute %d, écart %d an%s)',
                    $f['fichier'],
                    $f['ligne'],
                    $f['forme'],
                    $f['brut'],
                    $f['basse'],
                    $f['haute'],
                    $f['basse'] - $f['haute'],
                    1 === $f['basse'] - $f['haute'] ? '' : 's',
                );
            }
        }

        self::assertSame(
            [],
            $inversees,
            \sprintf(
                "%d fourchette(s) inversée(s) sur %d relevées dans %d fichiers :\n\n%s\n\n".
                "Une borne a été éditée sans l'autre. Corrigez la borne fautive — ne relâchez pas ce test : ".
                "il ne compare que deux nombres déjà écrits côte à côte, il n'a pas de faux positif possible ".
                'une fois la fenêtre de plausibilité (%d-%d) passée.',
                \count($inversees),
                \count($this->fourchettes),
                $this->fichiersExplores,
                implode("\n", $inversees),
                self::ANNEE_MINIMALE,
                self::ANNEE_MAXIMALE,
            ),
        );

        // Plancher propre à ce test : il ne doit pas pouvoir passer sur du vide.
        self::assertGreaterThanOrEqual(
            self::MINIMUM_FOURCHETTES_GEDCOM + self::MINIMUM_FOURCHETTES_COMPACTES + self::MINIMUM_FOURCHETTES_EN_PROSE,
            \count($this->fourchettes),
            \sprintf('Seulement %d fourchettes comparées — assiette insuffisante, voir le test d\'assiette.', \count($this->fourchettes)),
        );
    }

    /**
     * Le même invariant, un cran plus loin : dans une ligne complète
     * `1901-1909 – 1987`, la borne **haute** de la naissance doit rester
     * antérieure ou égale à l'année du décès.
     *
     * C'est la seconde moitié du défaut : décaler la naissance sans décaler le
     * décès laisse deux fourchettes parfaitement ordonnées chacune de son côté,
     * et une personne qui meurt avant d'être née.
     */
    public function testAucuneLigneDeDatesNeFaitMourirAvantDeNaitre(): void
    {
        $impossibles = [];

        foreach ($this->lignesDeDates as $l) {
            if ($l['naissance'] > $l['deces']) {
                $impossibles[] = \sprintf(
                    '  %s:%d — « %s » : née au plus tard en %d, morte en %d (%d an%s avant)',
                    $l['fichier'],
                    $l['ligne'],
                    $l['brut'],
                    $l['naissance'],
                    $l['deces'],
                    $l['naissance'] - $l['deces'],
                    1 === $l['naissance'] - $l['deces'] ? '' : 's',
                );
            }
        }

        self::assertSame(
            [],
            $impossibles,
            \sprintf(
                "%d ligne(s) de dates impossible(s) sur %d relevées :\n\n%s\n\n".
                'La fourchette de naissance et l\'année de décès n\'ont pas été éditées ensemble.',
                \count($impossibles),
                \count($this->lignesDeDates),
                implode("\n", $impossibles),
            ),
        );

        self::assertGreaterThanOrEqual(
            self::MINIMUM_LIGNES_NAISSANCE_DECES,
            \count($this->lignesDeDates),
            \sprintf(
                "Seulement %d ligne(s) « fourchette – décès » relevées, plancher %d.\n".
                'Le motif ne trouve plus la forme complète du § 14.4 ; ce test ne garde plus rien.',
                \count($this->lignesDeDates),
                self::MINIMUM_LIGNES_NAISSANCE_DECES,
            ),
        );
    }

    /**
     * `BET <borne> AND <borne>` — GEDCOM 5.5.1, tag `DATE`.
     *
     * Les bornes peuvent être une année seule (`1901`) ou une date complète
     * (`11 MAR 1930`). À gauche on retient le **dernier** millésime avant `AND`,
     * à droite le **premier** après : c'est ce qui rend `BET 11 MAR 1930 AND
     * 11 MAR 1930` lisible sans écrire un analyseur de dates GEDCOM.
     *
     * Une ligne sans millésime des deux côtés est ignorée sans bruit : c'est un
     * gabarit (`BET %s AND %s`), une expression régulière (`BET (\d{4}) AND …`)
     * ou de la prose (`« BET … AND … »`). Il n'y a rien à ordonner.
     */
    private function releverGedcom(string $fichier, int $numero, string $ligne): void
    {
        if (!preg_match_all('/\bBET\b([^\n]*?)\bAND\b([^\n]*)/i', $ligne, $captures, \PREG_SET_ORDER)) {
            return;
        }

        foreach ($captures as $capture) {
            $basse = $this->dernierMillesime($capture[1]);
            $haute = $this->premierMillesime($capture[2]);

            if (null === $basse || null === $haute) {
                continue;
            }

            $this->fourchettes[] = [
                'fichier' => $fichier,
                'ligne' => $numero,
                'forme' => 'gedcom',
                'brut' => \sprintf('BET %d AND %d', $basse, $haute),
                'basse' => $basse,
                'haute' => $haute,
            ];
        }
    }

    /**
     * Forme compacte du § 14.4 — `1901-1909`, telle que `jolie()` l'écrit.
     *
     * Les gardes `(?<![\d-])` / `(?![\d-])` écartent les dates ISO
     * (`1901-01-01` : la seconde borne n'a pas quatre chiffres) et les
     * identifiants à tirets multiples.
     */
    private function releverCompactes(string $fichier, int $numero, string $ligne): void
    {
        if (!preg_match_all('/(?<![\d-])(\d{4})-(\d{4})(?![\d-])/', $ligne, $captures, \PREG_SET_ORDER)) {
            return;
        }

        foreach ($captures as $capture) {
            $basse = $this->millesime($capture[1]);
            $haute = $this->millesime($capture[2]);

            if (null === $basse || null === $haute) {
                continue;
            }

            $this->fourchettes[] = [
                'fichier' => $fichier,
                'ligne' => $numero,
                'forme' => 'compacte',
                'brut' => $capture[0],
                'basse' => $basse,
                'haute' => $haute,
            ];
        }
    }

    /** La prose d'`UncertainDate::format()` — « entre 1901 et 1909 ». */
    private function releverProse(string $fichier, int $numero, string $ligne): void
    {
        if (!preg_match_all('/entre\s+(\d{4})\s+et\s+(\d{4})/i', $ligne, $captures, \PREG_SET_ORDER)) {
            return;
        }

        foreach ($captures as $capture) {
            $basse = $this->millesime($capture[1]);
            $haute = $this->millesime($capture[2]);

            if (null === $basse || null === $haute) {
                continue;
            }

            $this->fourchettes[] = [
                'fichier' => $fichier,
                'ligne' => $numero,
                'forme' => 'prose',
                'brut' => $capture[0],
                'basse' => $basse,
                'haute' => $haute,
            ];
        }
    }

    /**
     * Ligne complète du § 14.4 — `1901-1909 – 1987`. Le séparateur est un tiret
     * demi-cadratin entouré d'espaces, et c'est le moteur qui l'écrit ainsi.
     */
    private function releverLignesDeDates(string $fichier, int $numero, string $ligne): void
    {
        if (!preg_match_all('/(?<![\d-])(\d{4})-(\d{4})\s+–\s+(\d{4})(?![\d-])/', $ligne, $captures, \PREG_SET_ORDER)) {
            return;
        }

        foreach ($captures as $capture) {
            $naissance = $this->millesime($capture[2]);
            $deces = $this->millesime($capture[3]);

            if (null === $this->millesime($capture[1]) || null === $naissance || null === $deces) {
                continue;
            }

            $this->lignesDeDates[] = [
                'fichier' => $fichier,
                'ligne' => $numero,
                'brut' => $capture[0],
                'naissance' => $naissance,
                'deces' => $deces,
            ];
        }
    }

    /** Un millésime plausible, ou `null` — voir la fenêtre de plausibilité. */
    private function millesime(string $brut): ?int
    {
        $valeur = (int) $brut;

        return $valeur >= self::ANNEE_MINIMALE && $valeur <= self::ANNEE_MAXIMALE ? $valeur : null;
    }

    private function premierMillesime(string $fragment): ?int
    {
        foreach ($this->millesimes($fragment) as $valeur) {
            return $valeur;
        }

        return null;
    }

    private function dernierMillesime(string $fragment): ?int
    {
        $trouves = $this->millesimes($fragment);

        return [] === $trouves ? null : $trouves[\count($trouves) - 1];
    }

    /** @return list<int> */
    private function millesimes(string $fragment): array
    {
        if (!preg_match_all('/(?<!\d)(\d{4})(?!\d)/', $fragment, $captures)) {
            return [];
        }

        $trouves = [];
        foreach ($captures[1] as $brut) {
            $valeur = $this->millesime($brut);
            if (null !== $valeur) {
                $trouves[] = $valeur;
            }
        }

        return $trouves;
    }

    /**
     * Découverte du corpus. Rien n'est listé à la main : une racine, des
     * extensions, et les dossiers vendus par d'autres sont écartés.
     *
     * @return list<string>
     */
    private function corpus(): array
    {
        $racine = $this->racineDuDepot();
        $chemins = [];

        foreach (self::FICHIERS_ISOLES as $nom) {
            $chemin = $racine.'/'.$nom;
            if (is_file($chemin)) {
                $chemins[] = $chemin;
            }
        }

        foreach (self::RACINES as $sous => $extensions) {
            $depart = $racine.'/'.$sous;
            if (!is_dir($depart)) {
                continue;
            }
            $this->descendre($depart, $extensions, $chemins);
        }

        sort($chemins);

        return $chemins;
    }

    /**
     * @param list<string> $extensions
     * @param list<string> $chemins
     */
    private function descendre(string $dossier, array $extensions, array &$chemins): void
    {
        foreach (scandir($dossier) ?: [] as $entree) {
            if ('.' === $entree || '..' === $entree) {
                continue;
            }

            $chemin = $dossier.'/'.$entree;

            if (is_dir($chemin)) {
                if (!\in_array($entree, self::DOSSIERS_EXCLUS, true)) {
                    $this->descendre($chemin, $extensions, $chemins);
                }
                continue;
            }

            if (\in_array(strtolower(pathinfo($entree, \PATHINFO_EXTENSION)), $extensions, true)) {
                $chemins[] = $chemin;
            }
        }
    }

    private function relatif(string $chemin): string
    {
        $racine = $this->racineDuDepot();

        return str_starts_with($chemin, $racine.'/') ? substr($chemin, \strlen($racine) + 1) : $chemin;
    }

    private function racineDuDepot(): string
    {
        return str_replace('\\', '/', \dirname(__DIR__, 3));
    }
}
