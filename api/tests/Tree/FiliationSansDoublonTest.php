<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\ResultatDeValidation;
use App\Module\Proposal\Service\SerialiseurDeDemande;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UN LIEN DE PARENTÉ N'EMPILE JAMAIS DE FAMILLES PARENTALES INCOMPATIBLES.
 *
 * ## Le défaut, tel qu'il a eu lieu en production
 *
 * Quatre demandes validées le même jour, justes une à une :
 *
 * - 714 : Hélène (468) créée `conjoint_de` Jean-Marie (450) → famille 183 = 450 × 468 ;
 * - 715 : Véronique (469) créée `enfant_de` 450 seul → **famille 184 = 450 seul** ;
 * - 725 : 469 `enfant_de` 468 seule → **famille 189 = 468 seule** ;
 * - 727 : 469 `enfant_de` 468 et 450 → rattachée à 183, mais 184 et 189 restent.
 *
 * La fiche de Véronique montrait trois familles de parents, chaque parent en double. Les
 * données ont été corrigées à la main le 01/10/2026 ; ces tests empêchent que ça se
 * reproduise, contre le **vrai** port et une vraie base — le double `ArbreEnMemoire` ne
 * connaît ni le sexe ni `tree_child`, il ne prouverait rien ici.
 *
 * ## L'invariant, vérifié à la fin de chaque test
 *
 * {@see self::assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent()} : c'est la règle 4,
 * et c'est la seule phrase qui compte. Les autres assertions disent **comment** on y
 * arrive.
 */
final class FiliationSansDoublonTest extends BaseDeDemandeSurArbreReel
{
    /**
     * Le scénario exact, demandes 714 → 715 → 725 → 727. À la fin, Véronique n'a
     * **qu'une** famille de parents : Jean-Marie × Hélène, celle de la demande 714.
     */
    public function testLeScenarioDeProductionNeLaissePlusQuUneFamilleDeParents(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);

