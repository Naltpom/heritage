<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\MotifDeFinDeRecherche;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\FinDeRecherche;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;
use App\Module\Proposal\Repository\RevisionRepository;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * **La fin de recherche** dans le circuit des demandes (ADR 0023, § 6.2 bis du contrat) :
 * deux champs de la surface d'une personne, de nature `fin_de_recherche`, ni `RequestKind`
 * ni `OpKind` nouveaux.
 *
 * Ce que ce fichier tient : la pose, la modification et la levée ; « les deux côtés » comme
 * deux ops d'une demande ; les `422` du dépôt, avec leur chemin ; la caducité (deux poses
 * concurrentes, une modification croisée d'une levée) et le rejeu ; la signature passée à
 * l'arbre (le déposant, la date du dépôt) ; le diff, la révision et le calque.
 *
 * La portée `contribution` d'un jeton est éprouvée en HTTP, par
 * {@see FinDeRechercheParJetonEnHttpTest}.
 */
final class FinDeRechercheTest extends BaseDeDemandes
{
    private const string PERE = 'finDeRecherchePere';
    private const string MERE = 'finDeRechercheMere';

    protected function setUp(): void
    {
        parent::setUp();

        $this->poserLaFiche();
    }

    // ------------------------------------------------------------------- les trois gestes

    public function testLaPoseEstAcceptee(): void
    {
        $demande = $this->deposer([$this->op(self::MERE, null, $this->brique())]);

        $ops = self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande);
        self::assertCount(1, $ops);
        self::assertSame(OpKind::Champ, $ops[0]->getOpKind());
        self::assertSame(self::MERE, $ops[0]->getField());
        self::assertSame(ValueKind::FinDeRecherche, $ops[0]->getValueKind());
        self::assertNull($ops[0]->getOldValue());
        self::assertSame(['reason', 'beforeYear', 'body'], array_keys((array) $ops[0]->getNewValue()), 'Les trois clés, dans l’ordre canonique.');

        $resultat = $this->validation->valider($demande, $this->admin());
        self::assertSame('validee', $resultat->resultat);

