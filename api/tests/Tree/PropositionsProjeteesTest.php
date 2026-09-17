<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Contract\AncragePropose;
use App\Module\Core\Contract\NoeudPropose;
use App\Module\Core\Contract\ProjectionEnAttente;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
use App\Module\Tree\Serializer\NoeudProposeEcarte;
use App\Module\Tree\Serializer\PropositionsProjetees;
use App\Module\Tree\Service\MesureDeFiche;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\ValueObject\AncrageEnAttente;
use App\Module\Tree\ValueObject\AreteDePlacement;
use App\Module\Tree\ValueObject\BoiteEnglobante;
use App\Module\Tree\ValueObject\NoeudPlace;
use App\Module\Tree\ValueObject\Placement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La **traduction** entre ce que `Proposal` publie et ce que le moteur de placement sait
 * consommer — la seule couture où les deux vocabulaires se rencontrent.
 *
 * Elle mérite ses propres tests parce qu'elle déduit trois choses que le contrat du noyau
 * ne porte pas, et que chacune se trompe silencieusement :
 *
 * - la **génération**, qui fixe `y` — se tromper pose le nœud sur la mauvaise rangée, et
 *   au pire sur celle de l'ego ;
 * - la **branche**, qui donne sa couleur au nœud ;
 * - le **découpage de la ligne de dates**, dont la recomposition doit rendre *exactement*
 *   la chaîne reçue — sans quoi `w` est mesurée sur un texte et l'étiquette en affiche un
 *   autre, et le nœud déborde de sa boîte.
 *
 * Sans base et sans conteneur : un `Placement` posé à la main suffit, et c'est ce qui rend
 * ces cas écrivables un par un.
 */
final class PropositionsProjeteesTest extends TestCase
{
    /**
     * Un couple placé, leur losange, et leur enfant — assez pour éprouver les trois
     * genres d'ancrage.
     *
     * Les ordonnées sont celles du moteur : `y = génération × 150` pour une fiche, et le
     * losange 46 px sous la rangée de ses époux (`H_FICHE / 2 + 26`).
     */
    private function placement(): Placement
    {
        $noeuds = [
            'p1' => new NoeudPlace('p1', 0.0, 0.0, 120, MoteurDePlacement::H_FICHE),
            'p2' => new NoeudPlace('p2', -200.0, -150.0, 130, MoteurDePlacement::H_FICHE),
            'p3' => new NoeudPlace('p3', 200.0, -150.0, 130, MoteurDePlacement::H_FICHE),
            'f9' => new NoeudPlace('f9', 0.0, -104.0, MoteurDePlacement::LOSANGE_L, MoteurDePlacement::LOSANGE_H),
        ];

        $aretes = [
            AreteDePlacement::union('p2', 'f9', false),
            AreteDePlacement::union('p3', 'f9', false),
            AreteDePlacement::filiation('f9', 'p1'),
        ];

        return new Placement($noeuds, $aretes, new BoiteEnglobante(-400.0, -260.0, 800.0, 400.0), 'p1');
    }

    /**
     * @param array<int, array{displayName: string, branch: string|null}> $branches
     *
     * @return array{noeuds: list<\App\Module\Tree\Serializer\NoeudProposePlacable>, renommage: array<string, string>, ecartes: list<NoeudProposeEcarte>}
     */
    private function preparer(NoeudPropose $noeud, AncragePropose $ancrage, array $branches = []): array
    {
        return $this->preparerPlusieurs([$noeud], [$ancrage], $branches);
    }

    /**
     * @param list<NoeudPropose>                                          $noeuds
     * @param list<AncragePropose>                                        $ancrages
     * @param array<int, array{displayName: string, branch: string|null}> $branches
     *
     * @return array{noeuds: list<\App\Module\Tree\Serializer\NoeudProposePlacable>, renommage: array<string, string>, ecartes: list<NoeudProposeEcarte>}
     */
    private function preparerPlusieurs(array $noeuds, array $ancrages, array $branches = []): array
    {
        return (new PropositionsProjetees())->preparer(
            new ProjectionEnAttente([12], $noeuds, $ancrages),
            $this->placement(),
            $branches,
        );
    }

