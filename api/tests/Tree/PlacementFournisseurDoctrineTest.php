<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Entity\Person;
use App\Module\Tree\Service\EtiquetteDeDateGedcom;
use App\Module\Tree\Service\JeuDePlacementDoctrine;
use App\Module\Tree\Service\MoteurDePlacement;
use App\Module\Tree\Service\ReglePersonneVivante;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * **Le chemin réel donne-t-il le même résultat que le chemin de référence ?**.
 *
 * {@see PlacementNonRegressionTest} prouve que le moteur est un portage fidèle, mais il
 * l'alimente depuis `graphe.json`. Ce test-ci l'alimente depuis une **vraie base**, à
 * travers {@see JeuDePlacementDoctrine}, et vérifie qu'on retombe sur les mêmes 377
 * positions.
 *
 * C'est le seul essai qui couvre ce que le fournisseur peut casser tout seul : l'ordre
 * des lignes, la reconstruction des adjacences, l'étiquette des dates et le calcul de
 * l'état vivant. Aucun de ces quatre n'est visible dans le moteur, et chacun déplace des
 * nœuds.
 *
 * ## Pourquoi une base construite ici plutôt que l'import GEDCOM
 *
 * L'import (`heritage:import-gedcom`) appartient à un autre module et n'est pas encore
 * en état de peupler une base pour un test. On construit donc les entités directement,
 * **depuis `graphe.json`**, en reconstituant les chaînes GEDCOM verbatim que l'import
 * écrirait dans `birth_raw` et `death_raw`.
 *
 * La reconstitution est vérifiable et elle est vérifiée : `testLaReconstitutionDesDatesEstFidele`
 * repasse chaque chaîne reconstruite dans {@see EtiquetteDeDateGedcom} et exige de
 * retomber sur l'étiquette d'origine. Sans ce garde-fou, le test pourrait se tromper de
 * la même façon des deux côtés et rester vert.
 *
 * **Ce qui reste à faire, et qu'il ne faut pas croire acquis** : la chaîne complète
 * GEDCOM → `heritage:import-gedcom` → base → placement n'est pas couverte ici. Quand
 * l'import sera opérationnel, il faudra rejouer ce test sur la base qu'il produit.
 */
#[Group('donnees-reelles')]
final class PlacementFournisseurDoctrineTest extends TestCase
{
    private const float TOLERANCE = 0.01;

    /**
     * Le calcul de l'état vivant dépend de l'année courante. On la fige, sans quoi le
     * test changerait de réponse tout seul le 1er janvier — et le § 9.2 du modèle de
     * données a été vérifié pour cette année-là.
     */
    private const string AUJOURD_HUI = '2026-09-15';

    private ChargeurDeGrapheDeReference $reference;
    private BaseDArbreEnMemoire $base;

    protected function setUp(): void
    {
        if (!ChargeurDeGrapheDeReference::disponible()) {
            self::markTestSkipped(\sprintf(
                'Fichier de référence absent : %s (normal hors de la machine du commanditaire).',
                ChargeurDeGrapheDeReference::chemin(),
            ));
        }

        $this->reference = ChargeurDeGrapheDeReference::charge();
        $this->base = BaseDArbreEnMemoire::depuis($this->reference);
    }

    /** Le critère : les 377 nœuds, au centième, mais cette fois depuis la base. */
    public function testLes377NoeudsSontIdentiquesDepuisLaBase(): void
    {
        $placement = (new MoteurDePlacement())->calcule($this->fournisseur()->jeuComplet());
        $attendu = $this->reference->positionsAttendues();

        self::assertCount(377, $placement->noeuds);

        $exacts = 0;
        $ecarts = [];

        foreach ($attendu as $xref => $cible) {
            $noeud = $placement->noeud($this->base->idDe($xref));

            if (null === $noeud) {
                $ecarts[] = \sprintf('%s : absent', $xref);

                continue;
            }

            $obtenu = ['x' => $noeud->x, 'y' => $noeud->y, 'w' => (float) $noeud->w, 'h' => (float) $noeud->h];
            $divergents = [];

            foreach ($cible as $axe => $valeur) {
                if (abs($obtenu[$axe] - $valeur) > self::TOLERANCE) {
                    $divergents[] = \sprintf('%s attendu %.4f, obtenu %.4f', $axe, $valeur, $obtenu[$axe]);
                }
            }

            if ([] === $divergents) {
                ++$exacts;
            } else {
                $ecarts[] = \sprintf('%s : %s', $xref, implode(' ; ', $divergents));
            }
        }

        echo \sprintf("\n  Placement — via le fournisseur Doctrine : %d/%d nœuds exacts.\n", $exacts, \count($attendu));

        self::assertSame(
            \count($attendu),
            $exacts,
            \sprintf(
                "%d nœud(s) divergent(s) alors que le chemin depuis graphe.json est exact :\n%s\n".
                'Le défaut est donc dans le fournisseur — ordre des lignes, adjacences, étiquette de date ou état vivant.',
                \count($ecarts),
                implode("\n", \array_slice($ecarts, 0, 20)),
            ),
        );
    }

