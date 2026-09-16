<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\DraftLink;
use App\Module\Proposal\Entity\DraftPerson;
use App\Module\Proposal\Enum\LinkKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Tree\Serializer\NoeudProposeEcarte;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UN CALQUE QUI NE PEUT PAS TOUT MONTRER DOIT LE DIRE.
 *
 * Le défaut, mesuré sur un cas réel : cinq demandes ont déposé une branche de
 * vingt-deux personnes. Le calque du graphe a rendu `actif: true` et
 * `demandes: [1,2,3,4,5]` — **cinq nœuds placés, dix-sept absents**, et rien dans la
 * charge ne signalait l'écart. Le lecteur avait sous les yeux un calque qui se déclarait
 * complet sur cinq demandes en ayant écarté les trois quarts de ce qu'elles proposaient.
 *
 * ── Ce qui est corrigé, et ce qui ne l'est pas ─────────────────────────────────
 *
 * **L'omission reste.** Une fiche dont l'ancre n'est pas dans le placement partagé serait
 * posée à `x = 0`, c'est-à-dire sur l'ego : dix-sept fiches empilées là seraient pires
 * qu'absentes. Ce test vérifie donc que les mêmes nœuds sont placés qu'avant.
 *
 * Ce qui change, c'est que le calque **compte et nomme** ce qu'il n'a pas pu poser.
 * `actif` ne dit pas « complet » ; `proposes`, `places` et `nonPlaces` le disent.
 *
 * ── Pourquoi le cas n'est pas un accident rare ─────────────────────────────────
 *
 * Il est la forme normale d'une branche déposée en bloc. Le placement partagé ne contient
 * que l'arbre **validé** : une fiche rattachée à une personne qui existe s'y ancre, une
 * fiche rattachée à une autre fiche de la même demande n'a nulle part où s'accrocher.
 * D'où le rapport observé — seules les racines de la branche se placent.
 */
final class CalqueIncompletTest extends ArbreWebTestCase
{
    private const string MEMBRE = 'contributeur@heritage.test';

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LE CAS MESURÉ, EN RÉDUCTION : TROIS FICHES PROPOSÉES, UNE SEULE PLACÉE.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Les trois assertions de tête sont celles qui manquaient. Un test qui se contente de
     * parcourir `noeuds` et de vérifier que ceux qu'il trouve sont corrects reste vert
     * quand il n'en reste qu'un sur trois : c'est exactement ce qui s'est produit.
     */
    public function testLeCalqueAnnonceCombienDeNoeudsProposesIlNAPasPuPlacer(): void
    {
        $demande = $this->deposerUneBrancheEnchainee();

        $this->connexionDe(self::MEMBRE);
        $this->requete('/api/arbre/graphe');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $calque = $this->calque();

        self::assertTrue($calque['actif']);
        self::assertSame([$demande], $calque['demandes']);

        // ───── Les trois clés qui lèvent le silence.
        self::assertSame(3, $calque['proposes'], 'Trois fiches ont été proposées.');
        self::assertSame(1, $calque['places'], 'Une seule se raccroche à l’arbre validé.');
        self::assertCount(2, $calque['nonPlaces'], 'Les deux autres sont annoncées, pas tues.');

        // ───── L'invariant : rien ne disparaît entre les deux comptes.
        self::assertSame(
            $calque['proposes'],
            $calque['places'] + \count($calque['nonPlaces']),
            'Tout nœud proposé est soit placé, soit annoncé comme non placé.',
        );
    }

    /**
     * Chaque nœud non placé est **nommé**, et dit pourquoi.
     *
     * Un compte seul (« 17 fiches non affichées ») laisse l'utilisateur sans geste
     * possible. Le nom et la raison lui disent laquelle manque et ce qui la retient — et
     * `demandeId` et `brouillonRef` lui permettent d'ouvrir la fiche, qui reste
     * consultable même quand le graphe ne sait pas la dessiner.
     *
     * Ce n'est pas une fuite : la projection est déjà bornée aux demandes que ce lecteur
     * a le droit de voir (§ 13.3), et ce nom serait sur le nœud si le nœud avait été
     * placé.
     */
    public function testChaqueNoeudNonPlaceEstNommeAvecSaRaisonEtDeQuoiLOuvrir(): void
    {
        $demande = $this->deposerUneBrancheEnchainee();

        $this->connexionDe(self::MEMBRE);
        $this->requete('/api/arbre/graphe');

        $nonPlaces = [];
        foreach ($this->calque()['nonPlaces'] as $absent) {
            self::assertSame(['noeudId', 'demandeId', 'brouillonRef', 'displayName', 'raison'], array_keys($absent));
            self::assertSame($demande, $absent['demandeId']);
            $nonPlaces[$absent['displayName']] = $absent['raison'];
        }

        self::assertSame(
            [
                'Morille Chanterelle' => NoeudProposeEcarte::ENCHAINEMENT,
                'Cèpe Chanterelle' => NoeudProposeEcarte::ENCHAINEMENT,
            ],
            $nonPlaces,
            'Les deux enfants pendent à une fiche de la même demande, qui n’est dans aucun placement partagé.',
        );
    }

