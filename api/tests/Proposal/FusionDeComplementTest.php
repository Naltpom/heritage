<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Media\Entity\Media;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Service\CatalogueMedia;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\AccesRefuse;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\ErreurDeDemande;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * FUSIONNER UN COMPLÉMENT — § 6.7 bis, ADR 0017.
 *
 * **L'origine** est ici une proposition d'Opale à **deux fiches** : Céleste (`#p1`) et son
 * père Barnabé (`#p2`), époux de Jargon. C'est Barnabé que Tuf complète. Deux fiches plutôt
 * qu'une, pour qu'une traduction de référence fautive se voie : le `#p1` du complément doit
 * devenir le `#p2` de l'origine, jamais rester `#p1` — qui y désigne Céleste.
 */
final class FusionDeComplementTest extends BaseDeCompletion
{
    /**
     * **Le piège SQLite** que `tests/Media/CasDeTestMedia.php` décrit déjà :
     * `uniq_med_portrait_actif` est un index unique **partiel**, et la plateforme SQLite de
     * DBAL n'en transcrit pas la clause `WHERE`. Il devient un `UNIQUE` plein sur
     * `(subject_kind, subject_id)`, qui interdirait à une personne créée de recevoir **deux**
     * pièces — exactement ce que la fusion doit produire. On le recrée avec sa condition.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connexion = $this->em->getConnection();
        $connexion->executeStatement('DROP INDEX IF EXISTS uniq_med_portrait_actif');
        $connexion->executeStatement(
            'CREATE UNIQUE INDEX uniq_med_portrait_actif ON med_media (subject_kind, subject_id) '
            ."WHERE kind = 'portrait' AND is_active_portrait = 1",
        );
    }

    protected function tearDown(): void
    {
        $this->oublierLesMedias();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------
    // Les champs
    // ---------------------------------------------------------------------------------

    public function testLesChampsVidesDeLOrigineSontRemplis(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine, [
            'sex' => 'masculin',
            'nickname' => 'Bébert',
            'birth' => array_replace($this->blocDate($this->annee(1901)), ['place' => 'Saule, Ombrage, Sylvanie']),
        ]);

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame(['nickname', 'sex', 'birth'], array_column($rapport['remplis'], 'champ'));
        self::assertSame('masculin', $rapport['remplis'][1]['valeur']);
        self::assertSame('1901', $rapport['remplis'][2]['valeur']['texte']);
        self::assertSame([], $rapport['conflits']);

        $barnabe = $this->barnabe($this->recharger($origine));
        self::assertSame('Bébert', $barnabe->getNickname());
        self::assertSame('masculin', $barnabe->getSex()->value);
        self::assertSame('Saule, Ombrage, Sylvanie', $barnabe->getBirth()->getPlace());
    }

    /**
     * Une date **connue sans lieu** ne reçoit que le lieu : la date de l'origine, et son
     * `raw`, restent les siens — « 1901 » et « en 1901 » sont la même année, pas un conflit.
     */
    public function testUneDateConnueSansLieuNeRecoitQueLeLieu(): void
    {
        $origine = $this->origine(['birth' => $this->blocDate($this->annee(1901))]);
        $complement = $this->complement($origine, [
            'birth' => ['raw' => 'en 1901', 'qualifier' => 'annee', 'min' => '1901-01-01', 'max' => '1901-12-31', 'place' => 'Saule'],
        ]);

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame(['birth'], array_column($rapport['remplis'], 'champ'));
        $naissance = $this->barnabe($this->recharger($origine))->getBirth();
        self::assertSame('1901', $naissance->getRaw());
        self::assertSame('Saule', $naissance->getPlace());
    }

    /** Deux dates connues qui divergent : conflit, et **l'origine l'emporte** par défaut. */
    public function testUnConflitEstListeEtLOrigineLEmporte(): void
    {
        $origine = $this->origine(['birth' => $this->blocDate($this->annee(1901))]);
        $complement = $this->complement($origine, ['birth' => $this->blocDate($this->annee(1903))]);

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertCount(1, $rapport['conflits']);
        self::assertSame('birth', $rapport['conflits'][0]['champ']);
        self::assertSame('1901', $rapport['conflits'][0]['origine']['texte']);
        self::assertSame('1903', $rapport['conflits'][0]['complement']['texte']);
        self::assertSame('origine', $rapport['conflits'][0]['retenu']);
        self::assertSame('1901', $this->barnabe($this->recharger($origine))->getBirth()->format());
    }

