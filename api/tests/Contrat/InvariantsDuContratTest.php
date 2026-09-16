<?php

declare(strict_types=1);

namespace App\Tests\Contrat;

use PHPUnit\Framework\TestCase;

/**
 * Relit `docs/api-contract.md` et vérifie que ses exemples respectent les règles
 * que le contrat énonce lui-même.
 *
 * Pourquoi ce test existe. Trois fautes ont été trouvées le même soir par trois
 * agents différents, et elles avaient toutes la même forme : **une valeur
 * d'exemple recopiée d'un producteur qui n'est pas celui qui produira la valeur
 * réelle**. Une largeur de nœud mesurée sur la prose d'une fiche au lieu de
 * l'étiquette du moteur (`w: 173` au lieu de 120) ; une même clé illustrée
 * tantôt en prose, tantôt en forme compacte ; `displayName` servi là où le
 * canevas attend une étiquette mesurée. Aucune n'était visible à la relecture,
 * et aucune n'aurait fait rougir un test d'affichage : elles se voient en
 * superposant deux rendus, c'est-à-dire jamais.
 *
 * Ce test ne vérifie pas que le contrat dit vrai sur le produit — il n'a ni
 * noyau, ni base, ni fixture. Il vérifie que **le contrat est cohérent avec
 * lui-même**, ce qui est exactement la classe de défaut ci-dessus, et il le fait
 * en ne dépendant que d'un fichier. C'est ce qui le rend exécutable même quand
 * rien d'autre ne l'est.
 *
 * Il attrape la classe, pas les trois cas : le contrat continuera d'être édité
 * par des gens qui n'auront pas lu la conversation qui l'a produit.
 *
 * ## Ce qu'il ne peut pas voir — la borne de sa couverture
 *
 * **Il vérifie des formes, jamais des données.** Il sait qu'une génération et un
 * `y` qui ne se correspondent pas sont faux tous les deux ; il ne sait pas
 * laquelle des deux valeurs est la bonne. Il ne sait pas non plus que la
 * génération de la personne 17 vaut `-4`, ni que le premier millésime de l'arbre
 * est 1644 : **ces deux valeurs étaient fausses dans le contrat, et il les a
 * laissées passer.** Elles ont été trouvées par un agent qui interrogeait la
 * vraie base avec `psql`.
 *
 * C'est une borne nette, et il faut la connaître pour ne pas se croire couvert :
 * un test qui ne lit qu'un document ne peut pas savoir ce que la base contient.
 * Ce qu'il garantit, c'est qu'**une valeur corrigée ne laisse pas derrière elle
 * les valeurs qui en dérivent** — et c'est précisément ce qui a failli arriver
 * quand `generation: -3` est devenue `-4` : `y`, `rank` et la position du
 * nœud-famille se recalculaient à la main.
 *
 * Le contrôle des valeurs **de données** demanderait une base importée, donc un
 * second test, dans un autre dossier, avec une autre dépendance. C'est un autre
 * métier ; ne l'ajoutez pas ici.
 *
 * ## Une borne de plus : il ne vérifie que les formes que le contrat montre
 *
 * L'invariant 2 valide la grammaire compacte de **toute** valeur `ligneDates` ou
 * `etiquetteDates` qu'il trouve — mais il ne trouve que ce qui est écrit. Tant
 * que le contrat n'illustrait que `1901-1909` et `n. 26.9.2012`, les formes
 * `BEF` (`<1781`), `AFT` (`>1830`), `ABT` (`~1878`), le mois seul (`1/1920`) et
 * les préfixes `† ` / `—` n'étaient **exercés par rien**, et une régression sur
 * l'une d'elles serait passée au vert.
 *
 * C'est pourquoi la bonne réaction à cette lacune n'a pas été de la documenter
 * mais d'**ajouter les exemples manquants au contrat** (§ 9.1, les trois
 * nullités) : ils y ont leur place de toute façon, et ils font mécaniquement
 * grandir l'assiette de ce test. Le même réflexe vaut pour la suite — **une
 * forme qu'aucun exemple ne montre n'est gardée par personne**, ici comme dans
 * les tests de la chronologie, qui figent des valeurs plutôt qu'une grammaire.
 */
