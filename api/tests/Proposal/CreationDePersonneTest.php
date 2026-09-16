<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\DraftLinkRepository;
use App\Module\Proposal\Repository\DraftPersonRepository;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * PROPOSER L'AJOUT D'UNE PERSONNE — § 6.4 du dossier de mission.
 *
 * Le cas de référence est celui du § 9.5 du modèle : Tourmaline Muguet, proposée comme
 * **épouse de Jargon Sarriette** (qui existe, `p55`) et **mère de deux enfants
 * existants** (`p61`, `p62`). Il tient en une phrase les trois difficultés — une
 * personne sans identifiant, une famille qui n'existe pas encore, et deux liens qui
 * doivent se rejoindre sur la même famille.
 *
 * Le point structurant est qu'il n'y a **rien de nouveau** dans le circuit : même file,
 * même diff, même refus motivé, mêmes deux `409`. Ce fichier éprouve ce qui est propre
 * à la création ; tout le reste est déjà couvert par les autres classes de ce dossier,
 * et c'est le signe que « un seul mécanisme » a tenu.
 */
final class CreationDePersonneTest extends BaseDeDemandes
{
    /** Jargon Sarriette, l'époux proposé — il existe dans l'arbre validé. */
    private const int JARGON = 55;

    /** Ses deux enfants, existants eux aussi. */
    private const int ENFANT_A = 61;
    private const int ENFANT_B = 62;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbre
            ->poser(SubjectKind::Personne, self::JARGON, 'Jargon Sarriette', 3, $this->champsDUnePersonne('Jargon', 'Sarriette'))
            ->poser(SubjectKind::Personne, self::ENFANT_A, 'Lave Sarriette', 1, $this->champsDUnePersonne('Lave', 'Sarriette'))
            ->poser(SubjectKind::Personne, self::ENFANT_B, 'Opale Sarriette', 1, $this->champsDUnePersonne('Opale', 'Sarriette'));
    }

    /**
     * **Au moins un lien**, sinon la personne flotterait hors de l'arbre.
     *
     * Refusé à la **soumission** et non à la création : on doit pouvoir enregistrer un
     * brouillon incomplet, et c'est ce que la seconde moitié du test vérifie.
     */
    public function testUneCreationSansAucunLienEstRefusee(): void
    {
        $corps = $this->corpsDeCreation(liens: []);

        try {
            $this->depot->deposer($corps, $this->lecteur(self::OPALE));
            self::fail('Une création sans lien doit être refusée à la soumission.');
        } catch (DonneesInvalides $erreur) {
            self::assertSame('demande_sans_lien', $erreur->codeErreur());
            self::assertSame(422, $erreur->statutHttp());
            self::assertArrayHasKey('liens', (array) $erreur->champs());
        }

        // Mais le même corps **enregistré en brouillon** passe : le contrôle est à la
        // soumission, pas à la saisie.
        $corps['soumettre'] = false;
        $brouillon = $this->depot->deposer($corps, $this->lecteur(self::OPALE));

        self::assertSame(RequestStatus::Brouillon, $brouillon->getStatus());
        self::assertSame(0, $this->arbre->ecritures(), 'Un brouillon n\'écrit rien dans l\'arbre.');
    }

    /**
     * La personne proposée apparaît dans la projection **de son auteur et d'un
     * administrateur**, et **pas d'un autre membre**.
     *
     * C'est le test qui protège la promesse du produit : *le demandeur voit sa
     * modification avant validation*. Se tromper dans l'autre sens montrerait à toute la
     * famille une personne que personne n'a validée.
     */
    public function testLaPersonneProposeeEstVisibleDeSonAuteurEtDUnAdministrateurEtPasDUnAutreMembre(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $pourOpale = $this->propositions->projection($this->lecteur(self::OPALE));
        $pourAdmin = $this->propositions->projection($this->lecteur(self::ADMIN, administrateur: true));
        $pourTuf = $this->propositions->projection($this->lecteur(self::TUF));

        self::assertCount(1, $pourOpale->noeuds);
        self::assertSame('Tourmaline Muguet', $pourOpale->noeuds[0]->displayName);
        self::assertSame('#'.$demande->getId().'/p1', $pourOpale->noeuds[0]->noeudId);

        self::assertCount(1, $pourAdmin->noeuds);

        // ───── La règle.
        self::assertTrue($pourTuf->estVide(), 'Un autre membre ne voit pas la personne proposée.');
    }

    /**
     * Le cas du § 9.5, en entier : une personne, **une** famille, deux filiations.
     *
     * Le piège est la famille. Tourmaline est épouse de Jargon **et** mère de deux
     * enfants : trois liens, et un seul couple. Un service qui déduirait la famille lien
     * par lien en créerait deux — dont une que `uniq_tree_family_couple` refuserait.
     */
    public function testUneValidationCreeLaPersonneEtUneSeuleFamille(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertCount(1, $this->arbre->personnesCreees(), 'Une personne créée, pas deux.');
        self::assertCount(1, $this->arbre->famillesCreees(), 'Une famille créée, et une seule.');

        $tourmaline = $this->arbre->personnesCreees()[0];
        $famille = $this->arbre->famillesCreees()[0];

        self::assertSame('Muguet', $this->arbre->valeur(SubjectKind::Personne, $tourmaline, 'surname'));
        self::assertSame('Tourmaline', $this->arbre->valeur(SubjectKind::Personne, $tourmaline, 'givenNames'));

        // Les deux enfants sont rattachés à **cette** famille — celle du couple, pas une
        // seconde inventée pour l'occasion.
        self::assertSame([self::ENFANT_A, self::ENFANT_B], $this->arbre->enfantsDe($famille));

        // Et les dates d'union y sont portées.
        $mariage = $this->arbre->valeur(SubjectKind::Famille, $famille, 'marriage');
        self::assertInstanceOf(UncertainDate::class, $mariage);
        self::assertSame('23 septembre 1927', $mariage->format());
    }

    /**
     * L'ordre des liens ne change rien : « parent de » lu avant « conjoint de » doit
     * retrouver le même couple.
     *
     * C'est le cas que la lecture naïve rate — un `parent_de` traité en premier ne peut
     * pas deviner le conjoint qu'un lien suivant désignera, et créerait une famille d'un
     * parent seul avant d'en créer une seconde pour le couple.
     */
    public function testLOrdreDesLiensNeChangeRienAuNombreDeFamilles(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeCreation(liens: [
                $this->lienParent(seq: 1, enfant: self::ENFANT_A),
                $this->lienConjoint(seq: 2),
                $this->lienParent(seq: 3, enfant: self::ENFANT_B),
            ]),
            $this->lecteur(self::OPALE),
        );

        $this->validation->valider($demande, $this->admin());

        self::assertCount(1, $this->arbre->famillesCreees());
        self::assertSame(
            [self::ENFANT_A, self::ENFANT_B],
            $this->arbre->enfantsDe($this->arbre->famillesCreees()[0]),
        );
    }

    /**
     * La référence provisoire est **résolue** à la validation : `resolved_person_id`
     * porte l'identifiant réel, et le nœud `#12/p1` quitte le graphe.
     *
     * Sans cette colonne, « une demande validée perd le lien vers son résultat » (§ 5.3)
     * — et le front qui ouvre `GET /api/demandes/12/brouillons/p1` six mois plus tard
     * n'aurait nulle part où rediriger.
     */
    public function testLaValidationResoutLaReferenceProvisoire(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $this->validation->valider($demande, $this->admin());

        $brouillon = self::getContainer()
            ->get(DraftPersonRepository::class)
            ->parReference($this->recharger($demande), '#p1');

        self::assertNotNull($brouillon);
        self::assertSame($this->arbre->personnesCreees()[0], $brouillon->getResolvedPersonId());

        $lien = self::getContainer()->get(DraftLinkRepository::class)->pourDemande($brouillon->getRequest())[0];
        self::assertSame($this->arbre->famillesCreees()[0], $lien->getResolvedFamilyId());

        // La demande est tranchée : le nœud provisoire quitte le graphe de son auteur.
        self::assertTrue($this->propositions->projection($this->lecteur(self::OPALE))->estVide());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LA PASSE 6 — la preuve jointe au brouillon suit la personne créée.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * **Ce test prouve que le geste est APPELÉ, et c'est toute sa raison d'être.**
     * `Media` portait depuis longtemps sa moitié de la couture,
     * `CircuitDeValidationMedia::resoudreLeBrouillon()`, avec deux tests verts qui
     * l'invoquaient **à la main sur le service**. Ils prouvaient que la méthode
     * fonctionne ; aucun ne traversait `ValidationDeDemande`. Compté avant correction :
     * **0 appelant en production**.
     *
     * Et rien ne pouvait le signaler. Une `creation_personne` porte **0 op**, donc
     * `appliquerLesAutresOps()` ne boucle sur rien et les deux garde-fous qui attrapent
     * ailleurs une écriture perdue — `exigerUneEcriture()` et `applicateurPour()` — ne
     * s'arment jamais. Le silence était total.
     *
     * Le coût, mesuré de bout en bout sur la pile : la ligne `med_media` restait pointée
     * sur `#<demande>/p1` après validation, `subject_id` à `NULL`, statut `en_attente`.
     * Les octets vivaient toujours, mais plus **aucun écran n'y menait** — le nœud en
     * attente ayant quitté le graphe, la seule route qui la servait n'était plus
     * atteignable. Le membre voyait sa création validée **sans sa preuve**, ce que le
     * § 6.5 du dossier désigne comme le moment précis où l'on perd un contributeur.
     */
    public function testLaValidationRattacheALaPersonneCreeeLesPreuvesJointesAuBrouillon(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $demandeId = $demande->getId();
        self::assertNotNull($demandeId);

        // Une preuve déposée sur le brouillon `#p1`, telle que `POST /api/medias` la pose
        // : `subject_id` nul, la référence provisoire et sa demande à la place.
        $media = new Media(
            MediaKind::Preuve,
            MediaSubject::Personne,
            // La constante que pose le téléversement : on ne connaît pas encore la personne.
            'Personne proposée',
            'preuve-du-brouillon.jpg',
            'image/jpeg',
            91,
            str_repeat('a', 64),
            self::OPALE,
            'Compte '.self::OPALE,
        );
        // `p1` SANS dièse : c'est la convention de `med_media.subject_draft_ref`, et la
        // conversion depuis `#p1` se fait dans le port, à un seul endroit du produit.
        $media->rattacherAuBrouillon('p1', $demandeId);

        $this->em->persist($media);
        $this->em->flush();

        $mediaId = $media->getId();
        self::assertNotNull($mediaId);
        self::assertNull($media->getSubjectId(), 'Avant validation : la preuve ne désigne aucune personne réelle.');

        $this->validation->valider($demande, $this->admin());

        $this->em->clear();

        $relu = $this->em->getRepository(Media::class)->find($mediaId);
        self::assertInstanceOf(Media::class, $relu);

        $personneCreee = $this->arbre->personnesCreees()[0];

        self::assertSame(
            $personneCreee,
            $relu->getSubjectId(),
            'La preuve doit suivre la personne que la validation vient de créer.',
        );
        self::assertNull($relu->getSubjectDraftRef(), 'La référence provisoire est effacée : plus rien ne la désigne.');
        self::assertNull($relu->getSubjectRequestId());
        self::assertSame(
            'actif',
            $relu->getStatus()->value,
            'Résolue mais laissée `en_attente`, la preuve serait passée d’invisible à invisible : `CatalogueMedia::lister()` ne rend que les actifs.',
        );
        self::assertNotSame(
            'Personne proposée',
            $relu->getSubjectLabel(),
            'Le libellé reprend le nom de la personne créée, il ne reste pas sur la constante du dépôt.',
        );

        // `BaseDeDemandes::partirDUneBaseVide()` ne vide que les cinq tables `prp_*` —
        // pour de bonnes raisons, qu'elle explique. La ligne posée ici est donc à nous de
        // la retirer, sinon elle survit au cas suivant.
        $this->em->remove($relu);
        $this->em->flush();
    }

    /**
     * Une création **sans** preuve jointe traverse la passe 6 sans rien faire, et sans
     * lever : c'est le cas courant, et le repli `MediasNonBranches` rend exactement ce
     * que rend le port branché sur une demande sans média.
     */
    public function testUneCreationSansPreuveJointeNEstPasGeneeParLaPasse6(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertSame(0, \count($this->em->getRepository(Media::class)->findAll()));
    }

    /**
     * Une création validée s'inscrit en `Revision` — la personne, la famille, les
     * filiations — et **invalide l'empreinte de l'arbre**.
     *
     * L'invalidation ne demande aucun geste : l'empreinte est dérivée de la donnée, et
     * les écritures l'ont changée d'elles-mêmes (§ 2.3, ADR 0005).
     */
    public function testUneCreationValideeSInscritEnRevisionEtChangeLEmpreinte(): void
    {
        $avant = $this->arbre->empreinteDeLArbre();

        $demande = $this->proposerTourmaline(self::OPALE);
        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertNotSame($avant, $resultat->empreinte, 'Une création change l\'empreinte de l\'arbre.');

        $operations = array_map(
            static fn ($revision): string => $revision->getOperation()->value,
            $resultat->revisions,
        );

        self::assertContains(RevisionOperation::Creation->value, $operations);
        self::assertContains(RevisionOperation::LienAjoute->value, $operations);

        // Toutes les révisions d'une même validation portent la **même** empreinte :
        // sinon deux lignes d'un même acte sembleraient appartenir à deux états.
        foreach ($resultat->revisions as $revision) {
            self::assertSame($resultat->empreinte, $revision->getTreeFingerprintAfter());
            self::assertSame($demande->getId(), $revision->getRequest()?->getId());
        }

        $creation = array_values(array_filter(
            $resultat->revisions,
            static fn ($revision): bool => RevisionOperation::Creation === $revision->getOperation()
                && SubjectKind::Personne === $revision->getSubjectKind(),
        ))[0];

        // Pour une création, « `avant` vaut `null` partout » (§ 5.5).
        self::assertSame(['avant' => null, 'apres' => 'Muguet'], $creation->getChanges()['surname']);
        self::assertSame(['avant' => null, 'apres' => 'vers 1889'], $creation->getChanges()['birth']);
    }

    /**
     * Deux demandes visant la même personne manquante sont **signalées à
     * l'administrateur** comme doublons probables.
     *
     * « Deux cousins peuvent proposer le même arrière-grand-oncle » : sans ce
     * rapprochement, l'arbre gagne deux fois la même personne et personne ne s'en
     * aperçoit avant longtemps.
     */
    public function testDeuxDemandesSurLaMemePersonneManquanteSontSignaleesCommeDoublons(): void
    {
        $opale = $this->proposerTourmaline(self::OPALE);
        $tuf = $this->proposerTourmaline(self::TUF);

        self::assertNotNull($opale->getDedupKey());
        self::assertSame($opale->getDedupKey(), $tuf->getDedupKey());

        $vue = $this->serialiseur->pourAdministration($this->recharger($opale));

        self::assertCount(1, $vue['doublonsProbables']);
        self::assertSame($tuf->getId(), $vue['doublonsProbables'][0]['id']);
        self::assertSame('Compte '.self::TUF, $vue['doublonsProbables'][0]['authorLabel']);

        // Il n'y a **aucun `personId`** dans un doublon probable : la personne n'existe
        // pas, c'est un rapprochement, pas une identification.
        self::assertArrayNotHasKey('personId', $vue['doublonsProbables'][0]);
    }

    /** Et l'administrateur refuse l'une **en renvoyant à l'autre**, plutôt que d'un refus sec. */
    public function testLAdministrateurRefuseUnDoublonEnRenvoyantALAutreDemande(): void
    {
        $opale = $this->proposerTourmaline(self::OPALE);
        $tuf = $this->proposerTourmaline(self::TUF);

        $this->refus->refuser(
            $tuf,
            $this->admin(),
            'Votre proposition rejoint la demande d\'Opale, déposée la veille.',
            $opale->getId(),
        );

        self::assertSame(RequestStatus::Refusee, $tuf->getStatus());
        self::assertSame($opale->getId(), $tuf->getDuplicateOf()?->getId());
        self::assertSame(0, $this->arbre->ecritures(), 'Un refus n\'écrit rien dans l\'arbre.');
    }

    /**
     * Une demande qui ne décrit **aucune** personne différente ne se rapproche pas :
     * deux prénoms distincts sont deux personnes.
     */
    public function testDeuxPersonnesDifferentesNeSontPasDesDoublons(): void
    {
        $opale = $this->proposerTourmaline(self::OPALE);
        $tuf = $this->proposerTourmaline(self::TUF, 'Céleste');

        self::assertNotSame($opale->getDedupKey(), $tuf->getDedupKey());
        self::assertSame([], $this->serialiseur->pourAdministration($this->recharger($opale))['doublonsProbables']);
    }

    /**
     * Une demande ne décrit **aucune famille** : c'est le service qui les déduit à la
     * validation. Un front qui enverrait une famille est refusé.
     */
    public function testUneDemandeNeDecritAucuneFamille(): void
    {
        $corps = $this->corpsDeCreation();
        $corps['familles'] = [['epoux' => ['#p1', self::JARGON]]];

        try {
            $this->depot->deposer($corps, $this->lecteur(self::OPALE));
            self::fail('Une demande ne décrit aucune famille.');
        } catch (DonneesInvalides $erreur) {
            self::assertArrayHasKey('familles', (array) $erreur->champs());
        }
    }

    /** Une extrémité désigne une personne **ou** un brouillon, jamais les deux. */
    public function testUneExtremiteNeDesignePasALaFoisUnePersonneEtUnBrouillon(): void
    {
        $lien = $this->lienConjoint();
        $lien['otherDraftRef'] = '#p1';

        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer($this->corpsDeCreation(liens: [$lien]), $this->lecteur(self::OPALE));
    }

    /** Une référence provisoire qui ne correspond à aucun brouillon de la demande est refusée. */
    public function testUneReferenceProvisoireInconnueEstRefusee(): void
    {
        $lien = $this->lienConjoint();
        $lien['subjectDraftRef'] = '#p9';

        try {
            $this->depot->deposer($this->corpsDeCreation(liens: [$lien]), $this->lecteur(self::OPALE));
            self::fail('Une référence provisoire inconnue doit être refusée.');
        } catch (DonneesInvalides $erreur) {
            self::assertArrayHasKey('liens[0].subjectDraftRef', (array) $erreur->champs());
        }
    }

    /** Une personne désignée qui n'existe pas rend `404`, **et rien n'est écrit**. */
    public function testUnConjointInexistantRendIntrouvable(): void
    {
        $this->arbre->supprimer(SubjectKind::Personne, self::JARGON);

        $this->expectException(Introuvable::class);

        $this->depot->deposer($this->corpsDeCreation(), $this->lecteur(self::OPALE));
    }

    /**
     * Une cible qui disparaît **entre le dépôt et la validation** rend la demande
     * caduque : personne ne l'a refusée, le monde a bougé.
     *
     * C'est la seconde barrière du § 9.5, passe 1 — la première étant l'événement publié
     * par `Tree` à la suppression.
     */
    public function testUnConjointDisparuApresLeDepotRendLaDemandeCaduque(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $this->arbre->supprimer(SubjectKind::Personne, self::JARGON);

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Une cible disparue doit rendre la demande caduque.');
        } catch (Conflit $conflit) {
            self::assertSame('cible_disparue', $conflit->codeErreur());
            self::assertFalse(((array) $conflit->details())['rejouable']);
        }

        self::assertSame(RequestStatus::Caduque, $this->recharger($demande)->getStatus());
        self::assertSame([], $this->arbre->personnesCreees(), 'Rien n\'a été créé.');
    }

    /** Les dates d'union ne s'attachent qu'à un lien de conjoint. */
    public function testUneDateDeMariageSurUneFiliationEstRefusee(): void
    {
        $lien = $this->lienParent(seq: 1, enfant: self::ENFANT_A);
        $lien['marriage'] = $this->blocDate($this->annee(1927));

        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer($this->corpsDeCreation(liens: [$lien]), $this->lecteur(self::OPALE));
    }

    /**
     * Une union **sans date reste une union** (§ 6.4 du dossier) : c'est une règle de
     * fond, pas une tolérance — une grande partie des unions de cet arbre n'a pas de
     * date d'acte.
     */
    public function testUneUnionSansDateResteUneUnion(): void
    {
        $lien = $this->lienConjoint();
        unset($lien['marriage']);

        $demande = $this->depot->deposer($this->corpsDeCreation(liens: [$lien]), $this->lecteur(self::OPALE));
        $this->validation->valider($demande, $this->admin());

        self::assertCount(1, $this->arbre->famillesCreees());

        $mariage = $this->arbre->valeur(SubjectKind::Famille, $this->arbre->famillesCreees()[0], 'marriage');
        self::assertInstanceOf(UncertainDate::class, $mariage);
        self::assertFalse($mariage->isKnown());
    }

    /**
     * `ajout_lien` — deux personnes existantes, aucune fiche : « elle s'est remariée
     * avec lui ».
     */
    public function testUnAjoutDeLienEntreDeuxPersonnesExistantesNeCreeAucunePersonne(): void
    {
        $demande = $this->depot->deposer([
            'kind' => 'ajout_lien',
            'message' => 'acte de mariage de 1934',
            'soumettre' => true,
            'liens' => [[
                'seq' => 1,
                'kind' => 'conjoint_de',
                'subjectPersonId' => self::JARGON,
                'otherPersonId' => self::ENFANT_B,
                'marriage' => $this->blocDate($this->annee(1934)),
                'divorced' => true,
                'divorce' => $this->blocDate($this->annee(1942)),
            ]],
        ], $this->lecteur(self::OPALE));

        // La cible dénormalisée permet à la file de grouper par fiche sans jointure.
        self::assertSame(SubjectKind::Personne, $demande->getTargetKind());
        self::assertSame(self::JARGON, $demande->getTargetId());
        self::assertSame('Jargon Sarriette', $demande->getTargetLabel());

        $this->validation->valider($demande, $this->admin());

        self::assertSame([], $this->arbre->personnesCreees(), 'Un ajout de lien ne crée aucune personne.');
        self::assertCount(1, $this->arbre->famillesCreees());
        self::assertTrue($this->arbre->valeur(SubjectKind::Famille, $this->arbre->famillesCreees()[0], 'divorced'));
    }

    /** Un `ajout_lien` qui tenterait de glisser une fiche est refusé. */
    public function testUnAjoutDeLienNeCreeAucuneFiche(): void
    {
        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer([
            'kind' => 'ajout_lien',
            'brouillons' => [['localRef' => '#p1', 'givenNames' => 'Tourmaline', 'surname' => 'Muguet']],
            'liens' => [$this->lienConjoint()],
        ], $this->lecteur(self::OPALE));
    }

    /**
     * Une filiation peut désigner un **couple existant** : la famille est donnée, il n'y
     * en a aucune à créer.
     */
    public function testUneFiliationVersUnCoupleExistantNeCreeAucuneFamille(): void
    {
        $this->arbre->poser(SubjectKind::Famille, 28, 'Jargon × Agate', 2, [
            'marriage' => UncertainDate::inconnue(),
            'divorced' => false,
            'divorce' => UncertainDate::inconnue(),
        ]);

        $demande = $this->depot->deposer(
            $this->corpsDeCreation(liens: [[
                'seq' => 1,
                'kind' => 'enfant_de',
                'subjectDraftRef' => '#p1',
                'familyId' => 28,
            ]]),
            $this->lecteur(self::OPALE),
        );

        $this->validation->valider($demande, $this->admin());

        self::assertSame([], $this->arbre->famillesCreees(), 'Le couple existait : rien à créer.');
        self::assertSame([$this->arbre->personnesCreees()[0]], $this->arbre->enfantsDe(28));
    }

    /**
     * Une création refusée se **refait** : sa fiche et ses liens repartent tels quels.
     *
     * Rien de ce qu'ils décrivent n'existe encore, donc rien n'a pu bouger sous eux —
     * c'est ce qui distingue la reprise d'une création de celle d'une modification, où
     * l'`oldValue` est relue. « Une caducité sans ce bouton est une contribution
     * perdue », et un refus non plus ne doit pas obliger à tout ressaisir.
     */
    public function testUneCreationRefuseeSeRefaitAvecSaFicheEtSesLiens(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);
        $this->refus->refuser($demande, $this->admin(), 'Ajoutez la source du livret.', null);

        $corps = $this->reprise->corpsPreRempli($this->recharger($demande));

        self::assertSame('creation_personne', $corps['kind']);
        self::assertFalse($corps['soumettre']);
        self::assertCount(1, $corps['brouillons']);
        self::assertSame('Tourmaline', $corps['brouillons'][0]['givenNames']);
        self::assertCount(3, $corps['liens']);

        // Cinq clés, `texte` retiré : le corps rendu est **postable tel quel**, et c'est
        // la seule manipulation que le § 14.1 impose à qui reposte un bloc reçu.
        self::assertSame(
            ['raw', 'qualifier', 'min', 'max', 'place'],
            array_keys($corps['brouillons'][0]['birth']),
        );

        $refaite = $this->depot->deposer(array_merge($corps, ['soumettre' => true]), $this->lecteur(self::OPALE));
        self::assertSame(RequestStatus::EnAttente, $refaite->getStatus());

        // Et elle se valide comme la première l'aurait fait.
        $this->validation->valider($refaite, $this->admin());
        self::assertCount(1, $this->arbre->personnesCreees());
        self::assertCount(1, $this->arbre->famillesCreees());
    }

    /**
     * L'empreinte des demandes est la **seconde composante de l'`ETag`** : sans elle, un
     * auteur qui dépose un brouillon revalide, reçoit `304`, et ne voit jamais son
     * propre nœud — la seule chose que ce calque existe pour produire.
     */
    public function testLEmpreinteDesDemandesChangeAuDepotEtALaDecision(): void
    {
        $port = $this->propositions;
        $opale = $this->lecteur(self::OPALE);

        self::assertSame('', $port->empreinteDesDemandes($opale), 'Sans demande, elle est vide.');

        $demande = $this->proposerTourmaline(self::OPALE);
        $apresDepot = $port->empreinteDesDemandes($opale);

        self::assertNotSame('', $apresDepot, 'Un dépôt ne touche aucune table tree_*, mais change le calque.');

        // Un autre membre n'a rien en attente : son `ETag` ne doit pas bouger parce
        // qu'Opale a déposé.
        self::assertSame('', $port->empreinteDesDemandes($this->lecteur(self::TUF)));

        $this->refus->refuser($demande, $this->admin(), 'Ajoutez la source.', null);

        self::assertSame(
            '',
            $port->empreinteDesDemandes($this->lecteur(self::OPALE)),
            'Une demande tranchée quitte le calque, donc l\'empreinte.',
        );
    }

    /** `demandesSur` allume la pastille d'une fiche sans transporter le calque entier. */
    public function testLesDemandesSurUneFicheSuiventLaMemeVisibilite(): void
    {
        $demande = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $port = $this->propositions;

        self::assertSame(
            [$demande->getId()],
            $port->demandesSur(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::OPALE)),
        );
        self::assertSame(
            [$demande->getId()],
            $port->demandesSur(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::ADMIN, administrateur: true)),
        );
        self::assertSame(
            [],
            $port->demandesSur(SubjectKind::Personne, self::PERSONNE, $this->lecteur(self::TUF)),
        );
    }

    /** Le refus d'une création reste consultable par son auteur, avec son motif. */
    public function testUneCreationRefuseeNEcritRienEtResteConsultable(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $this->refus->refuser($demande, $this->admin(), 'Cette personne figure déjà sous le nom Deulain.', null);

        self::assertSame([], $this->arbre->personnesCreees());
        self::assertSame(
            'Cette personne figure déjà sous le nom Deulain.',
            $this->serialiseur->pourAuteur($this->recharger($demande))['decisionMessage'],
        );
    }

    private function proposerTourmaline(int $auteur, string $prenom = 'Tourmaline'): ChangeRequest
    {
        return $this->depot->deposer(
            $this->corpsDeCreation($prenom, [
                $this->lienConjoint(seq: 1),
                $this->lienParent(seq: 2, enfant: self::ENFANT_A),
                $this->lienParent(seq: 3, enfant: self::ENFANT_B),
            ]),
            $this->lecteur($auteur),
        );
    }

    /**
     * @param list<array<string, mixed>>|null $liens
     *
     * @return array<string, mixed>
     */
    private function corpsDeCreation(string $prenom = 'Tourmaline', ?array $liens = null): array
    {
        return [
            'kind' => 'creation_personne',
            'message' => 'épouse de Jargon, j\'ai le livret de famille',
            'soumettre' => true,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => $prenom,
                'surname' => 'Muguet',
                'marriedName' => 'Sarriette',
                'nickname' => null,
                'sex' => 'feminin',
                'deceased' => true,
                'birth' => [
                    'raw' => 'ABT 1889',
                    'qualifier' => 'environ',
                    'min' => '1889-01-01',
                    'max' => '1889-12-31',
                    'place' => 'Saule, Ombrage, Sylvanie',
                ],
                'death' => $this->blocDate($this->annee(1972)),
                'note' => 'Livret de famille en ma possession, photographié.',
            ]],
            'liens' => $liens ?? [$this->lienConjoint()],
        ];
    }

    /** @return array<string, mixed> */
    private function lienConjoint(int $seq = 1): array
    {
        return [
            'seq' => $seq,
            'kind' => 'conjoint_de',
            'subjectDraftRef' => '#p1',
            'otherPersonId' => self::JARGON,
            'marriage' => [
                'raw' => '23 SEP 1927',
                'qualifier' => 'exacte',
                'min' => '1927-09-23',
                'max' => '1927-09-23',
                'place' => 'Saule, Ombrage, Sylvanie',
            ],
            'divorced' => false,
            'divorce' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function lienParent(int $seq, int $enfant): array
    {
        return [
            'seq' => $seq,
            'kind' => 'parent_de',
            'subjectDraftRef' => '#p1',
            'otherPersonId' => $enfant,
        ];
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
