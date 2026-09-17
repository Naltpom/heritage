<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\NoteAcreer;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\RevisionOperation;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Service\ApplicateurDOperationNote;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * DOCUMENTER UNE FICHE EXISTANTE — `ajout_note`.
 *
 * ## Le résultat absurde que cette nature supprime
 *
 * **On pouvait créer une personne avec sa note, et pas ajouter une note à une personne
 * qui existe.** `prp_draft_person.note` était le seul chemin par lequel une `tree_note`
 * entrait dans l'arbre après l'import ; `OpKind::AjoutNote` existait sans applicateur et
 * levait la même `LogicException` que `ajout_citation`.
 *
 * Toute la matière d'archives portant sur les fiches déjà en base — une transcription
 * d'acte, une clôture de fratrie déclarée devant notaire, la correction d'une cote fausse
 * qui envoie le chercheur suivant dans le mauvais fonds — n'avait donc aucun moyen
 * d'entrer. Ce sont « les lignes les plus précieuses du fichier » (§ 3.5 du modèle).
 *
 * ## Ce que ces tests prouvent, et ce qu'ils ne peuvent pas prouver
 *
 * Le harnais lit ses services dans le **conteneur** : les tags, les itérateurs et les
 * alias sont ceux de la production, et un applicateur non découvert ferait tomber
 * {@see testValiderUneDemandeDeNoteEcritLaNote()} sur le `LogicException` nommé
 * d'`applicateurPour()`.
 *
 * Ce qu'ils ne prouvent pas, parce que `PortArbre` est ici un double : qu'une ligne
 * apparaît dans `tree_note`. C'est `App\Tests\Tree\NoteCreeeParUneDemandeTest` qui le
 * fait, avec la vraie implémentation et une vraie base.
 */
final class AjoutDeNoteTest extends BaseDeDemandes
{
    private const string TEXTE = 'Acte notarié du 12 frimaire an IX, étude de maître Gentiane à Saule : '
        ."la fratrie y est déclarée close à quatre enfants.\nLa cote portée jusqu'ici était fausse.";

    // ────────────────────────────────────────────────────────────── le dépôt

    /** **La table de l'objet visé n'est pas touchée** — règle centrale de l'ADR 0004. */
    public function testUneDemandeDeNoteDeposeeNEcritRienDansLArbre(): void
    {
        $demande = $this->deposer();

        self::assertSame(RequestStatus::EnAttente, $demande->getStatus());
        self::assertSame(0, $this->arbre->ecritures(), 'Un dépôt n\'écrit pas dans l\'arbre.');
        self::assertSame([], $this->arbre->notesCreees());
    }

    /** La cible est la **fiche documentée** : c'est elle que la file d'administration groupe. */
    public function testLaCibleDeLaDemandeEstLaFicheDocumentee(): void
    {
        $demande = $this->deposer();

        self::assertSame(RequestKind::AjoutNote, $demande->getKind());
        self::assertSame(SubjectKind::Personne, $demande->getTargetKind());
        self::assertSame(self::PERSONNE, $demande->getTargetId());
        self::assertSame('Marne Dolomite Sureau', $demande->getTargetLabel());
    }

    /**
     * L'op ne nomme aucun champ et ne verrouille pas la fiche.
     *
     * `ck_prp_op_champ` impose le premier ; le second est un choix : documenter une fiche
     * n'entre pas en conflit avec sa correction, puisque les deux n'écrivent pas au même
     * endroit. `tree_note` ne porte d'ailleurs pas de `#[ORM\Version]`.
     */
    public function testLOpNeNommeAucunChampEtNeVerrouillePasLaFiche(): void
    {
        $op = $this->opsDe($this->deposer())[0];

        self::assertSame(OpKind::AjoutNote, $op->getOpKind());
        self::assertSame(ValueKind::Note, $op->getValueKind());
        self::assertSame(SubjectKind::Personne, $op->getSubjectKind());
        self::assertNull($op->getField(), 'ck_prp_op_champ : un op non-champ ne nomme aucun champ.');
        self::assertNull($op->getBaseVersion(), 'Une note ne verrouille pas la fiche qu\'elle documente.');
        self::assertNull($op->getOldValue(), 'Une création n\'a pas de valeur précédente.');
    }