final class InvariantsDuContratTest extends TestCase
{
    /**
     * Planchers d'assiette. Tout contrôle qui itère sur un ensemble découvert
     * doit **affirmer la taille de cet ensemble** : sans cela, une extraction
     * cassée, un fichier déplacé ou un format d'exemple changé le rendraient
     * muet au lieu de le faire tomber — et un contrôle muet est pire qu'absent,
     * puisqu'il occupe la place.
     *
     * Valeurs constatées au 2026-09-15 ; ce sont des planchers, pas des égalités,
     * pour qu'ajouter un exemple ne casse pas le test. Les abaisser demande de
     * dire pourquoi.
     */
    private const MINIMUM_BLOCS_JSON = 55;
    private const MINIMUM_NŒUDS_PERSONNE = 4;
    private const MINIMUM_ETIQUETTES = 8;
    private const MINIMUM_BLOCS_DATE = 20;

    /** Constantes du moteur de placement — `docs/moteur-retrouve/graphe.py`, ADR 0005. */
    private const LARG_CAR = 6.6;
    private const LARG_CAR_DATES = 5.8;
    private const PAD = 14;
    private const LARGEUR_MINIMALE = 104;
    private const H_RANG = 150;

    /**
     * Grammaire de la forme compacte — `jolie()`, `graphe.py` l. 77, § 14.4 du
     * contrat. Une année peut valoir « ? » quand la source ne la donne pas.
     */
    private const ATOME_COMPACT = '/^(?:'
        .'\d{1,2}\.\d{1,2}\.\d{4}'      // 11.3.1930
        .'|\d{1,2}\/\d{4}'              // 1/1920
        .'|~(?:\d{4}|\?)'               // ~1878
        .'|<(?:\d{4}|\?)'               // <1920
        .'|>(?:\d{4}|\?)'               // >1900
        .'|(?:\d{4}|\?)-(?:\d{4}|\?)'   // 1901-1909
        .'|\d{4}'                       // 1987
        .')$/u';

    /** @var list<array{chemin: string, valeur: mixed}> */
    private array $blocs = [];

    protected function setUp(): void
    {
        foreach ($this->blocsJson() as $index => $bloc) {
            $decode = json_decode($bloc, true);
            if (\is_array($decode)) {
                $this->blocs[] = ['chemin' => 'bloc #'.$index, 'valeur' => $decode];
            }
        }
    }

    /**
     * Garde-fou d'assiette. Si l'extraction ne trouve plus rien, ce test tombe
     * ici et les suivants ne mentent pas en passant sur un ensemble vide.
     */
    public function testLeContratEstLisibleEtSesExemplesSontExtraits(): void
    {
        $blocs = $this->blocsJson();

        self::assertGreaterThanOrEqual(
            self::MINIMUM_BLOCS_JSON,
            \count($blocs),
            \sprintf(
                "Seulement %d blocs ```json extraits de %s, plancher %d.\n".
                "Soit l'extraction est cassée, soit le fichier a changé de forme. ".
                'Dans les deux cas les invariants ci-dessous deviendraient muets.',
                \count($blocs),
                $this->cheminDuContrat(),
                self::MINIMUM_BLOCS_JSON,
            ),
        );
    }

