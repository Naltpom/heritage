<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\Revision;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\RevisionRepository;
use App\Module\Proposal\Service\RefaireDemande;
use App\Module\Proposal\Service\ResultatDeValidation;
use App\Module\Proposal\Service\SerialiseurDeDemande;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Note;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UN LIEN SE DÉFAIT PAR UNE DEMANDE — `retrait_lien`, de bout en bout dans le vrai arbre.
 *
 * `OpKind::RetraitLien` était déclaré et rien ne l'appliquait : un enfant rattaché au
 * mauvais couple ne se détachait qu'en base, à la main. Ces tests passent une demande
 * entière — dépôt, contrôle préalable, validation — par le **vrai** port : le double
 * `ArbreEnMemoire` ne connaît ni `tree_child`, ni les dates d'une union, ni ses notes, et
 * c'est justement ce qui décide de ce qu'un retrait fait.
 *
 * Ce qu'ils gardent :
 *
 * - le dépôt mesure, et refuse ce qui ne peut pas se faire (un lien qui n'existe pas, le
 *   seul époux d'une union qui porte des enfants, un couple restant qui en doublerait un
 *   autre) ;
 * - la validation écrit, avec une révision sur la famille **et** sur chaque fiche reliée ;
 * - **une famille qui porte des données n'est jamais supprimée** : on en retire la
 *   personne, rien de plus ;
 * - une union qui a changé depuis le dépôt rend la demande **caduque** ;
 * - un enfant qui ne perd qu'un parent reste celui de l'autre, sans famille parentale
 *   doublée (l'invariant de `FiliationSansDoublonTest`).
 */
final class LienRetireParUneDemandeTest extends BaseDeDemandeSurArbreReel
{
    /** « Il n'est pas leur enfant » : l'enfant quitte le couple, qui reste une union avec ses autres enfants. */
    public function testUnEnfantRetireQuitteLaFamilleQuiGardeSesAutresEnfants(): void
    {
        [$pere, $mere, $enfant, $frere] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $enfant, $frere);

        $demande = $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $couple]], $enfant);
        self::assertSame(RequestStatus::EnAttente, $demande->getStatus());
        self::assertSame($enfant, $demande->getTargetId());

        $resultat = $this->validation()->valider($demande, $this->admin());

        self::assertSame([], $this->famillesParentales($enfant));
        self::assertSame([$couple], $this->famillesParentales($frere));
        self::assertInstanceOf(Family::class, $this->em()->find(Family::class, $couple));

        $retraits = $this->revisions($resultat, RevisionOperation::LienRetire);
        self::assertSame(
            [[SubjectKind::Famille, $couple], [SubjectKind::Personne, $enfant], [SubjectKind::Personne, $pere], [SubjectKind::Personne, $mere]],
            array_map(static fn (Revision $r): array => [$r->getSubjectKind(), $r->getSubjectId()], $retraits),
        );
        self::assertSame('Lien retiré : Pivoine Sureau n’est plus enfant de « Cobalt Sureau × Marne Bourrache ».', $retraits[0]->getChanges()['lien']['avant']);
        self::assertNotNull($retraits[0]->getVersionAfter(), 'La famille conservée a une version d’après.');
        self::assertSame([], $this->revisions($resultat, RevisionOperation::Suppression));

        // L'historique de la fiche le dit : c'est là qu'on viendra le chercher.
        $historique = $this->historiqueDe($enfant);
        self::assertSame(RevisionOperation::LienRetire, $historique[0]->getOperation());
    }

    /**
     * « Ce n'est pas son père » : l'enfant ne perd que ce parent, et reste celui de sa mère
     * — par `etablirFiliation()`, sans famille doublée. Le couple reste une union.
     */
    public function testUnSeulParentRetireLaisseLEnfantASonAutreParent(): void
    {
        [$pere, $mere, $enfant] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $enfant);

        $demande = $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $couple, 'parentId' => $pere]], $enfant);

        $annonce = $this->libelleDiff($demande, 1);
        self::assertSame('Lien retiré : Pivoine Sureau n’est plus enfant de Cobalt Sureau ; reste enfant de « Marne Bourrache ».', $annonce);

        $resultat = $this->validation()->valider($demande, $this->admin());

        $familles = $this->famillesParentales($enfant);
        self::assertCount(1, $familles);
        $deLaMere = $this->em()->find(Family::class, $familles[0]);
        self::assertInstanceOf(Family::class, $deLaMere);
        self::assertNull($deLaMere->getHusband());
        self::assertSame($mere, $deLaMere->getWife()?->getId());

        self::assertInstanceOf(Family::class, $this->em()->find(Family::class, $couple), 'Le couple reste une union : rien ne dit qu’ils n’étaient pas mariés.');
        self::assertCount(1, $this->revisions($resultat, RevisionOperation::LienAjoute), 'Le rattachement à la mère a sa révision.');
        $this->assertAucunEnfantDansDeuxFamillesQuiPartagentUnParent();
    }

    /** « Ils n'ont jamais été mariés » : une union qui ne porte rien d'autre que le couple disparaît. */
    public function testUnConjointRetireDUneUnionVideLaSupprime(): void
    {
        [$pere, $mere] = $this->famille();
        $couple = $this->poserFamille($pere, $mere);

        $demande = $this->deposerRetrait([['kind' => 'conjoint_de', 'personId' => $mere, 'familyId' => $couple]], $pere);
        $resultat = $this->validation()->valider($demande, $this->admin());

        self::assertNull($this->em()->find(Family::class, $couple));

        $suppressions = $this->revisions($resultat, RevisionOperation::Suppression);
        self::assertCount(1, $suppressions);
        self::assertSame($couple, $suppressions[0]->getSubjectId());
        self::assertNull($this->revisions($resultat, RevisionOperation::LienRetire)[0]->getVersionAfter());
        self::assertStringContainsString('l’union, qui ne porte plus rien, est supprimée', (string) $this->revisions($resultat, RevisionOperation::LienRetire)[0]->getChanges()['lien']['avant']);
    }

    /**
     * Le cœur de la règle : une union qui porte des enfants n'est **jamais** supprimée.
     * Le conjoint en sort, les enfants restent, et la phrase le dit avant le clic.
     */
    public function testUnConjointRetireDUneUnionAvecEnfantsNeSupprimeRien(): void
    {
        [$pere, $mere, $enfant, $frere] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $enfant, $frere);

        $demande = $this->deposerRetrait([['kind' => 'conjoint_de', 'personId' => $mere, 'familyId' => $couple]], $pere);
        self::assertSame(
            'Lien retiré : Marne Bourrache ne fait plus partie de l’union « Cobalt Sureau × Marne Bourrache » ; ses 2 enfants n’y gardent que Cobalt Sureau pour parent.',
            $this->libelleDiff($demande, 1),
        );

        $resultat = $this->validation()->valider($demande, $this->admin());

        $famille = $this->em()->find(Family::class, $couple);
        self::assertInstanceOf(Family::class, $famille, 'Une union qui porte des enfants ne se supprime jamais.');
        self::assertSame($pere, $famille->getHusband()?->getId());
        self::assertNull($famille->getWife());
        self::assertSame([$couple], $this->famillesParentales($enfant));
        self::assertSame([], $this->revisions($resultat, RevisionOperation::Suppression));
    }

    /** Une famille à un parent qui porte une note reste, même vidée de son seul enfant. */
    public function testUneFamilleQuiPorteUneNoteNEstPasSupprimee(): void
    {
        [$pere, , $enfant] = $this->famille();
        $seul = $this->poserFamille($pere, null, $enfant);
        $note = (new Note('Acte de reconnaissance, à relire.'))->setFamily($this->em()->find(Family::class, $seul));
        $this->em()->persist($note);
        $this->em()->flush();

        $resultat = $this->validation()->valider(
            $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $seul]], $enfant),
            $this->admin(),
        );

        self::assertSame([], $this->famillesParentales($enfant));
        self::assertInstanceOf(Family::class, $this->em()->find(Family::class, $seul));
        self::assertSame([], $this->revisions($resultat, RevisionOperation::Suppression));
    }

    /** …et une famille à un parent qui ne porte rien part avec son dernier enfant. */
    public function testUneFamilleAUnParentQuiNePorteRienPartAvecSonDernierEnfant(): void
    {
        [$pere, , $enfant] = $this->famille();
        $seul = $this->poserFamille($pere, null, $enfant);

        $this->validation()->valider(
            $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $seul]], $enfant),
            $this->admin(),
        );

        self::assertNull($this->em()->find(Family::class, $seul));
    }

    /** Une date de mariage est une donnée : retirer l'un des époux garde l'union, et sa date. */
    public function testUneUnionDateeGardeSaDateEtSonEpoux(): void
    {
        [$pere, $mere] = $this->famille();
        $couple = $this->poserFamille($pere, $mere);
        $famille = $this->em()->find(Family::class, $couple);
        self::assertInstanceOf(Family::class, $famille);
        $famille->setMarriage(new UncertainDate('12 MAY 1923', DateQualifier::Exacte, new \DateTimeImmutable('1923-05-12'), new \DateTimeImmutable('1923-05-12')));
        $this->em()->flush();

        $this->validation()->valider(
            $this->deposerRetrait([['kind' => 'conjoint_de', 'personId' => $mere, 'familyId' => $couple]], $pere),
            $this->admin(),
        );

        $this->em()->clear();
        $famille = $this->em()->find(Family::class, $couple);
        self::assertInstanceOf(Family::class, $famille);
        self::assertTrue($famille->getMarriage()->isKnown());
        self::assertNull($famille->getWife());
    }

    /** Un lien qui n'existe pas se refuse au dépôt, sur `retraits[0]`, avec la phrase de l'arbre. */
    public function testUnLienQuiNExistePasEstRefuseAuDepot(): void
    {
        [$pere, $mere, $enfant] = $this->famille();
        $couple = $this->poserFamille($pere, $mere);

        try {
            $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $couple]], $enfant);
            self::fail('Un lien absent doit être refusé.');
        } catch (DonneesInvalides $refus) {
            self::assertStringContainsString('n’est pas (ou plus) l’enfant', $refus->champs()['retraits[0]'] ?? '');
        }
    }

    /** `parent_de` ne se retire pas : on le lit du côté de l'enfant. */
    public function testParentDeEstRefuseAvecLaFormeAttendue(): void
    {
        [$pere, $mere, $enfant] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $enfant);

        $this->expectException(DonneesInvalides::class);
        $this->deposerRetrait([['kind' => 'parent_de', 'personId' => $pere, 'familyId' => $couple]], $pere);
    }

    /** Le seul époux d'une union qui porte des enfants ne peut pas en sortir : elle resterait sans personne. */
    public function testLeSeulEpouxDUneUnionAvecEnfantsNeSortPas(): void
    {
        [$pere, , $enfant] = $this->famille();
        $seul = $this->poserFamille($pere, null, $enfant);

        try {
            $this->deposerRetrait([['kind' => 'conjoint_de', 'personId' => $pere, 'familyId' => $seul]], $pere);
            self::fail('Le seul époux d’une union qui porte un enfant doit être refusé.');
        } catch (DonneesInvalides $refus) {
            self::assertStringContainsString('seul époux', $refus->champs()['retraits[0]'] ?? '');
        }
    }

    /**
     * `uniq_tree_family_couple` porte `NULLS NOT DISTINCT` : retirer la mère de
     * `(Cobalt, Marne)` donnerait un second `(Cobalt, ∅)` à côté de celui qui existe.
     */
    public function testUnCoupleRestantQuiEnDoubleraitUnAutreEstRefuse(): void
    {
        [$pere, $mere, $enfant, $frere] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $enfant);
        $this->poserFamille($pere, null, $frere);

        try {
            $this->deposerRetrait([['kind' => 'conjoint_de', 'personId' => $mere, 'familyId' => $couple]], $pere);
            self::fail('Une collision de couple doit être refusée au dépôt.');
        } catch (DonneesInvalides $refus) {
            self::assertStringContainsString('a déjà une famille sans conjoint', $refus->champs()['retraits[0]'] ?? '');
        }
    }

    /** Une famille qui n'existe pas : `404`, comme toute cible absente. */
    public function testUneFamilleInconnueRendIntrouvable(): void
    {
        [, , $enfant] = $this->famille();

        $this->expectException(Introuvable::class);
        $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => 9999]], $enfant);
    }

    /** La cible annoncée doit être une personne que les liens touchent. */
    public function testLaCibleAnnonceeDoitEtreTouchee(): void
    {
        [$pere, $mere, $enfant] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $enfant);
        $etrangere = $this->poserUnePersonne('Opale', 'Tilleul');

        $this->expectException(DonneesInvalides::class);
        $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $couple]], $etrangere);
    }

    /**
     * Un enfant arrivé dans l'union entre le dépôt et la validation change ce que le
     * retrait du conjoint ferait : la demande est **caduque**, et rien n'est écrit.
     */
    public function testUnEnfantArriveApresLeDepotRendLaDemandeCaduque(): void
    {
        [$pere, $mere, $enfant] = $this->famille();
        $couple = $this->poserFamille($pere, $mere);

        $demande = $this->deposerRetrait([['kind' => 'conjoint_de', 'personId' => $mere, 'familyId' => $couple]], $pere);

        $famille = $this->em()->find(Family::class, $couple);
        self::assertInstanceOf(Family::class, $famille);
        $this->em()->persist(new Child($famille, $this->personne($enfant), 0));
        $this->em()->flush();

        try {
            $this->validation()->valider($demande, $this->admin());
            self::fail('La demande aurait dû devenir caduque.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
        }

        self::assertSame(RequestStatus::Caduque, $demande->getStatus());
        $this->em()->clear();
        $famille = $this->em()->find(Family::class, $couple);
        self::assertInstanceOf(Family::class, $famille, 'Une demande caduque n’écrit rien.');
        self::assertSame($mere, $famille->getWife()?->getId());
    }

    /** Le lien déjà défait par quelqu'un d'autre : caducité, pas une `500` au milieu de la transaction. */
    public function testUnLienDejaDefaitRendLaDemandeCaduque(): void
    {
        [$pere, $mere, $enfant, $frere] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $enfant, $frere);

        $premiere = $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $couple]], $enfant);
        $seconde = $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $couple]], $enfant);
        $this->validation()->valider($premiere, $this->admin());

        $this->expectException(Conflit::class);

        try {
            $this->validation()->valider($seconde, $this->admin());
        } finally {
            self::assertSame(RequestStatus::Caduque, $seconde->getStatus());
        }
    }

    /** Plusieurs liens dans une demande — le regroupement que dépose le mode édition. */
    public function testPlusieursLiensSeRetirentEnUneDemande(): void
    {
        [$pere, $mere, $enfant, $frere] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $frere);
        $deLaMere = $this->poserFamille(null, $mere, $enfant);

        $demande = $this->deposerRetrait([
            ['kind' => 'enfant_de', 'personId' => $frere, 'familyId' => $couple],
            ['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $deLaMere],
        ], $mere);

        self::assertSame($mere, $demande->getTargetId());
        $this->validation()->valider($demande, $this->admin());

        self::assertSame([], $this->famillesParentales($frere));
        self::assertSame([], $this->famillesParentales($enfant));
        self::assertInstanceOf(Family::class, $this->em()->find(Family::class, $couple));
        self::assertNull($this->em()->find(Family::class, $deLaMere));
    }

    /** Une demande caduque se refait : le corps rendu désigne les mêmes liens, sans leur mesure. */
    public function testLaRepriseRedonneLesLiensSansLaMesure(): void
    {
        [$pere, $mere, $enfant] = $this->famille();
        $couple = $this->poserFamille($pere, $mere, $enfant);

        $demande = $this->deposerRetrait([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $couple, 'parentId' => $pere]], $enfant);
        $demande->setStatus(RequestStatus::Caduque);
        $this->em()->flush();

        $refaire = self::getContainer()->get(RefaireDemande::class);
        self::assertInstanceOf(RefaireDemande::class, $refaire);
        $corps = $refaire->corpsPreRempli($demande);

        self::assertSame('retrait_lien', $corps['kind']);
        self::assertSame([['kind' => 'enfant_de', 'personId' => $enfant, 'familyId' => $couple, 'parentId' => $pere]], $corps['retraits']);
    }

    // ------------------------------------------------------------------ outils

    /** @return array{0: int, 1: int, 2: int, 3: int} père, mère, enfant, frère */
    private function famille(): array
    {
        return [
            $this->poserUnePersonne('Cobalt', 'Sureau', Sex::Masculin),
            $this->poserUnePersonne('Marne', 'Bourrache', Sex::Feminin),
            $this->poserUnePersonne('Pivoine', 'Sureau', Sex::Feminin),
            $this->poserUnePersonne('Jaspe', 'Sureau', Sex::Masculin),
        ];
    }

    /** @param list<array<string, mixed>> $retraits */
    private function deposerRetrait(array $retraits, int $cible): ChangeRequest
    {
        return $this->deposerCorps([
            'kind' => 'retrait_lien',
            'message' => 'Acte de naissance relu.',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => $cible,
            'retraits' => $retraits,
        ]);
    }

    private function libelleDiff(ChangeRequest $demande, int $seq): string
    {
        $serialiseur = self::getContainer()->get(SerialiseurDeDemande::class);
        self::assertInstanceOf(SerialiseurDeDemande::class, $serialiseur);

        foreach ($serialiseur->pourAdministration($demande)['ops'] as $op) {
            if ($seq === $op['seq']) {
                self::assertFalse($op['aBouge']);

                return (string) $op['libelleDiff'];
            }
        }

        self::fail("Aucun op n° $seq.");
    }

    /** @return list<Revision> */
    private function revisions(ResultatDeValidation $resultat, RevisionOperation $operation): array
    {
        return array_values(array_filter(
            $resultat->revisions,
            static fn (Revision $revision): bool => $operation === $revision->getOperation(),
        ));
    }

    /** @return list<Revision> */
    private function historiqueDe(int $personne): array
    {
        $revisions = self::getContainer()->get(RevisionRepository::class);
        self::assertInstanceOf(RevisionRepository::class, $revisions);

        return $revisions->historique(SubjectKind::Personne, $personne, 1, 20)['elements'];
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
}
