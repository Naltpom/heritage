<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Proposal\Contract\ApplicateurDOperation;
use App\Module\Proposal\Contract\CitationAcreer;
use App\Module\Proposal\Contract\DemandeEnCours;
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
use App\Module\Proposal\Service\ApplicateurDOperationCitation;
use App\Tests\Proposal\Double\ArbreEnMemoire;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * PROPOSER LA SOURCE D'UNE FICHE — `ajout_citation`.
 *
 * ## Ce que ce fichier éprouve, et le trou qu'il comble
 *
 * `modification_citation` savait **corriger** une citation depuis le premier jour. Rien
 * ne savait en **créer** une : `OpKind::AjoutCitation` existait sans applicateur, le mot
 * « citation » n'apparaissait dans aucun corps de dépôt, et le seul applicateur d'op
 * enregistré dans toute l'application était celui des médias.
 *
 * Ce n'était pas une lacune théorique. La règle imposée à chaque contributeur — *chaque
 * fiche porte sa source* — n'avait **aucun chemin** dans le circuit : une cote d'archive
 * relevée ne pouvait être consignée que dans la note en prose d'une demande, où rien ne
 * l'indexe, où l'onglet « sources » ne la voit pas, et où `citation_count` reste à zéro.
 *
 * ## Le harnais passe par le conteneur, et c'est ce qui rend ces tests concluants
 *
 * `BaseDeDemandes` lit ses services dans le conteneur : les tags, les itérateurs et les
 * alias sont ceux de la production. Un applicateur qui ne serait pas découvert par
 * `app.proposal.applicateur_d_operation` ferait donc tomber
 * {@see testValiderUneDemandeDeSourceEcritLaCitation()} sur le `LogicException` d'
 * `applicateurPour()` — et non passer au vert sur une liste écrite à la main, ce qui est
 * précisément le défaut que ce module a déjà payé une fois.
 *
 * ## L'arbre est le double, et il suffit ici
 *
 * `ArbreEnMemoire` enregistre ce que le port a reçu. Ce qu'il **ne** prouve pas, c'est
 * qu'une ligne apparaît dans `tree_citation` : aucun test de ce dossier ne touche une
 * table `tree_*`, par construction. C'est
 * `App\Tests\Tree\CitationCreeeParUneDemandeTest` qui le prouve, avec la vraie
 * implémentation et une vraie base — et les deux ne se remplacent pas.
 */
final class AjoutDeCitationTest extends BaseDeDemandes
{
    /** « Registre paroissial de Saule, 1801-1820 » — une source posée par l'import. */
    private const int SOURCE = 204;

