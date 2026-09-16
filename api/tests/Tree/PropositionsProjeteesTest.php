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

    private function ancrageDe(string $sujet, string $kind, ?string $autre, ?int $familleId = null, ?string $familleProvisoire = null): AncragePropose
    {
        return new AncragePropose(12, 0, $kind, $sujet, $autre, null, $familleId, $familleProvisoire, false);
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
     *  LE CAS MESURÉ : UNE BRANCHE PROPOSÉE EN BLOC, DONT UNE SEULE FICHE SE POSE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Cinq demandes ont déposé une branche de vingt-deux personnes ; **cinq nœuds ont
     * été placés et dix-sept écartés**, et le calque annonçait `actif: true` sur les cinq
     * demandes sans un mot sur l'écart. Aucun test ne l'a vu, parce qu'aucun n'assertait
     * la **taille** de l'ensemble qu'il parcourait : un test qui vérifie que les nœuds
     * rendus sont bien placés reste vert quand il n'en reste qu'un sur quatre.
     *
     * Le mécanisme est ici, en réduction : seule la fiche rattachée à une personne de
     * l'arbre validé se place. Tout ce qui pend derrière elle — l'enfant d'un parent
     * lui-même proposé — a pour ancre `#12/p1`, qui n'est dans aucun placement partagé,
     * puisque celui-ci ne contient que l'arbre validé.
     *
     * **Ce n'est pas le comportement qui est corrigé, c'est le silence.** Les mêmes nœuds
     * sont placés, les mêmes sont omis ; l'invariant ci-dessous est la seule chose qui
     * change, et c'est lui qui permet à l'écran de dire ce qu'il ne montre pas.
     */
    public function testUneBrancheProposeeEnBlocNePlaceQueSaRacineEtCompteLeReste(): void
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

        // ───── L'assertion qui manquait : la taille de l'ensemble parcouru.
        self::assertCount(1, $prepare['noeuds'], 'Seule la fiche rattachée à l’arbre validé se place.');
        self::assertCount(3, $prepare['ecartes'], 'Les trois autres sont écartées, et comptées.');
        self::assertCount(
            4,
            [...$prepare['noeuds'], ...$prepare['ecartes']],
            'Tout nœud proposé est soit placé, soit écarté : aucun ne disparaît entre les deux.',
        );

        self::assertSame('#12/p1', $prepare['noeuds'][0]->id());

        $ecartes = [];
        foreach ($prepare['ecartes'] as $ecarte) {
            $ecartes[$ecarte->source->noeudId] = $ecarte->raison;
        }

        self::assertSame(
            [
                '#12/p2' => NoeudProposeEcarte::ENCHAINEMENT,
                '#12/p3' => NoeudProposeEcarte::ENCHAINEMENT,
                '#12/p4' => NoeudProposeEcarte::ENCHAINEMENT,
            ],
            $ecartes,
            'La raison distingue l’enchaînement — la forme normale d’une branche proposée — d’une cible disparue.',
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
     * « Enfant de » désignant un **seul parent qui n'a aucun couple** dans l'arbre : le
     * projecteur ne sait ancrer un enfant que sous un losange, et il n'y en a pas.
     *
     * Le distinguer d'une ancre disparue n'est pas un raffinement : la validation, elle,
     * **créerait** ce couple. Dire « introuvable » d'un parent bien présent enverrait
     * l'administrateur chercher une fiche qui n'a jamais manqué.
     */
    public function testUnEnfantDUnParentSansCoupleEstEcarteSousSonPropreMotif(): void
    {
        // `p1` est l'ego du placement de ce fichier : il est enfant de `f9`, et n'est
        // l'époux de personne.
        $prepare = $this->preparer($this->noeud('2001-'), $this->ancrage('enfant_de', 'p1'));

        self::assertSame([], $prepare['noeuds']);
        self::assertCount(1, $prepare['ecartes']);
        self::assertSame(NoeudProposeEcarte::SANS_COUPLE, $prepare['ecartes'][0]->raison);
    }
}
