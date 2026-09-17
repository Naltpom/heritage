<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\InventaireDeSuppression;
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
use App\Module\Proposal\Service\ApplicateurDOperationSuppressionDePersonne;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * EFFACER UNE FICHE — `suppression_personne`.
 *
 * ## Le chemin qui n'existait pas
 *
 * Le circuit des demandes ne savait ni fusionner ni supprimer. Un doublon dans un arbre
 * généalogique se réparait donc à la main en base : un `DELETE` dans un client SQL, sans
 * demande, sans motif, sans révision, sans relecture. C'était le seul chemin d'écriture
 * qui contournait le mécanisme, et l'ADR 0004 dit qu'il ne doit pas en exister.
 *
 * ## Les trois règles que ces tests tiennent
 *
 * 1. **Une suppression qui détacherait des descendants est refusée** — et refusée au
 *    dépôt, avec le nom des enfants concernés. Deux fiches du même homme, l'une portant
 *    six générations et l'autre vide : c'est la seule chose qui empêche d'effacer la
 *    mauvaise.
 * 2. **Le motif est obligatoire**, et il vit sur la demande : `POST /demandes/{id}/valider`
 *    ne lit aucun corps, une validation ne laisse donc aucune trace écrite de sa raison.
 * 3. **Rien ne part en silence** : l'op porte l'inventaire de ce qu'elle emporte, et une
 *    note ajoutée entre le dépôt et la validation rend la demande **caduque** plutôt que
 *    de partir avec le reste.
 *
 * ## Ce que ces tests ne peuvent pas prouver
 *
 * Que la ligne disparaît de `tree_person`, et que ses notes, citations, faits et unions
 * partent avec elle : `PortArbre` est ici un double. C'est
 * `App\Tests\Tree\PersonneSupprimeeParUneDemandeTest` qui le fait, avec la vraie
 * implémentation et une vraie base.
 */
final class SuppressionDePersonneTest extends BaseDeDemandes
{
    private const string MOTIF = 'Doublon de la fiche 42 : même homme, même acte de naissance, celle-ci est vide.';

    /** La seconde fiche, celle qui sert d'enfant ou de conjointe selon le cas. */
    private const int AUTRE = 55;

    // ────────────────────────────────────────────────────────────── le dépôt

    /** **La table de l'objet visé n'est pas touchée** — règle centrale de l'ADR 0004. */
    public function testUneDemandeDeSuppressionDeposeeNEcritRienDansLArbre(): void
    {
        $demande = $this->deposer();

        self::assertSame(RequestStatus::EnAttente, $demande->getStatus());
        self::assertSame(0, $this->arbre->ecritures(), 'Un dépôt n\'écrit pas dans l\'arbre.');
        self::assertNotNull($this->arbre->version(SubjectKind::Personne, self::PERSONNE), 'La fiche est toujours là.');
        self::assertSame([], $this->arbre->personnesSupprimees());
    }

    public function testLaCibleDeLaDemandeEstLaFicheASupprimer(): void
    {
        $demande = $this->deposer();

        self::assertSame(RequestKind::SuppressionPersonne, $demande->getKind());
        self::assertSame(SubjectKind::Personne, $demande->getTargetKind());
        self::assertSame(self::PERSONNE, $demande->getTargetId());
        self::assertSame('Marne Dolomite Sureau', $demande->getTargetLabel());
    }

