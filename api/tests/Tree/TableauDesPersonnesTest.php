<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Serializer\CercleDuLecteur;
use App\Module\Tree\Serializer\EtatVivant;
use App\Module\Tree\Serializer\LecteurDeLArbre;
use App\Module\Tree\Serializer\LectureDeLArbre;
use App\Module\Tree\Serializer\NormaliseurDeDate;
use App\Module\Tree\Serializer\NormaliseurDuTableau;
use App\Module\Tree\Serializer\ParenteDuLecteur;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/arbre/tableau` — le tableau des personnes (§ 5.5 ter).
 *
 * Un tableau est une **énumération** : il suit la règle de la recherche, et ce fichier en
 * est le test de liste blanche. Une personne vivante hors cercle n'a **aucune** ligne, une
 * personne d'un autre arbre non plus, et les noms cités dans une ligne (parents, conjoints)
 * passent par le cercle comme sur la fiche.
 *
 * Le normaliseur est appelé directement, avec un cercle construit à la main, comme dans
 * {@see ListeBlancheDeSerialisationTest} ; la route est traversée pour de vrai à la fin.
 */
final class TableauDesPersonnesTest extends ArbreWebTestCase
{
    /** Les clés d'une ligne, et pas une de plus. */
    private const array CLES_D_UNE_LIGNE = [
        'personId', 'gedcomXref', 'displayName', 'surname', 'givenNames', 'marriedName', 'nickname',
        'sex', 'vivant', 'generation', 'branch', 'parente',
        'naissance', 'naissanceTri', 'lieuNaissance', 'deces', 'decesTri', 'lieuDeces',
        'professions', 'parents', 'conjoints', 'enfants', 'citationCount', 'bestQuay',
        'createdAt', 'updatedAt',
    ];

    public function testUnePersonneVivanteHorsCercleNAPasDeLigne(): void
    {
        $tableau = $this->tableau(CercleDuLecteur::de([]));

        self::assertEqualsCanonicalizing(
            [$this->idDe('aieule'), $this->idDe('aieul'), $this->idDe('sansDate')],
            array_column($tableau['elements'], 'personId'),
            'Ego, sa conjointe et le cousin sont vivants et hors cercle : absents, pas réduits.',
        );
        self::assertSame(3, $tableau['total'], 'Le total se compte après le cercle.');
        self::assertStringNotContainsString(CercleDeVisibiliteInterface::NOM_MASQUE, implode(' ', array_column($tableau['elements'], 'displayName')));
    }

    public function testUneLignePorteExactementSesClesEtSesValeurs(): void
    {
        $aieule = $this->ligne($this->tableau(CercleDuLecteur::de([$this->idDe('ego')])), 'aieule');

        self::assertSame(self::CLES_D_UNE_LIGNE, array_keys($aieule));
        self::assertSame('Marne Dolomite Sureau', $aieule['displayName']);
        self::assertSame('Sureau', $aieule['surname']);
        self::assertSame('feminin', $aieule['sex']);
        self::assertFalse($aieule['vivant']);
        self::assertSame(-3, $aieule['generation']);
        self::assertSame('pm', $aieule['branch']);
        self::assertSame('1901-1909', $aieule['naissance']);
        self::assertSame('1901-01-01', $aieule['naissanceTri']);
        self::assertSame('Saule, Ombrage, Sylvanie', $aieule['lieuNaissance']);
        self::assertSame('1987', $aieule['deces']);
        self::assertSame('1987-01-01', $aieule['decesTri']);
        self::assertSame('Charmille, Ombrage, Sylvanie', $aieule['lieuDeces']);
        self::assertSame([['personId' => $this->idDe('aieul'), 'displayName' => 'Émeri Sarriette']], $aieule['conjoints']);
        self::assertSame([], $aieule['parents']);
        self::assertSame(1, $aieule['enfants']);
        self::assertSame([], $aieule['professions']);

        $aieul = $this->ligne($this->tableau(CercleDuLecteur::de([])), 'aieul');
        self::assertSame(['Tonnelier'], $aieul['professions']);
    }

    /** Un vivant hors cercle cité comme parent garde sa place, sous le masque. */
    public function testLesProchesCitesSuiventLeCercle(): void
    {
        $fermee = $this->ligne($this->tableau(CercleDuLecteur::de([])), 'sansDate');
        self::assertSame(
            [CercleDeVisibiliteInterface::NOM_MASQUE, CercleDeVisibiliteInterface::NOM_MASQUE],
            array_column($fermee['parents'], 'displayName'),
        );

        $ouverte = $this->ligne($this->tableau(CercleDuLecteur::de([$this->idDe('cousin'), $this->idDe('conjointe')])), 'sansDate');
        self::assertEqualsCanonicalizing(['Pyroxène Tilleul', 'Cobalt Romarin'], array_column($ouverte['parents'], 'displayName'));
    }

    /** ADR 0016 : la naissance d'un vivant ne sort qu'aux composantes partagées — tri compris. */
    public function testLaNaissanceDUnVivantPasseParLaReciprocite(): void
    {
        $cousin = $this->idDe('cousin');

        $rien = $this->ligne($this->tableau(CercleDuLecteur::de([$cousin])->avecPartage([$cousin => ComposantesDeDate::aucune()])), 'cousin');
        self::assertNull($rien['naissance']);
        self::assertNull($rien['naissanceTri'], 'Une clé de tri trahirait la date qu’on ne montre pas.');
        self::assertSame('Sorbière, Fougerie, Florie', $rien['lieuNaissance'], 'Le lieu reste, comme dans le bloc masqué de la fiche.');

        $annee = $this->ligne($this->tableau(CercleDuLecteur::de([$cousin])->avecPartage([$cousin => new ComposantesDeDate(annee: true)])), 'cousin');
        self::assertNotNull($annee['naissance']);
        self::assertStringContainsString('1934', $annee['naissance']);
        self::assertSame('1934-01-01', $annee['naissanceTri']);
    }

    public function testUnDefuntDUnAutreArbreNAPasDeLigneEtNEstPasCite(): void
    {
        $tableau = $this->tableau(CercleDuLecteur::de([])->avecHorsPerimetre([$this->idDe('aieul')]));

        self::assertNotContains($this->idDe('aieul'), array_column($tableau['elements'], 'personId'));
        self::assertSame([], $this->ligne($tableau, 'aieule')['conjoints']);

        $sansEgo = $this->tableau(CercleDuLecteur::de([])->avecHorsPerimetre([$this->idDe('ego')]));
        self::assertSame(0, $this->ligne($sansEgo, 'aieule')['enfants'], 'Un enfant d’un autre arbre ne se compte pas.');
    }

    public function testLaPorteeRestreintLesLignes(): void
    {
        $tableau = $this->tableau(CercleDuLecteur::de([]), [$this->idDe('aieule') => true]);

        self::assertSame([$this->idDe('aieule')], array_column($tableau['elements'], 'personId'));
        self::assertSame(1, $tableau['total']);
    }

    // ── La route ─────────────────────────────────────────────────────────────────

    public function testLaRouteEstDerriereLAuthentification(): void
    {
        $this->requete('/api/arbre/tableau');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testUnCompteEnAttenteNeLitPasLeTableau(): void
    {
        $this->creerUtilisateur('attente@heritage.test', statut: UserStatus::EnAttente);
        $this->connexionDe('attente@heritage.test');

        $this->requete('/api/arbre/tableau');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame('regime_en_attente', $this->charge()['erreur']['code']);
    }

    public function testUnMembreLitLeTableauSansCacheEtSansToutLaBase(): void
    {
        $this->creerLecteurHorsCercle('membre@heritage.test');
        $this->connexionDe('membre@heritage.test');

        $this->requete('/api/arbre/tableau?portee=tout');

        $reponse = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $reponse->getStatusCode());
        self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
        self::assertStringContainsString('Cookie', (string) $reponse->headers->get('Vary'));

        $charge = $this->charge();
        self::assertSame(['portee', 'elements', 'total'], array_keys($charge));
        self::assertSame('arbre', $charge['portee'], '« Toute la base » est réservée à l’administration.');
        self::assertIsArray($charge['elements']);
        self::assertNotContains($this->idDe('cousin'), array_column($charge['elements'], 'personId'));
        foreach ($charge['elements'] as $ligne) {
            self::assertIsArray($ligne);
            self::assertSame(self::CLES_D_UNE_LIGNE, array_keys($ligne));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        $profession = new Fact(FactType::Profession, $this->personnes['aieul']);
        $profession->setLabel('Tonnelier');
        $this->em()->persist($profession);
        $this->em()->flush();
    }

    /**
     * @param array<int, true>|null $portee
     *
     * @return array{elements: list<array<string, mixed>>, total: int}
     */
    private function tableau(CercleDuLecteur $cercle, ?array $portee = null): array
    {
        $parente = self::getContainer()->get(ParenteDuLecteur::class);
        \assert($parente instanceof ParenteDuLecteur);
        $lecture = self::getContainer()->get(LectureDeLArbre::class);
        \assert($lecture instanceof LectureDeLArbre);
        $vivants = self::getContainer()->get(EtatVivant::class);
        \assert($vivants instanceof EtatVivant);

        return (new NormaliseurDuTableau(new NormaliseurDeDate(), $parente))->tableau(
            $lecture->personnesDuTableau(),
            $lecture->liensDuTableau(),
            new LecteurDeLArbre(42, 'Membre de test', false, Regime::Cercle),
            $cercle,
            $vivants,
            $portee,
        );
    }

    /**
     * @param array{elements: list<array<string, mixed>>, total: int} $tableau
     *
     * @return array<string, mixed>
     */
    private function ligne(array $tableau, string $cle): array
    {
        foreach ($tableau['elements'] as $ligne) {
            if ($ligne['personId'] === $this->idDe($cle)) {
                return $ligne;
            }
        }

        self::fail(\sprintf('Aucune ligne pour « %s ».', $cle));
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
