<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Entity\User;
use App\Module\Core\Enum\PorteeDeJeton;
use App\Module\Core\Security\JetonDApiAuthentifie;
use App\Module\Core\Service\QuotasDUsage;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Repository\MediaRepository;
use App\Module\Media\Security\FabriqueDeLecteur;
use App\Module\Media\Service\QuotasDesMedias;
use App\Module\Tree\Entity\Person;
use App\Tests\Core\CoreWebTestCase;
use App\Tests\Membership\Double\ArbreEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UN TÉLÉVERSEMENT SOUS LE JETON DES AGENTS NE PUBLIE RIEN (ADR 0021)
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * Le jeton « Claude — agents » est de portée `contribution`, sur le compte du
 * commanditaire, qui est **administrateur**. Or le dépôt d'un administrateur est une
 * décision : il naît `actif`, remplace le portrait que toute la famille voit, et échappe
 * au plafond de 20 dépôts par heure. Sous ce jeton, `POST /api/medias` publiait donc
 * directement sur les vraies fiches — ce que la portée, qui dépose « sans décider », ne
 * permet nulle part ailleurs.
 *
 * La règle éprouvée ici ({@see FabriqueDeLecteur::deposant()}) : sous un jeton de portée
 * inférieure à `validation`, l'administrateur dépose **comme un membre**. Média
 * `en_attente`, portrait inchangé, quota compté — et la pièce n'est publiée qu'à la
 * validation de la demande `ajout_media` qui la désigne, par la session principale. Sous un
 * jeton `validation`, et par cookie, rien ne change.
 *
 * ── Ce qui est mesuré, et comment ───────────────────────────────────────────────
 *
 * Les dépôts sont réels (octets JPEG, purge, vignette), sur une fiche de défunte que le
 * cercle laisse voir : c'est la réponse **et** la base qui disent le statut et le
 * portrait. Les tests de quota envoient des dépôts **sans fichier**, comme
 * {@see PlafondDeDepotTest} : le limiteur passe avant la lecture du fichier, un dépôt à
 * vide consomme donc son coup puis rend `422`.
 *
 * Un jeton neuf **tous les dix dépôts** : un jeton n'a droit qu'à 12 requêtes par heure en
 * test (`app.core.jeton_api.requetes_par_heure`). Ce n'est pas une gêne, c'est une preuve
 * de plus — le plafond des dépôts se compte **par compte**, et changer de jeton ne le
 * remet pas à zéro.
 *
 * Aucun jeton n'est écrit en littéral ({@see CoreWebTestCase::creerJetonDApi()}).
 */
final class MediasParJetonEnHttpTest extends CoreWebTestCase
{
    /** § 1.9 du contrat. */
    private const int PLAFOND = 20;

    /** La fiche sur laquelle tout se dépose : une défunte, que tout lecteur voit. */
    private int $aieule;

    /** Le jeton des dépôts à vide en cours : voir {@see self::jetonDuRang()}. */
    private ?string $jetonCourant = null;

    protected function setUp(): void
    {
        // AVANT `createClient()` : le conteneur lit `MEDIA_RACINE` au premier appel. Même
        // détournement que `Tree\TeleversementDUnePreuveHttpTest` : rien n'est écrit dans
        // l'arborescence du dépôt.
        $racine = self::racineDesMedias();
        $_ENV['MEDIA_RACINE'] = $racine;
        $_SERVER['MEDIA_RACINE'] = $racine;

        parent::setUp();

        // Le compteur des dépôts vit dans `cache.app`, que le socle ne vide pas ; sa clé est
        // l'identifiant du compte, qui repart de 1 à chaque test (voir `PlafondDeDepotTest`).
        $pool = self::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        // Le piège SQLite décrit dans `CasDeTestMedia` : la clause `WHERE` de cet index
        // partiel n'est pas transcrite, et l'index plein refuserait un second média sur la
        // fiche — exactement ce que ces tests déposent. On le recrée avec sa condition.
        $connexion = $this->em()->getConnection();
        $connexion->executeStatement('DROP INDEX IF EXISTS uniq_med_portrait_actif');
        $connexion->executeStatement(
            'CREATE UNIQUE INDEX uniq_med_portrait_actif ON med_media (subject_kind, subject_id) '
            ."WHERE kind = 'portrait' AND is_active_portrait = 1",
        );

        $this->aieule = $this->semerLaFiche();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        ArbreEnMemoire::reinitialiser();
        self::effacer(self::racineDesMedias());

        unset($_ENV['MEDIA_RACINE'], $_SERVER['MEDIA_RACINE']);
    }

