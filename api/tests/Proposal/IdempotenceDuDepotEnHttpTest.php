<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Module\Membership\Enum\MemberStatus;
use App\Tests\Core\CoreWebTestCase;
use App\Tests\Proposal\Double\ArbreEnMemoire;
use App\Tests\Proposal\Double\NotificateurEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'idempotence du dépôt en HTTP : `POST /api/demandes` avec `Idempotency-Key` (§ 6.1 bis
 * du contrat d'API, ADR 0022).
 *
 * Ce que l'en-tête promet, et que chaque test tient :
 *
 * | Promesse | Test |
 * |---|---|
 * | sans l'en-tête, rien ne change | {@see self::testSansEnTeteDeuxDepotsFontDeuxDemandes()} |
 * | même clé, même corps : `200`, la même demande, `Idempotency-Replayed: true`, rien d'écrit | {@see self::testUnRejeuRend200AvecLaMemeDemandeEtNeCreeRien()} |
 * | la clé est au compte, pas au porteur ; celle d'un autre compte ne se rejoue pas | les deux tests sur la portée de la clé |
 * | le rejeu rend l'état courant | {@see self::testLeRejeuRendLEtatCourant()} |
 * | même clé, autre corps : `422 idempotence_corps_different` | {@see self::testLaMemeCleAvecUnAutreCorpsRend422()} |
 * | une clé mal formée : `422`, rien d'écrit | {@see self::testUneCleMalFormeeRend422EtNEcritRien()} |
 * | le rejeu repasse par la bascule ; un dépôt refusé ne consomme pas la clé | les deux tests sur les garde-fous |
 *
 * La course entre deux requêtes simultanées ne se joue pas sur SQLite : elle est éprouvée au
 * niveau du service par {@see DepotConcurrentDeMemeCleTest}, et l'index qui la tranche par
 * {@see IndexDIdempotencePostgresTest}. Par défaut, tout passe par un jeton `contribution` du
 * compte d'administration : c'est le client que l'en-tête sert, un agent qui rejoue ses plans.
 */
final class IdempotenceDuDepotEnHttpTest extends CoreWebTestCase
{
    /** Marne Dolomite Sureau, la fiche que les demandes modifient. */
    private const int PERSONNE = 17;

    private const int VERSION_DE_DEPART = 7;

    /** La personne d'Opale, membre de la famille : distincte de la fiche modifiée. */
    private const int PERSONNE_D_OPALE = 41;

    protected function setUp(): void
    {
        parent::setUp();

        // L'arbre et le notificateur des tests de `Proposal`, que `when@test` branche à la
        // place des vrais (`config/services/proposal.yaml`). Leur état est statique : on le
        // remet à zéro ici.
        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();
        $this->arbre()->poser(SubjectKind::Personne, self::PERSONNE, 'Marne Dolomite Sureau', self::VERSION_DE_DEPART, [
            'givenNames' => 'Marne Dolomite',
            'surname' => 'Sureau',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'feminin',
            'deceased' => true,
            'birth' => new UncertainDate('1987', DateQualifier::Annee, new \DateTimeImmutable('1987-01-01'), new \DateTimeImmutable('1987-12-31')),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);
    }

    protected function tearDown(): void
    {
        ArbreEnMemoire::oublierTout();
        NotificateurEnMemoire::oublierTout();

        parent::tearDown();
    }

    public function testSansEnTeteDeuxDepotsFontDeuxDemandes(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        foreach ([1, 2] as $fois) {
            $this->deposer($corps, $this->bearer($jeton));
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'dépôt n° '.$fois);
            self::assertNull($this->rejoue(), 'dépôt n° '.$fois);
        }

        self::assertSame(2, $this->nombreDeDemandes());
    }

    public function testUnRejeuRend200AvecLaMemeDemandeEtNeCreeRien(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->deposer($corps, $this->bearer($jeton) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->rejoue(), 'Absent du `201`, et non `false`.');
        $premiere = $this->charge();
        $lignesDuJournal = \count($this->journal());

        $this->deposer($corps, $this->bearer($jeton) + $this->idempotence($this->cle()));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('true', $this->rejoue());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame($premiere['id'], $this->charge()['id']);
        self::assertSame(array_keys($premiere), array_keys($this->charge()), 'La même représentation que le `201`.');

        self::assertSame(1, $this->nombreDeDemandes());
        self::assertCount(1, $this->notificateur()->de('demande_soumise'), 'Le rejeu ne notifie pas une seconde fois.');
        self::assertCount($lignesDuJournal, $this->journal(), 'Le rejeu n’écrit rien, pas même au journal.');
    }