    /**
     * Le nœud qui **est** placé l'est toujours, et au même endroit.
     *
     * L'ajout des trois clés ne devait rien changer au rendu : c'est la moitié de la
     * correction qui n'a pas d'effet visible, et c'est celle qu'il faut tenir.
     */
    public function testLaFichePlaceeResteDansLeGrapheAvecSesAretes(): void
    {
        $demande = $this->deposerUneBrancheEnchainee();

        $this->connexionDe(self::MEMBRE);
        $this->requete('/api/arbre/graphe');

        $graphe = $this->charge();

        $enAttente = [];
        foreach ($graphe['noeuds'] as $noeud) {
            if (true === ($noeud['enAttente'] ?? false) && 'personne' === $noeud['type']) {
                $enAttente[] = $noeud['id'];
            }
        }

        self::assertSame(['#'.$demande.'/p1'], $enAttente, 'La racine de la branche reste placée, et elle seule.');

        $aretes = 0;
        foreach ($graphe['aretes'] as $arete) {
            if ('#'.$demande.'/p1' === $arete['de'] || '#'.$demande.'/p1' === $arete['vers']) {
                ++$aretes;
            }
        }

        self::assertGreaterThan(0, $aretes, 'Le nœud placé garde ses arêtes : un nœud sans arête flotte.');
    }

    /**
     * **Un lecteur sans demande reçoit un calque éteint et des compteurs à zéro.**
     *
     * Pas de clé absente : « un front qui doit tester `"nonPlaces" in calque` avant de
     * tester son contenu écrira un jour le second test sans le premier » (§ 13.1). Un
     * compteur manquant se lit `undefined`, et `undefined > 0` est faux — l'avertissement
     * ne s'afficherait jamais, exactement comme aujourd'hui.
     */
    public function testUnLecteurSansDemandeRecoitDesCompteursAZeroEtPasDesClesAbsentes(): void
    {
        $this->connexionDe('temoin@heritage.test');
        $this->requete('/api/arbre/graphe');

        self::assertSame(
            ['actif' => false, 'demandes' => [], 'proposes' => 0, 'places' => 0, 'nonPlaces' => []],
            $this->calque(),
        );
    }

    /**
     * La branche : une fiche rattachée à l'arbre validé, deux qui pendent à elle.
     *
     * Écrite par entités plutôt que par le dépôt de `Proposal` : ce test porte sur la
     * **sortie du graphe**, et monter le circuit de dépôt ici y mêlerait ses propres
     * règles de validation. Le circuit est éprouvé chez lui
     * ({@see \App\Tests\Proposal\BrancheProposeeLisibleTest}).
     *
     * @return int l'identifiant de la demande déposée
     */
    private function deposerUneBrancheEnchainee(): int
    {
        $em = $this->em();
        $auteur = $this->creerUtilisateur(self::MEMBRE, nomAffiche: 'Girolle Chanterelle');
        $auteurId = $auteur->getId();
        self::assertNotNull($auteurId);

        $demande = new ChangeRequest(RequestKind::CreationPersonne, $auteurId, 'Compte contributeur');
        $demande->soumettre();
        $em->persist($demande);

        foreach ([['#p1', 'Girolle'], ['#p2', 'Morille'], ['#p3', 'Cèpe']] as [$reference, $prenom]) {
            $fiche = new DraftPerson($demande, $reference);
            $fiche->setGivenNames($prenom)->setSurname('Chanterelle')->setSex(Sex::Inconnu)->setDeceased(true);
            $em->persist($fiche);
        }

        // ⇢ La seule qui se raccroche à quelqu'un qui existe.
        $racine = new DraftLink($demande, 1, LinkKind::ConjointDe, 'Girolle Chanterelle');
        $racine->viserSujetBrouillon('#p1')->designerAutre($this->idDe('aieul'), null, 'Émeri Sarriette');
        $em->persist($racine);

        // ⇢ Les deux qui pendent à la première : leur ancre est `#N/p1`, qui n'est dans
        //   aucun placement partagé — celui-ci ne porte que l'arbre validé.
        foreach ([[2, '#p2', 'Morille Chanterelle'], [3, '#p3', 'Cèpe Chanterelle']] as [$seq, $reference, $libelle]) {
            $lien = new DraftLink($demande, $seq, LinkKind::EnfantDe, $libelle);
            $lien->viserSujetBrouillon($reference)->designerAutre(null, '#p1', 'Girolle Chanterelle');
            $em->persist($lien);
        }

        $em->flush();

        $id = $demande->getId();
        self::assertNotNull($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function calque(): array
    {
        $calque = $this->charge()['calque'];
        self::assertIsArray($calque);

        /* @var array<string, mixed> $calque */
        return $calque;
    }

    private function connexionDe(string $courriel): void
    {
        if (null === $this->em()->getRepository(\App\Module\Core\Entity\User::class)->findOneBy(['email' => $courriel])) {
            $this->creerUtilisateur($courriel);
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }
}
