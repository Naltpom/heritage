<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Tests\Proposal\CasDeTestDemandesHttp;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  JOINDRE UNE PIÈCE À UNE PERSONNE PROPOSÉE — qui le peut, de bout en bout.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * ## La faille
 *
 * `POST /api/medias` accepte, à la place d'un `subjectId`, le couple `demandeId` +
 * `brouillonRef` : la pièce est jointe à une personne **pas encore créée**. Jusqu'au
 * 09/10/2026, `Media\Service\TeleversementMedia` vérifiait la **forme** de ce couple et
 * rien d'autre. Or à la validation, `Proposal\Service\ApplicateurDeCreation` rattache
 * tous les médias du brouillon à la personne créée : n'importe quel membre pouvait glisser
 * une pièce dans la proposition d'un autre — un numéro de demande se devine, `p1` est la
 * référence de presque toutes —, et elle arrivait sur la fiche validée comme si l'auteur
 * l'avait jointe.
 *
 * ## Pourquoi par HTTP
 *
 * Les deux moitiés sont testées chacune chez elle — la règle dans
 * `tests/Proposal/FichesProposeesPourLesModulesTest.php`, la traduction du verdict et
 * l'ordre des contrôles dans `tests/Media/TeleversementMediaTest.php`. Aucune des deux ne
 * voit **la couture** : l'alias de `Core\Contract\FichesProposeesInterface` (un repli
 * fermé dans `core.yaml`, l'implémentation dans `proposal.yaml`, et c'est l'ordre de
 * chargement qui tranche), le `LecteurMedia` construit depuis la session, et l'enveloppe
 * d'erreur que le front lit. Ce fichier part d'un vrai compte connecté, d'une vraie
 * demande déposée par la route, et d'un vrai fichier ; il lit la réponse, la table et le
 * disque.
 *
 * ## Le disque est compté
 *
 * Un refus doit tomber **avant** le fichier : rien lu, rien ré-encodé, rien déposé. Le
 * stockage est détourné vers un dossier de `var/` propre à ce processus (comme
 * `Tree\TeleversementDUnePreuveHttpTest`), les fichiers envoyés sont écrits **ailleurs**,
 * et chaque refus vérifie que ce dossier est resté vide.
 */
final class TeleversementSurFicheProposeeTest extends CasDeTestDemandesHttp
{
    private const string OPALE = 'opale@example.net';
    private const string TUF = 'tuf@example.net';
    private const string ADMINISTRATION = 'natron@example.net';

    /** `uniq_mbr_member_person` : deux comptes ne réclament pas la même personne. */
    private const int PERSONNE_OPALE = 41;
    private const int PERSONNE_TUF = 42;

    /** @var list<string> les fichiers envoyés, écrits hors du stockage et effacés en fin de cas */
    private array $envois = [];

    protected function setUp(): void
    {
        // AVANT `createClient()` : `%env(default:…:MEDIA_RACINE)%` est lu par le
        // conteneur au premier appel. Sans ce détour, le test écrirait dans `uploads/`.
        self::effacer(self::racineDesMedias());
        $_ENV['MEDIA_RACINE'] = self::racineDesMedias();
        $_SERVER['MEDIA_RACINE'] = self::racineDesMedias();

        parent::setUp();

        $this->creerContributeur(self::OPALE, self::PERSONNE_OPALE, 'Opale Romarin');
        $this->creerContributeur(self::TUF, self::PERSONNE_TUF, 'Tuf Romarin');
        $this->creerUtilisateur(self::ADMINISTRATION, roles: ['ROLE_ADMIN'], nomAffiche: 'Natron Romarin');
    }

    protected function tearDown(): void
    {
        foreach ($this->envois as $chemin) {
            @unlink($chemin);
        }

        self::effacer(self::racineDesMedias());

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        unset($_ENV['MEDIA_RACINE'], $_SERVER['MEDIA_RACINE']);

        parent::tearDownAfterClass();
    }

    /** Une demande qui n'existe pas : `404`, et rien sur le disque ni en base. */
    public function testUneDemandeInexistanteRend404(): void
    {
        $this->connexion(self::OPALE);

        $this->televerserSurLaFiche(4242, 'p1');

        $this->assertIntrouvableSansRienEcrire();
    }

    /**
     * **La faille elle-même** : Tuf joint une pièce à la proposition d'Opale. `404` —
     * le même que pour une demande inexistante, pour qu'aucune réponse n'apprenne à Tuf
     * qu'un numéro est pris. Sur sa demande envoyée comme sur son brouillon.
     */
    public function testLaFicheProposeeDUnAutreMembreRend404(): void
    {
        $this->connexion(self::OPALE);
        $enAttente = $this->proposer();
        $brouillon = $this->proposer(soumettre: false);
        $this->deconnexion();

        $this->connexion(self::TUF);

        $this->televerserSurLaFiche($enAttente, 'p1');
        $this->assertIntrouvableSansRienEcrire();

        $this->televerserSurLaFiche($brouillon, 'p1');
        $this->assertIntrouvableSansRienEcrire();
    }