    /** ADR 0022 § 2 : un plan rejoué par l'autre jeton du même compte retrouve sa demande. */
    public function testLaCleEstCelleDuCompteQuelQueSoitLePorteur(): void
    {
        $administration = $this->administration();
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->deposer($corps, $this->bearer($this->creerJetonDApi($administration)) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->charge()['id'];

        $validation = $this->creerJetonDApi($administration, PorteeDeJeton::Validation, 'Claude — session principale');
        $this->deposer($corps, $this->bearer($validation) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($id, $this->charge()['id']);

        $this->connexion('administration@example.net');
        $this->deposer($corps, $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($id, $this->charge()['id']);

        self::assertSame(1, $this->nombreDeDemandes());
    }

    /** Aucune réponse n'est stockée : l'appelant apprend le sort de sa demande. */
    public function testLeRejeuRendLEtatCourant(): void
    {
        $administration = $this->administration();
        $jeton = $this->creerJetonDApi($administration);
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->deposer($corps, $this->bearer($jeton) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->charge()['id'];
        self::assertIsInt($id);

        $validation = $this->creerJetonDApi($administration, PorteeDeJeton::Validation, 'Claude — session principale');
        $this->requete('POST', '/api/administration/demandes/'.$id.'/valider', [], $this->bearer($validation));
        self::assertResponseIsSuccessful();

        $this->deposer($corps, $this->bearer($jeton) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($id, $this->charge()['id']);
        self::assertSame('validee', $this->charge()['status']);
    }

    public function testUneAutreCleFaitUneAutreDemande(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->deposer($corps, $this->bearer($jeton) + $this->idempotence($this->cle('premiere')));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $premiere = $this->charge()['id'];

        $this->deposer($corps, $this->bearer($jeton) + $this->idempotence($this->cle('seconde')));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNotSame($premiere, $this->charge()['id']);
    }

    public function testLaMemeCleAvecUnAutreCorpsRend422(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());

        $this->deposer($this->demande('surname', 'Sureau', 'SUREAU'), $this->bearer($jeton) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->charge()['id'];

        $this->deposer($this->demande('surname', 'Sureau', 'SUREAUX'), $this->bearer($jeton) + $this->idempotence($this->cle()));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->rejoue());
        $erreur = $this->erreur();
        self::assertSame('idempotence_corps_different', $erreur['code']);
        self::assertIsArray($erreur['champs']);
        self::assertArrayHasKey('Idempotency-Key', $erreur['champs']);
        self::assertSame(['demandeId' => $id], $erreur['details']);
        self::assertSame(1, $this->nombreDeDemandes());
    }

    /** Le même JSON, clés réordonnées, partout : ce n'est pas un autre corps. */
    public function testLOrdreDesClesDuCorpsNeComptePas(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->deposer($corps, $this->bearer($jeton) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->charge()['id'];

        $reordonne = array_reverse($corps, true);
        self::assertIsArray($reordonne['ops']);
        self::assertIsArray($reordonne['ops'][0]);
        $reordonne['ops'] = [array_reverse($reordonne['ops'][0], true)];
        self::assertNotSame(json_encode($corps), json_encode($reordonne), 'Le corps envoyé doit bien différer octet par octet.');

        $this->deposer($reordonne, $this->bearer($jeton) + $this->idempotence($this->cle()));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($id, $this->charge()['id']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clesMalFormees(): iterable
    {
        yield 'vide' => [''];
        yield '15 caractères' => [str_repeat('a', 15)];
        yield '129 caractères' => [str_repeat('a', 129)];
        yield 'espaces' => ['avec un espace dedans'];
    }

    #[DataProvider('clesMalFormees')]
    public function testUneCleMalFormeeRend422EtNEcritRien(string $cle): void
    {
        $jeton = $this->creerJetonDApi($this->administration());

        $this->deposer($this->demande('surname', 'Sureau', 'SUREAU'), $this->bearer($jeton) + $this->idempotence($cle));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('donnees_invalides', $this->erreur()['code']);
        self::assertIsArray($this->erreur()['champs']);
        self::assertArrayHasKey('Idempotency-Key', $this->erreur()['champs']);
        self::assertSame(0, $this->nombreDeDemandes());
    }

    /** La recherche porte sur le lecteur : la clé d'un autre compte n'est pas trouvée, rien ne dit qu'elle existe. */
    public function testLaCleDUnAutreCompteNeSeRejouePas(): void
    {
        $administration = $this->administration();
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->opale();
        $this->connexion('opale@example.net');
        $this->deposer($corps, $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $deOpale = $this->charge()['id'];

        $this->deposer($corps, $this->bearer($this->creerJetonDApi($administration)) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->rejoue());
        self::assertNotSame($deOpale, $this->charge()['id']);

        self::assertSame(2, $this->nombreDeDemandes());
    }

    /** Un rejeu est une écriture différée : il repasse par la bascule « voir comme ». */
    public function testLaBasculeVoirCommeRefuseLeRejeu(): void
    {
        $this->administration();
        $membre = $this->opale();
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->connexion('administration@example.net');
        $this->deposer($corps, $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->requete('POST', '/api/administration/voir-comme', ['memberId' => $membre]);
        self::assertResponseIsSuccessful();

        $this->deposer($corps, $this->idempotence($this->cle()));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('vue_comme_membre_active', $this->erreur()['code']);
        self::assertSame(1, $this->nombreDeDemandes());
    }

    /** Un rejeu est une écriture différée : un compte repassé en attente ne le rejoue plus. */
    public function testLeRegimeEnAttenteRefuseLeRejeu(): void
    {
        $this->opale();
        $corps = $this->demande('surname', 'Sureau', 'SUREAU');

        $this->connexion('opale@example.net');
        $this->deposer($corps, $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->em()->getConnection()->executeStatement("UPDATE mbr_member SET status = 'en_attente'");

        $this->deposer($corps, $this->idempotence($this->cle()));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('regime_en_attente', $this->erreur()['code']);
        self::assertSame(1, $this->nombreDeDemandes());
    }

    /** Seul un dépôt réussi consomme une clé : le corps corrigé passe sous la même. */
    public function testUnDepotRefuseNeConsommePasLaCle(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());
        $faux = $this->demande('surname', 'Sureau', 'SUREAU');
        $faux['message'] = 42;

        $this->deposer($faux, $this->bearer($jeton) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->deposer($this->demande('surname', 'Sureau', 'SUREAU'), $this->bearer($jeton) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testLaCleEtSonEmpreinteSontEnregistrees(): void
    {
        $jeton = $this->creerJetonDApi($this->administration());

        $this->deposer($this->demande('surname', 'Sureau', 'SUREAU'), $this->bearer($jeton) + $this->idempotence($this->cle()));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $ligne = $this->em()->getConnection()->fetchNumeric('SELECT idempotency_key, length(idempotency_fingerprint) FROM prp_change_request');
        self::assertIsArray($ligne);
        self::assertSame([$this->cle(), 64], [$ligne[0], (int) $ligne[1]]);
    }

    // ─── Outils ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>  $corps
     * @param array<string, string> $entetes
     */
    private function deposer(array $corps, array $entetes = []): void
    {
        $this->requete('POST', '/api/demandes', $corps, $entetes);
    }

    /** K : une empreinte sha256 en hexadécimal, la forme de clé que le serveur MCP enverra. */
    private function cle(string $graine = 'cle-de-test-b2'): string
    {
        return hash('sha256', $graine);
    }

    /** @return array{Idempotency-Key: string} */
    private function idempotence(string $cle): array
    {
        return ['Idempotency-Key' => $cle];
    }

    /** L'en-tête `Idempotency-Replayed` de la dernière réponse, `null` s'il est absent. */
    private function rejoue(): ?string
    {
        return $this->client->getResponse()->headers->get('Idempotency-Replayed');
    }

    private function nombreDeDemandes(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM prp_change_request');
    }

    /** @return array<string, mixed> */
    private function erreur(): array
    {
        $erreur = $this->charge()['erreur'];
        self::assertIsArray($erreur);

        /* @var array<string, mixed> $erreur */
        return $erreur;
    }

    /** Le compte du commanditaire, celui des deux jetons du plan : actif, administrateur, sans fiche. */
    private function administration(): User
    {
        return $this->creerUtilisateur('administration@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Administration');
    }

    /**
     * Opale, membre de la famille : un compte actif, rattaché à sa fiche. Rend l'identifiant
     * de son `Member`, celui que la bascule « voir comme » attend.
     */
    private function opale(): int
    {
        $opale = $this->creerUtilisateur('opale@example.net', nomAffiche: 'Opale Romarin');
        $id = $opale->getId();
        \assert(null !== $id);

        $membre = new Member($id, $opale->getEmail(), self::PERSONNE_D_OPALE, 'Opale Romarin', MatchKind::PreLie);
        $membre->setStatus(MemberStatus::Actif);
        $membre->activer();
        $this->em()->persist($membre);
        $this->em()->flush();

        $membreId = $membre->getId();
        \assert(null !== $membreId);

        return $membreId;
    }

    /** @return array<string, mixed> */
    private function demande(string $champ, ?string $ancienne, string $nouvelle): array
    {
        return [
            'kind' => 'modification_personne',
            'message' => null,
            'soumettre' => true,
            'targetKind' => 'personne',
            'targetId' => self::PERSONNE,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'champ',
                'subjectKind' => 'personne',
                'subjectId' => self::PERSONNE,
                'field' => $champ,
                'valueKind' => 'texte',
                'oldValue' => $ancienne,
                'newValue' => $nouvelle,
                'baseVersion' => self::VERSION_DE_DEPART,
            ]],
        ];
    }

    private function arbre(): ArbreEnMemoire
    {
        $arbre = self::getContainer()->get(ArbreEnMemoire::class);
        \assert($arbre instanceof ArbreEnMemoire);

        return $arbre;
    }

    private function notificateur(): NotificateurEnMemoire
    {
        $notificateur = self::getContainer()->get(NotificateurEnMemoire::class);
        \assert($notificateur instanceof NotificateurEnMemoire);

        return $notificateur;
    }

    /** @return array{Authorization: string} */
    private function bearer(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }
}
