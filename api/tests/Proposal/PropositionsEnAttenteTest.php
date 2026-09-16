<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Contract\CalqueEnAttenteInterface;
use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Enum\RequestKind;

/**
 * Le port `Tree → Proposal` : la surface publique du module.
 *
 * C'est par lui que passe la contrepartie de la promesse faite à l'utilisateur — *le
 * demandeur voit sa modification avant validation* —, et la même règle de visibilité y
 * vaut que pour le calque : **auteur et administrateurs, personne d'autre**. Un nœud en
 * attente est une personne, souvent vivante ; le montrer à un membre qui n'a rien
 * demandé serait la fuite que tout le circuit existe pour empêcher.
 *
 * Les brouillons sont écrits ici directement par l'`EntityManager` : le constructeur de
 * `creation_personne` viendra avec la tâche suivante (§ 6.4 du dossier), et ce port doit
 * être prêt et éprouvé avant lui — c'est l'agent de l'API de l'arbre qui l'attend.
 */
final class PropositionsEnAttenteTest extends BaseDeDemandes
{
    /**
     * Le port **tel que le conteneur le sert** — pas une copie assemblée ici.
     *
     * Ce qu'il faut éprouver, c'est ce que `Tree` recevra : une instance construite à la
     * main dans un test peut satisfaire toutes les assertions pendant que le conteneur
     * en sert une autre, ou aucune.
     */
    private function port(): CalqueEnAttenteInterface
    {
        return $this->propositions;
    }

    /**
     * La règle, sur les nœuds proposés cette fois : visible de son auteur et d'un
     * administrateur, **invisible d'un autre membre**.
     */
    public function testUnNoeudProposeEstVisibleDeSonAuteurEtDUnAdministrateurEtInvisibleDUnAutreMembre(): void
    {
        $this->proposerTourmaline(self::OPALE);

        $pourOpale = $this->port()->projection($this->lecteur(self::OPALE));
        $pourAdmin = $this->port()->projection($this->lecteur(self::ADMIN, administrateur: true));
        $pourTuf = $this->port()->projection($this->lecteur(self::TUF));

        self::assertCount(1, $pourOpale->noeuds, 'L\'auteur voit la personne qu\'il propose.');
        self::assertSame('Tourmaline Muguet', $pourOpale->noeuds[0]->displayName);

        self::assertCount(1, $pourAdmin->noeuds, 'L\'administrateur voit toutes les propositions.');

        // ───── La règle.
        self::assertTrue($pourTuf->estVide(), 'Un autre membre ne reçoit aucun nœud en attente.');
        self::assertSame([], $pourTuf->noeuds);
        self::assertSame([], $pourTuf->ancrages);
        self::assertSame([], $pourTuf->demandes);
    }

    /** Un compte en attente de rattachement n'a ni calque ni projection (§ 16.3). */
    public function testUnCompteEnAttenteNeRecoitAucuneProjection(): void
    {
        $this->proposerTourmaline(self::OPALE);

        self::assertTrue(
            $this->port()->projection($this->lecteur(self::OPALE, enAttente: true))->estVide(),
        );
    }

    /**
     * L'identifiant de nœud porte la demande — `#12/p1`, jamais `#p1`.
     *
     * `local_ref` n'est unique que dans sa demande ; deux demandes en attente du même
     * auteur, ou l'administrateur qui les reçoit toutes, mettraient sinon deux nœuds
     * `#p1` dans la même liste (§ 17.3, divergence 7).
     */
    public function testLIdentifiantProvisoirePorteLaDemandeEtSeDistingueDUnIdentifiantReel(): void
    {
        $premiere = $this->proposerTourmaline(self::OPALE);
        $seconde = $this->proposerTourmaline(self::OPALE, 'Céleste', 'Muguet');

        $projection = $this->port()->projection($this->lecteur(self::OPALE));

        $identifiants = array_map(static fn ($noeud): string => $noeud->noeudId, $projection->noeuds);

        self::assertSame(
            ['#'.$premiere->getId().'/p1', '#'.$seconde->getId().'/p1'],
            $identifiants,
            'Deux « #p1 » distincts ne doivent pas se recouvrir.',
        );
        self::assertCount(2, array_unique($identifiants));

        foreach ($projection->noeuds as $noeud) {
            self::assertStringStartsWith('#', $noeud->noeudId, 'Un identifiant provisoire commence par un dièse.');
            // La référence est rendue **sans dièse** à côté, parce que le `#` est le
            // séparateur de fragment d'une URL : le front ne découpe jamais `noeudId`.
            self::assertSame('p1', $noeud->brouillonRef);
        }
    }