    /**
     * Le garde-fou du fixture : les chaînes GEDCOM reconstituées doivent redonner
     * exactement les étiquettes de `graphe.json`. Si elles ne le font pas, le test
     * précédent comparerait deux erreurs symétriques.
     */
    public function testLaReconstitutionDesDatesEstFidele(): void
    {
        $etiquettes = new EtiquetteDeDateGedcom();
        $gens = $this->reference->jeu()->personnes;
        $verifiees = 0;

        foreach ($gens as $personne) {
            foreach (['nais' => $personne->nais, 'deces' => $personne->deces] as $champ => $attendu) {
                $brut = BaseDArbreEnMemoire::chaineGedcom($attendu);

                self::assertSame(
                    $attendu,
                    null === $brut ? '' : $etiquettes->jolie($brut),
                    \sprintf('%s de %s : la chaîne reconstituée %s ne redonne pas l\'étiquette.', $champ, $personne->id, var_export($brut, true)),
                );
                ++$verifiees;
            }
        }

        self::assertSame(548, $verifiees, '274 personnes × 2 dates.');
    }

    /**
     * La règle du § 9.2 reproduit **exactement** les 18 `RESN privacy` du GEDCOM : zéro
     * manque, zéro faux positif.
     *
     * C'est la vérification qui rend le reste possible : si l'état vivant calculé ne
     * retombait pas sur le drapeau du GEDCOM, les lignes de dates changeraient, donc les
     * largeurs, donc les 377 positions.
     */
    public function testLaRegleVivanteReproduitLes18DrapeauxDuGedcom(): void
    {
        $vivants = $this->regleVivante()->pourTous(
            $this->base->personnes(),
            ReglePersonneVivante::SEUIL_DEFAUT,
            new \DateTimeImmutable(self::AUJOURD_HUI),
        );

        $calcules = [];
        foreach ($this->base->personnes() as $personne) {
            if (true === ($vivants[$personne->getId()] ?? false)) {
                $calcules[] = $this->base->xrefDe($personne);
            }
        }

        $attendus = [];
        foreach ($this->reference->jeu()->personnes as $personne) {
            if ($personne->vivant) {
                $attendus[] = $personne->id;
            }
        }

        sort($calcules);
        sort($attendus);

        self::assertCount(18, $attendus, 'Le GEDCOM porte 18 `RESN privacy`.');
        self::assertSame($attendus, $calcules, 'La règle du § 9.2 doit retomber sur les 18, sans manque ni faux positif.');
    }

    /**
     * **Le repli par médiane se trompe des deux côtés quand on l'enlève**, et c'est ce qui
     * le rend nécessaire plutôt que confortable. Les 27 personnes sans aucune date de
     * naissance ni décès sont le nœud du problème : il faut bien décider quelque chose
     * pour elles, et les deux décisions évidentes sont fausses.
     *
     * - **« Sans date, donc vivante »** (la règle naïve du § 9.2) masque 45 personnes :
     *   **27 faux positifs**, des ancêtres du XVIII<sup>e</sup> siècle déclarés vivants
     *   parce qu'on ne sait rien d'eux. Masquer 27 fiches d'ancêtres n'est pas
     *   « prudent » : c'est rendre l'arbre inutilisable et entraîner les utilisateurs à
     *   ignorer la signalétique de confidentialité.
     * - **« Sans date, donc décédée »** n'en masque plus que 11 : **7 personnes réellement
     *   vivantes seraient exposées**. C'est une fuite, et c'est le sens de l'erreur qu'on
     *   ne peut pas se permettre.
     *
     * La médiane de génération est ce qui évite d'avoir à choisir entre les deux.
     */
    public function testLeRepliParMedianeEviteDeuxErreursSymetriques(): void
    {
        $regle = $this->regleVivante();
        $personnes = $this->base->personnes();
        $medianes = $regle->medianesParGeneration($personnes);
        $anneeCourante = (int) (new \DateTimeImmutable(self::AUJOURD_HUI))->format('Y');

        $avecRepli = 0;
        $sansBorneDoncDecedee = 0;
        $sansBorneDoncVivante = 0;

        foreach ($personnes as $personne) {
            if ($regle->estVivante($personne, $medianes, ReglePersonneVivante::SEUIL_DEFAUT, $anneeCourante)) {
                ++$avecRepli;
            }

            // La même règle privée de sa médiane : sans borne, pas de masquage.
            if ($regle->estVivante($personne, [], ReglePersonneVivante::SEUIL_DEFAUT, $anneeCourante)) {
                ++$sansBorneDoncDecedee;
            }

            if ($this->naivementVivante($personne, $anneeCourante)) {
                ++$sansBorneDoncVivante;
            }
        }

        self::assertSame(18, $avecRepli, 'Avec le repli : exactement les 18 du GEDCOM.');
        self::assertSame(11, $sansBorneDoncDecedee, '« Sans date donc décédée » exposerait 7 personnes vivantes.');
        self::assertSame(45, $sansBorneDoncVivante, '« Sans date donc vivante » masquerait 27 ancêtres à tort.');
    }

