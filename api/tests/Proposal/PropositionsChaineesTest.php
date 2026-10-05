<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Exception\Conflit;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Exception\Introuvable;
use App\Module\Proposal\Repository\DraftPersonRepository;
use App\Module\Proposal\Service\RechercheDeBrouillons;
use App\Module\Proposal\Service\ResolutionPrevue;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * PROPOSER UNE PERSONNE **RATTACHÉE À UNE AUTRE PERSONNE PROPOSÉE**.
 *
 * Le défaut réparé, dans les mots où il a été signalé :
 *
 * > « Si je propose une personne, elle apparaît dans mon arbre et celui de l'admin ;
 * > mais si je repousse une autre personne et que je cherche le père que j'ai proposé
 * > juste avant, non validé encore mais proposé par moi, il ne m'est pas proposé. »
 *
 * Deux moitiés, et ce fichier tient les deux. **Trouver** la fiche proposée : c'est
 * {@see RechercheDeBrouillons}, avec la règle du § 13.3 — l'auteur et les
 * administrateurs, personne d'autre. **La désigner** : c'est `*DraftPersonId`, et c'est
 * là que se joue le seul point réellement difficile, l'**ordre de validation** entre
 * deux demandes qui ne se connaissaient pas.
 *
 * ── Le scénario, une fois pour tout le fichier ──────────────────────────────────
 *
 * Opale propose **Barnabé Muguet** (demande A). Le lendemain, elle propose **Céleste
 * Muguet**, fille de Barnabé (demande B) : Barnabé n'existe pas encore, la demande B le
 * désigne donc par la ligne `prp_draft_person` de la demande A.
 *
 * Trois fins possibles, et chacune a son test :
 *
 * | Ce qui arrive à A | Ce qui arrive à B |
 * |---|---|
 * | rien encore | `409 dependance_en_attente`, B reste dans la file |
 * | validée | B s'applique, et Céleste est bien la fille de la **vraie** fiche de Barnabé |
 * | refusée | `409 dependance_morte`, B passe `caduque`, Opale est notifiée |
 */