    public function testSousUnJetonDeContributionUnePreuveNaitEnAttente(): void
    {
        $jeton = $this->creerJetonDApi($this->administration(), PorteeDeJeton::Contribution);

        $media = $this->televerser(MediaKind::Preuve, $jeton);

        self::assertSame(MediaStatus::EnAttente->value, $media['status'], 'Sous le jeton des agents, l’administrateur dépose comme un membre.');
        self::assertNull($media['changeRequestId']);
        self::assertSame(MediaStatus::EnAttente, $this->relire($media['id'])->getStatus(), 'La base le dit aussi.');

        // La fiche de tout le monde n'en montre rien : la liste par défaut ne rend que les `actif`.
        $this->requete('GET', '/api/medias?subjectKind=personne&subjectId='.$this->aieule, entetes: $this->bearer($jeton));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->liste(), 'Une preuve en attente n’est pas sur la fiche.');
    }

    public function testSousUnJetonDeContributionUnPortraitNeRemplacePasLActuel(): void
    {
        $administration = $this->administration();

        // Le portrait en place, posé par le commanditaire dans son navigateur.
        $this->connexion($administration->getEmail());
        self::assertResponseIsSuccessful();
        $actuel = $this->televerser(MediaKind::Portrait);
        self::assertTrue($actuel['isActivePortrait']);
        $this->client->getCookieJar()->clear();

        $jeton = $this->creerJetonDApi($administration, PorteeDeJeton::Contribution);
        $propose = $this->televerser(MediaKind::Portrait, $jeton);

        self::assertSame(MediaStatus::EnAttente->value, $propose['status']);
        self::assertFalse($propose['isActivePortrait'], 'Le portrait ne bascule qu’à la validation.');

        $enPlace = $this->portraitActif();
        self::assertNotNull($enPlace);
        self::assertSame($actuel['id'], $enPlace->getId(), 'Le portrait que la famille voit est toujours le même.');
        self::assertSame(MediaStatus::Actif, $enPlace->getStatus(), 'Et il n’est pas passé `remplace`.');
    }

    /**
     * Le chemin que suivra `deposer` (B3) : le dépôt en attente, la demande `ajout_media` qui
     * le désigne — sous le même jeton des agents —, puis la décision de la session
     * principale. C'est là, et seulement là, que le portrait bascule.
     */
    public function testLePortraitDesAgentsNEstPublieQuALaValidationDeSaDemande(): void
    {
        $administration = $this->administration();
        $agents = $this->creerJetonDApi($administration, PorteeDeJeton::Contribution);
        $principale = $this->creerJetonDApi($administration, PorteeDeJeton::Validation, 'Claude — session principale');

        $ancien = $this->televerser(MediaKind::Portrait, $principale);
        $propose = $this->televerser(MediaKind::Portrait, $agents);

        $this->requete('POST', '/api/demandes', [
            'kind' => 'ajout_media',
            'message' => null,
            'soumettre' => true,
            'ops' => [[
                'seq' => 1,
                'opKind' => 'ajout_media',
                'subjectKind' => 'media',
                'field' => null,
                'valueKind' => 'media',
                'oldValue' => null,
                'newValue' => ['mediaId' => $propose['id'], 'sha256' => $propose['sha256'], 'kind' => 'portrait'],
            ]],
        ], $this->bearer($agents));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'Un média en attente se désigne par une demande, comme celui d’un membre.');
        $demande = $this->charge()['id'];
        self::assertIsInt($demande);

        $this->assertPortraitActif($ancien['id'], 'Déposée, la demande n’a rien publié.');

        $this->requete('POST', '/api/administration/demandes/'.$demande.'/valider', [], $this->bearer($principale));
        self::assertResponseIsSuccessful();

        $this->assertPortraitActif($propose['id'], 'Validée par la session principale, la demande publie le portrait.');
        self::assertSame(MediaStatus::Remplace, $this->relire($ancien['id'])->getStatus());
    }

    public function testSousUnJetonDeContributionLeQuotaDesMembresSApplique(): void
    {
        $administration = $this->administration();

        for ($rang = 1; $rang <= self::PLAFOND; ++$rang) {
            $this->tenterUnDepotAVide($this->jetonDuRang($rang, $administration, PorteeDeJeton::Contribution));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, \sprintf('Dépôt n° %d : dans le plafond.', $rang));
        }

        $this->tenterUnDepotAVide($this->jetonDuRang(self::PLAFOND + 1, $administration, PorteeDeJeton::Contribution));
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'Le vingt et unième dépôt sous le jeton des agents est refusé (§ 1.9).');
        self::assertSame('trop_de_tentatives', $this->charge()['erreur']['code']);
        self::assertNotEmpty($this->client->getResponse()->headers->get('Retry-After'));

        self::assertSame(0, $this->coupsRestants($administration), 'C’est le compteur du compte qui est à sec.');
    }

    public function testSousUnJetonDeValidationLAdministrateurDeposeCommeAvant(): void
    {
        $administration = $this->administration();
        $jeton = $this->creerJetonDApi($administration, PorteeDeJeton::Validation, 'Claude — session principale');

        $media = $this->televerser(MediaKind::Portrait, $jeton);
        self::assertSame(MediaStatus::Actif->value, $media['status'], 'La session principale décide : son dépôt est direct (§ 7.1).');
        self::assertTrue($media['isActivePortrait']);

        $this->assertSansPlafond($administration, PorteeDeJeton::Validation);
    }

    public function testParCookieLAdministrateurDeposeCommeAvant(): void
    {
        $administration = $this->administration();
        $this->connexion($administration->getEmail());
        self::assertResponseIsSuccessful();

        $media = $this->televerser(MediaKind::Portrait);
        self::assertSame(MediaStatus::Actif->value, $media['status']);
        self::assertTrue($media['isActivePortrait']);

        $this->assertSansPlafond($administration);
    }

    /**
     * La règle elle-même, portée par portée, sans HTTP : la **portée** décide, jamais le
     * libellé — un jeton `contribution` nommé comme celui de la session principale reste un
     * jeton qui ne décide pas — et un membre n'y gagne rien.
     *
     * @return iterable<string, array{list<string>, PorteeDeJeton|null, string, bool}>
     */
    public static function deposants(): iterable
    {
        yield 'administrateur, par cookie' => [['ROLE_ADMIN'], null, '', true];
        yield 'administrateur, jeton validation' => [['ROLE_ADMIN'], PorteeDeJeton::Validation, 'Claude — session principale', true];
        yield 'administrateur, jeton contribution' => [['ROLE_ADMIN'], PorteeDeJeton::Contribution, 'Claude — agents', false];
        yield 'administrateur, jeton contribution au libellé trompeur' => [['ROLE_ADMIN'], PorteeDeJeton::Contribution, 'Claude — session principale', false];
        yield 'administrateur, jeton lecture' => [['ROLE_ADMIN'], PorteeDeJeton::Lecture, 'Lecture seule', false];
        yield 'membre, jeton validation' => [[], PorteeDeJeton::Validation, 'Claude — session principale', false];
    }

    /** @param list<string> $roles */
    #[DataProvider('deposants')]
    public function testLaPorteeDuJetonDecideSeuleDuDepotDirect(array $roles, ?PorteeDeJeton $portee, string $libelle, bool $administrateur): void
    {
        $compte = $this->creerUtilisateur('compte@example.net', roles: $roles, nomAffiche: 'Compte');
        $jetonDeSecurite = new PostAuthenticationToken($compte, 'api_jetons', $compte->getRoles());

        if (null !== $portee) {
            $jetonDeSecurite->setAttributes((new JetonDApiAuthentifie(1, $libelle, $portee))->attributs());
        }

        $stockage = new TokenStorage();
        $stockage->setToken($jetonDeSecurite);
        $fabrique = new FabriqueDeLecteur($stockage);

        self::assertSame($administrateur, $fabrique->deposant($compte)->administrateur);
        self::assertSame(\in_array('ROLE_ADMIN', $roles, true), $fabrique->depuisUtilisateur($compte)->administrateur, 'La lecture, elle, garde le rôle du compte.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /** Le compte du commanditaire, celui des deux jetons du plan : actif, administrateur, sans fiche. */
    private function administration(): User
    {
        return $this->creerUtilisateur('administration@example.net', roles: ['ROLE_ADMIN'], nomAffiche: 'Administration');
    }

    /**
     * Un dépôt réel sur la fiche de l'aïeule, par le jeton `$jeton` ou, à défaut, par le
     * cookie du client. Rend la charge du média, après avoir exigé le `201`.
     *
     * @return array<string, mixed>
     */
    private function televerser(MediaKind $nature, ?string $jeton = null): array
    {
        $chemin = self::racineDesMedias().'/entree-'.bin2hex(random_bytes(6));

        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0o777, true);
        }

        file_put_contents($chemin, FabriqueDeFichiers::jpegNu());

        $this->client->request(
            'POST',
            '/api/medias',
            parameters: [
                'kind' => $nature->value,
                'subjectKind' => MediaSubject::Personne->value,
                'subjectId' => (string) $this->aieule,
            ],
            files: ['fichier' => new UploadedFile($chemin, 'acte.jpg', 'image/jpeg', null, true)],
            server: $this->serveur($jeton),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->charge();
    }

    /**
     * Le jeton du `$rang`-ième dépôt à vide : un neuf tous les dix (voir l'en-tête), de la
     * même portée, sur le même compte.
     */
    private function jetonDuRang(int $rang, User $compte, PorteeDeJeton $portee): string
    {
        if (1 === $rang % 10 || null === $this->jetonCourant) {
            $this->jetonCourant = $this->creerJetonDApi($compte, $portee, 'Jeton n° '.intdiv($rang, 10));
        }

        return $this->jetonCourant;
    }

    /** Un dépôt sans fichier : il consomme son coup, puis rend `422`. */
    private function tenterUnDepotAVide(?string $jeton = null): void
    {
        $this->client->request('POST', '/api/medias', server: $this->serveur($jeton));
    }

    /** @return array<string, string> */
    private function serveur(?string $jeton): array
    {
        $serveur = ['HTTP_ACCEPT' => 'application/json'];

        if (null !== $jeton) {
            $serveur['HTTP_AUTHORIZATION'] = 'Bearer '.$jeton;
        }

        return $serveur;
    }

    /** @return array{Authorization: string} */
    private function bearer(string $jeton): array
    {
        return ['Authorization' => 'Bearer '.$jeton];
    }

    /**
     * Vingt-six dépôts à vide, aucun `429`, et le compteur intact : l'exemption de
     * l'administrateur n'a pas bougé (26 et non 21 : voir `PlafondDeDepotTest`).
     */
    private function assertSansPlafond(User $compte, ?PorteeDeJeton $portee = null): void
    {
        for ($rang = 1; $rang <= self::PLAFOND + 6; ++$rang) {
            $this->tenterUnDepotAVide(null === $portee ? null : $this->jetonDuRang($rang, $compte, $portee));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, \sprintf('Dépôt n° %d : l’administrateur qui décide n’est pas limité en nombre.', $rang));
        }

        self::assertSame(self::PLAFOND, $this->coupsRestants($compte), 'Aucun coup n’a été compté.');
    }

    private function assertPortraitActif(mixed $mediaId, string $message): void
    {
        $actif = $this->portraitActif();
        self::assertNotNull($actif, $message);
        self::assertSame($mediaId, $actif->getId(), $message);
    }

    private function portraitActif(): ?Media
    {
        $this->em()->clear();
        $depot = self::getContainer()->get(MediaRepository::class);
        \assert($depot instanceof MediaRepository);

        return $depot->portraitActif($this->aieule);
    }

    private function relire(mixed $mediaId): Media
    {
        $this->em()->clear();
        $media = $this->em()->find(Media::class, $mediaId);
        self::assertInstanceOf(Media::class, $media);

        return $media;
    }

    /** @return list<mixed> la liste de médias, un tableau nu (§ 1.5) */
    private function liste(): array
    {
        $contenu = $this->client->getResponse()->getContent();
        self::assertIsString($contenu);
        $decode = json_decode($contenu, true);
        self::assertIsArray($decode);
        self::assertTrue(array_is_list($decode));

        return $decode;
    }

    /** Les coups restants du compte dans sa fenêtre, lus sans en consommer (`consume(0)`). */
    private function coupsRestants(User $compte): int
    {
        $quotas = self::getContainer()->get(QuotasDUsage::class);
        $declares = self::getContainer()->get(QuotasDesMedias::class);
        \assert($quotas instanceof QuotasDUsage && $declares instanceof QuotasDesMedias);

        return $quotas->limiteur($declares->televersements(), (int) $compte->getId())->consume(0)->getRemainingTokens();
    }

    /**
     * Une défunte dans `tree_person` — que `Tree` résout comme sujet — et dans l'arbre en
     * mémoire du cercle, où l'inconnu passerait pour vivant, donc masqué.
     */
    private function semerLaFiche(): int
    {
        $personne = (new Person('Marne Dolomite Sureau'))
            ->setGivenNames('Marne Dolomite')
            ->setSurname('Sureau')
            ->setDeceased(true)
            ->setBirth(UncertainDate::inconnue())
            ->setDeath(UncertainDate::inconnue());

        $em = $this->em();
        $em->persist($personne);
        $em->flush();

        $id = $personne->getId();
        self::assertNotNull($id);

        ArbreEnMemoire::reinitialiser();
        ArbreEnMemoire::ajouterPersonne($id, 'Marne Dolomite', 'Sureau', vivante: false);

        return $id;
    }

    /** Hors du dépôt, et suffixée du numéro de processus comme le reste du harnais. */
    private static function racineDesMedias(): string
    {
        return \dirname(__DIR__, 2).'/var/test-medias-jetons-'.getmypid();
    }

    private static function effacer(string $chemin): void
    {
        if (is_file($chemin)) {
            @unlink($chemin);

            return;
        }

        if (!is_dir($chemin)) {
            return;
        }

        foreach (scandir($chemin) ?: [] as $entree) {
            if ('.' !== $entree && '..' !== $entree) {
                self::effacer($chemin.'/'.$entree);
            }
        }

        @rmdir($chemin);
    }
}