    /** La règle naïve du § 9.2 : pas de décès enregistré, et le doute profite au masquage. */
    private function naivementVivante(Person $personne, int $anneeCourante): bool
    {
        if ($personne->isDeceased()) {
            return false;
        }

        $max = $personne->getBirth()->getMax();

        return null === $max || ($anneeCourante - (int) $max->format('Y')) <= ReglePersonneVivante::SEUIL_DEFAUT;
    }

    /**
     * Les douze médianes publiées au § 9.2, verrouillées.
     *
     * > **Ces douze millésimes ne se substituent pas, et ce n'est pas un oubli.** Ils ne
     * > sont la date de naissance de personne : ce sont des **médianes**, calculées sur les 274
     * > personnes de `graphe.json` — un fichier qui vit hors du dépôt et ne part pas sur
     * > GitHub. Les décaler ferait comparer des médianes déplacées à une référence
     * > inchangée, et ce test tomberait sur le seul poste où il tourne. Même raison pour
     * > {@see AUJOURD_HUI}, qui fige l'horloge de ce même calcul.
     */
    public function testLesMedianesParGenerationSontCellesDuModeleDeDonnees(): void
    {
        $medianes = $this->regleVivante()->medianesParGeneration($this->base->personnes());

        self::assertSame(
            [0 => 2001, -1 => 1972, -2 => 1931, -3 => 1917, -4 => 1880, -5 => 1851,
                -6 => 1814, -7 => 1781, -8 => 1732, -9 => 1714, -10 => 1682, -11 => 1646],
            array_intersect_key($medianes, array_flip(range(0, -11))),
        );
    }

    /**
     * L'ordre rendu par le fournisseur **est** celui du GEDCOM, parce que l'import crée
     * les lignes dans l'ordre du fichier et qu'on trie par identifiant. C'est une entrée
     * du calcul, pas une commodité.
     */
    public function testLOrdreRenduEstCeluiDuFichierDeReference(): void
    {
        $jeu = $this->fournisseur()->jeuComplet();

        $xrefsRendus = array_map(fn ($p): string => $this->base->xrefDeId($p->id), $jeu->personnes);
        $xrefsAttendus = array_map(static fn ($p): string => $p->id, $this->reference->jeu()->personnes);

        self::assertSame($xrefsAttendus, $xrefsRendus, 'Les personnes doivent sortir dans l\'ordre du GEDCOM.');

        $famillesRendues = array_map(fn ($f): string => $this->base->xrefDeId($f->id), $jeu->familles);
        $famillesAttendues = array_map(static fn ($f): string => $f->id, $this->reference->jeu()->familles);

        self::assertSame($famillesAttendues, $famillesRendues, 'Les familles aussi.');
    }

    /**
     * Les adjacences viennent de `tree_family` et `tree_child`, pas d'une liste
     * dénormalisée : `epoux` reste dans l'ordre `HUSB` puis `WIFE`, et `enfants` dans
     * l'ordre des `CHIL`.
     */
    public function testLesAdjacencesViennentDesTablesDeLiens(): void
    {
        $jeu = $this->fournisseur()->jeuComplet();
        $attendues = $this->reference->jeu()->familles;

        foreach ($jeu->familles as $rang => $famille) {
            $cible = $attendues[$rang];

            self::assertSame(
                $cible->epoux,
                array_map(fn (string $id): string => $this->base->xrefDeId($id), $famille->epoux),
                \sprintf('Époux de %s.', $cible->id),
            );
            self::assertSame(
                $cible->enfants,
                array_map(fn (string $id): string => $this->base->xrefDeId($id), $famille->enfants),
                \sprintf('Enfants de %s, dans l\'ordre des CHIL.', $cible->id),
            );
        }
    }

    private function fournisseur(): JeuDePlacementDoctrine
    {
        return new JeuDePlacementDoctrine(
            $this->base->entityManager(),
            new EtiquetteDeDateGedcom(),
            $this->regleVivante(),
            new ReglagesFixes(ReglePersonneVivante::SEUIL_DEFAUT, $this->base->racineId()),
        );
    }

    private function regleVivante(): ReglePersonneVivante
    {
        return new ReglePersonneVivante(new EtiquetteDeDateGedcom());
    }
}
