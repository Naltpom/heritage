<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\CoteDeRecherche;
use App\Module\Core\Enum\MotifDeFinDeRecherche;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\FinDeRecherche;
use App\Module\Tree\Entity\ResearchClosure;
use App\Module\Tree\Serializer\AppuiDUneCitation;
use App\Module\Tree\Serializer\CercleDuLecteur;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\LecteurDeLArbre;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\MediasNonBranches;
use App\Module\Tree\Serializer\NormaliseurDAffiche;
use App\Module\Tree\Serializer\NormaliseurDeDate;
use App\Module\Tree\Serializer\NormaliseurDePersonne;
use App\Module\Tree\Serializer\ParenteDuLecteur;
use App\Module\Tree\Service\AdressesDArbresEnLigne;
use App\Tests\Tree\Double\CalqueEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * La fin de recherche dans les **trois charges utiles** que lit l'interface (ADR 0023, § 6) :
 * la fiche, le nœud du graphe et la personne d'affiche. Elle suit la règle des notes — servie
 * avec la forme **complète**, jamais avec la forme réduite.
 *
 * | Charge | Forme servie | Absente de |
 * |---|---|---|
 * | fiche | `null` ou `{reason, beforeYear, body, authorLabel, closedAt}`, toujours présentes | la fiche réduite (sept clés) |
 * | nœud du graphe | résumé `{reason, beforeYear}`, clé absente sans fin de recherche | un nœud `restreinte` |
 * | personne d'affiche | le même résumé | une personne restreinte (cinq clés) |
 *
 * Le texte libre et l'auteur ne sortent **que** de la fiche : un nœud ne porte pas de note,
 * il ne porte pas non plus le texte d'une fin de recherche.
 */
final class FinDeRechercheDansLesLecturesTest extends ArbreWebTestCase
{
    private const string TEXTE = 'Les registres paroissiaux de Saint-Martin commencent en 1668.';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        $depose = new \DateTimeImmutable('2026-10-30 10:12:00');