    /**
     * Les fins de ligne sont normalisées au dépôt.
     *
     * Le même texte saisi au navigateur (`\r\n`) et recopié depuis un fichier (`\n`) doit
     * produire la même note : sinon la détection de doublon ne verrait pas deux envois
     * identiques, et le libellé de révision dépendrait du système de l'auteur.
     */
    public function testLesFinsDeLigneSontNormaliseesAuDepot(): void
    {
        $demande = $this->deposer(corps: "Première ligne.\r\nSeconde ligne.\r\n");

        /** @var array<string, mixed> $valeur */
        $valeur = $this->opsDe($demande)[0]->getNewValue();

        self::assertSame("Première ligne.\nSeconde ligne.", $valeur['body']);
    }

    // ───────────────────────────────────────────────── la validation, l'essentiel

    /** **LE TEST DU LOT** : déposer, valider, et constater que la note est écrite sur la bonne fiche. */
    public function testValiderUneDemandeDeNoteEcritLaNote(): void
    {
        $demande = $this->deposer();

        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertSame(RequestStatus::Validee, $demande->getStatus());

        $notes = $this->arbre->notesCreees();
        self::assertCount(1, $notes, 'Une op de note écrit une note, et une seule.');

        self::assertSame(self::PERSONNE, $notes[0]['personneId']);
        self::assertSame(self::TEXTE, $notes[0]['corps']);
        self::assertSame('generale', $notes[0]['scope']);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **L'auteur inscrit est le DEMANDEUR, jamais l'administrateur qui valide.**.
     *
     * `tree_note.author_label` est rendu sur la fiche. Y inscrire le valideur serait faux
     * deux fois : il n'a pas écrit la note, et le membre qui l'a écrite disparaîtrait.
     * Le laisser vide ne conviendrait pas davantage — pour cette colonne, `null` veut dire
     * « importée du GEDCOM » (§ 3.5).
     */
    public function testLaNotePorteSonAuteurEtNonSonValideur(): void
    {
        $this->validation->valider($this->deposer(), $this->admin());

        $note = $this->arbre->notesCreees()[0];

        self::assertSame(self::OPALE, $note['auteurUserId'], 'La note doit porter le demandeur.');
        self::assertSame('Compte '.self::OPALE, $note['auteurLabel']);
        self::assertNotSame(self::ADMIN, $note['auteurUserId'], 'Le valideur n\'est pas l\'auteur.');
        self::assertNotNull($note['auteurUserId'], 'null signifierait « importée du GEDCOM ».');
    }

    /** Trois notes dans une demande : l'applicateur reçoit son lot et les écrit toutes. */
    public function testTroisNotesDansUneDemandeSontToutesEcrites(): void
    {
        $demande = $this->deposerPlusieurs([
            $this->op(1, 'La cote 3 Q est fausse : lire 61 Q, bureau de Beaumont.'),
            $this->op(2, 'Fratrie close à quatre enfants, déclarée devant notaire.', 'naissance'),
            $this->op(3, 'Le décès est enregistré trois fois : deux transcriptions et l\'original.', 'deces'),
        ]);

        $this->validation->valider($demande, $this->admin());

        $notes = $this->arbre->notesCreees();
        self::assertCount(3, $notes);
        self::assertSame(['generale', 'naissance', 'deces'], array_column($notes, 'scope'));
    }

    /**
     * La révision porte sur la **note**, avec son texte entier dans le diff.
     *
     * `subject_label` est borné à 255 caractères et porte la première ligne ; `changes`
     * est du `JSONB` sans borne et porte le corps complet. C'est le seul endroit où
     * l'historique garde ce qui a été ajouté.
     */
    public function testLaRevisionPorteSurLaNoteAvecSonTexteEntier(): void
    {
        $avant = $this->arbre->version(SubjectKind::Personne, self::PERSONNE);

        $resultat = $this->validation->valider($this->deposer(), $this->admin());

        self::assertCount(1, $resultat->revisions);
        $revision = $resultat->revisions[0];

        self::assertSame(SubjectKind::Note, $revision->getSubjectKind());
        self::assertSame(RevisionOperation::Creation, $revision->getOperation());
        self::assertSame(['avant' => null, 'apres' => self::TEXTE], $revision->getChanges()['body']);
        self::assertSame(['avant' => null, 'apres' => 'generale'], $revision->getChanges()['scope']);

        // Le libellé, lui, est court : la première ligne seulement.
        self::assertStringStartsWith('Acte notarié du 12 frimaire', $revision->getSubjectLabel());
        self::assertStringNotContainsString("\n", $revision->getSubjectLabel());

        self::assertSame($avant, $this->arbre->version(SubjectKind::Personne, self::PERSONNE), 'Documenter une fiche ne fait pas bouger sa version.');
    }

    /**
     * L'empreinte de l'arbre **ne bouge pas** : une note ne déplace aucun nœud.
     *
     * `TreeFingerprint` exclut `tree_note` explicitement (ADR 0005). Voir
     * {@see AjoutDeCitationTest::testUneSourceValideeNeChangePasLEmpreinteDeLArbre()},
     * où la même règle est établie — et où est raconté le test qui affirmait l'inverse
     * et passait au vert sur un double trop large.
     */
    public function testUneNoteValideeNeChangePasLEmpreinteDeLArbre(): void
    {
        $avant = $this->arbre->empreinteDeLArbre();

        $resultat = $this->validation->valider($this->deposer(), $this->admin());

        self::assertSame($avant, $resultat->empreinte);
        self::assertSame($resultat->empreinte, $resultat->revisions[0]->getTreeFingerprintAfter());
    }

    // ──────────────────────────────────────────── les refus, au dépôt

    public function testUneDemandeSansOpEstRefusee(): void
    {
        $erreur = $this->refusAuDepot(['kind' => 'ajout_note', 'soumettre' => true, 'ops' => []]);

        self::assertArrayHasKey('ops', (array) $erreur->champs());
    }

    /** **Une note vide n'est pas une note** : elle n'apprend rien à la fiche. */
    public function testUneNoteVideEstRefusee(): void
    {
        $erreur = $this->refusAuDepot($this->corps([$this->op(1, '   ')]));

        $champs = (array) $erreur->champs();
        self::assertArrayHasKey('ops[0].newValue.body', $champs);
        self::assertStringContainsString('n\'est pas une note', (string) $champs['ops[0].newValue.body']);
    }

    /** Un corps absent, ou d'un autre type qu'une chaîne, est refusé au même endroit. */
    public function testUnCorpsManquantEstRefuse(): void
    {
        $erreur = $this->refusAuDepot($this->corps([[
            'seq' => 1,
            'subjectId' => self::PERSONNE,
            'newValue' => ['scope' => 'generale'],
        ]]));

        self::assertArrayHasKey('ops[0].newValue.body', (array) $erreur->champs());
    }

    /** Une fiche qui n'existe pas rend `404` : c'est la cible, celle que l'écran affichait. */
    public function testUneFicheInexistanteRendIntrouvable(): void
    {
        $this->expectException(Introuvable::class);

        $this->depot->deposer(
            $this->corps([$this->op(1, 'Une note sur personne.', 'generale', personneId: 4242)]),
            $this->lecteur(self::OPALE),
        );
    }

    /** Une portée inventée ferait ranger la note là où personne ne la cherchera. */
    public function testUnePorteeInconnueEstRefuseeAuDepot(): void
    {
        $erreur = $this->refusAuDepot($this->corps([$this->op(1, 'Une note.', 'baptême')]));

        self::assertArrayHasKey('ops[0].newValue.scope', (array) $erreur->champs());
    }

    /** Deux fois le même texte sur la même fiche dans une demande : le formulaire a été validé deux fois. */
    public function testDeuxFoisLeMemeTexteEstRefuse(): void
    {
        $erreur = $this->refusAuDepot($this->corps([
            $this->op(1, 'La cote 3 Q est fausse : lire 61 Q.'),
            $this->op(2, 'La cote 3 Q est fausse : lire 61 Q.'),
        ]));

        self::assertArrayHasKey('ops[1].newValue.body', (array) $erreur->champs());
    }

    /** Deux notes **différentes** sur la même fiche restent légitimes : ce sont deux remarques. */
    public function testDeuxNotesDifferentesSurLaMemeFicheSontAcceptees(): void
    {
        $demande = $this->deposerPlusieurs([
            $this->op(1, 'La cote 3 Q est fausse : lire 61 Q.'),
            $this->op(2, 'Le décès est enregistré trois fois.'),
        ]);

        self::assertCount(2, $this->opsDe($demande));
    }

    /**
     * La référence de brouillon est refusée, et le message dit mieux que pour une
     * citation : une création **porte déjà** son champ `note`.
     */
    public function testUneNoteSurUnBrouillonEstRefuseeEtRenvoieAuChampNote(): void
    {
        $op = $this->op(1, 'Une note.');
        unset($op['subjectId']);
        $op['subjectDraftRef'] = '#p1';

        $erreur = $this->refusAuDepot($this->corps([$op]));

        $champs = (array) $erreur->champs();
        self::assertArrayHasKey('ops[0].subjectDraftRef', $champs);
        self::assertStringContainsString('porte déjà son propre champ', (string) $champs['ops[0].subjectDraftRef']);
    }

    // ──────────────────────────────────────── les refus, à la validation

    /**
     * La fiche effacée entre le dépôt et la validation rend la demande **caduque** :
     * personne n'a refusé, le monde a bougé. Rien n'est écrit.
     */
    public function testUneFicheEffaceeApresLeDepotRendLaDemandeCaduque(): void
    {
        $demande = $this->deposer();

        $this->arbre->supprimer(SubjectKind::Personne, self::PERSONNE);

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Une cible disparue ne se valide pas.');
        } catch (Conflit $conflit) {
            self::assertSame('cible_disparue', $conflit->codeErreur());
        }

        self::assertSame(RequestStatus::Caduque, $demande->getStatus());
        self::assertSame([], $this->arbre->notesCreees(), 'Une demande caduque n\'écrit rien.');
    }