    /**
     * Invariant 1 — la largeur d'un nœud se recalcule depuis les deux chaînes
     * que le moteur a mesurées, et depuis elles seules (§ 5.2).
     *
     * C'est celui qui aurait rougi sur `w: 173`.
     */
    public function testLaLargeurDeChaqueNoeudSeRecalculeDepuisSesDeuxEtiquettes(): void
    {
        $inspectes = 0;

        foreach ($this->noeudsPersonne() as [$chemin, $noeud]) {
            ++$inspectes;

            self::assertArrayHasKey(
                'etiquetteNom',
                $noeud,
                $chemin." : nœud de personne sans `etiquetteNom`.\n".
                "Le canevas dessinerait `displayName`, que le moteur n'a pas mesurée ".
                '(§ 5.2, « les deux chaînes mesurées »).',
            );

            // Sur un nœud restreint, `etiquetteDates` n'est pas envoyée (§ 11.2) ;
            // le moteur, lui, a bien mesuré « vivant·e », puisque la personne est
            // vivante — c'est la seule raison pour laquelle la clé peut manquer.
            $dates = $noeud['etiquetteDates'] ?? 'vivant·e';

            $attendue = $this->largeurDeFiche((string) $noeud['etiquetteNom'], (string) $dates);

            self::assertSame(
                $attendue,
                $noeud['w'],
                \sprintf(
                    "%s : largeur incohérente avec les étiquettes du même nœud.\n".
                    "  etiquetteNom   = %s (%d signes)\n".
                    "  etiquetteDates = %s (%d signes)\n".
                    "  w calculée     = %d\n".
                    "  w écrite       = %s\n".
                    'Formule § 5.2 : max(%d, max(len(nom) × %s, len(dates) × %s) + 2 × %d), tronquée.',
                    $chemin,
                    json_encode($noeud['etiquetteNom'], \JSON_UNESCAPED_UNICODE),
                    mb_strlen((string) $noeud['etiquetteNom']),
                    json_encode($dates, \JSON_UNESCAPED_UNICODE),
                    mb_strlen((string) $dates),
                    $attendue,
                    json_encode($noeud['w']),
                    self::LARGEUR_MINIMALE,
                    self::LARG_CAR,
                    self::LARG_CAR_DATES,
                    self::PAD,
                ),
            );
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_NŒUDS_PERSONNE,
            $inspectes,
            \sprintf('Seulement %d nœuds de personne inspectés, plancher %d.', $inspectes, self::MINIMUM_NŒUDS_PERSONNE),
        );
    }

    /**
     * Invariant 2 — toute étiquette de date respecte la grammaire compacte
     * (§ 14.4). C'est celui qui aurait rougi sur la prose « entre 1901 et 1909 –
     * 1987 » servie là où le moteur écrit « 1901-1909 – 1987 ».
     */
    public function testLesEtiquettesDeDateRespectentLaGrammaireCompacte(): void
    {
        $inspectees = 0;

        foreach ($this->valeurs(['ligneDates', 'etiquetteDates']) as [$chemin, $cle, $valeur]) {
            if (!\is_string($valeur)) {
                continue;
            }
            ++$inspectees;

            if ('etiquetteDates' === $cle && 'vivant·e' === $valeur) {
                continue; // La constante du moteur (§ 14.4).
            }

            self::assertNotSame(
                'vivant·e',
                $valeur,
                $chemin.' : `ligneDates` ne court-circuite jamais sur `vivant` ; '.
                'la constante appartient à `etiquetteDates` (§ 14.4).',
            );

            self::assertTrue(
                $this->ligneCompacteValide($valeur),
                \sprintf(
                    "%s : `%s` = %s n'est pas une étiquette compacte (§ 14.4).\n".
                    'Formes admises : « A – B », « n. A », « † A », « — », et pour A : '.
                    "11.3.1930 · 1/1920 · 1987 · ~1878 · 1901-1909 · <1920 · >1900.\n".
                    'Une valeur comme « entre 1901 et 1909 » est de la **prose** : '.
                    'elle a sa place dans la clé `texte` du bloc date, pas ici.',
                    $chemin,
                    $cle,
                    json_encode($valeur, \JSON_UNESCAPED_UNICODE),
                ),
            );
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_ETIQUETTES,
            $inspectees,
            \sprintf('Seulement %d étiquettes de date inspectées, plancher %d.', $inspectees, self::MINIMUM_ETIQUETTES),
        );
    }