        // Un sommet de branche : l'aïeule n'a aucun parent lié.
        $em->persist((new ResearchClosure(
            $this->personnes['aieule'],
            CoteDeRecherche::Mere,
            new FinDeRecherche(MotifDeFinDeRecherche::RegistresInexistants, 1668, self::TEXTE),
            $depose,
        ))->signer(7, 'Opale Romarin'));
        $em->persist((new ResearchClosure(
            $this->personnes['aieul'],
            CoteDeRecherche::Pere,
            new FinDeRecherche(MotifDeFinDeRecherche::PereInconnu, null, null),
            $depose,
        ))->signer(7, 'Opale Romarin'));
        // Un côté où un parent est lié (l'ego a ses deux parents) : la ligne reste enregistrée
        // et reste servie ; c'est l'écran qui fait l'emporter le parent.
        $em->persist((new ResearchClosure(
            $this->personnes['ego'],
            CoteDeRecherche::Pere,
            new FinDeRecherche(MotifDeFinDeRecherche::RegistresDetruits, null, null),
            $depose,
        ))->signer(7, 'Opale Romarin'));
        // Une personne vivante hors cercle : rien ne doit en sortir.
        $em->persist((new ResearchClosure(
            $this->personnes['cousin'],
            CoteDeRecherche::Mere,
            new FinDeRecherche(MotifDeFinDeRecherche::Autre, null, 'Texte secret du cousin.'),
            $depose,
        ))->signer(7, 'Opale Romarin'));
        $em->flush();
        $em->clear();
    }

    // ------------------------------------------------------------------------ la fiche

    public function testUneFicheCompleteLesPorteToujoursNullCompris(): void
    {
        $fiche = $this->fiche('aieule', CercleDuLecteur::de([]));

        self::assertSame('complete', $fiche['visibilite']);
        self::assertArrayHasKey('finDeRecherchePere', $fiche);
        self::assertNull($fiche['finDeRecherchePere'], 'Pas de fin de recherche : `null`, la clé reste.');
        self::assertSame(
            [
                'reason' => 'registres_inexistants',
                'beforeYear' => 1668,
                'body' => self::TEXTE,
                'authorLabel' => 'Opale Romarin',
                'closedAt' => '2026-10-30T10:12:00'.(new \DateTimeImmutable('2026-10-30 10:12:00'))->format('P'),
            ],
            $fiche['finDeRechercheMere'],
        );
        self::assertSame(['reason', 'beforeYear', 'body', 'authorLabel', 'closedAt'], array_keys((array) $fiche['finDeRechercheMere']));

        // Placées avant `enAttente`, après `medias`.
        $cles = array_keys($fiche);
        self::assertSame('finDeRecherchePere', $cles[array_search('enAttente', $cles, true) - 2]);
        self::assertSame('finDeRechercheMere', $cles[array_search('enAttente', $cles, true) - 1]);
        self::assertSame('medias', $cles[array_search('finDeRecherchePere', $cles, true) - 1]);
    }

    public function testUneFicheSansAucuneFinDeRechercheLesPorteQuandMemeA_Null(): void
    {
        $fiche = $this->fiche('sansDate', CercleDuLecteur::de([]));

        self::assertArrayHasKey('finDeRecherchePere', $fiche);
        self::assertArrayHasKey('finDeRechercheMere', $fiche);
        self::assertNull($fiche['finDeRecherchePere']);
        self::assertNull($fiche['finDeRechercheMere']);
    }

    public function testUneFinDeRechercheResteServieQuandUnParentEstLie(): void
    {
        $fiche = $this->fiche('ego', CercleDuLecteur::de([$this->idDe('ego')]));

        self::assertIsArray($fiche['finDeRecherchePere']);
        self::assertSame('registres_detruits', $fiche['finDeRecherchePere']['reason']);
    }

    public function testLesParentsDisentLeurCote(): void
    {
        $fiche = $this->fiche('ego', CercleDuLecteur::de([$this->idDe('ego')]));

        $cotes = [];
        foreach ($fiche['parents'] as $parent) {
            $cotes[$parent['personId']] = $parent['cote'];
        }

        self::assertSame([$this->idDe('aieul') => 'pere', $this->idDe('aieule') => 'mere'], $cotes, 'L’époux est le père, l’épouse la mère : la place de la famille.');
        self::assertSame('cote', array_keys($fiche['parents'][0])[4], 'Après familyId.');
    }

    public function testUneFicheReduiteNeLesPortePas(): void
    {
        $fiche = $this->fiche('cousin', CercleDuLecteur::de([]));

        self::assertSame(['id', 'displayName', 'sex', 'generation', 'branch', 'vivant', 'visibilite'], array_keys($fiche), 'Les sept clés, et pas une de plus.');
        self::assertArrayNotHasKey('finDeRecherchePere', $fiche);
        self::assertArrayNotHasKey('finDeRechercheMere', $fiche);
        self::assertStringNotContainsString('secret', json_encode($fiche, \JSON_THROW_ON_ERROR));
    }

    public function testLeCalqueEnAttenteEstVisibleDeLAuteurEtDeLAdministrateurSeulement(): void
    {
        $auteur = new LecteurDeLArbre(7, 'Opale Romarin', false, Regime::Cercle);
        $autre = new LecteurDeLArbre(9, 'Tuf Romarin', false, Regime::Cercle);
        $administrateur = new LecteurDeLArbre(1, 'Admin', true, Regime::Cercle);

        $propositions = (new CalqueEnMemoire($auteur->userId))->poser(SubjectKind::Personne, $this->idDe('aieul'), [
            'finDeRecherchePere' => [
                'propose' => null,
                'actuel' => ['reason' => 'pere_inconnu', 'beforeYear' => null, 'body' => null],
                'demandeId' => 21,
                'auteur' => 'Opale Romarin',
                'depuis' => '2026-10-30T11:00:00+01:00',
                'autresDemandes' => [],
            ],
        ]);
        $normaliseur = $this->normaliseurDePersonne($propositions);
        $cercle = CercleDuLecteur::de([]);

        $vueAuteur = $normaliseur->fiche($this->personnes['aieul'], $auteur, $cercle, $this->regle());
        $vueAdmin = $normaliseur->fiche($this->personnes['aieul'], $administrateur, $cercle, $this->regle());
        $vueAutre = $normaliseur->fiche($this->personnes['aieul'], $autre, $cercle, $this->regle());

        foreach ([$vueAuteur, $vueAdmin] as $vue) {
            $entree = ((array) $vue['enAttente'])['finDeRecherchePere'] ?? null;
            self::assertIsArray($entree);
            self::assertNull($entree['propose'], 'Une levée en attente : `propose` vaut null.');
            self::assertSame('pere_inconnu', $entree['actuel']['reason']);
        }
        self::assertSame([], (array) $vueAutre['enAttente'], 'Un autre membre voit les données validées, point.');
        self::assertIsArray($vueAutre['finDeRecherchePere'], 'La valeur validée, elle, est servie à tous.');
    }

    // ----------------------------------------------------------------------- le graphe

    public function testLeNoeudCompletPorteLeResumeEtLeNoeudRestreintRienDuTout(): void
    {
        $this->connexionDe('membre@heritage.test');
        $this->requete('/api/arbre/graphe');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $graphe = $this->charge();
        $noeuds = [];
        foreach ($graphe['noeuds'] as $noeud) {
            if ('personne' === $noeud['type']) {
                $noeuds[$noeud['personId']] = $noeud;
            }
        }

        $aieule = $noeuds[$this->idDe('aieule')];
        self::assertSame('complete', $aieule['visibilite']);
        self::assertSame(['reason' => 'registres_inexistants', 'beforeYear' => 1668], $aieule['finDeRechercheMere'], 'Le résumé, sans le texte ni l’auteur.');
        self::assertArrayNotHasKey('finDeRecherchePere', $aieule, 'Clé absente quand le côté n’est pas clos.');

        $aieul = $noeuds[$this->idDe('aieul')];
        self::assertSame(['reason' => 'pere_inconnu', 'beforeYear' => null], $aieul['finDeRecherchePere']);
        self::assertArrayNotHasKey('finDeRechercheMere', $aieul);

        self::assertArrayNotHasKey('finDeRecherchePere', $noeuds[$this->idDe('sansDate')] ?? [], 'Sans ligne, aucune clé.');

        $cousin = $noeuds[$this->idDe('cousin')];
        self::assertSame('restreinte', $cousin['visibilite']);
        self::assertArrayNotHasKey('finDeRecherchePere', $cousin);
        self::assertArrayNotHasKey('finDeRechercheMere', $cousin);

        $json = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Saint-Martin', $json, 'Le texte ne sort pas du graphe.');
        self::assertStringNotContainsString('secret', $json);
        self::assertStringNotContainsString('Opale Romarin', $json, 'Ni l’auteur.');
    }

    // ----------------------------------------------------------------------- l'affiche

    public function testLaPersonneCompleteDeLAfficheLePorteEtLaRestreinteNon(): void
    {
        // Le couple « mixte » : le cousin vivant, hors cercle, en est l'époux.
        $affiche = $this->afficheDe('mixte', CercleDuLecteur::de([$this->idDe('conjointe'), $this->idDe('sansDate')]));
        $cousin = $this->personneServie($affiche, $this->idDe('cousin'));
        self::assertSame('restreinte', $cousin['visibilite']);
        self::assertArrayNotHasKey('finDeRechercheMere', $cousin);
        self::assertArrayNotHasKey('finDeRecherchePere', $cousin);
        self::assertStringNotContainsString('secret', json_encode($affiche, \JSON_THROW_ON_ERROR));

        // Le couple des aïeux : les deux défunts sont complets.
        $affiche = $this->afficheDe('aieux', CercleDuLecteur::de([]));
        $aieule = $this->personneServie($affiche, $this->idDe('aieule'));
        self::assertSame(['reason' => 'registres_inexistants', 'beforeYear' => 1668], $aieule['finDeRechercheMere']);
        self::assertArrayNotHasKey('finDeRecherchePere', $aieule);
        $aieul = $this->personneServie($affiche, $this->idDe('aieul'));
        self::assertSame(['reason' => 'pere_inconnu', 'beforeYear' => null], $aieul['finDeRecherchePere']);
        self::assertStringNotContainsString('Saint-Martin', json_encode($affiche, \JSON_THROW_ON_ERROR), 'Le texte ne sort pas de l’affiche.');
    }

    // ------------------------------------------------------------------------ outils

    /** @return array<string, mixed> */
    private function fiche(string $cle, CercleDuLecteur $cercle): array
    {
        $personne = $this->em()->find(\App\Module\Tree\Entity\Person::class, $this->idDe($cle));
        \assert(null !== $personne);

        return $this->normaliseurDePersonne()->fiche($personne, new LecteurDeLArbre(42, 'Membre de test', false, Regime::Cercle), $cercle, $this->regle());
    }

    private function normaliseurDePersonne(?\App\Module\Core\Contract\CalqueEnAttenteInterface $propositions = null): NormaliseurDePersonne
    {
        $parente = self::getContainer()->get(ParenteDuLecteur::class);
        \assert($parente instanceof ParenteDuLecteur);

        return new NormaliseurDePersonne(
            new NormaliseurDeDate(),
            $this->lecture(),
            new MediasNonBranches(),
            $parente,
            $propositions ?? new \App\Module\Core\Contract\CalqueFerme(),
            new AppuiDUneCitation(),
            new AdressesDArbresEnLigne(),
        );
    }

    /** @return array<string, mixed> */
    private function afficheDe(string $famille, CercleDuLecteur $cercle): array
    {
        $couple = $this->lecture()->famille((int) $this->familles[$famille]->getId());
        self::assertNotNull($couple);

        return (new NormaliseurDAffiche(new NormaliseurDeDate(), $this->lecture(), new ReglagesFixes(110, null, 'Auteur Fictif')))
            ->affiche($couple, $cercle, $this->regle());
    }

    /**
     * @param array<string, mixed> $affiche
     *
     * @return array<string, mixed>
     */
    private function personneServie(array $affiche, int $id): array
    {
        foreach ($affiche['personnes'] as $personne) {
            if ($personne['id'] === $id) {
                return $personne;
            }
        }

        self::fail(\sprintf('La personne %d n’est pas servie.', $id));
    }

    private function lecture(): LectureDeLArbre
    {
        $lecture = self::getContainer()->get(LectureDeLArbre::class);
        \assert($lecture instanceof LectureDeLArbre);

        return $lecture;
    }

    private function regle(): EtatVivant
    {
        $etat = self::getContainer()->get(EtatVivant::class);
        \assert($etat instanceof EtatVivant);

        return $etat;
    }

    private function connexionDe(string $courriel): void
    {
        $this->creerLecteurHorsCercle($courriel);

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }
}