    /**
     * L'op ne nomme aucun champ, **mais verrouille la fiche** — et c'est la différence
     * avec une note.
     *
     * Documenter une fiche n'entre pas en conflit avec sa correction ; l'effacer, si.
     * `base_version` est donc posée, pour qu'une correction validée entre-temps fasse
     * rejouer la suppression sur la version courante au lieu de passer inaperçue.
     */
    public function testLOpNeNommeAucunChampMaisVerrouilleLaFiche(): void
    {
        $op = $this->opDe($this->deposer());

        self::assertSame(OpKind::SuppressionPersonne, $op->getOpKind());
        self::assertSame(ValueKind::Suppression, $op->getValueKind());
        self::assertSame(SubjectKind::Personne, $op->getSubjectKind());
        self::assertSame(self::PERSONNE, $op->getSubjectId());
        self::assertNull($op->getField(), 'ck_prp_op_champ : un op non-champ ne nomme aucun champ.');
        self::assertSame(7, $op->getBaseVersion(), 'Effacer une fiche est une écriture sur cette fiche.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **L'op porte l'inventaire de ce qu'elle emporte, et `newValue` vaut `null`.**
     *
     * C'est l'exact miroir d'une création. L'« avant » n'est pas la valeur d'un champ mais
     * le décompte de ce qui disparaîtra, et c'est lui que le contrôle préalable comparera
     * à l'état courant avant d'autoriser le clic.
     */
    public function testLOpPorteLInventaireDansOldValueEtRienDansNewValue(): void
    {
        $this->poserUneNote('La cote 3 Q est fausse : lire 61 Q, bureau de Beaumont.');

        $op = $this->opDe($this->deposer());

        self::assertNull($op->getNewValue(), 'Après une suppression, il n\'y a rien.');

        /** @var array<string, mixed> $inventaire */
        $inventaire = $op->getOldValue();

        self::assertSame(self::PERSONNE, $inventaire['personneId']);
        self::assertSame('Marne Dolomite Sureau', $inventaire['displayName']);
        self::assertSame(1, $inventaire['notes']);
        self::assertSame(0, $inventaire['citations']);
        self::assertSame([], $inventaire['unions']);
        self::assertSame([], $inventaire['descendants']);
        self::assertSame(0, $inventaire['descendanceTotale']);
        self::assertSame(0, $inventaire['medias']);
    }

    // ───────────────────────────────────────────────── la validation, l'essentiel

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LE TEST DU LOT** : déposer, valider, et constater que la fiche n'est plus là.
     */
    public function testValiderUneDemandeDeSuppressionEffaceLaFiche(): void
    {
        $demande = $this->deposer();

        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertSame(RequestStatus::Validee, $demande->getStatus());

        self::assertSame([self::PERSONNE], $this->arbre->personnesSupprimees());
        self::assertNull($this->arbre->chargerCible(SubjectKind::Personne, self::PERSONNE), 'La fiche a disparu de l\'arbre.');
    }

    /**
     * Ce que la suppression emporte est **journalisé**, et la révision est tout ce qui
     * reste de la fiche.
     *
     * `prp_revision.subject_id` désigne une clé primaire qui n'existe plus — la table n'a
     * délibérément aucune clé étrangère vers `tree_person` —, et `changes` porte
     * l'inventaire complet dans le sens inverse d'une création : `avant` renseigné,
     * `apres` à `null`.
     */
    public function testLaRevisionEstUneSuppressionEtGardeCeQuiEstParti(): void
    {
        $this->poserUneNote('Fratrie close à quatre enfants, déclarée devant notaire.');
        $this->poserUneNote('Le décès est enregistré trois fois.');

        $resultat = $this->validation->valider($this->deposer(), $this->admin());

        self::assertCount(1, $resultat->revisions);
        $revision = $resultat->revisions[0];

        self::assertSame(SubjectKind::Personne, $revision->getSubjectKind());
        self::assertSame(self::PERSONNE, $revision->getSubjectId());
        self::assertSame('Marne Dolomite Sureau', $revision->getSubjectLabel());
        self::assertSame(RevisionOperation::Suppression, $revision->getOperation());

        $changes = $revision->getChanges();

        self::assertSame(['avant' => 2, 'apres' => null], $changes['notes'], 'Les deux notes emportées sont comptées.');
        self::assertSame(['avant' => 'Marne Dolomite Sureau', 'apres' => null], $changes['displayName']);
        self::assertArrayNotHasKey('personneId', $changes, 'Un identifiant ne change pas, il cesse d\'exister.');
        self::assertStringContainsString('2 notes', (string) $changes['resume']['avant']);

        self::assertSame(7, $revision->getVersionBefore(), 'La dernière version connue de la fiche.');
        self::assertNull($revision->getVersionAfter(), 'Il n\'y a pas de version d\'après.');
    }

    /** Les notes de la fiche partent avec elle, et le double le constate. */
    public function testLesNotesDeLaFicheSontEmporteesAvecElle(): void
    {
        $this->poserUneNote('Une note qui disparaîtra avec la fiche.');

        self::assertCount(1, $this->arbre->notesCreees());

        $this->validation->valider($this->deposer(), $this->admin());

        self::assertSame([], $this->arbre->notesCreees(), 'Les notes d\'une fiche effacée partent avec elle.');
    }

    /**
     * **L'empreinte de l'arbre change** — et c'est la différence avec une note ou une
     * citation.
     *
     * `TreeFingerprint` couvre `tree_person`, `tree_family` et `tree_child` : une fiche
     * qui disparaît déplace le graphe, donc invalide le cache de placement et les cercles
     * de visibilité. C'est ce qu'on veut, et c'est obtenu sans geste (ADR 0005).
     */
    public function testUneSuppressionChangeLEmpreinteDeLArbre(): void
    {
        $avant = $this->arbre->empreinteDeLArbre();

        $resultat = $this->validation->valider($this->deposer(), $this->admin());

        self::assertNotSame($avant, $resultat->empreinte, 'Une fiche qui disparaît déplace le graphe.');
        self::assertSame($resultat->empreinte, $resultat->revisions[0]->getTreeFingerprintAfter());
    }

    /** Le motif survit à la décision : c'est la seule trace écrite de la raison. */
    public function testLeMotifResteLisibleApresLaValidation(): void
    {
        $demande = $this->deposer();
        $this->validation->valider($demande, $this->admin());

        self::assertSame(self::MOTIF, $this->recharger($demande)->getMessage());
    }

    // ──────────────────────────────────────────── les refus, au dépôt

    /** **Pas de motif, pas de demande** — la règle 2, et elle n'a pas d'autre place. */
    public function testUneDemandeSansMotifEstRefusee(): void
    {
        $erreur = $this->refusAuDepot($this->corps(motif: null));

        $champs = (array) $erreur->champs();
        self::assertArrayHasKey('message', $champs);
        self::assertStringContainsString('se justifie', (string) $champs['message']);
    }

    /** « doublon » ne dit pas de quelle autre fiche, et c'est ce qu'il faut savoir. */
    public function testUnMotifTropCourtEstRefuse(): void
    {
        $erreur = $this->refusAuDepot($this->corps(motif: 'doublon'));

        self::assertArrayHasKey('message', (array) $erreur->champs());
    }

    public function testUnMotifReduitADesEspacesEstRefuse(): void
    {
        $erreur = $this->refusAuDepot($this->corps(motif: '          '));

        self::assertArrayHasKey('message', (array) $erreur->champs());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LA RÈGLE 1** : une suppression qui détacherait des descendants est refusée, et
     * le message nomme l'enfant.
     *
     * C'est le cas réel qui motive tout ce lot — deux fiches du même homme, l'une portant
     * six générations et l'autre vide. Le refus n'est pas seulement un garde-fou : il
     * **dit laquelle des deux fiches on est en train d'effacer**, ce qu'un compte
     * (« 6 descendants ») laisserait deviner.
     */
    public function testUneFicheQuiPorteDesDescendantsEstRefuseeAuDepot(): void
    {
        $this->poserUnDescendant();

        $erreur = $this->refusAuDepot($this->corps());

        $champs = (array) $erreur->champs();
        self::assertArrayHasKey('targetId', $champs, 'Il n\'y a rien à corriger dans la saisie : c\'est la fiche choisie qui n\'est pas la bonne.');

        $message = (string) $champs['targetId'];

        // **Le chiffre d'abord** : c'est lui qui arrête la main, pas le nom.
        self::assertStringStartsWith('1 fiche descend de celle-ci', $message);
        self::assertStringContainsString('Cobalt Sureau', $message, 'Le message nomme l\'enfant qui serait détaché.');
        self::assertStringContainsString('l\'autre fiche', $message, 'Et il dit quoi faire à la place.');
    }

    /** Une fiche qui n'existe pas rend `404` : c'est la cible, celle que l'écran affichait. */
    public function testUneFicheInexistanteRendIntrouvable(): void
    {
        $this->expectException(Introuvable::class);

        $this->depot->deposer($this->corps(personneId: 4242), $this->lecteur(self::OPALE));
    }

    /** Ce circuit ne supprime que des personnes : une union n'emporte pas les mêmes lignes. */
    public function testUneCibleQuiNEstPasUnePersonneEstRefusee(): void
    {
        $corps = $this->corps();
        $corps['targetKind'] = 'famille';

        self::assertArrayHasKey('targetKind', (array) $this->refusAuDepot($corps)->champs());
    }

    // ──────────────────────────────────────── les refus, à la validation

    /**
     * La fiche effacée entre le dépôt et la validation rend la demande **caduque** :
     * personne n'a refusé, quelqu'un d'autre a fait le travail.
     */
    public function testUneFicheDejaEffaceeRendLaDemandeCaduque(): void
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
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **LA RÈGLE 3, tenue par la machinerie** : une note ajoutée après le dépôt rend la
     * demande caduque.
     *
     * L'administrateur a lu « emporte 0 note » sur son écran. Valider maintenant
     * effacerait une ligne que personne ne lui a montrée. La demande meurt, l'auteur la
     * refait avec l'inventaire à jour, et le bouton « refaire cette demande » existe
     * précisément pour que ce ne soit pas une contribution perdue.
     *
     * Le prix est assumé et il est écrit ici : documenter une fiche pendant qu'une
     * demande de suppression l'attend coûte un clic à son auteur. L'erreur inverse
     * coûterait la note.
     */
    public function testUneNoteAjouteeApresLeDepotRendLaDemandeCaduque(): void
    {
        $demande = $this->deposer();

        $this->poserUneNote('Une transcription arrivée entre-temps.');

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Une suppression qui emporterait plus que ce qui a été annoncé ne se valide pas.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
        }

        self::assertSame(RequestStatus::Caduque, $demande->getStatus());
        self::assertSame([], $this->arbre->personnesSupprimees(), 'Une demande caduque n\'efface rien.');
        self::assertCount(1, $this->arbre->notesCreees(), 'Et la note arrivée entre-temps est toujours là.');
    }

    /**
     * Un descendant rattaché après le dépôt rend la demande caduque — le second filet de
     * la règle 1.
     *
     * Le premier est le refus au dépôt ; celui-ci existe parce que le monde bouge entre
     * les deux, et parce qu'une demande déposée sur une fiche alors vide ne doit pas
     * emporter une branche apparue depuis.
     */
    public function testUnDescendantRattacheApresLeDepotRendLaDemandeCaduque(): void
    {
        $demande = $this->deposer();

        $this->poserUnDescendant();

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Une suppression qui détacherait une branche apparue depuis ne se valide pas.');
        } catch (Conflit $conflit) {
            self::assertSame('demande_caduque', $conflit->codeErreur());
        }

        self::assertSame([], $this->arbre->personnesSupprimees());
        self::assertNotNull($this->arbre->chargerCible(SubjectKind::Personne, self::PERSONNE));
    }

    /**
     * La caducité nomme le conflit, et ce n'est pas une évidence.
     *
     * `champsEnConflit()` ignorait toute op sans `field` — ce qu'une suppression est par
     * construction, `ck_prp_op_champ` le lui interdisant. La `409` serait partie avec
     * `champs: []`, c'est-à-dire un conflit qu'on annonce sans pouvoir dire lequel.
     */
    public function testLaCaduciteDUneSuppressionNommeLeConflit(): void
    {
        $demande = $this->deposer();
        $this->poserUneNote('Une transcription arrivée entre-temps.');

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Attendu une caducité.');
        } catch (Conflit $conflit) {
            $details = $conflit->details();
            /** @var list<array{field: string}> $champs */
            $champs = $details['champs'] ?? [];

            self::assertCount(1, $champs);
            self::assertSame('suppression_personne', $champs[0]['field']);
        }
    }