    /**
     * Sur **sa** demande tranchée — refusée pendant qu'il choisissait sa photo — l'auteur
     * reçoit `409 demande_deja_decidee`, le code que le front sait déjà traiter (« relire »).
     * Un autre membre, sur la même demande, reçoit toujours `404` : la réponse ne lui dit
     * ni qu'elle existe, ni qu'elle a été tranchée.
     */
    public function testUneDemandeTrancheeRend409ASonAuteurEt404AuxAutres(): void
    {
        $this->connexion(self::OPALE);
        $demande = $this->proposer();
        $this->deconnexion();

        $this->connexion(self::ADMINISTRATION);
        $this->requete('POST', '/api/administration/demandes/'.$demande.'/refuser', ['message' => 'Cette personne est déjà dans l’arbre.']);
        self::assertResponseIsSuccessful('Le refus doit réussir avant le reste du scénario.');
        $this->deconnexion();

        $this->connexion(self::OPALE);
        $this->televerserSurLaFiche($demande, 'p1');

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $erreur = $this->erreur();
        self::assertSame('demande_deja_decidee', $erreur['code']);
        self::assertNull($erreur['details'], '`Media` ne connaît pas les statuts de `Proposal`, seulement le verdict.');
        $this->assertRienEcrit();
        $this->deconnexion();

        $this->connexion(self::TUF);
        $this->televerserSurLaFiche($demande, 'p1');
        $this->assertIntrouvableSansRienEcrire();
    }

    /**
     * Une référence qui ne désigne **aucune fiche de cette demande** : `404`, même pour
     * son auteur, même pour un administrateur. `p2` n'existe pas dans une demande qui ne
     * propose qu'une personne.
     */
    public function testUneReferenceInconnueRend404(): void
    {
        $this->connexion(self::OPALE);
        $demande = $this->proposer();

        $this->televerserSurLaFiche($demande, 'p2');
        $this->assertIntrouvableSansRienEcrire();
        $this->deconnexion();

        $this->connexion(self::ADMINISTRATION);
        $this->televerserSurLaFiche($demande, 'p2');
        $this->assertIntrouvableSansRienEcrire();
    }

    /**
     * **L'administrateur instruit les demandes en attente** : il y joint une pièce. Pas
     * au brouillon d'un autre — un brouillon non envoyé n'est visible que de son auteur
     * (§ 5.7) —, qui lui rend `404` comme à n'importe qui.
     */
    public function testLAdministrateurJointUnePieceAUneDemandeEnAttenteMaisPasAUnBrouillon(): void
    {
        $this->connexion(self::OPALE);
        $enAttente = $this->proposer();
        $brouillon = $this->proposer(soumettre: false);
        $this->deconnexion();

        $this->connexion(self::ADMINISTRATION);

        $this->televerserSurLaFiche($brouillon, 'p1');
        $this->assertIntrouvableSansRienEcrire();

        $this->televerserSurLaFiche($enAttente, 'p1');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $media = $this->seulMedia();
        self::assertSame($enAttente, $media->getSubjectRequestId());
        self::assertSame('p1', $media->getSubjectDraftRef());
        self::assertNull($media->getSubjectId(), 'La personne n’existe pas encore : la pièce attend sur sa référence.');
        self::assertNotSame([], $this->fichiersStockes(), 'Le dépôt accepté, lui, écrit bien son fichier.');
    }

    /**
     * **Le parcours normal** : l'auteur joint l'acte à sa fiche **en brouillon**, puis,
     * l'ayant envoyée, en joint un second à sa demande **en attente**. Les deux pièces
     * attendent sur la référence, au statut d'un dépôt de membre.
     */
    public function testLAuteurJointUnePieceASonBrouillonPuisASaDemandeEnAttente(): void
    {
        $this->connexion(self::OPALE);
        $demande = $this->proposer(soumettre: false);

        $this->televerserSurLaFiche($demande, 'p1');
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'L’auteur joint une pièce à son brouillon.');

        $this->requete('POST', '/api/demandes/'.$demande.'/soumettre');
        self::assertResponseIsSuccessful('La soumission doit réussir avant le reste du scénario.');