    /**
     * Invariant 3 — un nœud du graphe ne porte pas les clés de la fiche.
     *
     * C'est celui qui aurait rougi sur `ligneDates` dans un nœud, et il ferme
     * dans l'autre sens la confusion des trois clés de date (§ 14.4).
     */
    public function testUnNoeudNePorteAucuneCleDeFiche(): void
    {
        $interdites = ['ligneDates', 'birth', 'death', 'texte', 'parente', 'unions', 'parents'];

        foreach ($this->noeudsPersonne() as [$chemin, $noeud]) {
            foreach ($interdites as $cle) {
                self::assertArrayNotHasKey(
                    $cle,
                    $noeud,
                    $chemin.' : un nœud du graphe porte `'.$cle."`, qui est une clé de fiche.\n".
                    'Le nœud porte `etiquetteNom` et `etiquetteDates`, et rien d\'autre en fait de texte (§ 5.2).',
                );
            }
        }
    }

    /**
     * Invariant 4 — un bloc date porte **les cinq clés, toujours les cinq**, plus
     * `texte` et rien d'autre en sortie (§ 14.1).
     *
     * Deux assertions, et la seconde est celle qui compte : `texte` ne figure
     * **jamais** dans une valeur d'op. Elle y ferait diverger la comparaison
     * `JSONB` de la détection de conflit (§ 9.6) sans que personne ne comprenne
     * pourquoi.
     *
     * Ce test ne cherche pas à distinguer un corps de requête d'un corps de
     * réponse — il ne peut pas le savoir depuis le JSON seul, et une heuristique
     * qui se tromperait le rendrait bruyant, donc désactivé. Il vérifie les deux
     * propriétés qui ne dépendent pas de cette distinction.
     */
    public function testLesBlocsDatePortentLesCinqClesEtJamaisTexteDansUnOp(): void
    {
        $inspectes = 0;
        $socle = ['max', 'min', 'place', 'qualifier', 'raw'];

        foreach ($this->blocsDate() as [$chemin, $bloc, $dansUnOp]) {
            ++$inspectes;
            $cles = array_keys($bloc);
            sort($cles);

            $attendues = \in_array('texte', $cles, true)
                ? ['max', 'min', 'place', 'qualifier', 'raw', 'texte']
                : $socle;

            self::assertSame(
                $attendues,
                $cles,
                \sprintf(
                    "%s : bloc date mal formé.\n".
                    "  clés présentes : %s\n".
                    "  clés attendues : %s\n".
                    '§ 14.1 : les cinq clés `raw`, `qualifier`, `min`, `max`, `place`, '.
                    '**toujours les cinq** même quand trois valent `null` — plus `texte` '.
                    'en sortie, et rien d\'autre.',
                    $chemin,
                    implode(', ', $cles),
                    implode(', ', $attendues),
                ),
            );

            if ($dansUnOp) {
                self::assertNotContains(
                    'texte',
                    $cles,
                    $chemin." : `texte` dans une valeur d'op.\n".
                    'Elle est en lecture seule (§ 14.1) : présente, elle ferait diverger la '.
                    'comparaison `JSONB` qui tranche entre rejeu et caducité (§ 9.6). Un front '.
                    'qui reposte un bloc reçu d\'une fiche doit en retirer `texte`.',
                );
            }
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_BLOCS_DATE,
            $inspectes,
            \sprintf('Seulement %d blocs date inspectés, plancher %d.', $inspectes, self::MINIMUM_BLOCS_DATE),
        );
    }

    /**
     * Invariant 5 — `y = generation × 150` sur tout nœud de personne (ADR 0005 :
     * « `y` est imposé, aucune liberté verticale »).
     *
     * Ajouté après qu'une correction de donnée — la génération de Marne Dolomite
     * Sureau, `-3` en réalité `-4`, mesurée contre `psql` — a obligé à retoucher
     * `y`, `rank` et la position du nœud-famille **à la main**. Le test ne sait
     * pas que la génération vaut `-4` ; il sait qu'une génération et un `y` qui
     * ne se correspondent pas sont forcément faux tous les deux.
     */
    public function testLeRangVerticalDeChaqueNoeudDecouleDeSaGeneration(): void
    {
        $inspectes = 0;

        foreach ($this->noeudsPersonne() as [$chemin, $noeud]) {
            if (!isset($noeud['generation'], $noeud['y'])) {
                continue;
            }
            ++$inspectes;

            self::assertSame(
                $noeud['generation'] * self::H_RANG,
                $noeud['y'],
                \sprintf(
                    "%s : `y` ne découle pas de `generation`.\n".
                    "  generation = %s  →  y attendue = %d\n".
                    "  y écrite   = %s\n".
                    'ADR 0005 : `y = génération × %d`, aucune liberté verticale. '.
                    'Corriger une génération sans corriger `y` laisse deux valeurs fausses '.
                    'au lieu d\'une.',
                    $chemin,
                    json_encode($noeud['generation']),
                    $noeud['generation'] * self::H_RANG,
                    json_encode($noeud['y']),
                    self::H_RANG,
                ),
            );
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_NŒUDS_PERSONNE,
            $inspectes,
            \sprintf('Seulement %d nœuds positionnés inspectés, plancher %d.', $inspectes, self::MINIMUM_NŒUDS_PERSONNE),
        );
    }

