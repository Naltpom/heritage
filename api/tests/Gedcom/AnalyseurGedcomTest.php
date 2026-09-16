<?php

declare(strict_types=1);

namespace App\Tests\Gedcom;

use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Service\CleDeRecherche;
use App\Module\Gedcom\Dto\ResultatAnalyse;
use App\Module\Gedcom\Service\AnalyseurGedcom;
use App\Module\Gedcom\Service\CollecteurAnomalies;
use App\Module\Gedcom\Service\ConvertisseurDate;
use App\Module\Gedcom\Service\DecoupageDuNom;
use App\Module\Gedcom\Service\LecteurGedcom;
use App\Module\Gedcom\Service\QualificateurSource;
use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Enum\NoteScope;
use App\Module\Tree\Enum\SourceKind;
use App\Module\Tree\Service\CalculAscendance;
use App\Module\Tree\Service\NormalisateurNom;
use PHPUnit\Framework\TestCase;

/**
 * L'analyseur complet, sur `fixtures/arbre-essai.ged`.
 *
 * **Pourquoi une fixture écrite à la main et pas les vraies données** : le GEDCOM réel
 * concerne des personnes vivantes et n'est pas versionné (§ 9 du dossier de mission). Un
 * test qui en dépendrait ne passerait que sur un poste. La fixture reproduit donc
 * délibérément chacun des pièges du fichier réel, en onze personnes :
 *
 * - une **date en fourchette** (`BET 1930 AND 1934`) et une date illisible ;
 * - un **implexe** — Julien et Hélène sont cousins germains par leurs mères, donc le
 *   couple Bréval × Sarrat est atteint deux fois en remontant depuis l'ego ;
 * - un **individu sans nom** (`1 NAME  //`) ;
 * - une **famille à un seul époux** ;
 * - une `NOTE` portée par un enregistrement `SOUR`, qui ne doit **pas** finir en note
 *   de recherche ;
 * - une ligne `1 SOUR` dans `HEAD`, qui n'est **pas** une citation ;
 * - un `QUAY 4`, valeur hors norme ;
 * - un `_MARNM`, qui est de la vraie donnée.
 *
 * L'analyse ne touche ni Doctrine ni le noyau : ce test tourne partout, y compris en CI
 * sans base de données.
 *
 * **Les dates de la fixture sont inventées et ne suivent AUCUN décalage.** Elles ne
 * désignent personne : elles ne servent qu'à tenir les pièges ci-dessus, et leur
 * cohérence interne est porteuse (l'âge `58y` de `@I2@`, le mariage de `@F2@` antérieur
 * à la naissance de son enfant, la fourchette `BET 1930 AND 1934` assertée plus bas).
 * Un décalage partiel les rendrait incohérentes ; un décalage total n'anonymiserait
 * rien, puisqu'aucune personne réelle n'y figure. Ne les décalez pas.
 */
final class AnalyseurGedcomTest extends TestCase
{
    /**
     * Les totaux de la fixture, comptés à la main sur le fichier.
     *
     * Ils jouent ici le rôle que les 274 personnes jouent pour le fichier réel : si l'un
     * bouge sans qu'on ait touché à la fixture, c'est l'analyseur qui a changé d'avis.
     *
     * @return array<string, int>
     */
    public static function totauxDeLaFixture(): array
    {
        return [
            'personnes' => 11,
            'familles' => 5,
            'filiations' => 7,
            'sources' => 2,
            'citations' => 5,
            'citations_individus' => 4,
            'citations_familles' => 1,
            'notes' => 8,
            'notes_de_source' => 1,
            'faits' => 3,
            'medias' => 1,
            'generations' => 4,
            'sans_deces' => 3,
            'deces_sans_date' => 3,
            'fiches_actees' => 2,
            'resn_privacy' => 1,
            'branche_pp' => 2,
            'branche_pm' => 3,
            'branche_mp' => 1,
            'branche_mm' => 1,
            'branche_tr' => 4,
        ];
    }

    public function testTousLesTotauxDeLaFixtureTombentJuste(): void
    {
        self::assertSame(self::totauxDeLaFixture(), $this->analyse()->totaux());
    }