    /**
     * Les deux extrémités d'un ancrage sont des **identifiants de nœud** : `p55` pour
     * une personne validée, `#12/p1` pour une personne proposée. L'appelant n'a aucune
     * règle à connaître pour les distinguer.
     */
    public function testUnAncrageDesigneSesExtremitesParIdentifiantDeNoeud(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $projection = $this->port()->projection($this->lecteur(self::OPALE));

        self::assertCount(1, $projection->ancrages);
        $ancrage = $projection->ancrages[0];

        self::assertSame('conjoint_de', $ancrage->kind);
        self::assertSame('#'.$demande->getId().'/p1', $ancrage->sujetNoeudId);
        self::assertSame('p55', $ancrage->autreNoeudId, 'Jargon Sarriette existe : son nœud est réel.');
        self::assertNull($ancrage->familleId);
    }

    /**
     * **Le losange n'existe nulle part.** Il n'y a pas de `prp_draft_family` : la
     * projection en alloue un en mémoire, sinon l'arête du couple proposé n'a pas
     * d'extrémité (§ 17.3, divergence 9).
     */
    public function testUneFamilleProvisoireEstAlloueeQuandAucunCoupleNExiste(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $ancrage = $this->port()->projection($this->lecteur(self::OPALE))->ancrages[0];

        self::assertSame('#'.$demande->getId().'/f1', $ancrage->familleProvisoire);
    }

    /** Un couple existant a déjà son losange dans l'arbre validé : on n'en invente pas un second. */
    public function testAucuneFamilleProvisoireQuandLeDemandeurDesigneUnCoupleExistant(): void
    {
        $demande = $this->nouvelleDemande(self::OPALE, RequestKind::CreationPersonne);
        $this->brouillon($demande, '#p1', 'Tourmaline', 'Muguet');

        $lien = new DraftLink($demande, 1, LinkKind::EnfantDe, 'Tourmaline Muguet');
        $lien->viserSujetBrouillon('#p1')->designerCoupleExistant(28, 'Jargon × Agate');
        $this->em->persist($lien);
        $this->em->flush();

        $ancrage = $this->port()->projection($this->lecteur(self::OPALE))->ancrages[0];

        self::assertSame(28, $ancrage->familleId);
        self::assertNull($ancrage->familleProvisoire);
        self::assertNull($ancrage->autreNoeudId);
    }

    /**
     * Une demande `ajout_lien` ne propose aucune personne et a pourtant une arête à
     * tracer — « elle s'est remariée avec lui ». `estVide()` doit en tenir compte, sans
     * quoi le front n'afficherait pas la légende « en attente ».
     */
    public function testUnAjoutDeLienEntreDeuxPersonnesExistantesEstProjeteSansNoeud(): void
    {
        $demande = $this->nouvelleDemande(self::OPALE, RequestKind::AjoutLien);

        $lien = new DraftLink($demande, 1, LinkKind::ConjointDe, 'Jargon Sarriette');
        $lien->viserSujetExistant(55)->designerAutre(130, null, 'Agate Persil')->setDivorced(true);
        $this->em->persist($lien);
        $this->em->flush();

        $projection = $this->port()->projection($this->lecteur(self::OPALE));

        self::assertSame([], $projection->noeuds);
        self::assertFalse($projection->estVide(), 'Un lien sans personne proposée reste quelque chose à dessiner.');
        self::assertSame('p55', $projection->ancrages[0]->sujetNoeudId);
        self::assertSame('p130', $projection->ancrages[0]->autreNoeudId);
        self::assertTrue($projection->ancrages[0]->divorced);
    }

    /** Le nœud porte ce qu'il faut pour l'afficher, et rien qui appartienne à `Tree`. */
    public function testLeNoeudPorteLaLigneDeDatesEtLAuteurMaisNiPositionNiGeneration(): void
    {
        $this->proposerTourmaline(self::OPALE);

        $noeud = $this->port()->projection($this->lecteur(self::OPALE))->noeuds[0];

        self::assertSame('vers 1889 – 1972', $noeud->ligneDates, 'Jamais un point là où la base a un intervalle.');
        self::assertSame('Compte '.self::OPALE, $noeud->auteurLabel);
        self::assertSame('feminin', $noeud->sex);
        self::assertTrue($noeud->deceased);
        self::assertFalse($noeud->vivant());

        // Ni `x`, ni `y`, ni `w`, ni `generation`, ni `branch` : ce sont le moteur de
        // placement et les colonnes dérivées de `Tree`, et les calculer ici recopierait
        // dans `Proposal` des règles qui ne lui appartiennent pas.
        foreach (['x', 'y', 'w', 'h', 'generation', 'branch', 'visibilite'] as $interdit) {
            self::assertFalse(
                property_exists($noeud, $interdit),
                \sprintf('« %s » appartient à Tree, pas à Proposal.', $interdit),
            );
        }
    }

