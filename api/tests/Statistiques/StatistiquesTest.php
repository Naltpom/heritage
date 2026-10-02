<?php

declare(strict_types=1);

namespace App\Tests\Statistiques;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Entity\User;
use App\Module\Core\Enum\Branch;
use App\Module\Core\Enum\SettingType;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Enum\UserStatus;
use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Module\Statistiques\Contract\PorteeDeLAppui;
use App\Tests\Membership\Double\ArbreEnMemoire;
use App\Tests\Statistiques\Double\ReleveEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Explorer › Stats, par ses deux routes : les règles de dates, la confidentialité des
 * records et des agrégats, et le chantier réservé à l'administration.
 *
 * Le petit arbre de ces tests — des noms inventés (ADR 0006) :
 *
 * ```
 *   Jean Romarin (14 MAR 1820 – 2 JAN 1890)  ∞  Marie Bourrache (1825 – 1910)
 *        ├── Jean Romarin (MAR 1850 – 1852)            ← mort en bas âge
 *        ├── Pierre Romarin (ABT 1850 – 1851)          ← date floue : ne compte pas
 *        └── Jean Romarin (1855 – 1920) ∞ Rose Chardon
 *                 └── Jean Romarin (1885 – 1960)       ← « Jean » sur trois générations
 *
 *                          └── Paul Romarin (1960, vivant)
 *                                   └── Natron Romarin (1990, vivant, le lecteur, racine)
 *   Cousin Éloigné (3 JUN 1920, vivant, fiche CONFIDENTIELLE hors du cercle du lecteur)
 * ```
 *
 * Cousin Éloigné aurait 106 ans : il est le doyen de l'arbre. Un membre ne le voit pas —
 * le record passe donc à Marie Bourrache (85 ans) ; l'administrateur, lui, le voit.
 */
final class StatistiquesTest extends WebTestCase
{
    private const int LECTEUR = 1;
    private const int HORS_CERCLE = 9;
    private const int JEAN = 10;
    private const int MARIE = 11;
    private const int JEAN_PETIT = 13;
    private const int PIERRE = 12;
    private const int JEAN_FILS = 16;
    private const int ROSE = 18;
    private const int JEAN_PETIT_FILS = 17;
    private const int PAUL = 50;

    private KernelBrowser $client;

    private string $ip;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->ip = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        ArbreEnMemoire::reinitialiser();
        ReleveEnMemoire::reinitialiser();

        $this->repartirDUneBaseVierge();
        $this->reglerEntier('cercle.generations', 3, 1, 6);
        $this->viderLesCaches();
        $this->semerLArbre();
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::reinitialiser();
        ReleveEnMemoire::reinitialiser();