    /**
     * La ligne `1 SOUR ESSAI` de l'en-tête décrit la provenance du fichier, pas un fait.
     *
     * C'est exactement l'écart entre les 484 lignes `1 SOUR` du fichier réel et ses 483
     * citations, et le décompte des `QUAY` le confirme de son côté.
     */
    public function testLaLigneSourDeLEnteteNEstPasUneCitation(): void
    {
        $analyse = $this->analyse();

        self::assertSame(5, $analyse->totaux()['citations']);
        // Six lignes `1 SOUR` dans la fixture, dont celle de HEAD.
        self::assertSame(6, substr_count((string) file_get_contents($this->fixture()), "\n1 SOUR "));
    }

    /**
     * Les `NOTE` d'un enregistrement `SOUR` vont dans `tree_source.note`, pas dans
     * `tree_note`. C'est l'écart entre le décompte brut des `NOTE` et les notes de
     * recherche.
     */
    public function testLaNoteDUneSourceNEstPasUneNoteDeRecherche(): void
    {
        $analyse = $this->analyse();

        self::assertStringContainsString('tree_source.note', (string) $analyse->sources['@S1@']->note);

        foreach ($analyse->personnes as $personne) {
            foreach ($personne->notes as $note) {
                self::assertStringNotContainsString('tree_source.note', $note->corps);
            }
        }
    }

    /** `_MARNM` porte les noms d'épouse : c'est de la vraie donnée, que `graphe.json` avait perdue. */
    public function testLeNomDEpouseEstConserve(): void
    {
        self::assertSame('Persil', $this->analyse()->personnes['@I3@']->nomEpouse);
    }

    /**
     * Les 4 individus sans `NAME` du fichier réel ne sont pas des lignes corrompues : ce
     * sont des ancêtres réellement inconnus, chacun ouvrant une branche de recherche.
     */
    public function testUnIndividuSansNomEstImporteEtSignale(): void
    {
        $analyse = $this->analyse();
        $anonyme = $analyse->personnes['@I10@'];

        self::assertSame('', $anonyme->prenoms);
        self::assertSame('', $anonyme->nom);
        self::assertSame('?', $anonyme->affichage);
        self::assertSame(1, $analyse->anomalies->compte(CollecteurAnomalies::NOM_VIDE));
    }

    /** Une famille à un seul époux est une donnée valide : `WIFE` sans `HUSB`, ou l'inverse. */
    public function testUneFamilleAUnSeulEpouxEstConservee(): void
    {
        $famille = $this->analyse()->familles['@F5@'];

        self::assertSame(['@I10@'], $famille->epoux());
        self::assertNull($famille->femme);
        self::assertSame(['@I4@'], $famille->enfants);
    }

    /**
     * **L'implexe.** Julien (`@I2@`) et Hélène (`@I3@`) sont cousins germains par leurs
     * mères : le couple `@I8@` × `@I9@` est donc atteint par deux chemins en remontant
     * depuis l'ego. Tout parcours récursif doit le supporter — `graphe.py` porte un
     * garde-fou explicite contre ces boucles.
     */
    public function testLImplexeNeFaitPasBoucler(): void
    {
        $analyse = $this->analyse();
        $ascendants = $analyse->graphe->ascendants('@I1@');

        self::assertArrayHasKey('@I8@', $ascendants);
        // Atteint par @I2@ → @I5@ → @I8@ et par @I3@ → @I7@ → @I8@ : trois générations
        // par les deux chemins, et la remontée s'arrête.
        self::assertSame(3, $ascendants['@I8@']);
        // Le libellé de parenté que cet implexe produit — « arrière-grand-père » — est
        // vérifié par {@see \App\Tests\Tree\CalculParenteTest}, où vit désormais le calcul.
        // 10 des 11 personnes de la fixture : tout le monde sauf Théo, le frère de l'ego,
        // qui n'est pas un ascendant. La remontée s'arrête sans repasser sur le couple
        // atteint deux fois.
        self::assertCount(10, $ascendants);
    }

    /** La fourchette reste une fourchette, de bout en bout de l'analyse. */
    public function testUneFourchetteTraverseLAnalyseSansSAplatir(): void
    {
        $naissance = $this->analyse()->personnes['@I5@']->naissance;

        self::assertSame(DateQualifier::Entre, $naissance->getQualifier());
        self::assertSame('BET 1930 AND 1934', $naissance->getRaw());
        self::assertTrue($naissance->isRange());
        self::assertSame('entre 1930 et 1934', $naissance->format());
    }