        // 714 : Hélène, conjointe de Jean-Marie.
        $helene = $this->creerEtValider('Hélène', [['seq' => 1, 'kind' => 'conjoint_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => $jeanMarie]]);
        $couple = $this->familleDuCouple($jeanMarie, $helene);

        // 715 : Véronique, enfant de Jean-Marie seul. Jean-Marie n'a qu'une union : la
        // règle 3 la rattache au couple, et l'écran l'a annoncé avant le clic.
        $demande715 = $this->deposerCreation('Véronique', [['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => $jeanMarie]]);
        $annonce = $this->annonceDuLien($demande715, 1);
        self::assertSame('union_unique', $annonce['motif']);
        self::assertSame($couple, $annonce['familleId']);
        self::assertTrue($annonce['aSignaler']);
        self::assertStringContainsString('Sera rattaché·e au couple « Jean-Marie Lebrun × Hélène Lebrun », seule union connue', $annonce['phrase']);

        $resultat = $this->validation()->valider($demande715, $this->admin());
        $veronique = $this->personneCreee($demande715);
        self::assertSame([$couple], $this->famillesParentales($veronique));

        $lien = $this->revisions($resultat, RevisionOperation::LienAjoute)[0];
        self::assertStringContainsString('seule union connue', (string) $lien->getChanges()['rattachement']['apres']);

        // 725 : enfant de Hélène seule — déjà le cas, rien à créer.
        $this->ajouterLienEtValider([['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => $veronique, 'otherPersonId' => $helene]]);

        // 727 : enfant des deux — toujours le même couple.
        $this->ajouterLienEtValider([['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => $veronique, 'otherPersonId' => $helene, 'other2PersonId' => $jeanMarie]]);

        self::assertSame([$couple], $this->famillesParentales($veronique));
        self::assertSame(1, $this->nombreDeFamilles(), 'Aucune famille à un parent ne doit être restée dans l’arbre.');
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    /**
     * Règle 2 — l'état exact que la production a laissé après 727, rejoué : 184 (450
     * seul) et 189 (468 seule) portent Véronique, 183 est le couple. Une filiation à deux
     * parents la rattache au couple **et** vide puis supprime les deux familles fantômes,
     * avec une révision pour chaque geste.
     */
    public function testUneFiliationADeuxParentsRetireLEnfantDesFamillesAUnParentEtLesSupprime(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $helene = $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin);
        $veronique = $this->poserUnePersonne('Véronique', 'Lebrun', Sex::Feminin);
        $couple = $this->poserFamille($jeanMarie, $helene);
        $deJeanMarie = $this->poserFamille($jeanMarie, null, $veronique);
        $deHelene = $this->poserFamille(null, $helene, $veronique);

        $resultat = $this->ajouterLienEtValider([['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => $veronique, 'otherPersonId' => $helene, 'other2PersonId' => $jeanMarie]]);

        self::assertSame([$couple], $this->famillesParentales($veronique));
        self::assertNull($this->em()->find(Family::class, $deJeanMarie));
        self::assertNull($this->em()->find(Family::class, $deHelene));

        $retraits = $this->revisions($resultat, RevisionOperation::LienRetire);
        self::assertSame([$deJeanMarie, $deHelene], array_map(static fn ($r): int => $r->getSubjectId(), $retraits));
        self::assertSame('Véronique Lebrun', $retraits[0]->getChanges()['filiation']['avant']);

        $suppressions = $this->revisions($resultat, RevisionOperation::Suppression);
        self::assertSame([$deJeanMarie, $deHelene], array_map(static fn ($r): int => $r->getSubjectId(), $suppressions));
        self::assertSame(SubjectKind::Famille, $suppressions[0]->getSubjectKind());
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    /** Règle 2, le garde-fou : une famille vidée qui porte une note reste. L'enfant, lui, la quitte. */
    public function testUneFamilleQuitteeQuiPorteUneNoteNEstJamaisSupprimee(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $helene = $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin);
        $veronique = $this->poserUnePersonne('Véronique', 'Lebrun', Sex::Feminin);
        $couple = $this->poserFamille($jeanMarie, $helene);
        $deJeanMarie = $this->poserFamille($jeanMarie, null, $veronique);
        $note = (new Note('Registre paroissial, à vérifier.'))->setFamily($this->em()->find(Family::class, $deJeanMarie));
        $this->em()->persist($note);
        $this->em()->flush();

        $resultat = $this->ajouterLienEtValider([['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => $veronique, 'otherPersonId' => $jeanMarie, 'other2PersonId' => $helene]]);

        self::assertSame([$couple], $this->famillesParentales($veronique));
        self::assertInstanceOf(Family::class, $this->em()->find(Family::class, $deJeanMarie), 'Une famille qui porte une note ne se supprime jamais.');
        self::assertCount(1, $this->revisions($resultat, RevisionOperation::LienRetire));
        self::assertSame([], $this->revisions($resultat, RevisionOperation::Suppression));
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    /**
     * Règle 1, premier cas : l'enfant a une famille à un parent (Jean-Marie seul), la
     * filiation nomme Hélène. La place libre la reçoit : **même** famille, complétée, et
     * une révision `modification` qui dit « Jean-Marie » → « Jean-Marie × Hélène ».
     */
    public function testUnParentSeulCompleteLaFamilleAUnParentQueLEnfantADeja(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $helene = $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin);
        $veronique = $this->poserUnePersonne('Véronique', 'Lebrun', Sex::Feminin);
        $deJeanMarie = $this->poserFamille($jeanMarie, null, $veronique);

        $demande = $this->deposerCorps($this->corpsDAjout([['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => $veronique, 'otherPersonId' => $helene]]));
        $annonce = $this->annonceDuLien($demande, 1);
        self::assertSame('famille_completee', $annonce['motif']);
        self::assertStringContainsString('« Jean-Marie Lebrun × Hélène Lebrun »', $annonce['phrase']);

        $resultat = $this->validation()->valider($demande, $this->admin());

        self::assertSame([$deJeanMarie], $this->famillesParentales($veronique));
        $famille = $this->em()->find(Family::class, $deJeanMarie);
        self::assertInstanceOf(Family::class, $famille);
        self::assertSame($helene, $famille->getWife()?->getId());
        self::assertSame(1, $this->nombreDeFamilles());

        $modification = $this->revisions($resultat, RevisionOperation::Modification);
        self::assertCount(1, $modification);
        self::assertSame(['avant' => 'Jean-Marie Lebrun', 'apres' => 'Jean-Marie Lebrun × Hélène Lebrun'], $modification[0]->getChanges()['couple']);
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    /**
     * Règle 1, second cas : Jean-Marie et Hélène forment **déjà** un couple. L'enfant de
     * Jean-Marie seul qu'on dit enfant d'Hélène rejoint ce couple, et la famille à un
     * parent, vidée, disparaît — c'est la demande 725 sur l'état laissé par la 715.
     */
    public function testUnParentSeulRattacheAuCoupleExistantEtSupprimeLaFamilleAUnParent(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $helene = $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin);
        $veronique = $this->poserUnePersonne('Véronique', 'Lebrun', Sex::Feminin);
        $couple = $this->poserFamille($jeanMarie, $helene);
        $deJeanMarie = $this->poserFamille($jeanMarie, null, $veronique);

        $resultat = $this->ajouterLienEtValider([['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => $veronique, 'otherPersonId' => $helene]]);

        self::assertSame([$couple], $this->famillesParentales($veronique));
        self::assertNull($this->em()->find(Family::class, $deJeanMarie));
        self::assertCount(1, $this->revisions($resultat, RevisionOperation::Suppression));
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    /**
     * Règle 1, troisième cas : la famille de Jean-Marie seul compte **un autre** enfant.
     * La compléter donnerait Hélène pour mère à ce frère, ce que personne n'a dit : le
     * couple se forme à côté, seule Véronique y passe, le frère reste où il était.
     */
    public function testUneFamilleAUnParentQuiADAutresEnfantsNEstPasCompletee(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $helene = $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin);
        $veronique = $this->poserUnePersonne('Véronique', 'Lebrun', Sex::Feminin);
        $frere = $this->poserUnePersonne('Paul', 'Lebrun', Sex::Masculin);
        $deJeanMarie = $this->poserFamille($jeanMarie, null, $veronique, $frere);

        $this->ajouterLienEtValider([['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => $veronique, 'otherPersonId' => $helene]]);

        $couple = $this->familleDuCouple($jeanMarie, $helene);
        self::assertSame([$couple], $this->famillesParentales($veronique));
        self::assertSame([$deJeanMarie], $this->famillesParentales($frere));
        self::assertNull($this->em()->find(Family::class, $deJeanMarie)?->getWife());
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    /**
     * Règle 3, l'autre versant : un parent à **deux** unions. Rien ne dit de laquelle
     * l'enfant est issu ; on garde la famille à un parent, comme avant, et rien n'est
     * signalé de plus que la phrase ordinaire.
     */
    public function testUnParentADeuxUnionsGardeLaFamilleAUnParent(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $this->poserFamille($jeanMarie, $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin));
        $this->poserFamille($jeanMarie, $this->poserUnePersonne('Odile', 'Marchal', Sex::Feminin));

        $demande = $this->deposerCreation('Véronique', [['seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherPersonId' => $jeanMarie]]);
        $annonce = $this->annonceDuLien($demande, 1);
        self::assertSame('parent_seul', $annonce['motif']);
        self::assertFalse($annonce['aSignaler']);

        $this->validation()->valider($demande, $this->admin());

        $familles = $this->famillesParentales($this->personneCreee($demande));
        self::assertCount(1, $familles);
        $famille = $this->em()->find(Family::class, $familles[0]);
        self::assertInstanceOf(Family::class, $famille);
        self::assertSame($jeanMarie, $famille->getHusband()?->getId());
        self::assertNull($famille->getWife());
    }

    /**
     * Règle 3, la limite : la demande **marie** elle-même le parent. Son « union unique »
     * d'aujourd'hui n'est peut-être plus la seule après la validation : l'annonce et
     * l'écriture divergeraient. La règle ne joue pas.
     */
    public function testLUnionUniqueNeJouePasPourUnParentQueLaDemandeMarie(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $odile = $this->poserUnePersonne('Odile', 'Marchal', Sex::Feminin);
        $this->poserFamille($jeanMarie, $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin));

        $demande = $this->deposerCorps($this->corpsDAjout([
            ['seq' => 1, 'kind' => 'conjoint_de', 'subjectPersonId' => $jeanMarie, 'otherPersonId' => $odile],
            ['seq' => 2, 'kind' => 'enfant_de', 'subjectPersonId' => $this->poserUnePersonne('Véronique', 'Lebrun'), 'otherPersonId' => $jeanMarie],
        ]));

        self::assertSame('parent_seul', $this->annonceDuLien($demande, 2)['motif']);
    }

    /**
     * Règle 4, le garde : Véronique est déjà enfant du couple Jean-Marie × Odile. La dire
     * enfant de Jean-Marie × Hélène lui donnerait deux familles qui partagent Jean-Marie —
     * deux mères possibles, aucune fusion sans perte. La validation refuse, **rien n'est
     * écrit**, et l'écran l'avait annoncé.
     */
    public function testDeuxCouplesQuiPartagentUnParentSontRefusesSansRienEcrire(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $helene = $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin);
        $veronique = $this->poserUnePersonne('Véronique', 'Lebrun', Sex::Feminin);
        $avecOdile = $this->poserFamille($jeanMarie, $this->poserUnePersonne('Odile', 'Marchal', Sex::Feminin), $veronique);

        $demande = $this->deposerCorps($this->corpsDAjout([['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => $veronique, 'otherPersonId' => $jeanMarie, 'other2PersonId' => $helene]]));
        self::assertStringContainsString('partage un parent', (string) $this->annonceDuLien($demande, 1)['incompatibilite']);

        try {
            $this->validation()->valider($demande, $this->admin());
            self::fail('La validation aurait dû refuser.');
        } catch (Conflit $conflit) {
            self::assertSame('filiation_incompatible', $conflit->codeErreur());
            self::assertFalse($conflit->details()['rejouable'] ?? null);
        }

        $this->em()->clear();
        self::assertSame([$avecOdile], $this->famillesParentales($veronique));
        self::assertSame(1, $this->nombreDeFamilles(), 'Rien ne doit avoir été écrit : la famille Jean-Marie × Hélène n’existe pas.');
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    /**
     * `parent_de` dit la même arête dans l'autre sens : il ne doit pas rouvrir le défaut
     * que `enfant_de` ferme. Hélène « parent de » Véronique, déjà enfant de Jean-Marie
     * seul, complète la famille comme le ferait « Véronique enfant de Hélène ».
     */
    public function testParentDeSuitLesMemesRegles(): void
    {
        $jeanMarie = $this->poserUnePersonne('Jean-Marie', 'Lebrun', Sex::Masculin);
        $helene = $this->poserUnePersonne('Hélène', 'Lebrun', Sex::Feminin);
        $veronique = $this->poserUnePersonne('Véronique', 'Lebrun', Sex::Feminin);
        $deJeanMarie = $this->poserFamille($jeanMarie, null, $veronique);

        $this->ajouterLienEtValider([['seq' => 1, 'kind' => 'parent_de', 'subjectPersonId' => $helene, 'otherPersonId' => $veronique]]);

        self::assertSame([$deJeanMarie], $this->famillesParentales($veronique));
        self::assertSame(1, $this->nombreDeFamilles());
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    // ------------------------------------------------------------------ outils

    /**
     * La règle 4, sur **toute** la table : aucun enfant n'est dans deux familles de
     * parents (hors adoption) qui partagent un époux.
     */
    private function assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent(): void
    {
        $this->em()->clear();

        /** @var list<Child> $filiations */
        $filiations = $this->em()->getRepository(Child::class)->findAll();
        $parEnfant = [];

        foreach ($filiations as $filiation) {
            $famille = $filiation->getFamily();
            $parEnfant[(int) $filiation->getPerson()->getId()][] = array_filter([$famille->getHusband()?->getId(), $famille->getWife()?->getId()]);
        }

        foreach ($parEnfant as $enfant => $couples) {
            foreach ($couples as $i => $un) {
                foreach (\array_slice($couples, $i + 1) as $autre) {
                    self::assertSame([], array_values(array_intersect($un, $autre)), \sprintf('La personne %d est dans deux familles de parents qui partagent un parent.', $enfant));
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $liens */
    private function creerEtValider(string $prenom, array $liens): int
    {
        $demande = $this->deposerCreation($prenom, $liens);
        $this->validation()->valider($demande, $this->admin());

        return $this->personneCreee($demande);
    }

    /** @param list<array<string, mixed>> $liens */
    private function deposerCreation(string $prenom, array $liens): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'creation_personne',
            'message' => 'Livret de famille.',
            'soumettre' => true,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => $prenom,
                'surname' => 'Lebrun',
                'sex' => 'feminin',
                'deceased' => false,
            ]],
            'liens' => $liens,
        ]);
    }

    /** @param list<array<string, mixed>> $liens */
    private function ajouterLienEtValider(array $liens): ResultatDeValidation
    {
        return $this->validation()->valider($this->deposerCorps($this->corpsDAjout($liens)), $this->admin());
    }

    /**
     * @param list<array<string, mixed>> $liens
     *
     * @return array<string, mixed>
     */
    private function corpsDAjout(array $liens): array
    {
        return ['kind' => 'ajout_lien', 'message' => 'Acte de naissance.', 'soumettre' => true, 'liens' => $liens];
    }

    private function personneCreee(ChangeRequest $demande): int
    {
        $brouillons = self::getContainer()->get(DraftPersonRepository::class);
        self::assertInstanceOf(DraftPersonRepository::class, $brouillons);
        $id = $brouillons->pourDemande($demande)[0]->getResolvedPersonId();
        self::assertNotNull($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function annonceDuLien(ChangeRequest $demande, int $seq): array
    {
        $serialiseur = self::getContainer()->get(SerialiseurDeDemande::class);
        self::assertInstanceOf(SerialiseurDeDemande::class, $serialiseur);

        foreach ($serialiseur->pourAdministration($demande)['liens'] as $lien) {
            if ($seq === $lien['seq']) {
                self::assertArrayHasKey('filiationPrevue', $lien, 'L’écran de décision doit annoncer où la filiation atterrira.');

                return $lien['filiationPrevue'];
            }
        }

        self::fail("Aucun lien n° $seq.");
    }

    /** @return list<\App\Module\Proposal\Entity\Revision> */
    private function revisions(ResultatDeValidation $resultat, RevisionOperation $operation): array
    {
        return array_values(array_filter(
            $resultat->revisions,
            static fn ($revision): bool => $operation === $revision->getOperation(),
        ));
    }

    private function poserFamille(?int $mari, ?int $femme, int ...$enfants): int
    {
        $famille = new Family(null === $mari ? null : $this->personne($mari), null === $femme ? null : $this->personne($femme));
        $this->em()->persist($famille);

        foreach (array_values($enfants) as $rang => $enfant) {
            $this->em()->persist(new Child($famille, $this->personne($enfant), $rang));
        }

        $this->em()->flush();

        return (int) $famille->getId();
    }

    private function familleDuCouple(int $mari, int $femme): int
    {
        $famille = $this->em()->getRepository(Family::class)->findOneBy(['husband' => $mari, 'wife' => $femme]);
        self::assertInstanceOf(Family::class, $famille, 'Le couple devrait avoir sa famille.');

        return (int) $famille->getId();
    }

    /** @return list<int> */
    private function famillesParentales(int $personne): array
    {
        /** @var list<array{id: int}> $lignes */
        $lignes = $this->em()->createQuery(\sprintf(
            'SELECT IDENTITY(c.family) AS id FROM %s c WHERE IDENTITY(c.person) = :personne ORDER BY c.family ASC',
            Child::class,
        ))->setParameter('personne', $personne)->getArrayResult();

        return array_map(static fn (array $ligne): int => (int) $ligne['id'], $lignes);
    }

    private function nombreDeFamilles(): int
    {
        return \count($this->em()->getRepository(Family::class)->findAll());
    }
}
