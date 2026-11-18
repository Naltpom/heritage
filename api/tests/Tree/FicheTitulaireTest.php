<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Regime;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Tree\Serializer\CercleDuLecteur;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\LecteurDeLArbre;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\NormaliseurDeDate;
use App\Module\Tree\Serializer\NormaliseurDuTableau;
use App\Module\Tree\Serializer\ParenteDuLecteur;
use App\Tests\Core\Double\FichesTitulairesEnMemoire;
use Symfony\Component\HttpFoundation\Response;

/**
 * La fiche d'un compte **actif** compte « Vérifiée » (ADR 0024, demande du 18/11/2026 :
 * « les fiches rattachées à une personne doivent être des fiches validées, étant donné que
 * la personne s'est connectée et est rattachée à sa fiche »).
 *
 * Traversé pour de vrai : le vrai `Membership` (`FichesTitulairesDesMembres`) lit
 * `mbr_member`, `Tree` pose `titulaire` sur le nœud et sur la fiche. Un compte en attente
 * ne compte pas, et un nœud restreint ne porte jamais le drapeau — comme `bestQuay`.
 *
 * L'`ETag` du graphe suit l'activation par sa sixième composante : c'est
 * {@see EtagDuGrapheTest::testActiverUnCompteChangeLEtagSansToucherALArbre()} qui la
 * garde. Ici, à travers la route, on ne pourrait pas l'isoler : créer ou activer un
 * compte change aussi la réciprocité des vivants (ADR 0016), qui entre dans l'`ETag`.
 */
final class FicheTitulaireTest extends ArbreWebTestCase
{
    public function testLeGrapheEtLaFicheDisentQuUnCompteActifEstTitulaire(): void
    {
        $this->rattacher('aieule@heritage.test', 'aieule', actif: true);
        // Un rattachement **en attente** n'a pas été reconnu : pas de titulaire.
        $this->rattacher('aieul@heritage.test', 'aieul', actif: false);
        // Un vivant hors du cercle du lecteur : son nœud reste restreint, sans drapeau.
        $this->rattacher('cousin@heritage.test', 'cousin', actif: true);

        $this->creerLecteurHorsCercle('membre@heritage.test');
        $this->connexionDe('membre@heritage.test');

        $this->requete('/api/arbre/graphe');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $noeuds = [];
        foreach ($this->charge()['noeuds'] as $noeud) {
            $noeuds[$noeud['id']] = $noeud;
        }

        $aieule = $noeuds['p'.$this->idDe('aieule')];
        self::assertSame('complete', $aieule['visibilite']);
        self::assertTrue($aieule['titulaire'] ?? null, 'La fiche d’un compte actif porte `titulaire: true`.');

        self::assertArrayNotHasKey('titulaire', $noeuds['p'.$this->idDe('aieul')], 'Un compte en attente ne rend pas sa fiche titulaire.');

        $cousin = $noeuds['p'.$this->idDe('cousin')];
        self::assertSame('restreinte', $cousin['visibilite']);
        self::assertArrayNotHasKey('titulaire', $cousin, 'Un nœud restreint ne dit pas qu’une personne vivante a un compte.');

        $this->requete('/api/arbre/personnes/'.$this->idDe('aieule'));
        self::assertTrue($this->charge()['titulaire']);

        $this->requete('/api/arbre/personnes/'.$this->idDe('aieul'));
        self::assertFalse($this->charge()['titulaire']);

        // La fiche réduite d'un vivant hors cercle ne porte pas la clé (liste blanche du § 11.4).
        $this->requete('/api/arbre/personnes/'.$this->idDe('cousin'));
        self::assertArrayNotHasKey('titulaire', $this->charge());
    }

    public function testLeTableauDitQuiEstTitulaire(): void
    {
        $parente = self::getContainer()->get(ParenteDuLecteur::class);
        \assert($parente instanceof ParenteDuLecteur);
        $lecture = self::getContainer()->get(LectureDeLArbre::class);
        \assert($lecture instanceof LectureDeLArbre);
        $vivants = self::getContainer()->get(EtatVivant::class);
        \assert($vivants instanceof EtatVivant);

        $tableau = (new NormaliseurDuTableau(new NormaliseurDeDate(), $parente, new FichesTitulairesEnMemoire([$this->idDe('aieule')])))->tableau(
            $lecture->personnesDuTableau(),
            $lecture->liensDuTableau(),
            new LecteurDeLArbre(42, 'Membre de test', false, Regime::Cercle),
            CercleDuLecteur::de([]),
            $vivants,
        );

        $titulaires = [];
        foreach ($tableau['elements'] as $ligne) {
            $titulaires[$ligne['personId']] = $ligne['titulaire'];
        }

        self::assertTrue($titulaires[$this->idDe('aieule')]);
        self::assertFalse($titulaires[$this->idDe('aieul')]);
    }

    private function rattacher(string $courriel, string $personne, bool $actif): Member
    {
        $identifiant = $this->creerUtilisateur($courriel)->getId();
        self::assertNotNull($identifiant);

        $membre = new Member($identifiant, $courriel, $this->idDe($personne), 'Fiche de '.$personne, MatchKind::PreLie);
        if ($actif) {
            $membre->activer();
        }

        $this->em()->persist($membre);
        $this->em()->flush();

        return $membre;
    }

    private function connexionDe(string $courriel): void
    {
        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'La connexion de test doit réussir.');
    }
}