    /**
     * `DEAT Y` sans `DATE` est un **décès certain sans date**. D'où un booléen distinct
     * du bloc date : le décès est un fait, sa date est une information.
     */
    public function testUnDecesSansDateResteUnDeces(): void
    {
        $analyse = $this->analyse();

        self::assertTrue($analyse->personnes['@I4@']->decede);
        self::assertFalse($analyse->personnes['@I4@']->deces->isKnown());
        self::assertFalse($analyse->personnes['@I1@']->decede);
    }

    /** `QUAY 4` n'existe pas dans la norme et `ck_tree_citation_quay` la refuserait. */
    public function testUnQuayHorsNormeEstRameneEtSignale(): void
    {
        $analyse = $this->analyse();

        self::assertSame(CitationQuality::PreuveDirecte, $analyse->personnes['@I3@']->citations[0]->quay);
        self::assertSame(1, $analyse->anomalies->compte(CollecteurAnomalies::QUAY_HORS_NORME));
    }

    /**
     * Les portées de note traduisent le découpage du § 3.1 : la naissance et le décès
     * n'ayant pas de ligne à eux, leurs notes se rattachent à la personne **avec leur
     * portée**.
     */
    public function testLesNotesPortentLaPorteeDeLeurBloc(): void
    {
        $analyse = $this->analyse();

        $portees = array_map(
            static fn (\App\Module\Gedcom\Dto\NoteLue $n): string => $n->portee->value,
            $analyse->personnes['@I1@']->notes,
        );
        self::assertSame([NoteScope::Generale->value, NoteScope::Naissance->value], $portees);

        $porteesFamille = array_map(
            static fn (\App\Module\Gedcom\Dto\NoteLue $n): string => $n->portee->value,
            $analyse->familles['@F1@']->notes,
        );
        sort($porteesFamille);
        self::assertSame(
            [NoteScope::Divorce->value, NoteScope::Generale->value, NoteScope::Mariage->value],
            $porteesFamille,
        );
    }

    /**
     * `BIRT` et `DEAT` vont dans les colonnes et **n'en font pas aussi** des faits
     * (§ 3.1). Un fait dupliqué est une divergence qui attend son heure.
     */
    public function testNaissanceEtDecesNeDeviennentPasDesFaits(): void
    {
        $analyse = $this->analyse();
        $types = [];

        foreach ($analyse->personnes as $personne) {
            foreach ($personne->faits as $fait) {
                $types[] = $fait->type;
            }
        }

        self::assertSame([FactType::Profession, FactType::Residence, FactType::Inhumation], $types);
    }

    /** L'adresse détaillée est recollée depuis `ADDR` et ses sous-lignes, sans doublon. */
    public function testLAdresseDetailleeEstRecollee(): void
    {
        $residence = $this->analyse()->personnes['@I2@']->faits[1];

        self::assertSame(FactType::Residence, $residence->type);
        self::assertSame('3 rue des Lilas, Cèdre, Florie', $residence->adresse);
        self::assertSame(DateQualifier::Depuis, $residence->quand->getQualifier());
    }

    /**
     * La branche est déduite de l'ego, pas écrite en dur : le père du père donne `pp`,
     * la mère du père `pm`, et ainsi de suite.
     */
    public function testLesBranchesSeDeduisentDeLEgo(): void
    {
        $branches = $this->analyse()->placement->branches;

        self::assertSame(Branch::Tr, $branches['@I1@']);
        self::assertSame(Branch::Tr, $branches['@I11@'], 'La fratrie de l’ego appartient au tronc.');
        self::assertSame(Branch::Pp, $branches['@I4@']);
        self::assertSame(Branch::Pm, $branches['@I5@']);
        self::assertSame(Branch::Mp, $branches['@I6@']);
        self::assertSame(Branch::Mm, $branches['@I7@']);
        // Atteint par la souche `pm` avant la souche `mm` : l'ordre pp, pm, mp, mm
        // départage, et c'est celui de `graphe.py`.
        self::assertSame(Branch::Pm, $branches['@I8@']);
    }

    public function testLesGenerationsPartentDeZeroEtDescendent(): void
    {
        $generations = $this->analyse()->placement->generations;

        self::assertSame(0, $generations['@I1@']);
        self::assertSame(-1, $generations['@I2@']);
        self::assertSame(-2, $generations['@I5@']);
        self::assertSame(-3, $generations['@I8@']);
        self::assertSame(-3, $generations['@I10@']);
    }