    /** Le port expose aussi le calque, et c'est le même calcul — pas un second chemin. */
    public function testLePortExposeLeMemeCalqueQueLeService(): void
    {
        $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $parLePort = $this->port()->calque(SubjectKind::Personne, [self::PERSONNE], $this->lecteur(self::OPALE));
        $parLeService = $this->calque->pourPlusieurs(SubjectKind::Personne, [self::PERSONNE], $this->lecteur(self::OPALE));

        self::assertSame($parLeService, $parLePort);
        self::assertSame('SUREAU', $parLePort[self::PERSONNE]['surname']['propose']);
        self::assertSame([], $this->port()->calque(SubjectKind::Personne, [self::PERSONNE], $this->lecteur(self::TUF)));
    }

    /** `demandes` alimente `calque.demandes` du § 5.2, et ne liste que ce que le lecteur voit. */
    public function testLesDemandesIncorporeesSontCellesDuLecteur(): void
    {
        $opale = $this->proposerTourmaline(self::OPALE);
        $tuf = $this->proposerTourmaline(self::TUF, 'Céleste', 'Persil');

        self::assertSame([$opale->getId()], $this->port()->projection($this->lecteur(self::OPALE))->demandes);
        self::assertSame([$tuf->getId()], $this->port()->projection($this->lecteur(self::TUF))->demandes);
        self::assertSame(
            [$opale->getId(), $tuf->getId()],
            $this->port()->projection($this->lecteur(self::ADMIN, administrateur: true))->demandes,
        );
    }

    /**
     * Sous la bascule « voir comme un membre », l'administrateur voit la projection du
     * membre emprunté — sinon la bascule cesse de vérifier quoi que ce soit.
     */
    public function testSousLaBasculeLAdministrateurVoitLaProjectionDuMembreEmprunte(): void
    {
        $this->proposerTourmaline(self::OPALE);

        $emprunt = new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true, membreEmprunteUserId: self::TUF);

        self::assertTrue($this->port()->projection($emprunt)->estVide());
    }

    /** Une demande tranchée quitte la projection, comme elle quitte le calque. */
    public function testUneDemandeTrancheeQuitteLaProjection(): void
    {
        $demande = $this->proposerTourmaline(self::OPALE);

        $this->refus->refuser(
            $demande,
            new Lecteur(self::ADMIN, 'Natron Romarin', administrateur: true),
            'Cette personne est déjà dans l\'arbre sous un autre nom.',
            null,
        );

        self::assertTrue($this->port()->projection($this->lecteur(self::OPALE))->estVide());
    }

    /**
     * Tourmaline Muguet, épouse de Jargon Sarriette (`@I500055@`, qui existe) : l'exemple
     * du § 6.3 du contrat d'API, écrit directement en base faute de constructeur.
     *
     * La xref est **littérale à dessein** (§ 9.1) : seule, elle n'énonce qu'un numéro
     * d'enregistrement, sans nom ni date ni lieu, et n'informe qu'accolée au GEDCOM réel,
     * qui n'est pas versionné. Ne la substituez pas.
     */
    private function proposerTourmaline(int $auteur, string $prenom = 'Tourmaline', string $nom = 'Muguet'): ChangeRequest
    {
        $demande = $this->nouvelleDemande($auteur, RequestKind::CreationPersonne);
        $this->brouillon($demande, '#p1', $prenom, $nom);

        $lien = new DraftLink($demande, 1, LinkKind::ConjointDe, $prenom.' '.$nom);
        $lien->viserSujetBrouillon('#p1')->designerAutre(55, null, 'Jargon Sarriette');
        $this->em->persist($lien);

        $this->em->flush();

        return $demande;
    }

    private function nouvelleDemande(int $auteur, RequestKind $nature): ChangeRequest
    {
        $demande = new ChangeRequest($nature, $auteur, 'Compte '.$auteur);
        $demande->soumettre();

        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }

    private function brouillon(ChangeRequest $demande, string $reference, string $prenom, string $nom): DraftPerson
    {
        $brouillon = new DraftPerson($demande, $reference);
        $brouillon
            ->setGivenNames($prenom)
            ->setSurname($nom)
            ->setSex(Sex::Feminin)
            ->setDeceased(true)
            ->setBirth(new UncertainDate(
                'ABT 1889',
                DateQualifier::Environ,
                new \DateTimeImmutable('1889-01-01'),
                new \DateTimeImmutable('1889-12-31'),
                'Saule, Ombrage, Sylvanie',
            ))
            ->setDeath($this->annee(1972));

        $this->em->persist($brouillon);

        return $brouillon;
    }
}