    /** Une source de plus, pour éprouver que le doublon se juge sur le couple source+page. */
    private const int AUTRE_SOURCE = 207;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbre
            ->poserSource(self::SOURCE, 'Registre paroissial de Saule, 1801-1820')
            ->poserSource(self::AUTRE_SOURCE, 'Recensement d\'Ombrage, 1836');
    }

    // ────────────────────────────────────────────────────────────── le dépôt

    /**
     * **La table de l'objet visé n'est pas touchée** — la règle centrale de l'ADR 0004,
     * et le premier test de toutes les natures de ce module.
     *
     * Le compteur du double le dit mieux qu'une inspection : il compte les appels au
     * port, et il doit rester à zéro tant qu'un administrateur n'a pas décidé.
     */
    public function testUneDemandeDeSourceDeposeeNEcritRienDansLArbre(): void
    {
        $demande = $this->deposer();

        self::assertSame(RequestStatus::EnAttente, $demande->getStatus());
        self::assertSame(0, $this->arbre->ecritures(), 'Un dépôt n\'écrit pas dans l\'arbre.');
        self::assertSame([], $this->arbre->citationsCreees());
    }

    /**
     * La cible dénormalisée est la **personne**, jamais la citation : la citation
     * n'existe pas encore, et la file d'administration filtre sur une fiche.
     */
    public function testLaCibleDeLaDemandeEstLaFicheSourcee(): void
    {
        $demande = $this->deposer();

        self::assertSame(RequestKind::AjoutCitation, $demande->getKind());
        self::assertSame(SubjectKind::Personne, $demande->getTargetKind());
        self::assertSame(self::PERSONNE, $demande->getTargetId());
        self::assertSame('Marne Dolomite Sureau', $demande->getTargetLabel());
    }

    /**
     * Le titre de la source est recopié depuis la **ligne**, pas depuis le corps.
     *
     * C'est ce titre que l'administrateur lira dans le diff avant de décider. Le prendre
     * du corps laisserait un client annoncer « Acte de naissance » sur une source qui
     * n'en est pas une, et la décision porterait sur une phrase que personne n'a écrite.
     */
    public function testLeTitreDeLaSourceEstReprisDeLArbreEtNonDuCorps(): void
    {
        $demande = $this->deposer(valeur: [
            'sourceId' => self::SOURCE,
            'sourceTitle' => 'Un titre inventé par le client',
            'page' => 'vue 12',
            'quay' => 2,
        ]);

        $op = $this->opsDe($demande)[0];
        /** @var array<string, mixed> $valeur */
        $valeur = $op->getNewValue();

        self::assertSame('Registre paroissial de Saule, 1801-1820', $valeur['sourceTitle']);
        self::assertNull($op->getOldValue(), 'Une création n\'a pas de valeur précédente.');
        self::assertNull($op->getField(), 'ck_prp_op_champ : un op non-champ ne nomme aucun champ.');
        self::assertNull($op->getBaseVersion(), 'Une source ne verrouille pas la fiche qu\'elle étaye.');
    }

    // ───────────────────────────────────────────────── la validation, l'essentiel

    /**
     * **LE TEST DU LOT** : déposer, valider, et constater que la citation est écrite,
     * rattachée à la bonne fiche, avec la bonne page et le bon `QUAY`.
     */
    public function testValiderUneDemandeDeSourceEcritLaCitation(): void
    {
        $demande = $this->deposer();

        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertSame('validee', $resultat->resultat);
        self::assertSame(RequestStatus::Validee, $demande->getStatus());

        $citations = $this->arbre->citationsCreees();
        self::assertCount(1, $citations, 'Une op de citation écrit une citation, et une seule.');

        self::assertSame(self::PERSONNE, $citations[0]['personneId']);
        self::assertSame(self::SOURCE, $citations[0]['sourceId']);
        self::assertSame('AD Sylvanie, 5 MI 118, vue 12', $citations[0]['page']);
        self::assertSame(2, $citations[0]['quay']);
        self::assertSame('Le douze frimaire an IX, devant nous…', $citations[0]['dataText']);
        self::assertSame('naissance', $citations[0]['scope']);
    }

    /**
     * Deux citations dans une seule demande : l'applicateur reçoit son lot en une fois,
     * dans l'ordre de `seq`, et les écrit toutes les deux.
     *
     * Une boucle qui s'arrêterait à la première laisserait la seconde marquée appliquée
     * sans rien écrire — la perte silencieuse que ce circuit a déjà connue.
     */
    public function testDeuxCitationsDansUneDemandeSontToutesLesDeuxEcrites(): void
    {
        $demande = $this->deposerPlusieurs([
            $this->op(1, self::SOURCE, 'vue 12', 2),
            $this->op(2, self::AUTRE_SOURCE, 'page 44', 1),
        ]);

        $this->validation->valider($demande, $this->admin());

        $citations = $this->arbre->citationsCreees();
        self::assertCount(2, $citations);
        self::assertSame([self::SOURCE, self::AUTRE_SOURCE], array_column($citations, 'sourceId'));
        self::assertSame(['vue 12', 'page 44'], array_column($citations, 'page'));
    }

    /**
     * La révision porte sur la **citation**, pas sur la personne.
     *
     * Les colonnes éditables de la fiche sont intactes et sa `version` n'a pas bougé :
     * inscrire une révision sur elle ferait apparaître dans son historique une
     * modification qui n'a pas eu lieu. C'est le raisonnement de l'applicateur des
     * médias, et il tient ici pour la même raison.
     */
    public function testLaRevisionPorteSurLaCitationEtNonSurLaFiche(): void
    {
        $avant = $this->arbre->version(SubjectKind::Personne, self::PERSONNE);

        $demande = $this->deposer();
        $resultat = $this->validation->valider($demande, $this->admin());

        self::assertCount(1, $resultat->revisions);
        $revision = $resultat->revisions[0];

        self::assertSame(SubjectKind::Citation, $revision->getSubjectKind());
        self::assertSame(RevisionOperation::Creation, $revision->getOperation());
        self::assertSame('Registre paroissial de Saule, 1801-1820', $revision->getSubjectLabel());

        // « Pour une création, `avant` vaut `null` partout » (§ 5.5).
        self::assertSame(['avant' => null, 'apres' => 'Registre paroissial de Saule, 1801-1820'], $revision->getChanges()['source']);
        self::assertSame(['avant' => null, 'apres' => 'AD Sylvanie, 5 MI 118, vue 12'], $revision->getChanges()['page']);
        self::assertSame(['avant' => null, 'apres' => 2], $revision->getChanges()['quay']);

        self::assertSame($avant, $this->arbre->version(SubjectKind::Personne, self::PERSONNE), 'Sourcer une fiche ne fait pas bouger sa version.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * **L'empreinte de l'arbre ne bouge PAS — et ce test a d'abord affirmé l'inverse.**.
     *
     * Écrit d'abord en « une source validée change l'empreinte », il passait au vert.
     * Il passait parce que `ArbreEnMemoire` hachait toutes ses lignes, alors que
     * `TreeFingerprint` ne couvre que `tree_person`, `tree_family` et `tree_child` :
     * « ni `med_media`, ni `tree_citation`, ni `tree_source`, ni `tree_fact`, ni
     * `tree_note` — rien de tout cela ne déplace un nœud ni ne modifie une parenté »
     * (ADR 0005, § Le périmètre est étroit, et c'est délibéré).
     *
     * Le double a été restreint au même périmètre, et l'affirmation retournée. Ce qui
     * suit est donc la règle, pas une limitation : élargir l'empreinte ferait recalculer
     * le placement des 377 nœuds **et** tous les cercles de visibilité parce que
     * quelqu'un a versé une cote d'archive.
     *
     * **Conséquence à connaître, et qui est sans danger ici** : seule
     * `GET /api/arbre/graphe` porte un `ETag` bâti sur cette empreinte. La fiche, qui est
     * le seul écran où une citation s'affiche, n'est pas revalidée par ce chemin — elle
     * ne peut donc pas être servie périmée.
     */
    public function testUneSourceValideeNeChangePasLEmpreinteDeLArbre(): void
    {
        $avant = $this->arbre->empreinteDeLArbre();

        $resultat = $this->validation->valider($this->deposer(), $this->admin());

        self::assertSame($avant, $resultat->empreinte, 'Une citation ne déplace aucun nœud : l’empreinte structurante ne bouge pas.');

        // La révision porte quand même l'empreinte du lot, comme toutes les autres : sans
        // cela, deux lignes d'un même acte sembleraient appartenir à deux états.
        self::assertSame($resultat->empreinte, $resultat->revisions[0]->getTreeFingerprintAfter());
    }

    // ──────────────────────────────────────────── les refus, au dépôt

    /** Une demande qui ne désigne aucune citation ne dit pas ce qu'elle demande. */
    public function testUneDemandeSansOpEstRefusee(): void
    {
        $erreur = $this->refusAuDepot(['kind' => 'ajout_citation', 'soumettre' => true, 'ops' => []]);

        self::assertArrayHasKey('ops', (array) $erreur->champs());
    }

    /**
     * Une source qui n'existe pas rend `422` sur **le chemin de l'op**, et non `404`.
     *
     * La source n'est pas la cible de la demande : c'est une valeur saisie dans le
     * formulaire. Même geste que `ConstructeurDAjoutDeMedia` sur `mediaId`, pour que
     * l'erreur se pose sur la ligne fautive de l'écran.
     */
    public function testUneSourceInexistanteEstRefuseeSurLeCheminDeLOp(): void
    {
        $erreur = $this->refusAuDepot($this->corps([$this->op(1, 9999, 'vue 12', 2)]));

        self::assertSame('donnees_invalides', $erreur->codeErreur());
        self::assertArrayHasKey('ops[0].newValue.sourceId', (array) $erreur->champs());
    }

    /**
     * Une fiche qui n'existe pas rend `404` : c'est la **cible**, celle que l'écran
     * affichait quand l'auteur a cliqué.
     */
    public function testUneFicheInexistanteRendIntrouvable(): void
    {
        $this->expectException(Introuvable::class);

        $this->depot->deposer(
            $this->corps([$this->op(1, self::SOURCE, 'vue 12', 2, personneId: 4242)]),
            $this->lecteur(self::OPALE),
        );
    }

    /**
     * Un `QUAY` hors de la norme est refusé **au dépôt**, devant celui qui saisit.
     *
     * `modification_citation` ne le fait pas : `quay` y est un `ValueKind::Enum`, validé
     * comme une simple chaîne. Le champ y est même inatteignable — voir le détail mesuré
     * en tête de `App\Tests\Tree\CitationCreeeParUneDemandeTest`. On ne reproduit pas ce
     * chemin : c'est le rôle de `PortArbre::vocabulaireDAnnotation()`.
     */
    /** Le lien de la visionneuse d'archives voyage de la demande jusqu'à la citation. */
    public function testLeLienDeLaPieceEstEcritAvecLaCitation(): void
    {
        $demande = $this->deposer([
            'sourceId' => self::SOURCE,
            'page' => 'AD Sylvanie, 5 MI 118, vue 12',
            'quay' => 2,
            'dataText' => null,
            'scope' => 'naissance',
            'url' => '  https://archives.sylvanie.example/ark:/12345/vue12  ',
        ]);

        $this->validation->valider($demande, $this->admin());

        $citations = $this->arbre->citationsCreees();
        self::assertSame('https://archives.sylvanie.example/ark:/12345/vue12', $citations[0]['url']);
    }

    /**
     * Le lien finit en `href` sur la fiche : un `javascript:` ou une adresse sans hôte
     * est refusé au dépôt, sur le chemin du champ.
     */
    public function testUnLienQuiNEstPasUneAdresseWebEstRefuse(): void
    {
        foreach (['javascript:alert(1)', 'archives.example/vue', 'ftp://archives.example/x', 'https://'] as $lien) {
            $op = $this->op(1, self::SOURCE, 'vue 12', 2);
            $op['newValue']['url'] = $lien;

            $erreur = $this->refusAuDepot($this->corps([$op]));

            self::assertArrayHasKey('ops[0].newValue.url', (array) $erreur->champs(), $lien);
        }
    }

    public function testUnQuayHorsNormeEstRefuseAuDepot(): void
    {
        $erreur = $this->refusAuDepot($this->corps([$this->op(1, self::SOURCE, 'vue 12', 7)]));

        self::assertArrayHasKey('ops[0].newValue.quay', (array) $erreur->champs());
    }

    /** Une portée inventée ferait ranger la citation là où personne ne la cherchera. */
    public function testUnePorteeInconnueEstRefuseeAuDepot(): void
    {
        $op = $this->op(1, self::SOURCE, 'vue 12', 2);
        $op['newValue']['scope'] = 'baptême';

        $erreur = $this->refusAuDepot($this->corps([$op]));

        self::assertArrayHasKey('ops[0].newValue.scope', (array) $erreur->champs());
    }

    /**
     * Une citation qui ne dit ni où retrouver la pièce, ni ce qu'elle dit, ni quelle
     * confiance lui accorder ne fait que faire **passer** la fiche pour sourcée.
     */
    public function testUneCitationVideEstRefusee(): void
    {
        $erreur = $this->refusAuDepot($this->corps([[
            'seq' => 1,
            'subjectId' => self::PERSONNE,
            'newValue' => ['sourceId' => self::SOURCE, 'page' => '   ', 'quay' => null, 'dataText' => null],
        ]]));

        self::assertArrayHasKey('ops[0].newValue', (array) $erreur->champs());
    }

    /**
     * **L'ordre des messages est un choix, et il est fixé ici.**.
     *
     * Un corps qui cumule les deux fautes — source inexistante *et* citation vide — doit
     * rendre la première. Celui qui se trompe d'identifiant de source a besoin de lire
     * « cette source n'existe pas » ; « votre citation est vide » serait vrai, et le
     * laisserait remplir une page pour se faire refuser une seconde fois.
     */
    public function testUneSourceFausseEstSignaleeAvantUneCitationVide(): void
    {
        $erreur = $this->refusAuDepot($this->corps([[
            'seq' => 1,
            'subjectId' => self::PERSONNE,
            'newValue' => ['sourceId' => 9999, 'page' => null, 'quay' => null, 'dataText' => null],
        ]]));

        $champs = (array) $erreur->champs();
        self::assertArrayHasKey('ops[0].newValue.sourceId', $champs);
        self::assertArrayNotHasKey('ops[0].newValue', $champs);
    }

    /** Deux fois la même page de la même source dans une demande : le formulaire a été validé deux fois. */
    public function testDeuxFoisLaMemePageDeLaMemeSourceEstRefusee(): void
    {
        $erreur = $this->refusAuDepot($this->corps([
            $this->op(1, self::SOURCE, 'vue 12', 2),
            $this->op(2, self::SOURCE, 'vue 12', 3),
        ]));

        self::assertArrayHasKey('ops[1].newValue', (array) $erreur->champs());
    }

    /**
     * **La décision sur les références de brouillon, écrite en test.**.
     *
     * Une citation sur une personne créée par la même demande n'est pas acceptée, et le
     * message dit quoi faire. La cause est structurelle : `ValidationDeDemande::ecrire()`
     * applique les ops **avant** la nature de la demande, donc avant que
     * `ApplicateurDeCreation` n'ait inséré les brouillons et rempli la carte `#p1 → 312`.
     * Ce test existe pour que le jour où l'ordre des passes change, quelqu'un vienne ici.
     */
    public function testUneCitationSurUnBrouillonEstRefuseeEtLeDitClairement(): void
    {
        $op = $this->op(1, self::SOURCE, 'vue 12', 2);
        unset($op['subjectId']);
        $op['subjectDraftRef'] = '#p1';

        $erreur = $this->refusAuDepot($this->corps([$op]));

        $champs = (array) $erreur->champs();
        self::assertArrayHasKey('ops[0].subjectDraftRef', $champs);
        self::assertStringContainsString('déposez d\'abord la création', (string) $champs['ops[0].subjectDraftRef']);
    }

    // ──────────────────────────────────────── les refus, à la validation

    /**
     * La fiche effacée entre le dépôt et la validation rend la demande **caduque**, pas
     * en échec : personne n'a refusé, le monde a bougé (ADR 0004).
     *
     * Rien n'est écrit, et la demande garde son bouton « refaire ». C'est
     * `AnalyseDeDemande` qui tranche, **avant** l'applicateur : elle charge la cible de
     * toute op portant un `subject_id`, quelle que soit sa nature.
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
        self::assertSame([], $this->arbre->citationsCreees(), 'Une demande caduque n\'écrit rien.');
    }

    /**
     * La **source** effacée entre le dépôt et la validation lève, et la transaction est
     * annulée : la demande reste `en_attente`, et rien n'est écrit.
     *
     * Aucune route ne supprime de source aujourd'hui — `tree_citation` la retient en
     * `RESTRICT` —, et c'est justement pourquoi ce cas doit lever plutôt que de passer
     * en silence : il ne peut arriver que si quelque chose s'est produit hors de
     * l'application, et une validation qui réussirait sans écrire serait le pire des
     * deux comptes rendus.
     */
    public function testUneSourceEffaceeApresLeDepotFaitEchouerLaValidationSansRienEcrire(): void
    {
        $demande = $this->deposer();

        // Le double n'a pas de `supprimerSource()` : on repose l'arbre sans elle, ce qui
        // est exactement ce qu'une disparition produit.
        ArbreEnMemoire::oublierTout();
        $this->poserLaFicheDeReference();

        try {
            $this->validation->valider($demande, $this->admin());
            self::fail('Une source disparue ne s\'écrit pas en silence.');
        } catch (\RuntimeException $erreur) {
            self::assertStringContainsString('a disparu pendant la validation', $erreur->getMessage());
        }

        self::assertSame([], $this->arbre->citationsCreees());
    }

    // ───────────────────────────────────── l'applicateur, unité par unité

    /**
     * L'applicateur est **decouvert par le conteneur**, sous le tag que l'interface pose.
     *
     * Ce test ne lit pas une liste : il demande le service au conteneur. S'il n'y etait
     * pas — classe absente, interface non implementee, `autoconfigure` desactive —, il
     * n'y serait pas non plus dans l'iterateur `app.proposal.applicateur_d_operation`
     * qu'`AutowireIterator` construit a partir du meme tag.
     *
     * La preuve que le validateur l'**appelle** vraiment, elle, est ailleurs et elle est
     * plus forte : {@see testValiderUneDemandeDeSourceEcritLaCitation()} passe par le
     * `ValidationDeDemande` du conteneur, et `applicateurPour()` leve un `LogicException`
     * nomme quand aucun applicateur ne reclame la nature. Un greffon non decouvert ferait
     * donc tomber ce test-la, en disant lequel manque.
     */
    public function testLApplicateurEstUnServiceDuConteneur(): void
    {
        self::assertInstanceOf(ApplicateurDOperation::class, $this->applicateur());
    }

    /** Il ne réclame que sa nature : deux applicateurs sur une même op se masqueraient. */
    public function testIlNeReclameQueSaNature(): void
    {
        self::assertSame([OpKind::AjoutCitation], $this->applicateur()->natures());
    }

    /**
     * Le second garde-fou sur la référence de brouillon : même si une op en portait une,
     * l'applicateur refuse, et son message nomme la cause plutôt que le symptôme.
     */
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

    /** Une `newValue` qui n'est pas un objet est un défaut de programme, pas une saisie. */
    public function testLApplicateurRefuseUneValeurQuiNEstPasUnObjet(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ne porte pas d\'objet en newValue/');

        $this->applicateur()->appliquer($this->vue(), [$this->opEnAttente(valeur: 'une chaîne')], $this->admin());
    }

    /** Une source non entière dans la valeur d'op : même chose. */
    public function testLApplicateurRefuseUneSourceQuiNEstPasUnEntier(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/« sourceId » qui n\'est pas un entier/');

        $this->applicateur()->appliquer(
            $this->vue(),
            [$this->opEnAttente(valeur: ['sourceId' => 'deux-cent-quatre'])],
            $this->admin(),
        );
    }

    /**
     * Un `QUAY` relu en chaîne depuis `JSONB` reste lisible : les deux bases du projet ne
     * rendent pas les entiers stockés de la même façon, et une citation qui perdrait son
     * `QUAY` à la relecture ferait basculer la fiche du mauvais côté de « appuyée sur un
     * acte » sans que rien ne le signale.
     */
    public function testUnQuayRelusEnChaineResteUnEntier(): void
    {
        $this->applicateur()->appliquer(
            $this->vue(),
            [$this->opEnAttente(valeur: ['sourceId' => self::SOURCE, 'page' => 'vue 12', 'quay' => '3', 'scope' => 'generale'])],
            $this->admin(),
        );

        self::assertSame(3, $this->arbre->citationsCreees()[0]['quay']);
    }

    /** `null` n'est pas `0` : « non renseigné » et « recopie non vérifiée » sont deux affirmations. */
    public function testUnQuayAbsentResteNullEtNePasseJamaisAZero(): void
    {
        $demande = $this->deposer(valeur: [
            'sourceId' => self::SOURCE,
            'page' => 'AD Sylvanie, 5 MI 118, vue 12',
            'quay' => null,
        ]);

        $this->validation->valider($demande, $this->admin());

        self::assertNull($this->arbre->citationsCreees()[0]['quay']);
    }

    /** `CitationAcreer` est un objet de transfert : il ne transporte rien d'autre que des scalaires. */
    public function testLObjetDeTransfertNeTransporteQueDesScalaires(): void
    {
        $citation = new CitationAcreer(self::PERSONNE, self::SOURCE, 'vue 12', 2, null, 'naissance');

        self::assertSame(self::PERSONNE, $citation->personneId);
        self::assertSame(2, $citation->quay);
        self::assertSame('naissance', $citation->scope);
    }

    // ────────────────────────────────────────────────────────────── le gréement

    private function applicateur(): ApplicateurDOperationCitation
    {
        $applicateur = self::getContainer()->get(ApplicateurDOperationCitation::class);
        self::assertInstanceOf(ApplicateurDOperationCitation::class, $applicateur);

        return $applicateur;
    }

    private function vue(): DemandeEnCours
    {
        return new DemandeEnCours(1, RequestKind::AjoutCitation->value, self::OPALE, 'Compte 11');
    }

    private function opEnAttente(?int $subjectId = self::PERSONNE, ?string $draftRef = null, mixed $valeur = null): OpEnAttente
    {
        return new OpEnAttente(
            1,
            OpKind::AjoutCitation->value,
            SubjectKind::Personne->value,
            $subjectId,
            $draftRef,
            null,
            ValueKind::Citation->value,
            null,
            $valeur ?? ['sourceId' => self::SOURCE, 'page' => 'vue 12', 'quay' => 2, 'scope' => 'generale'],
            null,
        );
    }

    /**
     * Un op de citation, prêt à être posté.
     *
     * @return array<string, mixed>
     */
    private function op(int $seq, int $sourceId, ?string $page, ?int $quay, ?int $personneId = null): array
    {
        return [
            'seq' => $seq,
            'opKind' => 'ajout_citation',
            'subjectKind' => 'personne',
            'subjectId' => $personneId ?? self::PERSONNE,
            'valueKind' => 'citation',
            'oldValue' => null,
            'newValue' => [
                'sourceId' => $sourceId,
                'page' => $page,
                'quay' => $quay,
                'dataText' => null,
                'scope' => 'generale',
            ],
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
            'kind' => 'ajout_citation',
            'message' => 'Relevé aux archives départementales.',
            'soumettre' => true,
            'ops' => $ops,
        ];
    }

    /** @param array<string, mixed>|null $valeur */
    private function deposer(?array $valeur = null): ChangeRequest
    {
        return $this->deposerPlusieurs([[
            'seq' => 1,
            'opKind' => 'ajout_citation',
            'subjectKind' => 'personne',
            'subjectId' => self::PERSONNE,
            'valueKind' => 'citation',
            'oldValue' => null,
            'newValue' => $valeur ?? [
                'sourceId' => self::SOURCE,
                'page' => 'AD Sylvanie, 5 MI 118, vue 12',
                'quay' => 2,
                'dataText' => 'Le douze frimaire an IX, devant nous…',
                'scope' => 'naissance',
            ],
        ]]);
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