    /** Le nœud de jonction se pose sur une demi-ligne : `rang = 2 × génération + 1`. */
    public function testLeRangDUneFamilleEstEntrelace(): void
    {
        $placement = $this->analyse()->placement;

        self::assertSame(-1, $placement->generationsFamille['@F1@']);
        self::assertSame(-1, $placement->rangs['@F1@']);
        self::assertSame(-2, $placement->generationsFamille['@F2@']);
        self::assertSame(-3, $placement->rangs['@F2@']);
    }

    public function testLOrdreDeFratrieSuitLesLignesChil(): void
    {
        self::assertSame(['@I1@', '@I11@'], $this->analyse()->familles['@F1@']->enfants);
    }

    public function testLeGenreDeSourceEstDeduitDuTitre(): void
    {
        $sources = $this->analyse()->sources;

        self::assertSame(SourceKind::ActeEtatCivil, $sources['@S1@']->genre);
        self::assertSame(SourceKind::ArbreTiers, $sources['@S2@']->genre);
    }

    public function testLeSexeEtLeNomDAffichageSontComposes(): void
    {
        $analyse = $this->analyse();

        self::assertSame(Sex::Feminin, $analyse->personnes['@I1@']->sexe);
        // `nickname ?? given_names`, puis `surname` : « Cam » l'emporte sur « Camille ».
        self::assertSame('Cam Persil', $analyse->personnes['@I1@']->affichage);

        // ── ASSERTION RETOURNÉE : `PERSIL CAMILLE` → `PERSIL CAMILLE CAM` ───────────
        //
        // Ces deux lignes se contredisaient, et c'est ce qui rend le cas parlant : le
        // `NICK` **l'emporte** dans le nom d'affichage — la fiche s'appelle « Cam
        // Persil » — mais il n'entrait pas dans la clé. L'import produisait donc une
        // personne affichée sous un nom que la recherche ne connaissait pas, et la
        // ligne du dessus le disait sans qu'on l'entende.
        //
        // Le surnom est ajouté **en fin de clé** : le patronyme doit rester en tête de
        // l'index de préfixe. Et il n'est ajouté qu'une fois — s'il reprenait un prénom
        // à l'identique, la déduplication de `CleDeRecherche::cleDeNom()` l'écarterait,
        // pour ne pas réécrire une ligne qui n'a pas changé (§ 9.6, `#[ORM\Version]`).
        self::assertSame(
            'PERSIL CAMILLE CAM',
            $analyse->personnes['@I1@']->cleRecherche,
            'L’import et la validation d’une demande doivent produire la MÊME clé : les deux passent le surnom.',
        );

        self::assertSame(Sex::Inconnu, $analyse->personnes['@I10@']->sexe);
    }

    /**
     * Les tags standard qu'aucune colonne n'accueille sont **signalés**, pas tus : un
     * `AGE` sous un `DEAT` et un `EMAIL` sous une `RESI`.
     */
    public function testLesTagsSansColonneSontSignales(): void
    {
        self::assertSame(2, $this->analyse()->anomalies->compte(CollecteurAnomalies::TAG_SANS_COLONNE));
    }

    /** Une racine absente du fichier est une erreur de commande, pas un repli silencieux. */
    public function testUneRacineInconnueEstRefusee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('@INEXISTANT@');

        $this->analyseur()->analyser($this->fixture(), '@INEXISTANT@');
    }

    /** Sans racine demandée, on prend la personne la plus profondément enracinée. */
    public function testLaRacineParDefautEstLaPlusEnracinee(): void
    {
        self::assertSame('@I1@', $this->analyseur()->analyser($this->fixture())->ego);
    }

    private function analyse(): ResultatAnalyse
    {
        return $this->analyseur()->analyser($this->fixture(), '@I1@');
    }

    private function analyseur(): AnalyseurGedcom
    {
        return new AnalyseurGedcom(
            new LecteurGedcom(),
            new ConvertisseurDate(),
            new DecoupageDuNom(),
            new NormalisateurNom(new CleDeRecherche()),
            new QualificateurSource(),
            new CalculAscendance(),
        );
    }

    private function fixture(): string
    {
        return __DIR__.'/fixtures/arbre-essai.ged';
    }
}