    /**
     * Invariant 6 — les bandes de la chronologie découlent de `bornes` et de
     * `bande` par la formule du § 9.1, sans arrondi ni division.
     *
     * Même motif que l'invariant 5 : la borne inférieure de l'arbre a dû être
     * corrigée de 1646 en 1644, et les deux bandes de l'exemple se recalculaient
     * à la main.
     */
    public function testLesBandesDeChronologieDecoulentDesBornes(): void
    {
        $inspectees = 0;

        foreach ($this->blocs as $bloc) {
            $v = $bloc['valeur'];
            if (!isset($v['bandes'], $v['bornes']['min'], $v['bande']) || !\is_array($v['bandes'])) {
                continue;
            }

            foreach (array_values($v['bandes']) as $k => $bande) {
                ++$inspectees;
                $debut = $v['bornes']['min'] + $k * $v['bande'];
                $fin = $debut + $v['bande'] - 1;

                self::assertSame(
                    ['debut' => $debut, 'fin' => $fin, 'libelle' => $debut.'–'.$fin],
                    ['debut' => $bande['debut'] ?? null, 'fin' => $bande['fin'] ?? null, 'libelle' => $bande['libelle'] ?? null],
                    \sprintf(
                        "%s › bande n° %d : ne découle pas de `bornes.min` = %s et `bande` = %s.\n".
                        '§ 9.1 : `debut(k) = bornes.min + k × bande`, `fin(k) = debut(k) + bande − 1`, '.
                        'libellé « debut–fin » (tiret demi-cadratin). Ni arrondi, ni division.',
                        $bloc['chemin'],
                        $k,
                        json_encode($v['bornes']['min']),
                        json_encode($v['bande']),
                    ),
                );
            }
        }

        self::assertGreaterThanOrEqual(
            2,
            $inspectees,
            \sprintf('Seulement %d bandes de chronologie inspectées, plancher 2.', $inspectees),
        );
    }

    // ---------------------------------------------------------------- outils

    /**
     * `max(104, max(len(nom) × 6.6, len(dates) × 5.8) + 2 × 14)`, **tronquée**.
     *
     * `int()` en Python, `(int)` en PHP : troncature vers zéro, jamais `round()`.
     * Un pixel d'écart sur une bonne partie de l'arbre, et il ne se voit qu'en
     * superposant deux rendus.
     */
    private function largeurDeFiche(string $nom, string $dates): int
    {
        $mesure = max(
            mb_strlen($nom) * self::LARG_CAR,
            mb_strlen($dates) * self::LARG_CAR_DATES,
        );

        return max(self::LARGEUR_MINIMALE, (int) ($mesure + 2 * self::PAD));
    }

