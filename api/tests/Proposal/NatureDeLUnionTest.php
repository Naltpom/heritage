<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\RevisionRepository;
use App\Module\Proposal\Service\EmpreinteDeDemande;

/**
 * **La nature d'une union** dans le circuit des demandes (ADR 0020) : mariés — daté ou
 * non —, union libre, on ne sait pas.
 *
 * Ce que ce fichier tient, cas par cas :
 *
 * - `married` est un booléen **tri-état** de la surface d'une famille : `null` y est une
 *   valeur, ailleurs non ;
 * - un mariage daté ou situé est un mariage : la demande qui date une union de nature
 *   inconnue reçoit l'op `married : null → true` — visible, journalisé, confronté au
 *   rejeu —, et celle qui contredit sa date est refusée ;
 * - le diff dit « Divorcés » ou « Séparés » selon la nature, et « Mariés : on ne sait pas
 *   → oui » plutôt que « (vide) → oui » ;
 * - un lien `conjoint_de` porte sa nature, de son dépôt à la famille qu'il écrit.
 *
 * Les couples sont inventés ; aucun n'existe dans l'arbre réel (ADR 0006).
 */
final class NatureDeLUnionTest extends BaseDeDemandes
{
    /** Le couple Jargon × Agate : une union sans date, de nature inconnue. */
    private const int COUPLE = 40;

    /** Jargon Sarriette et Agate Persil. */
    private const int JARGON = 55;
    private const int AGATE = 56;

    protected function setUp(): void
    {
        parent::setUp();

        $this->poserLeCouple(null);
        $this->arbre
            ->poser(SubjectKind::Personne, self::JARGON, 'Jargon Sarriette', 3, $this->champsDUnePersonne('Jargon', 'Sarriette'))
            ->poser(SubjectKind::Personne, self::AGATE, 'Agate Persil', 2, $this->champsDUnePersonne('Agate', 'Persil'));
    }

    // ------------------------------------------------------------- modification

    /** Le cas qui a déclenché le chantier : « ils se sont mariés, on ne sait pas quand ». */
    public function testUneUnionSansDatePeutEtreDiteMariee(): void
    {
        $demande = $this->modifier([$this->opNature(null, true)]);

        $plan = $this->analyse->analyser($demande);
        self::assertSame('Mariés : on ne sait pas → oui', $plan->ops[0]->libelleDiff, '« (vide) → oui » se lirait comme un oubli.');

        $this->validation->valider($demande, $this->admin());

        self::assertTrue($this->arbre->valeur(SubjectKind::Famille, self::COUPLE, 'married'));
        $revisions = self::getContainer()->get(RevisionRepository::class)->pourDemande($demande);
        self::assertSame(['avant' => 'on ne sait pas', 'apres' => 'oui'], $revisions[0]->getChanges()['married']);
    }

    public function testUneUnionLibreSeDitEtSeDefait(): void
    {
        $libre = $this->modifier([$this->opNature(null, false)]);
        $this->validation->valider($libre, $this->admin());
        self::assertFalse($this->arbre->valeur(SubjectKind::Famille, self::COUPLE, 'married'));

        // Et l'on peut revenir à « on ne sait pas » : `null` est une valeur de ce champ.
        $doute = $this->modifier([$this->opNature(false, null, $this->versionDuCouple())]);
        self::assertSame('Mariés : non (union libre) → on ne sait pas', $this->analyse->analyser($doute)->ops[0]->libelleDiff);
        $this->validation->valider($doute, $this->admin());

        self::assertNull($this->arbre->valeur(SubjectKind::Famille, self::COUPLE, 'married'));
    }

    /**
     * Le tri-état est **réservé** à `married`. Sur `deceased`, un `null` finirait en
     * `false` dans l'arbre — « vivant » —, à la place de qui n'a rien dit.
     */
    public function testLeNulNEstAdmisQueSurLaNatureDUneUnion(): void
    {
        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer($this->corpsDeModification([[
            'seq' => 1, 'opKind' => 'champ', 'subjectKind' => 'personne', 'subjectId' => self::PERSONNE,
            'field' => 'deceased', 'valueKind' => 'booleen', 'oldValue' => true, 'newValue' => null, 'baseVersion' => 7,
        ]]), $this->lecteur(self::OPALE));
    }

