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
 * UN CALQUE QUI NE PEUT PAS TOUT MONTRER DOIT LE DIRE — ET IL DOIT D'ABORD TOUT
 * ESSAYER.
 *
 * Le défaut d'origine, mesuré sur un cas réel : cinq demandes ont déposé une branche de
 * vingt-deux personnes. Le calque du graphe a rendu `actif: true` et
 * `demandes: [1,2,3,4,5]` — **cinq nœuds placés, dix-sept absents**, et rien dans la
 * charge ne signalait l'écart.
 *
 * ── Première réponse : compter et nommer ───────────────────────────────────────
 *
 * `actif` ne dit pas « complet » ; `proposes`, `places` et `nonPlaces` le disent. Cette
 * moitié-là reste, et elle est tenue plus bas : un compte seul (« 17 fiches non
 * affichées ») laisse l'utilisateur sans geste possible.
 *
 * ── Seconde réponse, et c'est celle que ce fichier a changée ───────────────────
 *
 * Les dix-sept absents n'étaient pas un fatalisme, c'était un **défaut de placement**.
 * Une branche se propose en bloc — « sa mère, puis le père de sa mère » — et tout ce qui
 * pendait derrière la première fiche tombait, parce que le placement partagé ne porte que
 * l'arbre validé et qu'on n'y cherchait les ancres qu'une seule fois.
 *
 * {@see \App\Module\Tree\Serializer\PropositionsProjetees} résout désormais les ancrages
 * **par vagues** : une fiche posée devient une ancre valable pour la suivante. Une branche
 * enchaînée se place donc en entier, et c'est ce que ce fichier vérifie maintenant.
 *
 * Les assertions qui disaient l'inverse ont été réécrites, pas supprimées : elles
 * décrivaient fidèlement ce que le code faisait, et ce que le code faisait était le
 * défaut que le commanditaire a signalé — « on devrait voir tous ceux que j'ajoute, même
 * non validés ».
 */
final class CalqueIncompletTest extends ArbreWebTestCase
{
    private const string MEMBRE = 'contributeur@heritage.test';

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LE CAS MESURÉ, EN RÉDUCTION : TROIS FICHES PROPOSÉES, TROIS PLACÉES.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * Les trois assertions de tête sont celles qui manquaient à l'origine. Un test qui se
     * contente de parcourir `noeuds` et de vérifier que ceux qu'il trouve sont corrects
     * reste vert quand il n'en reste qu'un sur trois : c'est exactement ce qui s'est
     * produit. Elles gardent donc leur place, avec les nombres d'aujourd'hui.
     */
    public function testUneBrancheEnchaineeSePlaceEnEntier(): void
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
        self::assertSame(
            3,
            $calque['places'],
            'La racine s’ancre à l’arbre validé ; les deux enfants s’ancrent à la racine, à la vague suivante.',
        );
        self::assertSame([], $calque['nonPlaces'], 'Il ne reste rien à annoncer comme absent.');