    /** Assemblage de `ligne_dates()`, `graphe.py` l. 399 (§ 14.4). */
    private function ligneCompacteValide(string $ligne): bool
    {
        if ('' === $ligne || '—' === $ligne) {
            return true;
        }

        foreach (['n. ', '† '] as $prefixe) {
            if (str_starts_with($ligne, $prefixe)) {
                return 1 === preg_match(self::ATOME_COMPACT, substr($ligne, \strlen($prefixe)));
            }
        }

        $atomes = explode(' – ', $ligne);
        if (\count($atomes) > 2) {
            return false;
        }

        foreach ($atomes as $atome) {
            if (1 !== preg_match(self::ATOME_COMPACT, $atome)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function noeudsPersonne(): array
    {
        $trouves = [];

        foreach ($this->blocs as $bloc) {
            if (!isset($bloc['valeur']['noeuds']) || !\is_array($bloc['valeur']['noeuds'])) {
                continue;
            }
            foreach ($bloc['valeur']['noeuds'] as $noeud) {
                if (\is_array($noeud) && 'personne' === ($noeud['type'] ?? null)) {
                    $trouves[] = [$bloc['chemin'].' › nœud '.($noeud['id'] ?? '?'), $noeud];
                }
            }
        }

        return $trouves;
    }

    /**
     * @param list<string> $cles
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function valeurs(array $cles): array
    {
        $trouves = [];

        foreach ($this->blocs as $bloc) {
            $this->parcourir($bloc['valeur'], $bloc['chemin'], static function (string $chemin, string $cle, mixed $valeur) use ($cles, &$trouves): void {
                if (\in_array($cle, $cles, true)) {
                    $trouves[] = [$chemin, $cle, $valeur];
                }
            });
        }

        return $trouves;
    }

    /**
     * Un bloc date se reconnaît à ses clés, pas à son nom : il apparaît sous
     * `birth`, `death`, `marriage`, `divorce`, `happened`, `taken`, mais aussi
     * sous `oldValue`, `newValue`, `propose`, `actuel`, `valeurCourante`….
     *
     * @return list<array{0: string, 1: array<string, mixed>, 2: bool}>
     */
    private function blocsDate(): array
    {
        $trouves = [];

        // Seules ces deux clés font l'aller-retour : le front les reçoit dans un
        // diff et les reposte à l'identique, et c'est leur comparaison `JSONB`
        // qui tranche entre rejeu et caducité (§ 9.6). Une sixième clé les ferait
        // diverger.
        //
        // `valeurALaSoumission`, `valeurCourante`, `propose`, `actuel` sont des
        // **sorties** — un corps d'erreur ou un calque, destinés à être lus, jamais
        // renvoyés. Elles portent `texte`, et il le faut : sans la prose, le front
        // devrait reformater une date incertaine lui-même, c'est-à-dire
        // réimplémenter `UncertainDate::format()` et finir par en diverger.
        //
        // Cette liste a été élargie une fois par erreur, et ce test l'a attrapée.
        $clesDop = ['oldValue', 'newValue'];

        foreach ($this->blocs as $bloc) {
            $this->parcourir($bloc['valeur'], $bloc['chemin'], static function (string $chemin, string $cle, mixed $valeur) use ($clesDop, &$trouves): void {
                if (!\is_array($valeur) || !\array_key_exists('qualifier', $valeur) || !\array_key_exists('raw', $valeur)) {
                    return;
                }
                $trouves[] = [$chemin, $valeur, \in_array($cle, $clesDop, true)];
            });
        }

        return $trouves;
    }

    /**
     * @param array<array-key, mixed>               $noeud
     * @param callable(string, string, mixed): void $visiteur
     */
    private function parcourir(array $noeud, string $chemin, callable $visiteur): void
    {
        foreach ($noeud as $cle => $valeur) {
            $sous = $chemin.'.'.$cle;
            if (\is_string($cle)) {
                $visiteur($sous, $cle, $valeur);
            }
            if (\is_array($valeur)) {
                $this->parcourir($valeur, $sous, $visiteur);
            }
        }
    }

    /** @return list<string> */
    private function blocsJson(): array
    {
        $contrat = @file_get_contents($this->cheminDuContrat());

        self::assertIsString(
            $contrat,
            'Contrat introuvable ou illisible : '.$this->cheminDuContrat().
            ". Ce test ne dépend que de ce fichier ; s'il a bougé, il faut corriger le chemin ".
            'plutôt que de désactiver le test.',
        );

        preg_match_all('/```json\R(.*?)```/su', $contrat, $captures);

        return $captures[1];
    }

    private function cheminDuContrat(): string
    {
        return \dirname(__DIR__, 3).'/docs/api-contract.md';
    }
}