    public function testRetenirLeComplementPrendSaValeurEntiere(): void
    {
        $origine = $this->origine(['birth' => array_replace($this->blocDate($this->annee(1901)), ['place' => 'Saule'])]);
        $complement = $this->complement($origine, ['birth' => $this->blocDate($this->annee(1903))]);

        $rapport = $this->fusion()->fusionner($origine, $complement, ['retenir' => ['birth' => 'complement']], $this->admin());

        self::assertSame('complement', $rapport['conflits'][0]['retenu']);
        $naissance = $this->barnabe($this->recharger($origine))->getBirth();
        self::assertSame('1903', $naissance->format());
        self::assertNull($naissance->getPlace(), 'Le bloc du complément, entier : jamais une moitié de chaque.');
    }

    /** Retenir un champ **sans** conflit : refusé, plutôt qu'ignoré — et rien n'est écrit. */
    public function testRetenirUnChampSansConflitEstRefuse(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine, ['sex' => 'masculin']);

        $erreur = $this->echec(fn () => $this->fusion()->fusionner($origine, $complement, ['retenir' => ['sex' => 'complement']], $this->admin()));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertArrayHasKey('retenir.sex', (array) $erreur->champs());
        self::assertSame(RequestStatus::EnAttente, $this->recharger($complement)->getStatus());
        self::assertSame('inconnu', $this->barnabe($this->recharger($origine))->getSex()->value);
    }

    public function testRetenirAutreChoseQueLesDeuxValeursEstRefuse(): void
    {
        $origine = $this->origine(['birth' => $this->blocDate($this->annee(1901))]);
        $complement = $this->complement($origine, ['birth' => $this->blocDate($this->annee(1903))]);

        $erreur = $this->echec(fn () => $this->fusion()->fusionner($origine, $complement, ['retenir' => ['birth' => 'les deux']], $this->admin()));

        self::assertArrayHasKey('retenir.birth', (array) $erreur->champs());
    }

    /** Un décès recopié pose « décédé » : une date de décès que l'administration accepte vaut décès. */
    public function testUnDecesRecopiePoseLeDeces(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine, ['deceased' => true, 'death' => $this->blocDate($this->annee(1968))]);

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame(['deceased', 'death'], array_column($rapport['remplis'], 'champ'));
        $barnabe = $this->barnabe($this->recharger($origine));
        self::assertTrue($barnabe->isDeceased());
        self::assertSame('1968', $barnabe->getDeath()->format());
    }

    /**
     * Un **lieu** de décès seul ne coche pas « décédé » : le dépôt ne l'exige pas, la
     * contrainte de l'arbre non plus, et cocher la case ouvre la visibilité d'un vivant.
     */
    public function testUnLieuDeDecesSeulNeCochePasDecede(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine, [
            'death' => ['raw' => null, 'qualifier' => 'inconnue', 'min' => null, 'max' => null, 'place' => 'Saule'],
        ]);

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame(['death'], array_column($rapport['remplis'], 'champ'));
        $barnabe = $this->barnabe($this->recharger($origine));
        self::assertFalse($barnabe->isDeceased());
        self::assertSame('Saule', $barnabe->getDeath()->getPlace());
    }

    /** La note ne se dispute pas : celle du complément s'ajoute, avec le mot de son auteur. */
    public function testLaNoteDuComplementSAjouteALaNoteDOrigine(): void
    {
        $origine = $this->origine(['note' => 'Livret militaire.']);
        $complement = $this->complement($origine, ['note' => 'Acte de décès vu à Saule.'], message: 'mon arrière-grand-père');

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame(['note'], array_column($rapport['remplis'], 'champ'));
        self::assertSame(
            "Livret militaire.\n\n— Complément (demande n° {$complement->getId()}) : Acte de décès vu à Saule.\nmon arrière-grand-père",
            $this->barnabe($this->recharger($origine))->getNote(),
        );
    }

    // ---------------------------------------------------------------------------------
    // Les liens
    // ---------------------------------------------------------------------------------

    /**
     * Les liens du complément sont **traduits** — son `#p1` devient le `#p2` de l'origine —
     * et ajoutés aux rangs suivants ; un lien déjà présent et un lien vers une fiche
     * proposée ailleurs ne sont pas repris, et le rapport dit pourquoi.
     */
    public function testLesLiensDuComplementSontTraduitsAjoutesOuEcartes(): void
    {
        $ailleurs = $this->ficheDe($this->proposer(self::TUF, ['givenNames' => 'Rose']))->getId();
        $origine = $this->origine();
        $complement = $this->complement($origine, [], [
            $this->lienConjoint(1),
            $this->lienParent(2),
            ['seq' => 3, 'kind' => 'parent_de', 'subjectDraftRef' => '#p1', 'otherDraftPersonId' => $ailleurs],
        ]);

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame([['seqComplement' => 2, 'seq' => 3]], $rapport['liensAjoutes']);
        self::assertSame([
            ['seqComplement' => 1, 'raison' => 'deja_present'],
            ['seqComplement' => 3, 'raison' => 'fiche_proposee_ailleurs'],
        ], $rapport['liensIgnores']);

        $liens = $this->liensEnBase()->pourDemande($this->recharger($origine));
        self::assertCount(3, $liens);
        $ajoute = $liens[2];
        self::assertSame(3, $ajoute->getSeq());
        self::assertSame('#p2', $ajoute->getSubjectDraftRef(), 'Le #p1 du complément est le #p2 de l\'origine.');
        self::assertSame(self::ENFANT, $ajoute->getOtherPersonId());
        self::assertSame('Barnabé Muguet', $ajoute->getSubjectLabel());
    }

    /** Une union se reconnaît dans les deux sens : Jargon époux de Barnabé est déjà là. */
    public function testUneUnionDejaPresenteSeReconnaitDansLesDeuxSens(): void
    {
        $origine = $this->origine();
        $complement = $this->proposer(self::TUF, [], [[
            'seq' => 1, 'kind' => 'conjoint_de', 'subjectPersonId' => self::JARGON, 'otherDraftRef' => '#p1', 'divorced' => false,
        ]], complementDe: $this->barnabe($origine)->getId(), message: null);

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame([['seqComplement' => 1, 'raison' => 'deja_present']], $rapport['liensIgnores']);
    }

    // ---------------------------------------------------------------------------------
    // Les pièces, les citations, la clôture
    // ---------------------------------------------------------------------------------

    /**
     * Les pièces du complément **suivent la fiche d'origine** — et l'auteur de l'origine
     * peut les lire : elles sont désormais dans sa proposition, même si c'est Tuf qui les a
     * déposées.
     */
    public function testLesPiecesDuComplementSuiventLaFicheDOrigineEtSonAuteurLesLit(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine);
        $piece = $this->joindreUnePiece($complement, self::TUF);
        $catalogue = self::getContainer()->get(CatalogueMedia::class);

        self::assertFalse($catalogue->lisible($piece, new LecteurMedia(self::OPALE, 'Opale', false)), 'Avant : la pièce est dans le complément de Tuf.');

        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame(1, $rapport['mediasDeplaces']);
        $relue = $this->em->getRepository(Media::class)->find($piece->getId());
        self::assertInstanceOf(Media::class, $relue);
        self::assertSame($origine->getId(), $relue->getSubjectRequestId());
        self::assertSame('p2', $relue->getSubjectDraftRef());
        self::assertSame(self::TUF, $relue->getUploadedByUserId(), 'Qui l\'a apportée ne change pas.');
        self::assertSame('en_attente', $relue->getStatus()->value);

        self::assertTrue($catalogue->lisible($relue, new LecteurMedia(self::OPALE, 'Opale', false)), 'Après : elle est dans la proposition d\'Opale.');
        self::assertTrue($catalogue->lisible($relue, new LecteurMedia(self::TUF, 'Tuf', false)), 'Son déposant la lit toujours.');
        self::assertFalse($catalogue->lisible($relue, new LecteurMedia(13, 'Un autre', false)));
    }

    /**
     * Le complément est clos **en renvoyant à l'origine** — et son auteur l'apprend comme
     * une reprise, pas comme un refus sec. L'auteur de l'origine apprend que
     * l'administration a complété sa demande, sans que le contributeur soit nommé.
     */
    public function testLeComplementEstClosEnRenvoyantALOrigineSansRefusSec(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine, ['sex' => 'masculin'], [$this->lienConjoint(1), $this->lienParent(2)]);

        $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        $clos = $this->recharger($complement);
        self::assertSame(RequestStatus::Refusee, $clos->getStatus());
        self::assertSame($origine->getId(), $clos->getDuplicateOf()?->getId());
        self::assertSame(\sprintf('Fusionné dans la demande n° %d.', $origine->getId()), $clos->getDecisionMessage());
        self::assertSame(self::ADMIN, $clos->getDecidedByUserId());

        self::assertSame([], $this->notificateur->de('demande_refusee'), 'Pas de refus sec.');
        $fusionne = $this->notificateur->de('complement_fusionne');
        self::assertCount(1, $fusionne);
        self::assertSame($complement->getId(), $fusionne[0]['demande']);
        self::assertSame((string) $origine->getId(), $fusionne[0]['message']);

        $completee = $this->notificateur->de('demande_completee');
        self::assertCount(1, $completee);
        self::assertSame($origine->getId(), $completee[0]['demande']);
        self::assertTrue($completee[0]['rejouee'], 'Par l\'administration.');
        self::assertSame([['libelles' => ['Sexe'], 'liensAjoutes' => 1]], $completee[0]['champs']);
    }

    /**
     * Les demandes qui citaient la fiche du complément la désignent désormais par celle de
     * l'origine — et gardent le nom que **leur** auteur avait saisi : l'origine est la
     * proposition d'un vivant faite par un autre membre, qu'il ne voit pas.
     */
    public function testLesDemandesQuiCitaientLeComplementSontRepointeesSansLeNomDeLOrigine(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine, ['givenNames' => 'Barnabé Jules']);
        $ficheDuComplement = $this->ficheDe($complement)->getId();

        $rose = $this->vieillir($this->proposer(self::TUF, ['givenNames' => 'Rose'], [[
            'seq' => 1, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherDraftPersonId' => $ficheDuComplement,
        ]]));
        $avant = $rose->getUpdatedAt()->getTimestamp();

        [$origine, $complement] = $this->relire($origine, $complement);
        $rapport = $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        self::assertSame([$rose->getId()], $rapport['demandesRepointees']);

        $relue = $this->recharger($rose);
        $lien = $this->liensEnBase()->pourDemande($relue)[0];
        self::assertSame($this->barnabe($origine)->getId(), $lien->getOtherDraftPersonId());
        self::assertSame('Barnabé Jules Muguet', $lien->getOtherLabel(), 'Le nom que Tuf avait saisi, pas celui d\'Opale.');
        self::assertGreaterThan($avant, $relue->getUpdatedAt()->getTimestamp());
        self::assertSame(
            'Barnabé Jules Muguet (proposée)',
            $this->serialiseur->pourAuteur($relue)['liens'][0]['otherLabel'],
            'La lecture ne relit pas le nom de la fiche d\'un autre.',
        );

        // Et l'ordre de validation suit : la demande de Rose attend celle d'Opale, puis
        // rattache Rose à la personne qu'elle a créée.
        self::assertSame('dependance_en_attente', $this->echec(fn () => $this->validation->valider($this->recharger($rose), $this->admin()))->codeErreur());
        $this->validation->valider($this->recharger($origine), $this->admin());
        self::assertSame('validee', $this->validation->valider($this->recharger($rose), $this->admin())->resultat);
    }

    public function testLaFusionToucheLOrigineEtRecalculeSaCle(): void
    {
        $origine = $this->vieillir($this->origine());
        $avant = $origine->getUpdatedAt()->getTimestamp();
        $complement = $this->complement($origine, ['birth' => $this->blocDate($this->annee(1904))]);

        [$origine, $complement] = $this->relire($origine, $complement);
        $this->fusion()->fusionner($origine, $complement, [], $this->admin());

        $relue = $this->recharger($origine);
        self::assertGreaterThan($avant, $relue->getUpdatedAt()->getTimestamp());
        // La clé d'une demande est celle de sa **première** fiche : Céleste, dont rien n'a
        // changé. Recalculée quand même — la recalculer est la règle, son résultat dépend
        // des fiches.
        self::assertSame('muguet|celeste|?', $relue->getDedupKey());
    }

    // ---------------------------------------------------------------------------------
    // L'aperçu
    // ---------------------------------------------------------------------------------

    /** L'aperçu calcule **exactement** ce que la fusion fera — et n'écrit rien. */
    public function testLApercuNEcritRienEtAnnonceCeQueLaFusionFera(): void
    {
        $origine = $this->origine(['birth' => $this->blocDate($this->annee(1901))]);
        $complement = $this->complement($origine, ['sex' => 'masculin', 'birth' => $this->blocDate($this->annee(1903))], [$this->lienConjoint(1), $this->lienParent(2)]);
        $this->joindreUnePiece($complement, self::TUF);
        $empreinteOrigine = $this->empreinte($origine);
        $empreinteComplement = $this->empreinte($complement);

        $apercu = $this->fusion()->apercu($origine, $complement);

        self::assertSame(['remplis', 'conflits', 'liensAjoutes', 'liensIgnores', 'mediasDeplaces', 'demandesRepointees'], array_keys($apercu));
        self::assertSame(1, $apercu['mediasDeplaces']);

        $this->em->flush();
        [$origineRelue, $complementRelu] = $this->relire($origine, $complement);
        self::assertSame($empreinteOrigine, $this->empreinte($origineRelue), 'L\'origine n\'a pas bougé.');
        self::assertSame($empreinteComplement, $this->empreinte($complementRelu));
        self::assertSame(RequestStatus::EnAttente, $complementRelu->getStatus());
        self::assertSame([], $this->notificateur->de('complement_fusionne'));

        self::assertSame($apercu, $this->fusion()->fusionner($origineRelue, $complementRelu, [], $this->admin()), 'Un seul calcul pour les deux.');
    }

    // ---------------------------------------------------------------------------------
    // Les refus
    // ---------------------------------------------------------------------------------

    public function testUneDemandeTrancheeNeSeFusionnePas(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine);
        $this->refus->refuser($complement, $this->admin(), 'Rien de nouveau.', null);

        [$origine, $complement] = $this->relire($origine, $complement);
        $erreur = $this->echec(fn () => $this->fusion()->fusionner($origine, $complement, [], $this->admin()));

        self::assertInstanceOf(Conflit::class, $erreur);
        self::assertSame('demande_deja_decidee', $erreur->codeErreur());
        self::assertSame('demande_deja_decidee', $this->echec(fn () => $this->fusion()->apercu($origine, $complement))->codeErreur());
    }

    public function testUnComplementDUneAutrePropositionEstRefuse(): void
    {
        $origine = $this->origine();
        $autre = $this->proposer(self::OPALE, ['givenNames' => 'Lys']);
        $complement = $this->complement($origine);

        $erreur = $this->echec(fn () => $this->fusion()->fusionner($autre, $complement, [], $this->admin()));

        self::assertInstanceOf(DonneesInvalides::class, $erreur);
        self::assertArrayHasKey('complementId', (array) $erreur->champs());

        $erreur = $this->echec(fn () => $this->fusion()->fusionner($origine, $origine, [], $this->admin()));
        self::assertArrayHasKey('complementId', (array) $erreur->champs(), 'Une demande ne se fusionne pas en elle-même.');
    }

    /** L'empreinte de l'origine **et** celle du complément : chacun a pu être complété entre-temps. */
    public function testUneEmpreintePerimeeEmpecheLaFusion(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine, ['sex' => 'masculin']);
        $luOrigine = $this->empreinte($origine);
        $luComplement = $this->empreinte($complement);

        $this->completion()->completer($complement, 'p1', ['fiche' => $this->fiche(['sex' => 'masculin', 'nickname' => 'Bébert'])], $this->lecteur(self::TUF));

        [$origine, $complement] = $this->relire($origine, $complement);
        $erreur = $this->echec(fn () => $this->fusion()->fusionner(
            $origine,
            $complement,
            ['empreinte' => $luOrigine, 'empreinteComplement' => $luComplement],
            $this->admin(),
        ));

        self::assertSame('demande_modifiee_entre_temps', $erreur->codeErreur());
        self::assertSame($complement->getId(), ((array) $erreur->details())['demandeId']);
        self::assertSame(RequestStatus::EnAttente, $this->recharger($complement)->getStatus());
    }

    public function testUnMembreNeFusionnePas(): void
    {
        $origine = $this->origine();
        $complement = $this->complement($origine);

        $erreur = $this->echec(fn () => $this->fusion()->fusionner($origine, $complement, [], $this->lecteur(self::OPALE)));

        self::assertInstanceOf(AccesRefuse::class, $erreur);
    }

    // ---------------------------------------------------------------------------------
    // Le bout de la chaîne
    // ---------------------------------------------------------------------------------

    /**
     * **Après la fusion, la validation crée une seule personne, avec ses pièces** — celles
     * qu'Opale avait jointes comme celles qu'apportait le complément. Le complément, clos,
     * ne se valide plus.
     */
    public function testApresLaFusionLaValidationCreeUneSeulePersonneAvecSesPieces(): void
    {
        $origine = $this->origine();
        $deLOrigine = $this->joindreUnePiece($origine, self::OPALE);
        $deLOrigine->rattacherAuBrouillon('p2', (int) $origine->getId());
        $this->em->flush();

        $complement = $this->complement($origine, ['sex' => 'masculin', 'birth' => $this->blocDate($this->annee(1901))]);
        $duComplement = $this->joindreUnePiece($complement, self::TUF);

        $this->fusion()->fusionner($origine, $complement, [], $this->admin());
        $resultat = $this->validation->valider($this->recharger($origine), $this->admin());

        self::assertSame('validee', $resultat->resultat);
        $creees = $this->arbre->personnesCreees();
        self::assertCount(2, $creees, 'Céleste et Barnabé — Barnabé une seule fois.');

        $barnabe = $this->barnabe($this->recharger($origine))->getResolvedPersonId();
        self::assertNotNull($barnabe);
        self::assertSame('masculin', $this->arbre->valeur(SubjectKind::Personne, $barnabe, 'sex'));
        self::assertSame(DateQualifier::Annee, $this->arbre->valeur(SubjectKind::Personne, $barnabe, 'birth')?->getQualifier());

        foreach ([$deLOrigine, $duComplement] as $piece) {
            $relue = $this->em->getRepository(Media::class)->find($piece->getId());
            self::assertInstanceOf(Media::class, $relue);
            self::assertSame($barnabe, $relue->getSubjectId(), 'Chaque pièce suit la personne créée.');
            self::assertSame('actif', $relue->getStatus()->value);
        }

        self::assertSame('demande_deja_decidee', $this->echec(fn () => $this->validation->valider($this->recharger($complement), $this->admin()))->codeErreur());
    }

    // ---------------------------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------------------------

    /**
     * La proposition d'Opale : Céleste (`#p1`) et son père Barnabé (`#p2`), époux de Jargon.
     *
     * @param array<string, mixed> $barnabe ce qui change de la fiche de Barnabé
     */
    private function origine(array $barnabe = []): ChangeRequest
    {
        return $this->depot->deposer([
            'kind' => 'creation_personne',
            'soumettre' => true,
            'brouillons' => [
                $this->fiche(['givenNames' => 'Céleste']),
                $this->fiche(['localRef' => '#p2'] + $barnabe),
            ],
            'liens' => [
                $this->lienConjoint(1, '#p2'),
                ['seq' => 2, 'kind' => 'enfant_de', 'subjectDraftRef' => '#p1', 'otherDraftRef' => '#p2'],
            ],
        ], $this->lecteur(self::OPALE));
    }

    /**
     * Le complément de Tuf, sur Barnabé.
     *
     * @param array<string, mixed>            $fiche
     * @param list<array<string, mixed>>|null $liens
     */
    private function complement(ChangeRequest $origine, array $fiche = [], ?array $liens = null, ?string $message = null): ChangeRequest
    {
        return $this->proposer(self::TUF, $fiche, $liens, true, $this->barnabe($origine)->getId(), $message);
    }

    private function barnabe(ChangeRequest $origine): DraftPerson
    {
        $fiche = $this->fiches()->parReference($origine, '#p2');
        self::assertInstanceOf(DraftPerson::class, $fiche);

        return $fiche;
    }

    private function echec(\Closure $geste): ErreurDeDemande
    {
        try {
            $geste();
        } catch (ErreurDeDemande $erreur) {
            return $erreur;
        }

        self::fail('Le geste devait être refusé.');
    }

    /**
     * Deux demandes relues **dans la même unité de travail** : `recharger()` vide le
     * gestionnaire à chaque appel, et deux appels successifs laisseraient la première
     * détachée — la fusion comparerait alors deux instances de la même origine.
     *
     * @return array{ChangeRequest, ChangeRequest}
     */
    private function relire(ChangeRequest $a, ChangeRequest $b): array
    {
        $this->em->clear();
        $relueA = $this->demandes->find($a->getId());
        $relueB = $this->demandes->find($b->getId());
        self::assertInstanceOf(ChangeRequest::class, $relueA);
        self::assertInstanceOf(ChangeRequest::class, $relueB);

        return [$relueA, $relueB];
    }
}