    /**
     * Une date posée sur une union de nature inconnue **entraîne** l'op de nature : la
     * déduction est certaine, et un op — plutôt qu'une écriture muette de `Tree` — se
     * lit dans le diff, se journalise et se confronte au rejeu.
     */
    public function testDaterUneUnionDeNatureInconnueAjouteLOpDeNature(): void
    {
        $demande = $this->modifier([$this->opMariage(UncertainDate::inconnue(), $this->annee(1962))]);

        $ops = self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande);
        self::assertCount(2, $ops);
        self::assertSame('married', $ops[1]->getField());
        self::assertSame(2, $ops[1]->getSeq());
        self::assertNull($ops[1]->getOldValue());
        self::assertTrue($ops[1]->getNewValue());
        self::assertSame(4, $ops[1]->getBaseVersion(), 'La version lue par l’auteur, celle de l’op qui date.');

        $this->validation->valider($demande, $this->admin());

        self::assertTrue($this->arbre->valeur(SubjectKind::Famille, self::COUPLE, 'married'));
    }

    /** Un lieu seul suffit : « marié à Saule, date inconnue ». */
    public function testUnLieuDeMariageSeulEntraineAussiLaNature(): void
    {
        $demande = $this->modifier([$this->opMariage(UncertainDate::inconnue(), UncertainDate::inconnue('Saule'))]);

        self::assertCount(2, self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande));
    }

    /** Une demande qui dit elle-même la nature n'a pas besoin qu'on la complète. */
    public function testUneDemandeQuiDitMariesNEstPasCompletee(): void
    {
        $demande = $this->modifier([
            $this->opMariage(UncertainDate::inconnue(), $this->annee(1962)),
            $this->opNature(null, true, seq: 2),
        ]);

        self::assertCount(2, self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande));
    }

    /** « Union libre » sous une date de mariage : une pièce contre une déclaration — on ne tranche pas. */
    public function testUneNatureQuiContreditLaDateEstRefusee(): void
    {
        // « Union libre » sur une union de nature inconnue, et « on ne sait pas » sur une
        // union dite mariée : les deux contredisent la date que la même demande pose.
        foreach ([[null, false], [true, null]] as [$avant, $apres]) {
            $this->poserLeCouple($avant);

            try {
                $this->modifier([
                    $this->opMariage(UncertainDate::inconnue(), $this->annee(1962)),
                    $this->opNature($avant, $apres, seq: 2),
                ]);
                self::fail(\sprintf('« %s » sous une date de mariage doit être refusé.', var_export($apres, true)));
            } catch (DonneesInvalides $erreur) {
                self::assertSame(422, $erreur->statutHttp());
                self::assertStringContainsString('attestent le mariage', (string) json_encode($erreur->champs(), \JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /** Dater une union **enregistrée libre** sans la requalifier : on ne l'efface pas en silence. */
    public function testDaterUneUnionLibreSansLaRequalifierEstRefuse(): void
    {
        $this->poserLeCouple(false);

        try {
            $this->modifier([$this->opMariage(UncertainDate::inconnue(), $this->annee(1962))]);
            self::fail('Dater une union libre sans la dire mariée doit être refusé.');
        } catch (DonneesInvalides $erreur) {
            self::assertStringContainsString('union libre', (string) json_encode($erreur->champs(), \JSON_UNESCAPED_UNICODE));
        }

        // Requalifiée dans la même demande, elle passe.
        $demande = $this->modifier([
            $this->opMariage(UncertainDate::inconnue(), $this->annee(1962)),
            $this->opNature(false, true, seq: 2),
        ]);
        $this->validation->valider($demande, $this->admin());

        self::assertTrue($this->arbre->valeur(SubjectKind::Famille, self::COUPLE, 'married'));
    }

    /**
     * Le rejeu **voit** la nature : une union dite libre entre le dépôt et la validation
     * rend caduque la demande qui la datait, au lieu de la requalifier sans que personne
     * l'ait décidé. C'est ce que l'op implicite achète.
     */
    public function testLeRejeuConfronteLaNatureImplicite(): void
    {
        $demande = $this->modifier([$this->opMariage(UncertainDate::inconnue(), $this->annee(1962))]);

        $this->arbre->modifierAilleurs(SubjectKind::Famille, self::COUPLE, 'married', false);

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('La nature a bougé : la demande doit être caduque.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
            self::assertSame('married', ((array) $conflit->details())['champs'][0]['field']);
        }
    }

    /** Une nature qui n'a pas bougé laisse rejouer : seule la version a changé. */
    public function testLeRejeuPasseQuandLaNatureNAPasBouge(): void
    {
        $demande = $this->modifier([$this->opMariage(UncertainDate::inconnue(), $this->annee(1962))]);

        $this->arbre->modifierAilleurs(SubjectKind::Famille, self::COUPLE, 'divorced', true);

        self::assertSame('rejouee', $this->validation->valider($demande, $this->admin())->resultat);
        self::assertTrue($this->arbre->valeur(SubjectKind::Famille, self::COUPLE, 'married'));
    }

    /**
     * « Divorcés » ou « Séparés » : on ne divorce que d'un mariage, et l'on n'affirme pas
     * un mariage qu'on ignore. La nature lue est celle d'**après** la demande.
     */
    public function testLeDiffDitDivorcesOuSeparesSelonLaNature(): void
    {
        $rupture = fn (int $seq = 1): array => [
            'seq' => $seq, 'opKind' => 'champ', 'subjectKind' => 'famille', 'subjectId' => self::COUPLE,
            'field' => 'divorced', 'valueKind' => 'booleen', 'oldValue' => false, 'newValue' => true, 'baseVersion' => 4,
        ];

        self::assertSame('Séparés : non → oui', $this->libelles($this->modifier([$rupture()]))[0], 'Nature inconnue : séparés.');

        self::assertSame(
            ['Mariés : on ne sait pas → oui', 'Divorcés : non → oui'],
            $this->libelles($this->modifier([$this->opNature(null, true), $rupture(2)])),
            'La demande dit « mariés » : la rupture est un divorce.',
        );

        $this->poserLeCouple(true);
        self::assertSame('Divorcés : non → oui', $this->libelles($this->modifier([$rupture()]))[0]);

        $this->poserLeCouple(false);
        self::assertSame('Séparés : non → oui', $this->libelles($this->modifier([$rupture()]))[0]);
    }

    // ------------------------------------------------------------------- liens

    public function testUnLienConjointPorteSaNatureJusquALaFamille(): void
    {
        $demande = $this->ajouterUnConjoint(['married' => true, 'divorced' => true]);

        $liens = self::getContainer()->get(DraftLinkRepository::class)->pourDemande($demande);
        self::assertTrue($liens[0]->isMarried());
        self::assertTrue($this->serialiseur->pourAuteur($demande)['liens'][0]['married']);

        $this->validation->valider($demande, $this->admin());

        $famille = $this->arbre->famillesCreees()[0];
        self::assertTrue($this->arbre->valeur(SubjectKind::Famille, $famille, 'married'), 'Mariés, puis divorcés : la date manque, pas le mariage.');
        self::assertTrue($this->arbre->valeur(SubjectKind::Famille, $famille, 'divorced'));
    }

    public function testUnLienSansNatureResteDeNatureInconnue(): void
    {
        $demande = $this->ajouterUnConjoint([]);

        self::assertNull(self::getContainer()->get(DraftLinkRepository::class)->pourDemande($demande)[0]->isMarried());
        self::assertArrayHasKey('married', $this->serialiseur->pourAuteur($demande)['liens'][0], '`null` est émis : c’est une réponse.');
    }

    /** Une date sur le lien dit le mariage, sans qu'on ait à le cocher. */
    public function testUneDateSurLeLienDitLeMariage(): void
    {
        $demande = $this->ajouterUnConjoint(['marriage' => $this->blocDate($this->annee(1934))]);

        self::assertTrue(self::getContainer()->get(DraftLinkRepository::class)->pourDemande($demande)[0]->isMarried());
    }

    public function testUneUnionLibreDateeEstRefusee(): void
    {
        $this->expectException(DonneesInvalides::class);

        $this->ajouterUnConjoint(['married' => false, 'marriage' => $this->blocDate($this->annee(1934))]);
    }

    /** La nature ne vaut que sur une union, comme ses dates (`ck_prp_link_dates_union`). */
    public function testUneFiliationNePortePasDeNature(): void
    {
        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer([
            'kind' => 'ajout_lien',
            'soumettre' => true,
            'liens' => [['seq' => 1, 'kind' => 'enfant_de', 'subjectPersonId' => self::JARGON, 'otherPersonId' => self::AGATE, 'married' => false]],
        ], $this->lecteur(self::OPALE));
    }

    public function testRefaireUnLienRepriseSaNature(): void
    {
        $demande = $this->ajouterUnConjoint(['married' => false]);
        $this->refus->refuser($demande, $this->admin(), 'Le couple est déjà décrit ailleurs.', null);

        $corps = $this->reprise->corpsPreRempli($demande);

        self::assertFalse($corps['liens'][0]['married']);
    }

    /** Deux liens qui ne diffèrent que par la nature ne valident pas la même chose. */
    public function testLEmpreinteDUneDemandeSuitLaNatureDeSesLiens(): void
    {
        $demande = $this->ajouterUnConjoint(['married' => false]);
        $empreintes = self::getContainer()->get(EmpreinteDeDemande::class);
        $avant = $empreintes->de($demande);

        self::getContainer()->get(DraftLinkRepository::class)->pourDemande($demande)[0]->setMarried(true);

        self::assertNotSame($avant, $empreintes->de($demande));
    }

    // ------------------------------------------------------------------- outils

    /** Pose (ou repose) le couple, à la version 4, avec la nature voulue et sans date. */
    private function poserLeCouple(?bool $nature): void
    {
        $this->arbre->poserCouple(self::COUPLE, 'Jargon Sarriette × Agate Persil', self::JARGON, self::AGATE);
        $this->arbre->poser(SubjectKind::Famille, self::COUPLE, 'Jargon Sarriette × Agate Persil', 4, [
            'marriage' => UncertainDate::inconnue(),
            'married' => $nature,
            'divorced' => false,
            'divorce' => UncertainDate::inconnue(),
        ]);
    }

    private function versionDuCouple(): int
    {
        return $this->arbre->version(SubjectKind::Famille, self::COUPLE) ?? 0;
    }

    /**
     * @param list<array<string, mixed>> $ops
     */
    private function modifier(array $ops): ChangeRequest
    {
        return $this->depot->deposer([
            'kind' => 'modification_famille',
            'soumettre' => true,
            'targetKind' => 'famille',
            'targetId' => self::COUPLE,
            'ops' => $ops,
        ], $this->lecteur(self::OPALE));
    }

    /** @return array<string, mixed> */
    private function opNature(?bool $ancienne, ?bool $nouvelle, ?int $baseVersion = null, int $seq = 1): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'champ',
            'subjectKind' => 'famille',
            'subjectId' => self::COUPLE,
            'field' => 'married',
            'valueKind' => 'booleen',
            'oldValue' => $ancienne,
            'newValue' => $nouvelle,
            'baseVersion' => $baseVersion ?? 4,
        ];
    }

    /** @return array<string, mixed> */
    private function opMariage(UncertainDate $ancienne, UncertainDate $nouvelle, int $seq = 1): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'champ',
            'subjectKind' => 'famille',
            'subjectId' => self::COUPLE,
            'field' => 'marriage',
            'valueKind' => 'date_incertaine',
            'oldValue' => $this->blocDate($ancienne),
            'newValue' => $this->blocDate($nouvelle),
            'baseVersion' => 4,
        ];
    }

    /**
     * Un `ajout_lien` « Jargon conjoint d'Agate », avec ce que le cas ajoute.
     *
     * @param array<string, mixed> $union
     */
    private function ajouterUnConjoint(array $union): ChangeRequest
    {
        // Un autre couple que celui du décor : la validation doit créer sa famille.
        $this->arbre->poser(SubjectKind::Personne, 57, 'Lave Romarin', 1, $this->champsDUnePersonne('Lave', 'Romarin'));

        return $this->depot->deposer([
            'kind' => 'ajout_lien',
            'soumettre' => true,
            'liens' => [['seq' => 1, 'kind' => 'conjoint_de', 'subjectPersonId' => self::JARGON, 'otherPersonId' => 57] + $union],
        ], $this->lecteur(self::OPALE));
    }

    /** @return list<string> */
    private function libelles(ChangeRequest $demande): array
    {
        return array_map(static fn ($etat): string => $etat->libelleDiff, $this->analyse->analyser($demande)->ops);
    }

    /** @return array<string, mixed> */
    private function champsDUnePersonne(string $prenom, string $nom): array
    {
        return [
            'givenNames' => $prenom,
            'surname' => $nom,
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            'deceased' => true,
            'birth' => UncertainDate::inconnue(),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ];
    }

    private function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }
}