    private function noeud(string $ligneDates, bool $decede = true): NoeudPropose
    {
        return $this->noeudNomme('#12/p1', 'Tourmaline', 'Muguet', $ligneDates, $decede);
    }

    private function noeudNomme(string $noeudId, string $prenom, string $nom, string $ligneDates = '1889-1972', bool $decede = true): NoeudPropose
    {
        return new NoeudPropose(
            noeudId: $noeudId,
            demandeId: 12,
            brouillonRef: ltrim(strstr($noeudId, '/') ?: '/p1', '/'),
            displayName: $prenom.' '.$nom,
            givenNames: $prenom,
            surname: $nom,
            marriedName: null,
            sex: Sex::Feminin->value,
            deceased: $decede,
            ligneDates: $ligneDates,
            auteurLabel: 'Opale Romarin',
        );
    }

    private function ancrage(string $kind, ?string $autre, ?int $familleId = null, ?string $familleProvisoire = null): AncragePropose
    {
        return $this->ancrageDe('#12/p1', $kind, $autre, $familleId, $familleProvisoire);
    }

    private function ancrageDe(string $sujet, string $kind, ?string $autre, ?int $familleId = null, ?string $familleProvisoire = null, ?string $autre2 = null): AncragePropose
    {
        return new AncragePropose(12, 0, $kind, $sujet, $autre, $autre2, $familleId, $familleProvisoire, false);
    }

    /**
     * Un conjoint proposé se place **sur la rangée de la personne qu'il épouse**. Une
     * génération déduite de travers mettrait le nœud une rangée trop haut ou trop bas,
     * c'est-à-dire ailleurs que dans son couple.
     */
    public function testUnConjointProposePrendLaGenerationDeLaPersonneQuIlEpouse(): void
    {
        $prepare = $this->preparer(
            $this->noeud('1889-1972'),
            $this->ancrage('conjoint_de', 'p2', familleProvisoire: '#12/f1'),
            [2 => ['displayName' => 'Cresson', 'branch' => Branch::Pm->value]],
        )['noeuds'][0];

        self::assertSame(-1, $prepare->mesure->generation, 'p2 est sur la rangée −1 : son conjoint aussi.');
        self::assertSame(Branch::Pm, $prepare->branche, 'Un conjoint appartient au même quart d’ascendance que la personne qu’il épouse.');
        self::assertSame(AncrageEnAttente::CONJOINT_DE, $prepare->ancrage->genre);
        self::assertSame('p2', $prepare->ancrage->cibleId);
    }

    /** Un parent proposé est **une génération plus haut**, donc un rang plus négatif. */
    public function testUnParentProposeEstUneGenerationPlusHaut(): void
    {
        $prepare = $this->preparer($this->noeud('1861-1931'), $this->ancrage('parent_de', 'p2'))['noeuds'][0];

        self::assertSame(-2, $prepare->mesure->generation);
        self::assertSame(AncrageEnAttente::PARENT_DE, $prepare->ancrage->genre);
    }

    /**
     * Un enfant proposé sous un **couple existant** : la génération se lit sur le losange,
     * qui vit 46 px sous la rangée de ses époux. Oublier ce décalage poserait l'enfant un
     * tiers de rangée trop bas — assez pour chevaucher ses cousins sans que rien ne
     * signale l'erreur.
     */
    public function testUnEnfantSousUnCoupleExistantSePlaceUneRangeeSousSesParents(): void
    {
        $prepare = $this->preparer(
            $this->noeud('2001-'),
            $this->ancrage('enfant_de', null, familleId: 9),
            [2 => ['displayName' => 'Cresson', 'branch' => Branch::Mm->value]],
        )['noeuds'][0];

        self::assertSame(0, $prepare->mesure->generation, 'Le losange f9 joint la rangée −1 : ses enfants sont sur la rangée 0.');
        self::assertSame(AncrageEnAttente::ENFANT_DE_FAMILLE, $prepare->ancrage->genre);
        self::assertSame('f9', $prepare->ancrage->cibleId);
        self::assertSame(Branch::Mm, $prepare->branche, 'La branche vient du premier époux du couple désigné.');
    }