        $this->televerserSurLaFiche($demande, 'p1', FabriqueDeFichiers::jpegNu(64, 48));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'Et à sa demande envoyée.');

        $medias = $this->medias();
        self::assertCount(2, $medias);

        foreach ($medias as $media) {
            self::assertSame($demande, $media->getSubjectRequestId());
            self::assertSame('p1', $media->getSubjectDraftRef());
            self::assertSame(MediaStatus::EnAttente, $media->getStatus(), 'Le dépôt d’un membre naît en attente.');
        }
    }

    /**
     * **Le refus tombe avant tout dépôt de fichier.** Tuf envoie, sur la fiche d'Opale, un
     * texte déguisé en image — que le contrôle de type refuserait en `415`. C'est le `404`
     * qui sort : la question a été posée avant que le moindre octet soit lu, et le
     * stockage est resté vide.
     *
     * Si quelqu'un déplaçait le contrôle après `controlerLeFichier()`, ce cas rendrait
     * `415` et rougirait — c'est ce qui le rend capable d'échouer.
     */
    public function testLeRefusTombeAvantToutContactAvecLeFichier(): void
    {
        $this->connexion(self::OPALE);
        $demande = $this->proposer();
        $this->deconnexion();

        $this->connexion(self::TUF);
        $this->televerserSurLaFiche($demande, 'p1', FabriqueDeFichiers::texteDeguiseEnImage());

        $this->assertIntrouvableSansRienEcrire();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Dépose, **par la route**, une création de personne du compte connecté, et rend son
     * identifiant. Une seule fiche, `#p1`, mariée à la fiche de référence du socle.
     */
    private function proposer(bool $soumettre = true): int
    {
        $this->requete('POST', '/api/demandes', [
            'kind' => 'creation_personne',
            'soumettre' => $soumettre,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => 'Émeri',
                'surname' => 'Muguet',
                'sex' => 'masculin',
                'deceased' => true,
            ]],
            'liens' => [[
                'seq' => 1,
                'kind' => 'conjoint_de',
                'subjectDraftRef' => '#p1',
                'otherPersonId' => self::PERSONNE,
            ]],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'Le dépôt de la création doit réussir avant le reste du scénario.');

        $charge = $this->charge();
        self::assertIsInt($charge['id']);
        self::assertSame($soumettre ? 'en_attente' : 'brouillon', $charge['status']);

        return $charge['id'];
    }

    /**
     * Le `multipart/form-data` du § 7.1, tel que le front l'envoie
     * (`app/src/lib/api/medias.ts`) : `demandeId` et `brouillonRef` **sans dièse**.
     */
    private function televerserSurLaFiche(int $demandeId, string $reference, ?string $octets = null): void
    {
        $chemin = sys_get_temp_dir().'/heritage-envoi-'.bin2hex(random_bytes(6)).'.jpg';
        file_put_contents($chemin, $octets ?? FabriqueDeFichiers::jpegNu());
        $this->envois[] = $chemin;

        $this->client->request(
            'POST',
            '/api/medias',
            parameters: [
                'kind' => MediaKind::Preuve->value,
                'subjectKind' => MediaSubject::Personne->value,
                'demandeId' => (string) $demandeId,
                'brouillonRef' => $reference,
            ],
            // `test: true` : le fichier n'est pas passé par `move_uploaded_file()`.
            files: ['fichier' => new UploadedFile($chemin, 'acte-de-mariage.jpg', 'image/jpeg', null, true)],
            server: ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    private function assertIntrouvableSansRienEcrire(): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('introuvable', $this->erreur()['code']);
        $this->assertRienEcrit();
    }

    private function assertRienEcrit(): void
    {
        self::assertSame([], $this->medias(), 'Un dépôt refusé ne laisse aucune ligne.');
        self::assertSame([], $this->fichiersStockes(), 'Un dépôt refusé n’écrit rien sur le disque.');
    }

    private function seulMedia(): Media
    {
        $medias = $this->medias();
        self::assertCount(1, $medias);

        return $medias[0];
    }

    /** @return list<Media> */
    private function medias(): array
    {
        $em = $this->em();
        $em->clear();

        /** @var list<Media> $medias */
        $medias = $em->getRepository(Media::class)->findBy([], ['id' => 'ASC']);

        return $medias;
    }

    /** @return list<string> tout ce que le stockage porte, originaux et vignettes confondus */
    private function fichiersStockes(): array
    {
        if (!is_dir(self::racineDesMedias())) {
            return [];
        }

        $fichiers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::racineDesMedias(), \FilesystemIterator::SKIP_DOTS)) as $fichier) {
            \assert($fichier instanceof \SplFileInfo);
            $fichiers[] = $fichier->getPathname();
        }

        return $fichiers;
    }

    /** Hors du dépôt, et suffixée du numéro de processus comme le reste du harnais. */
    private static function racineDesMedias(): string
    {
        return \dirname(__DIR__, 2).'/var/test-medias-fiches-proposees-'.getmypid();
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