        $ecrite = $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE);
        self::assertInstanceOf(FinDeRecherche::class, $ecrite);
        self::assertSame(MotifDeFinDeRecherche::RegistresInexistants, $ecrite->reason);
        self::assertSame(1668, $ecrite->beforeYear);
        self::assertNull($this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::PERE), 'Le côté du père n’a pas bougé.');
    }

    public function testLesDeuxCotesSontDeuxOpsDansUneDemande(): void
    {
        $demande = $this->deposer([
            $this->op(self::PERE, null, ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null], seq: 1),
            $this->op(self::MERE, null, $this->brique(), seq: 2),
        ]);

        self::assertCount(2, self::getContainer()->get(ChangeRequestOpRepository::class)->pourDemande($demande));

        $this->validation->valider($demande, $this->admin());

        self::assertInstanceOf(FinDeRecherche::class, $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::PERE));
        self::assertInstanceOf(FinDeRecherche::class, $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE));
        self::assertCount(1, ArbreEnMemoire::signaturesRecues(), 'Un seul appel au port : une ligne, une écriture.');
    }

    public function testLaModificationRemplaceLaValeur(): void
    {
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::RegistresDetruits, null, null));

        $demande = $this->deposer([$this->op(
            self::MERE,
            ['reason' => 'registres_detruits', 'beforeYear' => null, 'body' => null],
            ['reason' => 'lacune', 'beforeYear' => null, 'body' => 'Années 1789 à 1792.'],
            base: 8,
        )]);

        // Le garde de la caducité : une `old_value` objet n'est pas un bloc date.
        self::assertSame('validee', $this->validation->valider($demande, $this->admin())->resultat);

        $ecrite = $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE);
        self::assertInstanceOf(FinDeRecherche::class, $ecrite);
        self::assertSame(MotifDeFinDeRecherche::Lacune, $ecrite->reason);
        self::assertSame('Années 1789 à 1792.', $ecrite->body);
    }

    public function testLaLeveeEfface(): void
    {
        $this->poserUneFin(self::PERE, new FinDeRecherche(MotifDeFinDeRecherche::PereInconnu, null, null));

        $demande = $this->deposer([$this->op(self::PERE, ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null], null, base: 8)]);

        self::assertSame('validee', $this->validation->valider($demande, $this->admin())->resultat);
        self::assertNull($this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::PERE));
    }

    public function testLeTexteEstRogneEtVideVautNull(): void
    {
        $demande = $this->deposer([
            $this->op(self::MERE, null, ['reason' => 'lacune', 'beforeYear' => null, 'body' => "  Un texte \n"], seq: 1),
            $this->op(self::PERE, null, ['reason' => 'registres_detruits', 'beforeYear' => null, 'body' => '   '], seq: 2),
        ]);
        $this->validation->valider($demande, $this->admin());

        $mere = $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE);
        $pere = $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::PERE);
        self::assertInstanceOf(FinDeRecherche::class, $mere);
        self::assertInstanceOf(FinDeRecherche::class, $pere);
        self::assertSame('Un texte', $mere->body);
        self::assertNull($pere->body);
    }

    // --------------------------------------------------------------------- les 422

    /**
     * @param array<array-key, mixed> $valeur
     */
    #[DataProvider('lesValeursRefusees')]
    public function testLesValeursFautivesSontRefuseesAvecLeurChemin(string $champ, array $valeur, string $chemin): void
    {
        try {
            $this->deposer([$this->op($champ, null, $valeur)]);
            self::fail('Cette valeur doit être refusée.');
        } catch (DonneesInvalides $erreur) {
            self::assertSame(422, $erreur->statutHttp());
            self::assertSame([$chemin], array_keys($erreur->champs() ?? []));
        }
    }

    /** @return iterable<string, array{string, array<array-key, mixed>, string}> */
    public static function lesValeursRefusees(): iterable
    {
        yield 'clé manquante' => [self::MERE, ['reason' => 'lacune', 'beforeYear' => null], 'ops[0].newValue'];
        yield 'clé en plus (authorLabel)' => [self::MERE, ['reason' => 'lacune', 'beforeYear' => null, 'body' => null, 'authorLabel' => 'Opale'], 'ops[0].newValue'];
        yield 'clé en plus (closedAt)' => [self::MERE, ['reason' => 'lacune', 'beforeYear' => null, 'body' => null, 'closedAt' => '2026-10-30T10:00:00+01:00'], 'ops[0].newValue'];
        yield 'motif inconnu' => [self::MERE, ['reason' => 'au_bon_plaisir', 'beforeYear' => null, 'body' => null], 'ops[0].newValue.reason'];
        yield 'année absente' => [self::MERE, ['reason' => 'registres_inexistants', 'beforeYear' => null, 'body' => null], 'ops[0].newValue.beforeYear'];
        yield 'année trop ancienne' => [self::MERE, ['reason' => 'registres_inexistants', 'beforeYear' => 999, 'body' => null], 'ops[0].newValue.beforeYear'];
        yield 'année dans le futur' => [self::MERE, ['reason' => 'registres_inexistants', 'beforeYear' => 9999, 'body' => null], 'ops[0].newValue.beforeYear'];
        yield 'année en chaîne' => [self::MERE, ['reason' => 'registres_inexistants', 'beforeYear' => '1668', 'body' => null], 'ops[0].newValue.beforeYear'];
        yield 'année sur un autre motif' => [self::MERE, ['reason' => 'lacune', 'beforeYear' => 1668, 'body' => null], 'ops[0].newValue.beforeYear'];
        yield 'autre sans texte' => [self::MERE, ['reason' => 'autre', 'beforeYear' => null, 'body' => null], 'ops[0].newValue.body'];
        yield 'autre avec texte blanc' => [self::MERE, ['reason' => 'autre', 'beforeYear' => null, 'body' => "  \n "], 'ops[0].newValue.body'];
        yield 'texte trop long' => [self::MERE, ['reason' => 'lacune', 'beforeYear' => null, 'body' => str_repeat('é', 4001)], 'ops[0].newValue.body'];
        yield 'texte non chaîne' => [self::MERE, ['reason' => 'lacune', 'beforeYear' => null, 'body' => 12], 'ops[0].newValue.body'];
        yield 'père inconnu côté mère' => [self::MERE, ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null], 'ops[0].newValue.reason'];
        yield 'valeur en liste' => [self::MERE, ['lacune'], 'ops[0].newValue'];
    }

    public function testQuatreMilleCaracteresPassentEtLeComptageEstEnCaracteres(): void
    {
        $demande = $this->deposer([$this->op(self::MERE, null, ['reason' => 'lacune', 'beforeYear' => null, 'body' => str_repeat('é', 4000)])]);

        self::assertSame('en_attente', $demande->getStatus()->value);
    }

    public function testLAnneeEnCoursEstAdmise(): void
    {
        $demande = $this->deposer([$this->op(self::MERE, null, ['reason' => 'registres_inexistants', 'beforeYear' => (int) date('Y'), 'body' => null])]);

        self::assertSame('en_attente', $demande->getStatus()->value);
    }

    public function testPoserSurUnCoteDontUnParentEstLieEstRefuse(): void
    {
        $this->arbre->poserCotesConnus(self::PERSONNE, pere: true, mere: false);

        try {
            $this->deposer([$this->op(self::PERE, null, ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null])]);
            self::fail('Un parent est lié du côté du père : la recherche n’y est pas close.');
        } catch (DonneesInvalides $erreur) {
            self::assertSame(['ops[0].newValue'], array_keys($erreur->champs() ?? []));
            self::assertSame('Un parent est déjà lié du côté du père : la recherche n\'y est pas close.', $erreur->champs()['ops[0].newValue'] ?? null);
        }

        // L'autre côté reste libre.
        self::assertSame('en_attente', $this->deposer([$this->op(self::MERE, null, $this->brique())])->getStatus()->value);

        // Et la mère, une fois liée, dit « de la mère ».
        $this->arbre->poserCotesConnus(self::PERSONNE, pere: false, mere: true);
        try {
            $this->deposer([$this->op(self::MERE, null, $this->brique())]);
            self::fail('Un parent est lié du côté de la mère.');
        } catch (DonneesInvalides $erreur) {
            self::assertStringContainsString('du côté de la mère', $erreur->champs()['ops[0].newValue'] ?? '');
        }
    }

    public function testModifierUnCoteDontUnParentEstLieEstRefuse(): void
    {
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::RegistresDetruits, null, null));
        $this->arbre->poserCotesConnus(self::PERSONNE, pere: false, mere: true);

        $this->expectException(DonneesInvalides::class);

        $this->deposer([$this->op(
            self::MERE,
            ['reason' => 'registres_detruits', 'beforeYear' => null, 'body' => null],
            ['reason' => 'lacune', 'beforeYear' => null, 'body' => null],
            base: 8,
        )]);
    }

    public function testLaLeveeResteAcceptableQuandUnParentEstLie(): void
    {
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::RegistresDetruits, null, null));
        $this->arbre->poserCotesConnus(self::PERSONNE, pere: false, mere: true);

        $demande = $this->deposer([$this->op(self::MERE, ['reason' => 'registres_detruits', 'beforeYear' => null, 'body' => null], null, base: 8)]);
        $this->validation->valider($demande, $this->admin());

        self::assertNull($this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE));
    }

    public function testUneValeurIdentiqueApresNormalisationEstRefusee(): void
    {
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::Lacune, null, 'Même texte'));

        try {
            $this->deposer([$this->op(
                self::MERE,
                ['reason' => 'lacune', 'beforeYear' => null, 'body' => 'Même texte'],
                ['reason' => 'lacune', 'beforeYear' => null, 'body' => "  Même texte  \n"],
                base: 8,
            )]);
            self::fail('Un texte rogné identique ne modifie rien.');
        } catch (DonneesInvalides $erreur) {
            self::assertSame(['ops[0].newValue'], array_keys($erreur->champs() ?? []));
        }
    }

    public function testUnAncienneValeurQuiNEstPasLaCouranteEstUnConflit(): void
    {
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::RegistresDetruits, null, null));

        $this->expectException(Conflit::class);

        // Le formulaire croit le côté libre : la fiche a changé pendant qu’il était ouvert.
        $this->deposer([$this->op(self::MERE, null, $this->brique(), base: 8)]);
    }

    /**
     * Le `409` ne doit pas rendre ce que la fiche réduite cache : sur un vivant hors du
     * cercle du déposant, ni la valeur courante ni la valeur annoncée (motif, année et
     * texte libre d'une fin de recherche) ne figurent dans `details`.
     */
    public function testLeConflitSurUneFicheMasqueeNeRendNiValeurCouranteNiAnnoncee(): void
    {
        $this->arbre->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', 7, ['deceased' => false] + $this->champs());
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::RegistresInexistants, 1700, 'Texte réservé à la fiche entière.'));

        try {
            $this->deposer([$this->op(self::MERE, null, $this->brique(), base: 8)]);
            self::fail('Le côté est déjà clos : le dépôt doit être en conflit.');
        } catch (Conflit $conflit) {
            self::assertSame('conflit', $conflit->codeErreur());
            $details = (array) $conflit->details();
            self::assertSame(self::MERE, $details['field']);
            self::assertArrayNotHasKey('valeurCourante', $details);
            self::assertArrayNotHasKey('valeurAnnoncee', $details);
            self::assertStringNotContainsString('réservé', (string) json_encode($details));
        }
    }

    /** Même chemin pour `privacyNote` : le `409` d'une fiche masquée ne la divulgue pas. */
    public function testLeConflitSurLaNoteDeConfidentialiteDUneFicheMasqueeNeLaDivulguePas(): void
    {
        $this->arbre->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', 7, ['deceased' => false, 'privacyNote' => 'Note secrète'] + $this->champs());

        try {
            $this->depot->deposer($this->corpsDeModification([$this->opTexte('privacyNote', 'Autre chose', 'Nouvelle note', baseVersion: 7)]), $this->lecteur(self::OPALE));
            self::fail('L\'ancienne valeur est fausse : le dépôt doit être en conflit.');
        } catch (Conflit $conflit) {
            $details = (array) $conflit->details();
            self::assertArrayNotHasKey('valeurCourante', $details);
            self::assertArrayNotHasKey('valeurAnnoncee', $details);
            self::assertStringNotContainsString('secrète', (string) json_encode($details));
        }
    }

    public function testLeDepotABlancRendToutesLesErreursEnUnePasse(): void
    {
        $this->arbre->poserCotesConnus(self::PERSONNE, pere: true, mere: false);

        try {
            $this->depot->verifierSansEcrire($this->corpsDeModification([
                $this->op(self::MERE, null, ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null], seq: 1),
                $this->op(self::MERE, null, ['reason' => 'registres_inexistants', 'beforeYear' => null, 'body' => null], seq: 2),
                $this->op(self::PERE, null, ['reason' => 'lacune', 'beforeYear' => null, 'body' => null], seq: 3),
                $this->op(self::MERE, null, ['reason' => 'autre', 'beforeYear' => null, 'body' => null], seq: 4),
            ]), $this->lecteur(self::OPALE));
            self::fail('Quatre ops fautifs : la vérification doit les dire tous.');
        } catch (DonneesInvalides $erreur) {
            self::assertSame(
                ['ops[0].newValue.reason', 'ops[1].newValue.beforeYear', 'ops[2].newValue', 'ops[3].newValue.body'],
                array_keys($erreur->champs() ?? []),
            );
        }
    }

    // ----------------------------------------------------------------------- caducité

    public function testDeuxPosesConcurrentesDuMemeCoteLaSecondeEstCaduque(): void
    {
        $premiere = $this->deposer([$this->op(self::MERE, null, $this->brique())]);
        $seconde = $this->deposer([$this->op(self::MERE, null, ['reason' => 'registres_detruits', 'beforeYear' => null, 'body' => null])]);

        $this->validation->valider($premiere, $this->admin());

        try {
            $this->validation->valider($seconde, $this->admin());
            self::fail('Le côté est déjà clos : la seconde pose doit être caduque.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
            self::assertSame(self::MERE, ((array) $conflit->details())['champs'][0]['field']);
        }

        $ecrite = $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE);
        self::assertInstanceOf(FinDeRecherche::class, $ecrite);
        self::assertSame(MotifDeFinDeRecherche::RegistresInexistants, $ecrite->reason, 'La première pose n’est pas écrasée.');
    }

    public function testUneModificationCroiseeAvecUneLeveeEstCaduque(): void
    {
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::RegistresDetruits, null, null));
        $ancienne = ['reason' => 'registres_detruits', 'beforeYear' => null, 'body' => null];

        $modification = $this->deposer([$this->op(self::MERE, $ancienne, ['reason' => 'lacune', 'beforeYear' => null, 'body' => null], base: 8)]);
        $levee = $this->deposer([$this->op(self::MERE, $ancienne, null, base: 8)]);

        $this->validation->valider($levee, $this->admin());
        self::assertNull($this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE));

        try {
            $this->validation->valider($modification, $this->admin());
            self::fail('Le côté a été rouvert entre-temps : la modification doit être caduque.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
        }

        self::assertNull($this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE), 'Rien n’a été réécrit.');
    }

    public function testUnAutreChampValideEntreTempsFaitRejouer(): void
    {
        $demande = $this->deposer([$this->op(self::MERE, null, $this->brique())]);

        $this->arbre->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, 'surname', 'SUREAU');

        self::assertSame('rejouee', $this->validation->valider($demande, $this->admin())->resultat);
        self::assertInstanceOf(FinDeRecherche::class, $this->arbre->valeur(SubjectKind::Personne, self::PERSONNE, self::MERE));
    }

    // ------------------------------------------------------------- signature, diff, calque

    public function testLeValidateurPasseLeDeposantEtLaDateDuDepot(): void
    {
        $demande = $this->deposer([$this->op(self::MERE, null, $this->brique())]);
        $depose = $demande->getSubmittedAt();
        self::assertNotNull($depose);

        $this->validation->valider($demande, $this->admin());

        $signatures = ArbreEnMemoire::signaturesRecues();
        self::assertCount(1, $signatures);
        self::assertSame(self::OPALE, $signatures[0]->auteurUserId, 'Le déposant, pas l’administrateur.');
        self::assertNotSame(self::ADMIN, $signatures[0]->auteurUserId);
        self::assertSame('Compte '.self::OPALE, $signatures[0]->auteurLabel);
        self::assertEquals($depose, $signatures[0]->deposeeLe, 'La date du dépôt, pas celle de la validation.');
    }

    public function testLeDiffDitAucuneVersLaPhrase(): void
    {
        $demande = $this->deposer([
            $this->op(self::MERE, null, ['reason' => 'registres_inexistants', 'beforeYear' => 1668, 'body' => 'Saint-Martin commence en 1668.'], seq: 2),
            $this->op(self::PERE, null, ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null], seq: 1),
        ]);

        $libelles = array_map(static fn ($etat): string => $etat->libelleDiff, $this->analyse->analyser($demande)->ops);

        self::assertContains('Fin de recherche côté mère : (aucune) → Registres inexistants avant 1668 — Saint-Martin commence en 1668.', $libelles);
        self::assertContains('Fin de recherche côté père : (aucune) → Père inconnu', $libelles);
    }

    public function testLeDiffDUneLeveeDitAucune(): void
    {
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::Lacune, null, null));
        $demande = $this->deposer([$this->op(self::MERE, ['reason' => 'lacune', 'beforeYear' => null, 'body' => null], null, base: 8)]);

        self::assertSame('Fin de recherche côté mère : Lacune dans les registres → (aucune)', $this->analyse->analyser($demande)->ops[0]->libelleDiff);
    }

    public function testLaRevisionDitLaPoseEtLaLevee(): void
    {
        $pose = $this->deposer([$this->op(self::MERE, null, $this->brique())]);
        $this->validation->valider($pose, $this->admin());

        $revisions = self::getContainer()->get(RevisionRepository::class)->pourDemande($pose);
        self::assertSame(
            ['avant' => '(aucune)', 'apres' => 'Registres inexistants avant 1668 — Saint-Martin commence en 1668.'],
            $revisions[0]->getChanges()[self::MERE],
        );

        $levee = $this->deposer([$this->op(self::MERE, $this->brique(), null, base: 8)]);
        $this->validation->valider($levee, $this->admin());

        $revisions = self::getContainer()->get(RevisionRepository::class)->pourDemande($levee);
        self::assertSame(
            ['avant' => 'Registres inexistants avant 1668 — Saint-Martin commence en 1668.', 'apres' => '(aucune)'],
            $revisions[0]->getChanges()[self::MERE],
        );
    }

    public function testLeCalqueMontreLaPoseALAuteurEtALAdministrateurSeulement(): void
    {
        $this->deposer([$this->op(self::MERE, null, $this->brique())]);

        $pourOpale = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE));
        $pourAdmin = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::ADMIN, administrateur: true));
        $pourTuf = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::TUF));

        foreach ([$pourOpale, $pourAdmin] as $calque) {
            self::assertArrayHasKey(self::MERE, $calque);
            self::assertNull($calque[self::MERE]['actuel'], 'Pose : rien de validé.');
            self::assertSame($this->brique(), $calque[self::MERE]['propose'], 'Trois clés, aucune clé d’affichage ajoutée.');
        }
        self::assertSame([], $pourTuf, 'Un autre membre ne reçoit rien.');
    }

    public function testLeCalqueDUneLeveeProposeNull(): void
    {
        $this->poserUneFin(self::MERE, new FinDeRecherche(MotifDeFinDeRecherche::Lacune, null, null));
        $this->deposer([$this->op(self::MERE, ['reason' => 'lacune', 'beforeYear' => null, 'body' => null], null, base: 8)]);

        $calque = $this->calque->pour(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE));

        self::assertNull($calque[self::MERE]['propose']);
        self::assertSame(['reason' => 'lacune', 'beforeYear' => null, 'body' => null], $calque[self::MERE]['actuel']);
    }

    public function testLaSurfaceDeclareLesDeuxChampsEtLeurLibelle(): void
    {
        self::assertSame(ValueKind::FinDeRecherche, $this->surface->natureDe(SubjectKind::Personne, self::PERE));
        self::assertSame(ValueKind::FinDeRecherche, $this->surface->natureDe(SubjectKind::Personne, self::MERE));
        self::assertSame('Fin de recherche côté père', $this->surface->libelle(self::PERE));
        self::assertSame('Fin de recherche côté mère', $this->surface->libelle(self::MERE));
        self::assertNull($this->surface->natureDe(SubjectKind::Famille, self::PERE));
        self::assertSame(16, \strlen(ValueKind::FinDeRecherche->value), 'Tient pile dans value_kind VARCHAR(16).');
    }

    // ------------------------------------------------------------------------ outils

    private function poserLaFiche(): void
    {
        $this->arbre->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', 7, $this->champs());
    }

    /** Écrit une fin de recherche « venue d’ailleurs » : la version monte, comme après une validation. */
    private function poserUneFin(string $champ, FinDeRecherche $fin): void
    {
        $this->arbre->modifierAilleurs(SubjectKind::Personne, self::PERSONNE, $champ, $fin);
    }

    /** @return array<string, mixed> */
    private function champs(): array
    {
        return [
            'givenNames' => 'Marne Dolomite',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => true,
            'birth' => $this->annee(1987),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
            self::PERE => null,
            self::MERE => null,
        ];
    }

    /** @return array{reason: string, beforeYear: int|null, body: string|null} */
    private function brique(): array
    {
        return [
            'reason' => 'registres_inexistants',
            'beforeYear' => 1668,
            'body' => 'Saint-Martin commence en 1668.',
        ];
    }

    /**
     * @param array<array-key, mixed>|null $ancienne
     * @param array<array-key, mixed>|null $nouvelle
     *
     * @return array<string, mixed>
     */
    private function op(string $champ, ?array $ancienne, ?array $nouvelle, int $seq = 1, int $base = 7): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'champ',
            'subjectKind' => 'personne',
            'subjectId' => self::PERSONNE,
            'field' => $champ,
            'valueKind' => 'fin_de_recherche',
            'oldValue' => $ancienne,
            'newValue' => $nouvelle,
            'baseVersion' => $base,
        ];
    }

    /**
     * @param list<array<string, mixed>> $ops
     */
    private function deposer(array $ops): ChangeRequest
    {
        return $this->depot->deposer($this->corpsDeModification($ops), $this->lecteur(self::OPALE));
    }

    private function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }
}