    // ───────────────────────────────────── refaire une suppression caduque

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **Une suppression caduque se refait, et le corps rendu est redéposable tel quel.**
     *
     * C'est ce qui empêche la caducité d'être une contribution perdue — « un membre dont
     * la première contribution se perd n'en dépose pas de seconde » (§ 9.6).
     *
     * `RefaireDemande` **laisse tomber l'op**, parce qu'elle ne nomme aucun champ et que
     * sa boucle passe les ops sans `field`. Ce n'est pas une perte ici, et c'est le point
     * du test : le corps d'une suppression ne porte rien d'autre que `targetId` et
     * `message`, et l'inventaire se remesure au dépôt suivant. Le recopier serait même
     * nuisible — c'est justement parce qu'il a changé que la demande est morte.
     */
    public function testUneSuppressionCaduqueSeRefaitEtLeCorpsEstRedeposable(): void
    {
        $demande = $this->deposer();
        $this->poserUneNote('Une transcription arrivée entre-temps.');

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Attendu une caducité.');
        } catch (Conflit) {
            // La caducité attendue : c'est elle qui ouvre le droit de refaire.
        }

        $corps = $this->reprise->corpsPreRempli($this->recharger($demande));

        self::assertSame('suppression_personne', $corps['kind']);
        self::assertSame(self::PERSONNE, $corps['targetId']);
        self::assertSame(self::MOTIF, $corps['message']);
        self::assertSame([], $corps['ops'], 'L\'op est reconstruite au dépôt, pas recopiée.');

