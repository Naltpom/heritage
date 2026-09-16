<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Contract\AncragePropose;
use App\Module\Core\Contract\NoeudPropose;
use App\Module\Core\Contract\ProjectionEnAttente;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\Sex;
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
     * @return array{noeuds: list<\App\Module\Tree\Serializer\NoeudProposePlacable>, renommage: array<string, string>}
     */
    private function preparer(NoeudPropose $noeud, AncragePropose $ancrage, array $branches = []): array
    {
        return (new PropositionsProjetees())->preparer(
            new ProjectionEnAttente([12], [$noeud], [$ancrage]),
            $this->placement(),
            $branches,
        );
    }

    private function noeud(string $ligneDates, bool $decede = true): NoeudPropose
    {
        return new NoeudPropose(
            noeudId: '#12/p1',
            demandeId: 12,
            brouillonRef: 'p1',
            displayName: 'Tourmaline Muguet',
            givenNames: 'Tourmaline',
            surname: 'Muguet',
            marriedName: null,
            sex: Sex::Feminin->value,
            deceased: $decede,
            ligneDates: $ligneDates,
            auteurLabel: 'Opale Romarin',
        );
    }

    private function ancrage(string $kind, ?string $autre, ?int $familleId = null, ?string $familleProvisoire = null): AncragePropose
    {
        return new AncragePropose(12, 0, $kind, '#12/p1', $autre, null, $familleId, $familleProvisoire, false);
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
     * « Enfant de » avec un **seul parent nommé** : le projecteur ne sait ancrer un enfant
     * que sous un losange, et la famille de ce parent se retrouve dans les **arêtes** du
     * placement — exactes, là où la géométrie ne l'est qu'approximativement.
     */
    public function testUnEnfantDUnSeulParentRetrouveLaFamilleParLesAretes(): void
    {
        $prepare = $this->preparer($this->noeud('2001-'), $this->ancrage('enfant_de', 'p3'))['noeuds'][0];

        self::assertSame(AncrageEnAttente::ENFANT_DE_FAMILLE, $prepare->ancrage->genre);
        self::assertSame('f9', $prepare->ancrage->cibleId);
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
     */
    public function testUnAncrageIntrouvableOmetLeNoeudPlutotQueDeLePoserSurLEgo(): void
    {
        $prepare = $this->preparer($this->noeud('1889-1972'), $this->ancrage('conjoint_de', 'p404'));

        self::assertSame([], $prepare['noeuds']);
        self::assertSame([], $prepare['renommage']);
    }
}