final class PropositionsChaineesTest extends BaseDeDemandes
{
    /** Le père d'accueil de nos deux propositions : il existe, lui. */
    private const int JARGON = 55;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbre->poser(
            SubjectKind::Personne,
            self::JARGON,
            'Jargon Sarriette',
            3,
            $this->champsDUnePersonne('Jargon', 'Sarriette'),
        );
    }

    // ---------------------------------------------------------------------------------
    // Trouver la fiche proposée
    // ---------------------------------------------------------------------------------

    /**
     * **La moitié « il ne m'est pas proposé » du défaut.**.
     *
     * La recherche rend la fiche à son auteur et à l'administrateur, et **rien** à un
     * autre membre. C'est mot pour mot la règle du calque (§ 13.3), et elle est testée
     * ici parce qu'une seconde route est un second endroit où elle peut être fausse.
     */
    public function testLaRechercheRendMesFichesProposeesEtPasCellesDesAutres(): void
    {
        $this->proposerBarnabe(self::OPALE);

        $recherche = $this->recherche();

        $pourOpale = $recherche->resultats('Barnabé', 10, $this->lecteur(self::OPALE));
        self::assertCount(1, $pourOpale['elements']);
        self::assertSame('Barnabé Muguet', $pourOpale['elements'][0]->nomPropose());
        self::assertFalse($pourOpale['tronque']);

        $pourAdmin = $recherche->resultats('Barnabé', 10, $this->admin());
        self::assertCount(1, $pourAdmin['elements'], 'Un administrateur voit toutes les demandes.');

        // ───── La règle.
        $pourTuf = $recherche->resultats('Barnabé', 10, $this->lecteur(self::TUF));
        self::assertSame([], $pourTuf['elements'], 'Un autre membre ne trouve pas la fiche proposée.');

        // Un compte en attente de rattachement ne dépose pas de demande : il n'a donc
        // rien à retrouver, et surtout pas les données d'une personne peut-être vivante.
        $pourCompteEnAttente = $recherche->resultats(
            'Barnabé',
            10,
            $this->lecteur(self::OPALE, enAttente: true),
        );
        self::assertSame([], $pourCompteEnAttente['elements']);
    }

    /**
     * Le terme se cherche **mot par mot**, sans accent et sans ordre imposé.
     *
     * C'est la faute mesurée sur l'arbre validé — la clé s'écrit « patronyme, prénoms »,
     * et un terme « Prénom Nom » n'en était ni un préfixe ni un infixe, si bien que la
     * recherche rendait **zéro** ligne sur 272. Elle n'a pas à être commise une seconde
     * fois de ce côté-ci.
     */
    public function testLaRechercheTrouveLeNomDansNImporteQuelOrdreEtSansAccent(): void
    {
        $this->proposerBarnabe(self::OPALE);

        $recherche = $this->recherche();
        $lecteur = $this->lecteur(self::OPALE);

        foreach (['Barnabé Muguet', 'Muguet Barnabé', 'barnabe', 'MUG', 'muguet barn'] as $terme) {
            self::assertCount(
                1,
                $recherche->resultats($terme, 10, $lecteur)['elements'],
                \sprintf('Le terme « %s » doit retrouver la fiche proposée.', $terme),
            );
        }

        self::assertSame(
            [],
            $recherche->resultats('Sarriette', 10, $lecteur)['elements'],
            'Un nom qui ne correspond pas ne rend rien — et ce n\'est pas une panne.',
        );
    }

    /**
     * Une fiche dont la demande n'est **plus en attente** quitte la recherche.
     *
     * Une demande validée n'a plus de fiche à proposer : elle a produit une personne,
     * que `GET /api/arbre/recherche` sait trouver. Une demande refusée n'a plus rien à
     * offrir du tout. Laisser les deux dans cette liste proposerait de s'appuyer sur ce
     * qui n'arrivera pas — ou sur un doublon de ce qui existe.
     */
    public function testUneDemandeTrancheeQuitteLaRecherche(): void
    {
        $demande = $this->proposerBarnabe(self::OPALE);
        $this->refus->refuser($demande, $this->admin(), 'homonymie avec la fiche 41', null);

        self::assertSame(
            [],
            $this->recherche()->resultats('Barnabé', 10, $this->lecteur(self::OPALE))['elements'],
        );
    }

    /**
     * **Un brouillon non envoyé se retrouve aussi — par son seul auteur.**.
     *
     * « Il faut que la liste des personnes, même mes demandes non acceptées, fasse
     * partie de la liste aussi » (05/10/2026). On enregistre la fiche du grand-père un
     * soir sans l'envoyer ; le lendemain, on veut y rattacher son fils. Un brouillon
     * n'est visible que de son auteur : ni un autre membre, ni l'administrateur ne le
     * trouvent.
     */
    public function testUnBrouillonNonEnvoyeSeRetrouveParSonSeulAuteur(): void
    {
        $this->proposerBarnabe(self::OPALE, soumettre: false);

        $recherche = $this->recherche();

        $pourOpale = $recherche->resultats('Barnabé', 10, $this->lecteur(self::OPALE));
        self::assertCount(1, $pourOpale['elements']);
        self::assertFalse(
            $this->serialiseur->resultatDeRecherche($pourOpale['elements'][0])['enAttente'],
            'La charge dit que la fiche n\'est pas encore envoyée.',
        );

        self::assertSame([], $recherche->resultats('Barnabé', 10, $this->admin())['elements']);
        self::assertSame([], $recherche->resultats('Barnabé', 10, $this->lecteur(self::TUF))['elements']);
    }

    /**
     * Une demande peut désigner la fiche d'un brouillon de son auteur, et elle attend :
     * le brouillon peut encore être envoyé, il n'est pas mort.
     */
    public function testUneDemandeQuiDesigneUnBrouillonAttendSonEnvoi(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE, soumettre: false);
        $celeste = $this->proposerCeleste(self::OPALE, $this->ficheDe($barnabe));

        self::assertSame(
            ResolutionPrevue::DependanceEnAttente,
            $this->analyse->analyser($this->recharger($celeste))->resolution,
        );
    }

    // ---------------------------------------------------------------------------------
    // La désigner
    // ---------------------------------------------------------------------------------

    /**
     * Le dépôt accepte `otherDraftPersonId`, et le libellé du lien porte **le nom** de
     * la fiche citée — pas son numéro de ligne.
     *
     * Le numéro était la faute d'origine des libellés dénormalisés : « Personne proposée
     * #p1 » a rendu illisible la parenté d'une branche de vingt-deux personnes. Une
     * troisième forme d'extrémité ne doit pas la réintroduire.
     */
    public function testUneDemandePeutDesignerLaFicheProposeeParUneAutre(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);
        $ficheDeBarnabe = $this->ficheDe($barnabe);

        $celeste = $this->proposerCeleste(self::OPALE, $ficheDeBarnabe);

        $liens = (array) $this->serialiseur->pourAuteur($this->recharger($celeste))['liens'];
        self::assertCount(1, $liens);

        $lien = (array) $liens[0];
        self::assertSame('enfant_de', $lien['kind']);
        self::assertSame($ficheDeBarnabe, $lien['otherDraftPersonId']);
        self::assertNull($lien['otherPersonId'], 'Aucune personne de l\'arbre n\'est désignée.');
        self::assertSame('Barnabé Muguet (proposée)', $lien['otherLabel']);
    }

    /**
     * **Le nœud proposé se rattache au nœud proposé.**.
     *
     * Les deux demandes se dessinent dans le même graphe, et l'arête se referme parce
     * que la fiche citée est nommée `#<sa demande>/p1` des deux côtés — pas
     * `#<la demande qui la cite>/p1`. C'est le seul point du rendu où la troisième forme
     * d'extrémité pouvait silencieusement fabriquer un nœud fantôme.
     */
    public function testLeGrapheRelieLesDeuxNoeudsProposes(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);
        $celeste = $this->proposerCeleste(self::OPALE, $this->ficheDe($barnabe));

        $projection = $this->propositions->projection($this->lecteur(self::OPALE));

        self::assertCount(2, $projection->noeuds, 'Les deux fiches proposées sont dans le graphe.');

        $ancrages = array_values(array_filter(
            $projection->ancrages,
            static fn (object $ancrage): bool => 'enfant_de' === $ancrage->kind,
        ));

        self::assertCount(1, $ancrages);
        self::assertSame('#'.$celeste->getId().'/p1', $ancrages[0]->sujetNoeudId);
        self::assertSame(
            '#'.$barnabe->getId().'/p1',
            $ancrages[0]->autreNoeudId,
            'L\'autre extrémité porte l\'identifiant du nœud de **sa** demande.',
        );
    }

    /**
     * La fiche proposée par **quelqu'un d'autre** ne se désigne pas — et la réponse est
     * `404`, jamais `403`.
     *
     * Un `403` apprendrait que la ligne existe, c'est-à-dire qu'une demande porte cette
     * fiche-là. C'est la règle du § 15.2 : « partout, et sur tout ce que le lecteur n'a
     * pas le droit de voir ».
     */
    public function testLaFicheProposeeDUnAutreMembreEstIntrouvable(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);

        $this->expectException(Introuvable::class);
        $this->proposerCeleste(self::TUF, $this->ficheDe($barnabe));
    }

    /**
     * Une extrémité désigne **une** des trois formes, pas deux.
     *
     * C'est un `CHECK` en base (`ck_prp_link_cible`), et le refuser ici plutôt qu'en
     * base donne à l'appelant le nom du champ à corriger — une `500` de contrainte ne
     * dit que le nom de l'index.
     */
    public function testUneExtremiteNeCumulePasDeuxFormes(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);

        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer([
            'kind' => 'ajout_lien',
            'soumettre' => true,
            'liens' => [[
                'seq' => 1,
                'kind' => 'conjoint_de',
                'subjectPersonId' => self::JARGON,
                'otherPersonId' => self::JARGON,
                'otherDraftPersonId' => $this->ficheDe($barnabe),
            ]],
        ], $this->lecteur(self::OPALE));
    }

    // ---------------------------------------------------------------------------------
    // L'ordre de validation — le point réellement difficile
    // ---------------------------------------------------------------------------------

    /**
     * **La seule `409` qui n'écrit rien du tout, pas même un statut.**.
     *
     * B s'appuie sur A, qui n'est pas tranchée : il y a un ordre à respecter, pas une
     * demande à condamner. B reste `en_attente` et se validera telle quelle après A.
     *
     * Le contraire — appliquer — rattacherait Céleste à personne : le lien n'a pas
     * d'identifiant à résoudre, et une famille se créerait avec une extrémité nulle.
     */
    public function testValiderLaSecondeAvantLaPremiereEstRefuseSansRienEcrire(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);
        $celeste = $this->proposerCeleste(self::OPALE, $this->ficheDe($barnabe));

        $plan = $this->analyse->analyser($this->recharger($celeste));
        self::assertSame(ResolutionPrevue::DependanceEnAttente, $plan->resolution);
        self::assertCount(1, $plan->dependancesEnAttente());
        self::assertSame($barnabe->getId(), $plan->dependancesEnAttente()[0]['demandeId']);

        try {
            $this->validation->valider($this->recharger($celeste), $this->admin());
            self::fail('Une demande qui attend une autre demande ne se valide pas.');
        } catch (Conflit $erreur) {
            self::assertSame('dependance_en_attente', $erreur->codeErreur());
            self::assertSame(409, $erreur->statutHttp());

            $details = (array) $erreur->details();
            self::assertTrue($details['rejouable'], 'Le geste redeviendra possible tel quel.');
            self::assertSame(RequestStatus::EnAttente->value, $details['statut']);
        }

        self::assertSame(
            RequestStatus::EnAttente,
            $this->recharger($celeste)->getStatus(),
            'Rien n\'a été écrit : la demande reste dans la file.',
        );
        self::assertSame(0, $this->arbre->ecritures(), 'Et rien n\'a touché l\'arbre.');
    }

    /**
     * **Le cas qui devait marcher, et qui est tout l'objet de la tâche.**.
     *
     * A validée, puis B : Céleste est créée, et elle est rattachée à la famille de la
     * **vraie** fiche de Barnabé — celle que la validation de A vient d'écrire.
     */
    public function testValideesDansLOrdreLesDeuxPropositionsSeRejoignent(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);
        $celeste = $this->proposerCeleste(self::OPALE, $this->ficheDe($barnabe));

        $this->validation->valider($this->recharger($barnabe), $this->admin());

        $ficheReelleDeBarnabe = $this->arbre->personnesCreees()[0];
        self::assertSame(
            'Muguet',
            $this->arbre->valeur(SubjectKind::Personne, $ficheReelleDeBarnabe, 'surname'),
        );

        $this->nouvelleRequete();

        $resultat = $this->validation->valider($this->recharger($celeste), $this->admin());
        self::assertSame('validee', $resultat->resultat);

        $creees = $this->arbre->personnesCreees();
        self::assertCount(2, $creees, 'Barnabé, puis Céleste.');

        // **Deux** familles, et ce n'est pas un défaut de la chaîne : c'est la règle du
        // parent unique désigné, déjà celle d'une filiation vers une personne de
        // l'arbre. La demande A décrit le couple Barnabé × Jargon ; la demande B ne dit
        // pas de quel couple Céleste est issue, seulement de qui elle est l'enfant — le
        // service ne peut donc pas la rattacher au premier sans inventer une mère.
        $familles = $this->arbre->famillesCreees();
        self::assertCount(2, $familles);

        $familleDeCeleste = $familles[1];
        self::assertSame([$creees[1]], $this->arbre->enfantsDe($familleDeCeleste));

        // La famille est bien celle **de Barnabé**, et non un couple vide : le lien de
        // filiation a résolu `other_draft_person_id` en `resolved_person_id`, et c'est
        // ce que `prp_draft_link.resolved_family_id` conserve — la même famille que
        // celle qui porte l'enfant.
        $lienDeCeleste = (array) ((array) $this->serialiseur->pourAuteur($this->recharger($celeste))['liens'])[0];
        self::assertSame($familleDeCeleste, $lienDeCeleste['resolvedFamilyId']);

        // **Le point de la tâche.** Le double compose le libellé d'une famille avec
        // celui de ses époux : celle de la filiation porte donc le nom de Barnabé, ce
        // qui n'est vrai que si `other_draft_person_id` a été résolu en la personne que
        // la validation de A vient de créer. Un couple vide dirait que Céleste est la
        // fille de personne.
        self::assertSame(
            $this->arbre->chargerCible(SubjectKind::Personne, $ficheReelleDeBarnabe)?->libelle,
            $this->arbre->chargerCible(SubjectKind::Famille, $familleDeCeleste)?->libelle,
        );
    }

    /**
     * A refusée : B ne peut plus aboutir, et **on le lui dit**.
     *
     * C'est une caducité de la même nature qu'une cible disparue — personne n'a refusé
     * B —, avec les trois conséquences que le § 6.8 exige : statut `caduque`, aucune
     * écriture dans l'arbre, et une notification qui **dit pourquoi**. Un corps de
     * notification vide serait ici le pire résultat : l'auteur verrait sa contribution
     * mourir sans savoir de quoi elle dépendait.
     */
    public function testSiLaPremiereEstRefuseeLaSecondeDevientCaduqueEtSonAuteurEstPrevenu(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);
        $celeste = $this->proposerCeleste(self::OPALE, $this->ficheDe($barnabe));

        // **Rechargée avant le refus** : `ficheDe()` a vidé le gestionnaire d'entités,
        // et refuser un objet détaché ne serait pas écrit — le test passerait au vert en
        // n'ayant rien refusé.
        $this->refus->refuser($this->recharger($barnabe), $this->admin(), 'doublon de la fiche 41', null);

        $plan = $this->analyse->analyser($this->recharger($celeste));
        self::assertSame(ResolutionPrevue::DependanceMorte, $plan->resolution);
        self::assertTrue($plan->resolution->estCaduque());

        try {
            $this->validation->valider($this->recharger($celeste), $this->admin());
            self::fail('Une demande dont la dépendance est morte ne se valide pas.');
        } catch (Conflit $erreur) {
            self::assertSame('dependance_morte', $erreur->codeErreur());

            $details = (array) $erreur->details();
            self::assertFalse($details['rejouable'], 'Elle ne se réessaie pas : elle se refait.');

            $dependances = (array) $details['dependances'];
            self::assertCount(1, $dependances);
            self::assertSame('Barnabé Muguet', ((array) $dependances[0])['libelle']);
        }

        self::assertSame(RequestStatus::Caduque, $this->recharger($celeste)->getStatus());
        self::assertSame(0, $this->arbre->ecritures());

        // La notification, et sous **son propre** nom d'événement : une caducité de
        // dépendance ne raconte pas un diff de champs, elle nomme la demande perdue.
        $notifications = $this->notificateur->de('demande_caduque_dependance');
        self::assertCount(1, $notifications);
        self::assertSame($celeste->getId(), $notifications[0]['demande']);
        self::assertSame('Barnabé Muguet', ((array) $notifications[0]['champs'][0])['libelle']);
    }

    /**
     * A validée **entre** la recherche et le dépôt : la référence est résolue en
     * personne réelle, sans que l'auteur ait à recommencer.
     *
     * Le refuser serait faire échouer une demande que la réalité vient de conforter — et
     * l'auteur ne comprendrait pas : il vient de voir la fiche dans sa liste de choix.
     */
    public function testUneFicheValideeEntreTempsEstDesigneeCommePersonneReelle(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);
        $fiche = $this->ficheDe($barnabe);

        $this->validation->valider($this->recharger($barnabe), $this->admin());
        $ficheReelle = $this->arbre->personnesCreees()[0];

        $this->nouvelleRequete();

        $celeste = $this->proposerCeleste(self::OPALE, $fiche);

        $liens = (array) $this->serialiseur->pourAuteur($this->recharger($celeste))['liens'];
        $lien = (array) $liens[0];

        self::assertSame($ficheReelle, $lien['otherPersonId'], 'La personne réelle, pas la fiche.');
        self::assertNull($lien['otherDraftPersonId']);
        self::assertSame(ResolutionPrevue::Application, $this->analyse->analyser($this->recharger($celeste))->resolution);
    }

    // ---------------------------------------------------------------------------------
    // L'ajout de lien sur une fiche existante
    // ---------------------------------------------------------------------------------

    /**
     * **La cible annoncée l'emporte, si les liens la désignent.**.
     *
     * « Ajouter un frère à Jargon » s'écrit « ce frère est enfant du couple de ses
     * parents » : le **sujet** du lien est le frère, pas Jargon. Sans `targetId`, la
     * file afficherait la demande sur la fiche du frère, alors que le contributeur
     * travaillait sur celle de Jargon — et l'administrateur la chercherait longtemps.
     */
    public function testUnAjoutDeLienPeutAnnoncerLaFicheSurLaquelleIlPorte(): void
    {
        $this->arbre->poser(
            SubjectKind::Personne,
            41,
            'Cobalt Sarriette',
            2,
            $this->champsDUnePersonne('Cobalt', 'Sarriette'),
        );

        $demande = $this->depot->deposer([
            'kind' => 'ajout_lien',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::JARGON,
            'liens' => [[
                'seq' => 1,
                'kind' => 'conjoint_de',
                'subjectPersonId' => 41,
                'otherPersonId' => self::JARGON,
            ]],
        ], $this->lecteur(self::OPALE));

        $rechargee = $this->recharger($demande);
        self::assertSame(SubjectKind::Personne, $rechargee->getTargetKind());
        self::assertSame(self::JARGON, $rechargee->getTargetId());
        self::assertSame('Jargon Sarriette', $rechargee->getTargetLabel());
    }

    /**
     * **Le geste « ajouter un frère ou une sœur ».**.
     *
     * Il s'écrit « ce frère est enfant du couple de ses parents » : le sujet est le
     * frère, l'autre extrémité est une `family_id`, et la fiche d'où vient le
     * contributeur n'apparaît **nulle part** dans le corps. C'est pourtant elle qu'il
     * faut annoncer en cible, et c'est ce que `epouxDeFamille()` permet de reconnaître.
     *
     * Sans cette lecture, le contrôle d'appartenance interdirait le seul cas pour lequel
     * `targetId` a été ajouté.
     */
    public function testUnFrereSeRattacheAuCoupleEtLaDemandePorteSurLaFicheDOrigine(): void
    {
        $frere = 41;
        $this->arbre->poser(
            SubjectKind::Personne,
            $frere,
            'Cobalt Sarriette',
            2,
            $this->champsDUnePersonne('Cobalt', 'Sarriette'),
        );
        // Le couple des parents de Jargon : c'est lui que la fratrie désigne.
        $this->arbre->poserCouple(90, 'Argile Sarriette × Sanguine Verveine', self::JARGON, $frere);

        $demande = $this->depot->deposer([
            'kind' => 'ajout_lien',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::JARGON,
            'liens' => [[
                'seq' => 1,
                'kind' => 'enfant_de',
                'subjectPersonId' => $frere,
                'familyId' => 90,
                'siblingOrder' => 2,
            ]],
        ], $this->lecteur(self::OPALE));

        $rechargee = $this->recharger($demande);
        self::assertSame(
            self::JARGON,
            $rechargee->getTargetId(),
            'La demande porte sur la fiche d\'origine, pas sur celle du frère.',
        );

        // Et elle s'applique : le frère rejoint le couple, sans qu'aucune famille ne
        // soit créée — elle existait.
        $this->validation->valider($rechargee, $this->admin());

        self::assertSame([], $this->arbre->famillesCreees(), 'Le couple existait déjà.');
        self::assertSame([$frere], $this->arbre->enfantsDe(90));
    }

    /**
     * Une cible annoncée que les liens ne citent pas est refusée.
     *
     * Sans ce contrôle, `targetId` serait un champ libre : il suffirait de le poser pour
     * faire apparaître n'importe quelle demande sur n'importe quelle fiche de la file.
     */
    public function testUneCibleAnnonceeQueLesLiensNeCitentPasEstRefusee(): void
    {
        $this->expectException(DonneesInvalides::class);

        $this->depot->deposer([
            'kind' => 'ajout_lien',
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'liens' => [[
                'seq' => 1,
                'kind' => 'conjoint_de',
                'subjectPersonId' => self::JARGON,
                'otherPersonId' => self::JARGON,
            ]],
        ], $this->lecteur(self::OPALE));
    }

    /**
     * Un `ajout_lien` peut désigner une fiche proposée ailleurs.
     *
     * C'est l'autre moitié du besoin : « le père que je viens de proposer » doit pouvoir
     * être rattaché à une personne **qui existe**, sans attendre une validation. La
     * demande ne crée alors aucune fiche — elle n'en a pas le droit — et porte le seul
     * lien.
     */
    public function testUnAjoutDeLienPeutDesignerUneFicheProposeeAilleurs(): void
    {
        $barnabe = $this->proposerBarnabe(self::OPALE);

        $demande = $this->depot->deposer([
            'kind' => 'ajout_lien',
            'soumettre' => true,
            'liens' => [[
                'seq' => 1,
                'kind' => 'enfant_de',
                'subjectPersonId' => self::JARGON,
                'otherDraftPersonId' => $this->ficheDe($barnabe),
            ]],
        ], $this->lecteur(self::OPALE));

        $lien = (array) ((array) $this->serialiseur->pourAuteur($this->recharger($demande))['liens'])[0];
        self::assertSame('Barnabé Muguet (proposée)', $lien['otherLabel']);

        // Et elle attend, comme toute demande qui s'appuie sur une autre.
        self::assertSame(
            ResolutionPrevue::DependanceEnAttente,
            $this->analyse->analyser($this->recharger($demande))->resolution,
        );
    }

    // ---------------------------------------------------------------------------------
    // Le décor
    // ---------------------------------------------------------------------------------

    private function recherche(): RechercheDeBrouillons
    {
        $service = self::getContainer()->get(RechercheDeBrouillons::class);
        self::assertInstanceOf(RechercheDeBrouillons::class, $service);

        return $service;
    }

    /** L'identifiant de la **ligne** `prp_draft_person` que porte cette demande. */
    private function ficheDe(ChangeRequest $demande): int
    {
        $brouillon = self::getContainer()
            ->get(DraftPersonRepository::class)
            ->parReference($this->recharger($demande), '#p1');

        self::assertNotNull($brouillon);
        $id = $brouillon->getId();
        self::assertNotNull($id);

        return $id;
    }

    /** La demande A : Barnabé, rattaché à Jargon pour ne pas flotter hors de l'arbre. */
    private function proposerBarnabe(int $auteur, string $prenom = 'Barnabé', bool $soumettre = true): ChangeRequest
    {
        return $this->depot->deposer([
            'kind' => 'creation_personne',
            'message' => 'mon arrière-grand-père, j\'ai son livret militaire',
            'soumettre' => $soumettre,
            'brouillons' => [$this->ficheProposee($prenom)],
            'liens' => [[
                'seq' => 1,
                'kind' => 'conjoint_de',
                'subjectDraftRef' => '#p1',
                'otherPersonId' => self::JARGON,
                'divorced' => false,
            ]],
        ], $this->lecteur($auteur));
    }

    /** La demande B : Céleste, fille de la fiche proposée par A. */
    private function proposerCeleste(int $auteur, int $ficheDuPere): ChangeRequest
    {
        return $this->depot->deposer([
            'kind' => 'creation_personne',
            'message' => 'sa fille, née après le mariage',
            'soumettre' => true,
            'brouillons' => [$this->ficheProposee('Céleste')],
            'liens' => [[
                'seq' => 1,
                'kind' => 'enfant_de',
                'subjectDraftRef' => '#p1',
                'otherDraftPersonId' => $ficheDuPere,
            ]],
        ], $this->lecteur($auteur));
    }

    /** @return array<string, mixed> */
    private function ficheProposee(string $prenom): array
    {
        return [
            'localRef' => '#p1',
            'givenNames' => $prenom,
            'surname' => 'Muguet',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'inconnu',
            'deceased' => true,
            'birth' => $this->blocDate($this->annee(1901)),
            'death' => $this->blocDate($this->annee(1968)),
            'note' => null,
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