        // Redéposé tel quel — `soumettre` mis à `true`, comme le fait le front —, il
        // produit une demande neuve dont l'inventaire est celui d'aujourd'hui.
        $corps['soumettre'] = true;
        $refaite = $this->depot->deposer($corps, $this->lecteur(self::OPALE));

        /** @var array<string, mixed> $inventaire */
        $inventaire = $this->opDe($refaite)->getOldValue();

        self::assertSame(1, $inventaire['notes'], 'La note arrivée entre-temps est cette fois annoncée.');
        self::assertSame(RequestStatus::EnAttente, $refaite->getStatus());
    }
    // ───────────────────────────────────── l'écran d'administration

    /**
     * L'administrateur lit l'inventaire **d'aujourd'hui** à côté de celui du dépôt, et une
     * phrase toute faite.
     *
     * Sans `valeurCourante`, il déciderait sur un décompte de trois jours ; sans
     * `libelleDiff`, il déplierait un objet JSON pour savoir ce qu'il efface.
     */
    public function testLAdministrationVoitCeQueLaSuppressionEmporte(): void
    {
        $this->poserUneNote('Une note.');
        $demande = $this->deposer();

        $charge = $this->serialiseur->pourAdministration($demande);

        /** @var list<array<string, mixed>> $ops */
        $ops = $charge['ops'];

        self::assertSame('application', $charge['resolutionPrevue']);
        self::assertStringContainsString('Suppression de la fiche', (string) $ops[0]['libelleDiff']);
        self::assertStringContainsString('1 note', (string) $ops[0]['libelleDiff']);

        /** @var array<string, mixed> $courant */
        $courant = $ops[0]['valeurCourante'];
        self::assertSame(1, $courant['notes']);
        self::assertFalse($ops[0]['aBouge']);
    }

    /** Le même écran, après qu'une note est arrivée : le bouton doit se désarmer. */
    public function testLAdministrationAnnonceLaCaduciteAvantLeClic(): void
    {
        $demande = $this->deposer();
        $this->poserUneNote('Une note arrivée entre-temps.');

        $charge = $this->serialiseur->pourAdministration($demande);

        self::assertSame('caducite', $charge['resolutionPrevue'], 'Sans cela, l\'administrateur découvre la caducité en cliquant.');
    }

    // ───────────────────────────────────── l'applicateur, unité par unité

    /** Il est un service du conteneur, donc découvert par le tag que l'interface pose. */
    public function testLApplicateurEstUnServiceDuConteneur(): void
    {
        self::assertInstanceOf(ApplicateurDOperation::class, $this->applicateur());
    }

    /** Deux applicateurs qui réclameraient la même nature se masqueraient l'un l'autre. */
    public function testIlNeReclameQueSaNature(): void
    {
        self::assertSame([OpKind::SuppressionPersonne], $this->applicateur()->natures());
    }

    public function testLApplicateurRefuseUneOpQuiViseUnBrouillon(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/n\'a pas encore créée/');

        $this->applicateur()->appliquer(
            $this->vue(),
            [$this->opEnAttente(subjectId: null, draftRef: '#p1')],
            $this->admin(),
        );
    }

    public function testLApplicateurRefuseUnSujetQuiNEstPasUnePersonne(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/que des fiches de personne/');

        $this->applicateur()->appliquer(
            $this->vue(),
            [$this->opEnAttente(subjectKind: SubjectKind::Famille)],
            $this->admin(),
        );
    }

    // ───────────────────────────────────── l'inventaire, unité par unité

    /** Une liste de clés dont l'ordre est un contrat : voir `ValueKind::Suppression`. */
    public function testLInventaireSeRelitDansLeMemeOrdreQuelQueSoitCeluiDeLaBase(): void
    {
        $inventaire = new InventaireDeSuppression(17, 'Marne Dolomite Sureau', 3, 2, 1, 1, [], [], 23, 1);

        $desordonne = array_reverse($inventaire->versJson(), preserve_keys: true);

        self::assertSame(
            $inventaire->versJson(),
            InventaireDeSuppression::depuisJson($desordonne)->versJson(),
            'Sans ce détour, PostgreSQL rendrait caduque une demande que rien ne contredit.',
        );
    }

    /** Une fiche qui ne porte rien le dit, plutôt que de rendre une phrase vide. */
    public function testUnInventaireVideSeLitQuandMeme(): void
    {
        $inventaire = new InventaireDeSuppression(17, 'Cobalt Sureau', 0, 0, 0, 0, [], []);

        self::assertStringContainsString('ne porte rien', $inventaire->enUneLigne());
        self::assertFalse($inventaire->estBloquee());
    }

    /** Les deux blocages se nomment séparément : ils ne se corrigent pas au même endroit. */
    public function testLesDeuxBlocagesSeNommentSeparement(): void
    {
        // Un enfant direct, vingt-trois fiches dans la branche : c'est l'écart exact entre
        // les deux fiches d'un doublon, et c'est le second nombre qui le dit.
        $avecDescendance = new InventaireDeSuppression(
            17,
            'Marne',
            0,
            0,
            0,
            0,
            [],
            [['personneId' => 55, 'displayName' => 'Cobalt Sureau']],
            23,
        );
        $avecMedia = (new InventaireDeSuppression(17, 'Marne', 0, 0, 0, 0, [], []))->avecMedias(2);

        self::assertTrue($avecDescendance->estBloquee());
        self::assertCount(1, $avecDescendance->raisonsDuBlocage());
        self::assertStringStartsWith('23 fiches descendent de celle-ci', $avecDescendance->raisonsDuBlocage()[0]);
        self::assertStringContainsString('Cobalt Sureau', $avecDescendance->raisonsDuBlocage()[0]);

        self::assertTrue($avecMedia->estBloquee());
        self::assertStringContainsString('2 preuves', $avecMedia->raisonsDuBlocage()[0]);
    }

    // ────────────────────────────────────────────────────────────── le gréement

    private function applicateur(): ApplicateurDOperationSuppressionDePersonne
    {
        $applicateur = self::getContainer()->get(ApplicateurDOperationSuppressionDePersonne::class);
        self::assertInstanceOf(ApplicateurDOperationSuppressionDePersonne::class, $applicateur);

        return $applicateur;
    }

    private function vue(): DemandeEnCours
    {
        return new DemandeEnCours(1, RequestKind::SuppressionPersonne->value, self::OPALE, 'Compte '.self::OPALE);
    }

    private function opEnAttente(
        ?int $subjectId = self::PERSONNE,
        ?string $draftRef = null,
        SubjectKind $subjectKind = SubjectKind::Personne,
    ): OpEnAttente {
        return new OpEnAttente(
            1,
            OpKind::SuppressionPersonne->value,
            $subjectKind->value,
            $subjectId,
            $draftRef,
            null,
            ValueKind::Suppression->value,
            null,
            null,
            7,
        );
    }

    /** @return array<string, mixed> */
    private function corps(?string $motif = self::MOTIF, ?int $personneId = null): array
    {
        return [
            'kind' => 'suppression_personne',
            'message' => $motif,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => $personneId ?? self::PERSONNE,
        ];
    }

    private function deposer(): ChangeRequest
    {
        return $this->depot->deposer($this->corps(), $this->lecteur(self::OPALE));
    }

    /** Une note posée directement dans l'arbre, comme une demande validée l'aurait posée. */
    private function poserUneNote(string $corps): void
    {
        $this->arbre->creerNote(new NoteAcreer(self::PERSONNE, $corps, 'generale', self::OPALE, 'Compte '.self::OPALE));
    }

    /**
     * Un enfant rattaché à une union de la fiche visée — la mise en scène du cas réel.
     *
     * L'enfant est posé d'abord, puis l'union, puis la filiation : c'est l'ordre du
     * § 9.5, et c'est celui qu'une validation de création suivrait.
     */
    private function poserUnDescendant(): void
    {
        $this->arbre->poser(SubjectKind::Personne, self::AUTRE, 'Cobalt Sureau', 1, [
            'givenNames' => 'Cobalt',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            'deceased' => true,
            'birth' => UncertainDate::inconnue(),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);

        $famille = $this->arbre->trouverOuCreerFamille(self::PERSONNE, null);
        $this->arbre->rattacherEnfant($famille->id, self::AUTRE, 1);
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

    private function opDe(ChangeRequest $demande): ChangeRequestOp
    {
        $ops = $this->em
            ->getRepository(ChangeRequestOp::class)
            ->findBy(['request' => $demande], ['seq' => 'ASC']);

        self::assertCount(1, $ops, 'Une suppression porte un op, et un seul.');

        return $ops[0];
    }

    private function admin(): Lecteur
    {
        return new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true);
    }
}