    /**
     * « Enfant de » avec un **seul parent nommé** : l'ancrage désigne le parent, et la
     * famille se retrouve — ou s'invente — chez le projecteur.
     *
     * ── Pourquoi la recherche du losange a changé de camp ──────────────────────────
     *
     * Elle se faisait ici, dans les arêtes du placement **partagé**. C'était juste tant
     * que le parent était une personne validée, et faux dans deux cas qui pèsent lourd
     * dans la file réelle : un parent lui-même proposé n'a aucune arête partagée, et un
     * parent validé sans couple n'en a pas non plus — l'enfant partait alors en
     * `sans_couple`, alors que la validation, elle, aurait créé cette famille.
     *
     * La topologie se lit désormais là où elle est tenue,
     * {@see \App\Module\Tree\Service\ProjecteurDePlacement}, qui voit les arêtes
     * provisoires en plus des validées. Ce test garde donc la **rangée** — la seule chose
     * que cette classe décide encore — et la cible désignée.
     */
    public function testUnEnfantDUnSeulParentSAncreSurLeParent(): void
    {
        $prepare = $this->preparer($this->noeud('2001-'), $this->ancrage('enfant_de', 'p3'))['noeuds'][0];

        self::assertSame(AncrageEnAttente::ENFANT_DE_PARENT, $prepare->ancrage->genre);
        self::assertSame('p3', $prepare->ancrage->cibleId);
        self::assertSame(0, $prepare->mesure->generation, 'p3 est sur la rangée −1 : son enfant est sur la rangée 0.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LA RECOMPOSITION DOIT RENDRE **EXACTEMENT** LA CHAÎNE REÇUE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Le contrat du noyau rend `ligneDates` déjà composée ; `PersonnePlacable` porte la
     * naissance et le décès séparément, et c'est sur leur recomposition que
     * {@see MesureDeFiche} mesure `w`. Si le découpage perd un caractère, la largeur
     * envoyée ne correspond plus au texte envoyé — et l'étiquette déborde de sa boîte.
     */
    #[DataProvider('fournirLesLignesDeDates')]
    public function testLaLigneDeDatesSeRecomposeALIdentique(string $ligne, string $attendu): void
    {
        $prepare = $this->preparer($this->noeud($ligne), $this->ancrage('conjoint_de', 'p2'))['noeuds'][0];

        self::assertSame($attendu, (new MesureDeFiche())->ligneDates($prepare->mesure));
    }

    /** @return iterable<string, array{string, string}> */
    public static function fournirLesLignesDeDates(): iterable
    {
        yield 'naissance et décès' => ['1901-1909 – 1987', '1901-1909 – 1987'];
        yield 'naissance seule' => ['n. 26.9.2009', 'n. 26.9.2009'];
        yield 'décès seul' => ['† 1980', '† 1980'];
        yield 'rien de connu' => ['', '—'];
        yield 'tiret du moteur' => ['—', '—'];
    }

    /**
     * Pour une personne **vivante**, l'étiquette vaut la constante « vivant·e » quoi qu'on
     * ait reçu : c'est ce court-circuit qui empêche la largeur de la boîte d'encoder la
     * longueur des dates d'une personne masquée (ADR 0003).
     */
    public function testUnePersonneVivanteGardeLEtiquetteConstante(): void
    {
        $prepare = $this->preparer(
            $this->noeud('2001-', decede: false),
            $this->ancrage('conjoint_de', 'p2'),
        )['noeuds'][0];

        self::assertTrue($prepare->mesure->vivant);
        self::assertSame('vivant·e', (new MesureDeFiche())->ligneDates($prepare->mesure));
    }

    /**
     * Le losange provisoire garde le numéro que **`Proposal`** lui a donné : c'est lui qui
     * connaît l'ordre des liens de la demande. Le projecteur en fabrique un autre pour son
     * usage interne, et la correspondance est rendue ici pour être appliquée aux nœuds
     * **et** aux arêtes.
     */
    public function testLeLosangeProvisoireGardeLeNumeroDeLaDemande(): void
    {
        $prepare = $this->preparer(
            $this->noeud('1889-1972'),
            $this->ancrage('conjoint_de', 'p2', familleProvisoire: '#12/f1'),
        );

        self::assertSame(['#12/p1#union' => '#12/f1'], $prepare['renommage']);
    }

    /**
     * Une personne proposée dont l'ancre a disparu entre le dépôt et l'affichage est
     * **omise**, jamais posée à `x = 0` — où elle se superposerait à l'ego.
     *
     * L'omission reste la bonne réponse. Ce que le second bloc d'assertions protège,
     * c'est qu'elle ne soit plus **muette** : la fiche omise sort dans `ecartes`, nommée,
     * avec la raison de son absence.
     */
    public function testUnAncrageIntrouvableOmetLeNoeudPlutotQueDeLePoserSurLEgo(): void
    {
        $prepare = $this->preparer($this->noeud('1889-1972'), $this->ancrage('conjoint_de', 'p404'));

        self::assertSame([], $prepare['noeuds']);
        self::assertSame([], $prepare['renommage']);

        self::assertCount(1, $prepare['ecartes'], 'Le nœud omis est compté, pas perdu.');
        self::assertSame('#12/p1', $prepare['ecartes'][0]->source->noeudId);
        self::assertSame('Tourmaline Muguet', $prepare['ecartes'][0]->source->displayName);
        self::assertSame(NoeudProposeEcarte::ANCRE_ABSENTE, $prepare['ecartes'][0]->raison);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LE CAS MESURÉ : UNE BRANCHE PROPOSÉE EN BLOC SE PLACE EN ENTIER.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Cinq demandes ont déposé une branche de vingt-deux personnes ; **cinq nœuds ont
     * été placés et dix-sept écartés**, et le calque annonçait `actif: true` sur les cinq
     * demandes sans un mot sur l'écart. Aucun test ne l'a vu, parce qu'aucun n'assertait
     * la **taille** de l'ensemble qu'il parcourait : un test qui vérifie que les nœuds
     * rendus sont bien placés reste vert quand il n'en reste qu'un sur quatre.
     *
     * ── Ce que ce cas assertait, et ce qu'il asserte maintenant ────────────────────
     *
     * Il a d'abord servi à **lever le silence** : les mêmes nœuds étaient placés, les
     * mêmes omis, et le compte rendait l'écart visible. C'était la bonne première
     * réponse, et l'invariant qu'elle a introduit reste ci-dessous.
     *
     * Mais l'omission n'était pas une fatalité. Une seule passe cherchait les ancres, et
     * uniquement dans le placement **partagé** — qui ne porte que l'arbre validé. Tout ce
     * qui pendait derrière la racine tombait, par construction. La résolution par vagues
     * pose d'abord ce qui s'ancre au validé, puis ce qui s'ancre à ce qui vient d'être
     * posé, et ainsi de suite : quatre fiches, quatre vagues au pire, zéro écarté.
     *
     * L'ordre de sortie compte et il est assertionné : le projecteur pose dans l'ordre
     * reçu, donc une ancre doit précéder ce qui pend à elle.
     */
    public function testUneBrancheProposeeEnBlocSePlaceEnEntierParVagues(): void
    {
        $prepare = $this->preparerPlusieurs(
            [
                $this->noeudNomme('#12/p1', 'Girolle', 'Chanterelle'),
                $this->noeudNomme('#12/p2', 'Morille', 'Chanterelle'),
                $this->noeudNomme('#12/p3', 'Cèpe', 'Chanterelle'),
                $this->noeudNomme('#12/p4', 'Russule', 'Chanterelle'),
            ],
            [
                // La racine de la branche : rattachée à `p2`, qui existe dans l'arbre validé.
                $this->ancrageDe('#12/p1', 'conjoint_de', 'p2', familleProvisoire: '#12/f1'),
                // Les trois autres pendent à des fiches de la même demande.
                $this->ancrageDe('#12/p2', 'enfant_de', '#12/p1'),
                $this->ancrageDe('#12/p3', 'enfant_de', '#12/p1'),
                $this->ancrageDe('#12/p4', 'conjoint_de', '#12/p2'),
            ],
            [2 => ['displayName' => 'Cresson', 'branch' => Branch::Pm->value]],
        );

        // ───── L'assertion qui manquait à l'origine : la taille de l'ensemble parcouru.
        self::assertCount(4, $prepare['noeuds'], 'La branche entière se place, racine comprise.');
        self::assertSame([], $prepare['ecartes'], 'Il ne reste rien à écarter.');

        self::assertSame(
            ['#12/p1', '#12/p2', '#12/p3', '#12/p4'],
            array_map(static fn ($noeud): string => $noeud->id(), $prepare['noeuds']),
            'L’ordre est celui des vagues : une ancre précède toujours ce qui pend à elle.',
        );

        // ───── Les rangées suivent l'enchaînement, et ne retombent pas sur celle de l'ego.
        $rangees = [];
        foreach ($prepare['noeuds'] as $noeud) {
            $rangees[$noeud->id()] = $noeud->mesure->generation;
        }

        self::assertSame(
            [
                // Conjoint de `p2`, qui est sur la rangée −1.
                '#12/p1' => -1,
                // Enfants de `#12/p1` : une rangée plus bas.
                '#12/p2' => 0,
                '#12/p3' => 0,
                // Conjointe de `#12/p2` : la même rangée que lui.
                '#12/p4' => 0,
            ],
            $rangees,
        );

        // ───── La branche se transmet de proche en proche, comme pour une ancre validée.
        foreach ($prepare['noeuds'] as $noeud) {
            self::assertSame(Branch::Pm, $noeud->branche, $noeud->id());
        }
    }

    /**
     * Une ancre qui **n'est nulle part** n'est pas rattrapée par les vagues : elle sort
     * en `enchainement`, et le calque le dit.
     *
     * C'est ce qui garde le motif vivant. Les vagues placent tout ce qui pend à une fiche
     * posée ; ce qui pend à une fiche **elle-même jamais posée** n'a pas d'issue, et le
     * taire recréerait exactement le silence que ce fichier existe pour empêcher.
     */
    public function testCeQuiPendAUneFicheJamaisPoseeSortEnEnchainement(): void
    {
        $prepare = $this->preparerPlusieurs(
            [$this->noeudNomme('#12/p2', 'Morille', 'Chanterelle')],
            [
                // `#12/p1` n'est proposée par aucune fiche de cette projection : l'ancre
                // ne sera posée par aucune vague.
                $this->ancrageDe('#12/p2', 'enfant_de', '#12/p1'),
            ],
        );

        self::assertSame([], $prepare['noeuds']);
        self::assertCount(1, $prepare['ecartes']);
        self::assertSame(NoeudProposeEcarte::ENCHAINEMENT, $prepare['ecartes'][0]->raison);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LE LIEN SE LIT DANS LES DEUX SENS.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * L'écran qui ajoute des liens à une fiche met en sujet **la fiche qu'on complète**,
     * pas celle qu'on ajoute : « ajouter le père de Jean » s'envoie
     * `enfant_de(sujet: Jean, autre: le père proposé)`. Indexée par le seul sujet, la
     * fiche proposée n'avait aucun ancrage et sortait en `sans_lien` — « flotte hors de
     * l'arbre » — alors qu'elle était parfaitement rattachée. Mesuré sur la file réelle :
     * treize nœuds sur trente et un.
     *
     * L'inversion porte sur les **rôles** : vu de l'autre bout, `enfant_de` est
     * `parent_de`, et réciproquement ; `conjoint_de` est sa propre réciproque.
     */
    public function testUneFicheProposeeALAutreBoutDuLienEstRattacheeQuandMeme(): void
    {
        // `p1` est l'ego du placement, sur la rangée 0. On lui propose un parent.
        $prepare = $this->preparerPlusieurs(
            [$this->noeudNomme('#12/p1', 'Girolle', 'Chanterelle')],
            [$this->ancrageDe('p1', 'enfant_de', '#12/p1')],
        );

        self::assertSame([], $prepare['ecartes'], 'Ce n’est pas une fiche sans lien : le lien la nomme à l’autre bout.');
        self::assertCount(1, $prepare['noeuds']);

        $place = $prepare['noeuds'][0];

        self::assertSame(AncrageEnAttente::PARENT_DE, $place->ancrage->genre, 'Enfant de X, vu de X, c’est parent de.');
        self::assertSame('p1', $place->ancrage->cibleId);
        self::assertSame(-1, $place->mesure->generation, 'Un parent est une rangée au-dessus de son enfant.');
    }

    /**
     * Le sens direct est **essayé** avant le sens déduit.
     *
     * Une fiche peut être le sujet d'un lien **et** l'autre bout d'un autre. Ce qu'un lien
     * dit d'elle en la prenant pour sujet est direct ; ce qu'on tire d'un lien où elle est
     * l'autre bout est une déduction. Les deux se posant, on garde ce qui est dit.
     */
    public function testLeLienDontLaFicheEstSujetEstEssayeAvantLInverse(): void
    {
        $prepare = $this->preparerPlusieurs(
            [$this->noeudNomme('#12/p1', 'Girolle', 'Chanterelle')],
            [
                // Déduit : `p1` est enfant de la fiche ⇒ la fiche est parente de `p1`.
                $this->ancrageDe('p1', 'enfant_de', '#12/p1'),
                // Direct : la fiche est conjointe de `p2`.
                $this->ancrageDe('#12/p1', 'conjoint_de', 'p2'),
            ],
        );

        $place = $prepare['noeuds'][0];

        self::assertSame(AncrageEnAttente::CONJOINT_DE, $place->ancrage->genre);
        self::assertSame('p2', $place->ancrage->cibleId);
    }

    /**
     * ════════════════════════════════════════════════════════════════════════════
     *  LA FORME LA PLUS COURANTE DE LA FILE : « J'AJOUTE LES DEUX PARENTS ».
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Relevée telle quelle en production, sur huit des neuf demandes en attente :
     *
     * ```
     * seq 1 : conjoint_de(sujet: #p2, autre: #p1)             les deux proposés
     * seq 2 : enfant_de(sujet: p1, autre: #p1, autre2: #p2)   p1 est l'enfant des deux
     * ```
     *
     * **Le piège est le premier lien.** Il rattache les deux fiches proposées l'une à
     * l'autre, et rien d'autre. Ne garder qu'un rattachement par fiche — la simplification
     * d'avant les vagues — faisait que `#p1` ne connaissait que `#p2` et réciproquement :
     * un couple qui s'attend lui-même, que ni les vagues ni quoi que ce soit ne pouvait
     * dénouer. Les deux sortaient en `enchainement`, c'est-à-dire invisibles.
     *
     * Le second lien les rattache pourtant tous les deux à une personne réelle. Il faut
     * donc garder **tous** les candidats et les essayer, au lieu de choisir avant de
     * savoir lequel se pose.
     */
    public function testLesDeuxParentsProposesDUneMemePersonneSePlacent(): void
    {
        $prepare = $this->preparerPlusieurs(
            [
                $this->noeudNomme('#12/p1', 'Pierre', 'Soulage'),
                $this->noeudNomme('#12/p2', 'Marie', 'Antignac'),
            ],
            [
                $this->ancrageDe('#12/p2', 'conjoint_de', '#12/p1', familleProvisoire: '#12/f1'),
                // `p1` est l'ego du placement de ce fichier, sur la rangée 0.
                $this->ancrageDe('p1', 'enfant_de', '#12/p1', autre2: '#12/p2'),
            ],
        );

        self::assertSame([], $prepare['ecartes'], 'Aucun des deux parents n’est laissé de côté.');
        self::assertCount(2, $prepare['noeuds']);

        $rangees = [];
        foreach ($prepare['noeuds'] as $noeud) {
            $rangees[$noeud->id()] = $noeud->mesure->generation;
        }

        self::assertSame(
            ['#12/p1' => -1, '#12/p2' => -1],
            $rangees,
            'Les parents de l’ego sont une rangée au-dessus de lui, tous les deux.',
        );
    }

    /**
     * Une fiche proposée que **aucun lien ne porte** : le § 6.4 du dossier l'interdit à
     * la saisie, mais si le cas survient, elle est écartée sous son propre motif — et
     * non confondue avec une ancre disparue, qui appellerait un tout autre geste.
     */
    public function testUneFicheSansAucunLienEstEcarteeSousSonPropreMotif(): void
    {
        $prepare = $this->preparerPlusieurs([$this->noeud('1889-1972')], []);

        self::assertSame([], $prepare['noeuds']);
        self::assertCount(1, $prepare['ecartes']);
        self::assertSame(NoeudProposeEcarte::SANS_LIEN, $prepare['ecartes'][0]->raison);
    }

    /**
     * « Enfant de » désignant un **seul parent qui n'a aucun couple** dans l'arbre.
     *
     * ── Ce cas s'écartait, et il se place ──────────────────────────────────────────
     *
     * Il sortait en `sans_couple` : « le projecteur ne sait ancrer un enfant que sous un
     * losange, et il n'y en a pas ». Le constat était exact, la conclusion non. La
     * validation, elle, **créerait** ce couple — « désigner un parent crée une famille »
     * (§ 6.4) —, et le calque existe précisément pour montrer ce que la validation ferait.
     * Refuser de dessiner l'enfant privait le demandeur de la seule chose qu'il attend en
     * regardant l'arbre après son dépôt.
     *
     * Le losange provisoire se pose donc sous le parent, comme pour un parent seul. La
     * décision a changé de camp : elle appartient à
     * {@see \App\Module\Tree\Service\ProjecteurDePlacement}, qui tient les arêtes et voit
     * les provisoires — voir `testUnEnfantDUnSeulParentSAncreSurLeParent()`.
     */
    public function testUnEnfantDUnParentSansCoupleSePlaceQuandMeme(): void
    {
        // `p1` est l'ego du placement de ce fichier : il est enfant de `f9`, et n'est
        // l'époux de personne.
        $prepare = $this->preparer($this->noeud('2001-'), $this->ancrage('enfant_de', 'p1'));

        self::assertSame([], $prepare['ecartes']);
        self::assertCount(1, $prepare['noeuds']);
        self::assertSame(AncrageEnAttente::ENFANT_DE_PARENT, $prepare['noeuds'][0]->ancrage->genre);
        self::assertSame(1, $prepare['noeuds'][0]->mesure->generation, 'p1 est sur la rangée 0 : son enfant est sur la 1.');
    }
}