    // ───────────────────────────────────── l'applicateur, unité par unité

    /** Il est un service du conteneur, donc découvert par le tag que l'interface pose. */
    public function testLApplicateurEstUnServiceDuConteneur(): void
    {
        self::assertInstanceOf(ApplicateurDOperation::class, $this->applicateur());
    }

    /**
     * **Il ne réclame que `ajout_note`** — et son frère ne réclame que `ajout_citation`.
     *
     * Deux applicateurs qui réclameraient la même nature se masqueraient l'un l'autre :
     * `applicateurPour()` rend le premier venu, et le second se tairait sans qu'aucun
     * test ne le voie. C'est la raison pour laquelle ce lot livre deux classes frères
     * plutôt qu'une classe à deux natures.
     */
    public function testIlNeReclameQueSaNatureEtNeMarquePasSonFrere(): void
    {
        self::assertSame([OpKind::AjoutNote], $this->applicateur()->natures());

        $citation = self::getContainer()->get(\App\Module\Proposal\Service\ApplicateurDOperationCitation::class);
        self::assertInstanceOf(\App\Module\Proposal\Service\ApplicateurDOperationCitation::class, $citation);
        self::assertSame([OpKind::AjoutCitation], $citation->natures());
    }

    public function testLApplicateurRefuseUneOpQuiViseUnBrouillon(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/la même demande n\'a pas encore créée/');