        parent::tearDown();
    }

    // ── L'accès ──────────────────────────────────────────────────────────────────

    public function testUnCompteEnAttenteNeLitRienDesStatistiques(): void
    {
        $this->creerUtilisateur('attente@example.test', statut: UserStatus::EnAttente);
        $this->requete('POST', '/api/connexion', ['email' => 'attente@example.test', 'password' => 'motdepasse-de-test']);
        $this->requete('GET', '/api/explorer/stats');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testLeChantierEstReserveALAdministration(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/administration/stats/chantier');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'Un membre n’ouvre pas le chantier.');

        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('sansSource', $contenu);
    }

    public function testLAdministrateurLitLeChantier(): void
    {
        $this->connecterUnAdministrateur();
        $this->requete('GET', '/api/administration/stats/chantier');

        self::assertResponseIsSuccessful();
        $chantier = $this->charge();

        // Sans source : toutes les fiches sauf Jean (citations = 2) et Marie (citations = 1).
        self::assertSame(8, $chantier['fiches']['sansSource']['n']);
        // Non vérifiée : Marie — une citation, aucune qui soit un acte, et décédée.
        self::assertSame([self::MARIE], array_column($chantier['fiches']['nonVerifiees']['personnes'], 'id'));
        // Sans parents : Jean, Marie, Rose et le cousin.
        self::assertSame(4, $chantier['fiches']['sansParents']['n']);
        self::assertSame(0, $chantier['activite']['actesEnAttente']);
        self::assertCount(12, $chantier['activite']['demandesParMois'], 'Un an glissant, mois vides compris.');
    }

    // ── Les dates ────────────────────────────────────────────────────────────────

    public function testUneDateFloueNeComptePasEtUneAnneeSeuleCompteALAnnee(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/explorer/stats');
        $vie = $this->charge()['vie'];

        $xixe = $this->ligneDuSiecle($vie['ageAuDeces'], 19);

        // Jean (1820, exacte), Marie (1825, année seule), Jean (MAR 1850), Jean (1855) et
        // Jean (1885). Pierre (ABT 1850) est absent : sa date est floue.
        self::assertSame(5, $xixe['ensemble']['n']);
        self::assertSame(1, $xixe['femmes']['n'], 'Marie et sa naissance « 1825 » comptent pour un âge en années.');
    }

    public function testUneAnneeSeuleNeComptePasPourLeMois(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/explorer/stats');
        $mois = $this->charge()['vie']['moisDesNaissances'];

        // 14 MAR 1820 et MAR 1850 : deux naissances en mars. « 1825 », « 1855 », « 1885 »
        // n'ont pas de mois ; « ABT 1850 » non plus.
        self::assertSame(2, $mois['mois'][2]);
        self::assertSame(2, $mois['n'], 'Le cousin confidentiel (juin) est retiré de sa case : une seule contribution, invisible.');
    }

    public function testLaMortaliteInfantile(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/explorer/stats');

        $xixe = $this->ligneDuSiecle($this->charge()['vie']['mortaliteInfantile'], 19);

        self::assertSame(1, $xixe['avantCinqAns'], 'Le petit Jean, mort à deux ans.');
        self::assertSame(5, $xixe['n']);
    }

    // ── La confidentialité ───────────────────────────────────────────────────────

    public function testUnRecordDontLeTitulaireEstInvisiblePasseAuSuivant(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/explorer/stats');

        $charge = $this->charge();
        $doyen = $charge['records']['doyen'];

        self::assertSame(self::MARIE, $doyen['personnes'][0]['id'], 'Le cousin confidentiel est invisible : le record passe à Marie.');
        self::assertSame(85, $doyen['valeur']);

        self::assertStringNotContainsString(
            'Cousin',
            (string) $this->client->getResponse()->getContent(),
            'Ni son nom, ni son prénom ne sortent, par aucune clé — records, prénoms, noms de famille.',
        );
        self::assertSame(10, $charge['arbre']['personnes'], 'Les comptes, eux, comptent tout le monde.');
    }

    public function testLAdministrateurVoitLeDoyenConfidentiel(): void
    {
        $this->connecterUnAdministrateur();
        $this->requete('GET', '/api/explorer/stats');

        $charge = $this->charge();

        self::assertSame(self::HORS_CERCLE, $charge['records']['doyen']['personnes'][0]['id']);
        self::assertTrue($charge['records']['doyen']['enCours'], 'Un vivant : son âge est compté jusqu’à aujourd’hui.');
        self::assertSame(1, $charge['vie']['moisDesNaissances']['mois'][5], 'Pour l’administrateur, rien n’est retiré.');
    }

    public function testLeCercleNEstConsulteQuAUnSeulEndroit(): void
    {
        $racine = \dirname(__DIR__, 2).'/src/Module/Statistiques';
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        $citants = [];
        $vus = 0;

        foreach ($fichiers as $fichier) {
            if (!$fichier instanceof \SplFileInfo || 'php' !== $fichier->getExtension()) {
                continue;
            }

            ++$vus;
            $code = (string) file_get_contents($fichier->getPathname());

            if (str_contains($code, 'use '.CercleDeVisibiliteInterface::class.';')) {
                $citants[] = $fichier->getFilename();
            }
        }

        self::assertGreaterThanOrEqual(20, $vus, 'Un balayage devenu vide passerait au vert en ne vérifiant plus rien.');
        self::assertSame(['StatistiquesVisibles.php'], $citants);
    }

    // ── Les chiffres ─────────────────────────────────────────────────────────────

    public function testLaPartVerifieeEtSonEvolution(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/explorer/stats');
        $verification = $this->charge()['arbre']['verification'];

        self::assertSame(['verifiees' => 1, 'total' => 10], $verification['fiches'], 'Jean seul a un acte (bestQuay = 3).');
        self::assertSame(['verifiees' => 1, 'total' => 9], $verification['naissances'], 'Toutes les naissances renseignées, sauf celle de Rose.');

        $historique = $verification['historique'];
        self::assertNotEmpty($historique);
        self::assertSame('2026-08', $historique[0]['mois'], 'L’historique commence au mois de la première fiche.');
        self::assertSame(0, $historique[0]['verifiees'], 'L’acte de Jean n’a été saisi qu’en septembre.');
        self::assertSame(1, end($historique)['verifiees']);
    }

    public function testLesBranchesPortentLePatronymeDeTete(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/explorer/stats');
        $branches = $this->charge()['arbre']['branches'];

        self::assertSame('pp', $branches[0]['branche']);
        self::assertSame('Romarin', $branches[0]['nom'], 'Le père du père de la racine s’appelle Romarin.');
    }

    public function testUnPrenomTransmisSurTroisGenerations(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/explorer/stats');
        $transmis = $this->charge()['prenomsEtNoms']['prenomsTransmis'];

        self::assertSame('Jean', $transmis[0]['prenom']);
        self::assertSame(3, $transmis[0]['generations']);
        self::assertSame(self::JEAN, $transmis[0]['depuis']['id']);
    }

    public function testLesMetiersEtLesDistances(): void
    {
        $this->connecterUnMembre();
        $this->requete('GET', '/api/explorer/stats');
        $lieux = $this->charge()['lieux'];

        $xixe = $this->ligneDuSiecle($lieux['metiers'], 19);
        self::assertSame([['libelle' => 'Tisserand', 'n' => 2]], $xixe['metiers'], '« tisserand » et « Tisserand » sont un même métier.');

        self::assertSame('Saule (1)', $lieux['communes']['palmares'][0]['libelle']);
        $distance = $this->ligneDuSiecle($lieux['distance'], 19);
        self::assertSame(1, $distance['n']);
        self::assertGreaterThan(100.0, $distance['moyenne']);
    }

    // ── Le petit arbre ───────────────────────────────────────────────────────────

    private function semerLArbre(): void
    {
        ReleveEnMemoire::ajouterLieu('Saule, Ombrage, Sylvanie', 'Saule (1)', 'Saule', 'Ombrage', 'FR', 46.0, 2.0);
        ReleveEnMemoire::ajouterLieu('Charmille, Ombrage, Sylvanie', 'Charmille (2)', 'Charmille', 'Ombrage', 'FR', 48.0, 2.0);

        $this->poser(self::LECTEUR, 'Natron', 'Romarin', Sex::Masculin, '1990', vivant: true, branche: Branch::Tr, generation: 0);
        $this->poser(self::HORS_CERCLE, 'Cousin', 'Éloigné', Sex::Masculin, '3 JUN 1920', vivant: true, confidentielle: true, branche: Branch::Mm, generation: -2);
        $this->poser(self::JEAN, 'Jean', 'Romarin', Sex::Masculin, '14 MAR 1820', '2 JAN 1890', branche: Branch::Pp, generation: -4, bestQuay: 3, citations: 2, lieuNaissance: 'Saule, Ombrage, Sylvanie', lieuDeces: 'Charmille, Ombrage, Sylvanie');
        $this->poser(self::MARIE, 'Marie', 'Bourrache', Sex::Feminin, '1825', '1910', branche: Branch::Pp, generation: -4, bestQuay: 1, citations: 1);
        $this->poser(self::JEAN_PETIT, 'Jean', 'Romarin', Sex::Masculin, 'MAR 1850', '1852', branche: Branch::Pp, generation: -3);
        $this->poser(self::PIERRE, 'Pierre', 'Romarin', Sex::Masculin, 'ABT 1850', '1851', branche: Branch::Pp, generation: -3);
        $this->poser(self::JEAN_FILS, 'Jean', 'Romarin', Sex::Masculin, '1855', '1920', branche: Branch::Pp, generation: -3);
        $this->poser(self::ROSE, 'Rose', 'Chardon', Sex::Feminin, '', '', branche: Branch::Pp, generation: -3);
        $this->poser(self::JEAN_PETIT_FILS, 'Jean', 'Romarin', Sex::Masculin, '1885', '1960', branche: Branch::Pp, generation: -2);
        $this->poser(self::PAUL, 'Paul', 'Romarin', Sex::Masculin, '1960', vivant: true, branche: Branch::Tr, generation: -1);

        ReleveEnMemoire::ajouterFamille(100, self::JEAN, self::MARIE, [self::JEAN_PETIT, self::PIERRE, self::JEAN_FILS], '1849', -4);
        ReleveEnMemoire::ajouterFamille(101, self::JEAN_FILS, self::ROSE, [self::JEAN_PETIT_FILS], '', -3);
        // La racine (le lecteur) descend des Romarin par son père Paul : le père du père de
        // la racine (sosa 4) est le dernier Jean Romarin.
        ReleveEnMemoire::ajouterFamille(102, self::JEAN_PETIT_FILS, null, [self::PAUL], '', -2);
        ReleveEnMemoire::ajouterFamille(103, self::PAUL, null, [self::LECTEUR], '', -1);
        ReleveEnMemoire::racine(self::LECTEUR);

        ReleveEnMemoire::ajouterMetier(self::JEAN, 'tisserand', '1850');
        ReleveEnMemoire::ajouterMetier(self::JEAN_FILS, 'Tisserand');
        ReleveEnMemoire::ajouterAppui(self::JEAN, 3, '2026-09-20', PorteeDeLAppui::Naissance);
        ReleveEnMemoire::ajouterAppui(self::MARIE, 1, '2026-09-20');

        // Le cercle : le lecteur est rattaché à 1, et le cousin, confidentiel, n'est relié à
        // personne — il est hors de son cercle. Toutes les autres fiches sont visibles d'un
        // membre (révision du 01/10/2026 de l'ADR 0003).
        ArbreEnMemoire::ajouterFamille([self::JEAN, self::MARIE], [self::JEAN_PETIT, self::PIERRE, self::JEAN_FILS]);
    }

    private function poser(
        int $id,
        string $prenoms,
        string $nom,
        Sex $sexe,
        string $naissance,
        string $deces = '',
        bool $vivant = false,
        bool $confidentielle = false,
        ?Branch $branche = null,
        ?int $generation = null,
        ?int $bestQuay = null,
        int $citations = 0,
        ?string $lieuNaissance = null,
        ?string $lieuDeces = null,
    ): void {
        // Les fiches sont créées en août, l'acte de Jean saisi en septembre.
        ReleveEnMemoire::ajouterPersonne($id, $prenoms, $nom, $sexe, $naissance, $deces, $vivant, $branche, $generation, $bestQuay, $citations, $lieuNaissance, $lieuDeces, '2026-08-20');
        ArbreEnMemoire::ajouterPersonne($id, $prenoms, $nom, $vivant, confidentielle: $confidentielle);
    }

    // ── L'outillage ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<string, mixed>
     */
    private function ligneDuSiecle(array $lignes, int $siecle): array
    {
        foreach ($lignes as $ligne) {
            if ($siecle === $ligne['siecle']) {
                return $ligne;
            }
        }

        self::fail('Aucune ligne pour le siècle '.$siecle);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /** @param list<string> $roles */
    private function creerUtilisateur(string $courriel, array $roles = [], UserStatus $statut = UserStatus::Actif): User
    {
        $hacheur = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hacheur instanceof UserPasswordHasherInterface);

        $utilisateur = new User($courriel);
        $utilisateur->setRoles($roles)->setStatus($statut)->setPassword($hacheur->hashPassword($utilisateur, 'motdepasse-de-test'));

        $this->em()->persist($utilisateur);
        $this->em()->flush();

        return $utilisateur;
    }

    private function connecterUnMembre(): void
    {
        $utilisateur = $this->creerUtilisateur('membre@example.test');
        $identifiant = $utilisateur->getId();
        \assert(null !== $identifiant);

        $membre = new Member($identifiant, $utilisateur->getEmail(), self::LECTEUR, 'Natron Romarin', MatchKind::PreLie);
        $membre->setStatus(MemberStatus::Actif);
        $membre->activer();
        $this->em()->persist($membre);
        $this->em()->flush();

        $this->requete('POST', '/api/connexion', ['email' => 'membre@example.test', 'password' => 'motdepasse-de-test']);
    }

    private function connecterUnAdministrateur(): void
    {
        $this->creerUtilisateur('admin@example.test', ['ROLE_ADMIN']);
        $this->requete('POST', '/api/connexion', ['email' => 'admin@example.test', 'password' => 'motdepasse-de-test']);
    }

    /** @param array<string, mixed>|null $corps */
    private function requete(string $methode, string $chemin, ?array $corps = null): void
    {
        $this->client->request(
            $methode,
            $chemin,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $this->ip],
            content: null !== $corps ? json_encode($corps, \JSON_THROW_ON_ERROR) : null,
        );
    }

    /** @return array<string, mixed> */
    private function charge(): array
    {
        $decode = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decode, 'La réponse doit être un objet JSON.');

        /* @var array<string, mixed> $decode */
        return $decode;
    }

    private function reglerEntier(string $cle, int $valeur, int $min, int $max): void
    {
        $this->em()->persist(new Setting($cle, (string) $valeur, SettingType::Entier, $cle, null, $min, $max));
        $this->em()->flush();
    }

    private function viderLesCaches(): void
    {
        foreach (['cache.rate_limiter', 'cache.app'] as $identifiant) {
            $pool = self::getContainer()->get($identifiant);

            if ($pool instanceof CacheItemPoolInterface) {
                $pool->clear();
            }
        }
    }

    private function repartirDUneBaseVierge(): void
    {
        $em = $this->em();
        $connexion = $em->getConnection();

        $connexion->executeStatement('PRAGMA foreign_keys = OFF');
        foreach ($connexion->createSchemaManager()->listTableNames() as $table) {
            $connexion->executeStatement(\sprintf('DROP TABLE IF EXISTS "%s"', $table));
        }
        $connexion->executeStatement('PRAGMA foreign_keys = ON');

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }
}