        // ───── L'invariant : rien ne disparaît entre les deux comptes.
        self::assertSame(
            $calque['proposes'],
            $calque['places'] + \count($calque['nonPlaces']),
            'Tout nœud proposé est soit placé, soit annoncé comme non placé.',
        );
    }

    /**
     * Les trois fiches sont dans le graphe, **et rattachées** — pas seulement comptées.
     *
     * Un nœud sans arête flotte : le compter comme placé et le dessiner à l'écart serait
     * une autre façon de mentir sur le même sujet.
     */
    public function testLesTroisFichesSontDansLeGrapheAvecLeursAretes(): void
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

        sort($enAttente);

        self::assertSame(
            ['#'.$demande.'/p1', '#'.$demande.'/p2', '#'.$demande.'/p3'],
            $enAttente,
            'La branche entière est placée, pas seulement sa racine.',
        );

        foreach ($enAttente as $id) {
            $aretes = 0;
            foreach ($graphe['aretes'] as $arete) {
                if ($id === $arete['de'] || $id === $arete['vers']) {
                    ++$aretes;
                }
            }

            self::assertGreaterThan(0, $aretes, "Le nœud $id garde ses arêtes : un nœud sans arête flotte.");
        }
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
     *
     * **Le cas éprouvé ici a changé**, et il fallait qu'il change : l'enchaînement se
     * place désormais. Reste ce qu'aucune vague ne peut sauver — une fiche que *rien* ne
     * rattache. Le § 6.4 du dossier l'interdit à la saisie ; le rendu, lui, ne suppose
     * jamais qu'une règle d'écriture a tenu.
     */
    public function testChaqueNoeudNonPlaceEstNommeAvecSaRaisonEtDeQuoiLOuvrir(): void
    {
        $demande = $this->deposerUneFicheQueRienNeRattache();

        $this->connexionDe(self::MEMBRE);
        $this->requete('/api/arbre/graphe');

        $nonPlaces = [];
        foreach ($this->calque()['nonPlaces'] as $absent) {
            self::assertSame(['noeudId', 'demandeId', 'brouillonRef', 'displayName', 'raison'], array_keys($absent));
            self::assertSame($demande, $absent['demandeId']);
            $nonPlaces[$absent['displayName']] = $absent['raison'];
        }

        self::assertSame(
            ['Amanite Chanterelle' => NoeudProposeEcarte::SANS_LIEN],
            $nonPlaces,
            'Aucun lien ne porte cette fiche, dans un sens ni dans l’autre : elle flotterait hors de l’arbre.',
        );
    }

    /**
     * **Un lecteur sans demande reçoit un calque éteint et des compteurs à zéro.**.
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
        $demande = $this->demandeDe($em);

        foreach ([['#p1', 'Girolle'], ['#p2', 'Morille'], ['#p3', 'Cèpe']] as [$reference, $prenom]) {
            $fiche = new DraftPerson($demande, $reference);
            $fiche->setGivenNames($prenom)->setSurname('Chanterelle')->setSex(Sex::Inconnu)->setDeceased(true);
            $em->persist($fiche);
        }

        // ⇢ La seule qui se raccroche à quelqu'un qui existe : la vague 0.
        $racine = new DraftLink($demande, 1, LinkKind::ConjointDe, 'Girolle Chanterelle');
        $racine->viserSujetBrouillon('#p1')->designerAutre($this->idDe('aieul'), null, 'Émeri Sarriette');
        $em->persist($racine);

        // ⇢ Les deux qui pendent à la première : leur ancre est `#N/p1`, qui n'est dans
        //   aucun placement partagé. Elle y sera à la vague 1, et c'est tout le sujet.
        foreach ([[2, '#p2', 'Morille Chanterelle'], [3, '#p3', 'Cèpe Chanterelle']] as [$seq, $reference, $libelle]) {
            $lien = new DraftLink($demande, $seq, LinkKind::EnfantDe, $libelle);
            $lien->viserSujetBrouillon($reference)->designerAutre(null, '#p1', 'Girolle Chanterelle');
            $em->persist($lien);
        }

        return $this->enregistrer($em, $demande);
    }

    /**
     * Une fiche proposée que **rien** ne rattache — ni comme sujet, ni comme autre bout.
     *
     * @return int l'identifiant de la demande déposée
     */
    private function deposerUneFicheQueRienNeRattache(): int
    {
        $em = $this->em();
        $demande = $this->demandeDe($em);

        $fiche = new DraftPerson($demande, '#p1');
        $fiche->setGivenNames('Amanite')->setSurname('Chanterelle')->setSex(Sex::Inconnu)->setDeceased(true);
        $em->persist($fiche);

        return $this->enregistrer($em, $demande);
    }

    private function demandeDe(\Doctrine\ORM\EntityManagerInterface $em): ChangeRequest
    {
        $auteur = $this->creerLecteurHorsCercle(self::MEMBRE, nomAffiche: 'Girolle Chanterelle');
        $auteurId = $auteur->getId();
        self::assertNotNull($auteurId);

        $demande = new ChangeRequest(RequestKind::CreationPersonne, $auteurId, 'Compte contributeur');
        $demande->soumettre();
        $em->persist($demande);

        return $demande;
    }

    private function enregistrer(\Doctrine\ORM\EntityManagerInterface $em, ChangeRequest $demande): int
    {
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
            $this->creerLecteurHorsCercle($courriel);
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }
}