        $this->applicateur()->appliquer(
            $this->vue(),
            [$this->opEnAttente(subjectId: null, draftRef: '#p1')],
            $this->admin(),
        );
    }

    public function testLApplicateurRefuseUneValeurQuiNEstPasUnObjet(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ne porte pas d\'objet en newValue/');

        $this->applicateur()->appliquer($this->vue(), [$this->opEnAttente(valeur: 'une chaîne')], $this->admin());
    }

    /** Une op fabriquée ailleurs, sans texte : on ne pose pas une note muette sur une fiche. */
    public function testLApplicateurRefuseUneNoteSansTexte(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ne porte aucun texte/');

        $this->applicateur()->appliquer($this->vue(), [$this->opEnAttente(valeur: ['scope' => 'generale'])], $this->admin());
    }

    /** Une portée absente de la valeur d'op retombe sur `generale` plutôt que de lever. */
    public function testUnePorteeAbsenteRetombeSurGenerale(): void
    {
        $this->applicateur()->appliquer(
            $this->vue(),
            [$this->opEnAttente(valeur: ['body' => 'Une note sans portée.'])],
            $this->admin(),
        );

        self::assertSame('generale', $this->arbre->notesCreees()[0]['scope']);
    }

    /** `NoteAcreer` est un objet de transfert : il ne transporte que des scalaires. */
    public function testLObjetDeTransfertNeTransporteQueDesScalaires(): void
    {
        $note = new NoteAcreer(self::PERSONNE, 'Un texte.', 'naissance', self::OPALE, 'Compte 11');

        self::assertSame(self::PERSONNE, $note->personneId);
        self::assertSame('naissance', $note->scope);
        self::assertSame(self::OPALE, $note->auteurUserId);
    }

    // ────────────────────────────────────────────────────────────── le gréement

    private function applicateur(): ApplicateurDOperationNote
    {
        $applicateur = self::getContainer()->get(ApplicateurDOperationNote::class);
        self::assertInstanceOf(ApplicateurDOperationNote::class, $applicateur);

        return $applicateur;
    }

    private function vue(): DemandeEnCours
    {
        return new DemandeEnCours(1, RequestKind::AjoutNote->value, self::OPALE, 'Compte '.self::OPALE);
    }

    private function opEnAttente(?int $subjectId = self::PERSONNE, ?string $draftRef = null, mixed $valeur = null): OpEnAttente
    {
        return new OpEnAttente(
            1,
            OpKind::AjoutNote->value,
            SubjectKind::Personne->value,
            $subjectId,
            $draftRef,
            null,
            ValueKind::Note->value,
            null,
            $valeur ?? ['body' => 'Une note.', 'scope' => 'generale'],
            null,
        );
    }

    /** @return array<string, mixed> */
    private function op(int $seq, string $corps, string $scope = 'generale', ?int $personneId = null): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'ajout_note',
            'subjectKind' => 'personne',
            'subjectId' => $personneId ?? self::PERSONNE,
            'valueKind' => 'note',
            'oldValue' => null,
            'newValue' => ['body' => $corps, 'scope' => $scope],
        ];
    }

    /**
     * @param list<array<string, mixed>> $ops
     *
     * @return array<string, mixed>
     */
    private function corps(array $ops): array
    {
        return [
            'kind' => 'ajout_note',
            'message' => 'Relevé dans l\'acte notarié.',
            'soumettre' => true,
            'ops' => $ops,
        ];
    }

    private function deposer(string $corps = self::TEXTE): ChangeRequest
    {
        return $this->deposerPlusieurs([$this->op(1, $corps)]);
    }

    /** @param list<array<string, mixed>> $ops */
    private function deposerPlusieurs(array $ops): ChangeRequest
    {
        return $this->depot->deposer($this->corps($ops), $this->lecteur(self::OPALE));
    }

    /** @param array<string, mixed> $corps */
    private function refusAuDepot(array $corps): DonneesInvalides
    {
        try {
            $this->depot->deposer($corps, $this->lecteur(self::OPALE));
        } catch (DonneesInvalides $erreur) {
            return $erreur;
        }

        self::fail('Ce corps aurait dû être refusé en 422.');
    }

    /** @return list<ChangeRequestOp> */
    private function opsDe(ChangeRequest $demande): array
    {
        return $this->em
            ->getRepository(ChangeRequestOp::class)
            ->findBy(['request' => $demande], ['seq' => 'ASC']);
    }

    private function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }
}
