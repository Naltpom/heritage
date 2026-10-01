<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\UserStatus;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Membership\Entity\Member;
use App\Module\Membership\Enum\MatchKind;
use App\Tests\Media\FabriqueDeFichiers;
use App\Tests\Membership\Double\ArbreEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  « AJOUTER DES PREUVES », DE BOUT EN BOUT — la dernière capacité non prouvée.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * `med_media` est **vide** sur la pile de travail : zéro ligne. Le module `Media` est
 * abondamment testé — dix-sept fichiers — mais aucun de ces tests ne passe par le
 * contrôleur : `tests/Media/CasDeTestMedia.php` câble les services à la main, pour de
 * bonnes raisons qu'il explique, et `tests/Media/RoutesMediaTest.php` ne vérifie que les
 * six `401` de l'anonyme. Le comportement nominal — **téléverser, lister, servir les
 * octets, supprimer** — n'avait jamais été atteint à travers HTTP.
 *
 * Et ce n'est pas un trou décoratif : le chemin nominal traverse quatre modules.
 *
 * ```
 *   POST /api/medias
 *     → Media\Controller           multipart, limiteur, énumérations
 *     → Media\TeleversementMedia   régime, taille, reniflage, purge EXIF, vignette
 *     → Tree\ResolutionDesSujets…  le sujet existe-t-il, qui engage-t-il
 *     → Membership\CercleDeVisib…  le déposant a-t-il le droit d'y toucher
 *     → Media\CircuitDeValidation… auto-approbation d'un dépôt d'administrateur
 *   puis, sur d'autres routes :
 *     → Tree\NormaliseurDeFiche    le portrait apparaît sur la fiche
 *     → Tree\NormaliseurDeGraphe   `avecImage` bascule sur le nœud
 * ```
 *
 * `RoutesDeLArbreTest::testLeFiltreAvecImageBasculeQuandUneImageExiste()` prouve déjà que
 * la couture `Tree` ↔ `Media` est branchée — mais il **fabrique l'entité `Media` à la
 * main**, avec un `storage_name` qui ne désigne aucun fichier. Il ne dit donc rien du
 * téléversement. Ce fichier-ci part d'octets JPEG réels et va jusqu'au booléen du graphe.
 *
 * ── Le stockage est détourné, et il est nettoyé ─────────────────────────────────
 *
 * `app.media.racine` vaut `%kernel.project_dir%/uploads` par défaut : un test qui s'en
 * contenterait écrirait dans l'arborescence du dépôt. `MEDIA_RACINE` est donc posée sur
 * un dossier de `var/`, **suffixé du numéro de processus** comme tout le reste du harnais
 * (voir `tests/bootstrap.php`), et effacée en fin de classe.
 *
 * ── Le double du cercle doit être amorcé ────────────────────────────────────────
 *
 * Même artefact que celui documenté dans `RoutesDeLArbreTest` : sous `when@test`, le
 * `PortArbre` de `Membership` est un arbre en mémoire, vide par défaut, dont
 * `estVivante()` rend `true` pour un identifiant inconnu. Sans amorce, **toutes** les
 * personnes passent pour vivantes, aucune n'est dans un cercle, et le téléversement
 * échouerait en `404` pour une raison qui n'a rien à voir avec ce qu'on éprouve.
 */
final class TeleversementDUnePreuveHttpTest extends ArbreWebTestCase
{
    /** La légende, choisie pour qu'on la retrouve telle quelle dans trois réponses. */
    private const string LEGENDE = 'Acte de naissance, Saule, 1904';

    /**
     * Les clés du § 7.2, telles qu'un **administrateur** les reçoit : la charge commune,
     * plus `originalName` et `sha256`, réservés au déposant et aux administrateurs.
     *
     * @var list<string>
     */
    private const array CLES_DU_MEDIA = [
        'id', 'kind', 'subjectKind', 'subjectId', 'subjectLabel', 'mimeType', 'byteSize',
        'width', 'height', 'pageCount', 'exifStripped', 'status', 'isActivePortrait',
        'caption', 'taken', 'uploadedByLabel', 'createdAt', 'url', 'vignetteUrl',
        'changeRequestId', 'originalName', 'sha256',
    ];

    protected function setUp(): void
    {
        // AVANT `createClient()` : `%env(default:…:MEDIA_RACINE)%` est résolu à
        // l'exécution, mais le conteneur lit l'environnement au premier appel.
        $racine = self::racineDesMedias();
        $_ENV['MEDIA_RACINE'] = $racine;
        $_SERVER['MEDIA_RACINE'] = $racine;

        parent::setUp();

        $this->amorcerLaVueDuCercle();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        ArbreEnMemoire::reinitialiser();
        self::effacer(self::racineDesMedias());

        unset($_ENV['MEDIA_RACINE'], $_SERVER['MEDIA_RACINE']);
    }

    /**
     * **Le parcours complet**, en six requêtes, sur une seule photo.
     *
     * L'administrateur téléverse un portrait ; le dépôt est auto-approuvé (§ 7.1), donc
     * `actif` et portrait actif immédiatement. On suit ensuite la même image dans quatre
     * réponses différentes — la liste, les octets, la fiche, le graphe — parce que c'est
     * la seule façon de prouver que la couture tient sur toute sa longueur.
     */
    public function testUnPortraitTeleverseRessortSurLaFicheEtFaitBasculerAvecImage(): void
    {
        $aieule = $this->idDe('aieule');

        $this->connexionDe('natron@example.net', administrateur: true);

        // ── 1. Le téléversement.
        $this->televerser(FabriqueDeFichiers::jpegAvecGps(240, 180), [
            'kind' => MediaKind::Portrait->value,
            'subjectKind' => MediaSubject::Personne->value,
            'subjectId' => (string) $aieule,
            'caption' => self::LEGENDE,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'Un dépôt qui réussit rend 201 avec le média (§ 7.1).');

        $media = $this->charge();
        self::assertSame(self::CLES_DU_MEDIA, array_keys($media), 'La charge du § 7.2, vue par un administrateur.');

        $mediaId = $media['id'];
        self::assertIsInt($mediaId);

        self::assertSame(MediaKind::Portrait->value, $media['kind']);
        self::assertSame($aieule, $media['subjectId']);
        self::assertSame(
            $this->personnes['aieule']->getDisplayName(),
            $media['subjectLabel'],
            'Le libellé du sujet est celui que `Tree` résout, jamais celui que le client annonce.',
        );
        self::assertSame('image/jpeg', $media['mimeType']);
        self::assertSame(self::LEGENDE, $media['caption']);
        self::assertSame(240, $media['width']);
        self::assertSame(180, $media['height']);
        self::assertTrue($media['exifStripped'], 'Ce qui est déposé n’est jamais le fichier reçu : c’est sa réécriture.');
        self::assertSame(MediaStatus::Actif->value, $media['status'], 'Le dépôt d’un administrateur est auto-approuvé (§ 7.1).');
        self::assertTrue($media['isActivePortrait']);
        self::assertStringStartsWith('/api/medias/'.$mediaId.'/fichier?v=', $media['url']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $media['sha256']);

        // ── 2. La liste du sujet — un tableau NU, sans enveloppe (§ 1.5).
        $liste = $this->listeDeMedias('/api/medias?subjectKind=personne&subjectId='.$aieule);
        self::assertCount(1, $liste, 'Une preuve déposée, une preuve listée.');
        self::assertSame($mediaId, $liste[0]['id']);

        // ── 3. Les octets, servis par le contrôleur — et PURGÉS.
        $octets = $this->octetsDe('/api/medias/'.$mediaId.'/fichier');

        self::assertNotSame('', $octets, 'La route de fichier doit servir le contenu, pas une enveloppe vide.');
        self::assertStringStartsWith("\xFF\xD8", $octets, 'Ce sont bien des octets JPEG qui sortent.');
        self::assertFalse(
            FabriqueDeFichiers::porteDesCoordonneesGps($octets),
            'L’image déposée portait les coordonnées GPS écrites par `jpegAvecGps()`. Ce qui sort du tuyau ne doit plus donner l’adresse de la maison.',
        );

        $reponse = $this->client->getResponse();
        self::assertSame('image/jpeg', $reponse->headers->get('Content-Type'));
        self::assertSame('nosniff', $reponse->headers->get('X-Content-Type-Options'));
        self::assertSame('"'.$media['sha256'].'"', $reponse->headers->get('ETag'), 'L’`ETag` est le sha256 du fichier stocké, pas un horodatage.');

        // ── 4. La vignette a sa propre empreinte : deux fichiers, deux `ETag`.
        $vignette = $this->octetsDe('/api/medias/'.$mediaId.'/vignette');
        self::assertNotSame('', $vignette);
        self::assertSame('"v'.$media['sha256'].'"', $this->client->getResponse()->headers->get('ETag'));

        // ── 5. La fiche de la personne porte le portrait.
        $this->requete('/api/arbre/personnes/'.$aieule);
        self::assertResponseIsSuccessful();

        $fiche = $this->charge();
        self::assertArrayHasKey('portrait', $fiche, 'Une fiche dont la personne a un portrait actif le sert.');
        self::assertIsArray($fiche['portrait']);
        self::assertSame($mediaId, $fiche['portrait']['mediaId']);
        self::assertSame(self::LEGENDE, $fiche['portrait']['caption']);

        // Les médias de la fiche portent la date du dépôt — la même que la charge du
        // § 7.2 : l'onglet Historique de la fiche les range par elle.
        self::assertIsArray($fiche['medias']);
        self::assertCount(1, $fiche['medias']);
        self::assertSame($media['createdAt'], $fiche['medias'][0]['createdAt']);

        // ── 6. `avecImage` bascule sur le nœud, et sur lui seul.
        $noeuds = $this->noeudsDuGraphe();

        self::assertTrue($noeuds['p'.$aieule]['avecImage'], 'La capacité « ajouter des preuves » se voit jusque dans le filtre du graphe.');
        self::assertFalse($noeuds['p'.$this->idDe('aieul')]['avecImage'], 'Et une personne sans preuve le dit aussi.');
    }

    /**
     * **Le dépôt d'un membre naît `en_attente`** et ne touche pas au portrait.
     *
     * C'est la règle qui distingue ce module d'un simple téléversement de fichiers :
     * « un membre remplacerait le portrait d'une fiche sans que personne l'ait
     * approuvé — c'est-à-dire qu'il écrirait dans l'arbre, ce que l'ADR 0004 lui
     * interdit ». On la vérifie ici **par la réponse**, et par ce que le graphe continue
     * de dire.
     */
    public function testLeDepotDUnMembreNaitEnAttenteEtNeBasculePasLePortrait(): void
    {
        $aieule = $this->idDe('aieule');

        $this->rattacherAUnePersonne('cobalt@example.net', 'ego');
        $this->connexionDe('cobalt@example.net');

        $this->televerser(FabriqueDeFichiers::jpegNu(120, 90), [
            'kind' => MediaKind::Portrait->value,
            'subjectKind' => MediaSubject::Personne->value,
            'subjectId' => (string) $aieule,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $media = $this->charge();
        self::assertSame(MediaStatus::EnAttente->value, $media['status']);
        self::assertFalse($media['isActivePortrait'], 'Le portrait ne bascule qu’à la validation, et à un seul endroit du module.');

        // La liste par défaut ne rend que les `actif` : la preuve en attente n'est pas
        // encore sur la fiche de tout le monde.
        self::assertSame(
            [],
            $this->listeDeMedias('/api/medias?subjectKind=personne&subjectId='.$aieule),
            'Un dépôt en attente n’apparaît pas dans la liste publique du sujet.',
        );

        // …mais son déposant retrouve le sien.
        $siennes = $this->listeDeMedias('/api/medias?subjectKind=personne&subjectId='.$aieule.'&status=en_attente');
        self::assertCount(1, $siennes);
        self::assertSame($media['id'], $siennes[0]['id']);

        // Et le graphe ne bascule pas : `avecImage` suit les médias ACTIFS.
        $noeuds = $this->noeudsDuGraphe();
        self::assertFalse(
            $noeuds['p'.$aieule]['avecImage'],
            'Une preuve en attente d’approbation ne doit pas allumer le filtre du graphe : ce serait écrire dans l’arbre par la bande.',
        );
    }

    /**
     * Deux fichiers dont **le nom et le `Content-Type` annoncés mentent**. Le module
     * renifle les octets d'en-tête, ne croit ni l'un ni l'autre, et la réponse ne dit
     * pas ce qu'elle a détecté.
     *
     * ── Pourquoi PAS `FabriqueDeFichiers::scriptDeguiseEnImage()` ici ───────────────
     *
     * C'est le cas le plus parlant — la porte dérobée PHP déposée sous le nom `acte.jpg`
     * — et il est **inutilisable comme test sur cette machine**. Mesuré :
     *
     * ```
     * $p = sys_get_temp_dir().'/x.jpg';
     * file_put_contents($p, '<?php @eval($_GET["c"]);');
     * filesize($p);                              // 25
     * file_get_contents($p, false, null, 0, 16); // false
     * ```
     *
     * L'antivirus de Windows reconnaît la signature `@eval($_GET…)` et **bloque la
     * lecture** du fichier qu'on vient d'écrire. `DetecteurDeType::detecter()` sort donc
     * sur `return null` avant d'avoir regardé le moindre octet, et la route rend bien
     * `415` — mais pour une raison qui n'a rien à voir avec le reniflage. Vérifié en
     * remplaçant le corps du détecteur par `throw` : l'exception n'est jamais atteinte,
     * et le test reste vert. **Un test qui ne peut pas échouer ne prouve rien.**
     *
     * Les deux charges ci-dessous portent la même propriété — « les octets décident, pas
     * le nom » — et sont lisibles partout. La seconde est la plus retorse : un PDF précédé
     * de six octets de rebut, ce que la spécification tolère sur 1 024 octets et que ce
     * module refuse, parce que c'est la marge dans laquelle se loge un polyglotte.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function fichiersQuiMentent(): iterable
    {
        yield 'texte déguisé en JPEG' => ["Ceci n'est pas une image, et le nom dit le contraire.\n", 'acte.jpg', 'image/jpeg'];
        yield 'polyglotte GIF + PDF' => [FabriqueDeFichiers::pdfPrecedeDeRebut(), 'acte.pdf', 'application/pdf'];
    }

    #[DataProvider('fichiersQuiMentent')]
    public function testUnFichierDontLeNomMentEstRefuseEtNeLaissePasDeLigne(string $octets, string $nom, string $type): void
    {
        self::assertCount(2, iterator_to_array(self::fichiersQuiMentent()), 'L’ensemble éprouvé est dénombré, pas supposé.');

        $this->connexionDe('natron@example.net', administrateur: true);

        $this->televerser($octets, [
            'kind' => MediaKind::Preuve->value,
            'subjectKind' => MediaSubject::Personne->value,
            'subjectId' => (string) $this->idDe('aieule'),
        ], nomDuFichier: $nom, typeAnnonce: $type);

        self::assertResponseStatusCodeSame(415, 'Un type refusé rend 415, jamais 422 (§ 15.2).');

        $erreur = $this->charge()['erreur'];
        self::assertSame('type_de_fichier_refuse', $erreur['code']);
        self::assertIsArray($erreur['details']);
        self::assertSame(
            ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
            $erreur['details']['mimesAutorises'],
            'La réponse dit ce qui est ACCEPTÉ — jamais ce qu’elle a détecté.',
        );

        // Aucune ligne, et donc aucun fichier orphelin dans le stockage.
        self::assertSame(0, $this->nombreDeMedias());
    }

    /**
     * `403 regime_en_attente` : un compte dont le rattachement n'est pas confirmé ne
     * contribue pas (§ 12.1). La garde est **avant** la lecture du fichier.
     */
    public function testUnCompteEnAttenteNeTeleversePas(): void
    {
        $this->creerUtilisateur('attente@example.net', statut: UserStatus::EnAttente);
        $this->connexionDe('attente@example.net');

        $this->televerser(FabriqueDeFichiers::jpegNu(), [
            'kind' => MediaKind::Preuve->value,
            'subjectKind' => MediaSubject::Personne->value,
            'subjectId' => (string) $this->idDe('aieule'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('regime_en_attente', $this->charge()['erreur']['code']);
        self::assertSame(0, $this->nombreDeMedias());
    }

    /** Sans partie « fichier » dans le multipart : `422`, avec le champ nommé. */
    public function testUnTeleversementSansFichierRend422(): void
    {
        $this->connexionDe('natron@example.net', administrateur: true);

        $this->client->request('POST', '/api/medias', parameters: [
            'kind' => MediaKind::Preuve->value,
            'subjectKind' => MediaSubject::Personne->value,
            'subjectId' => (string) $this->idDe('aieule'),
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $erreur = $this->charge()['erreur'];
        self::assertSame('donnees_invalides', $erreur['code']);
        self::assertSame(['fichier'], array_keys($erreur['champs']));
    }

    /**
     * La suppression, et son effet sur le reste : le média disparaît de la liste, son
     * identifiant rend `404`, et `avecImage` **retombe**.
     *
     * « Une preuve supprimée qui laisserait le filtre allumé donnerait un arbre où l'on
     * cherche une image qui n'existe plus » — et c'est le genre de reliquat qu'on ne voit
     * que dans le graphe.
     */
    public function testLaSuppressionEteintAussiLeFiltreDuGraphe(): void
    {
        $aieule = $this->idDe('aieule');

        $this->connexionDe('natron@example.net', administrateur: true);
        $this->televerser(FabriqueDeFichiers::jpegNu(160, 120), [
            'kind' => MediaKind::Portrait->value,
            'subjectKind' => MediaSubject::Personne->value,
            'subjectId' => (string) $aieule,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $mediaId = $this->charge()['id'];

        $noeuds = $this->noeudsDuGraphe();
        self::assertTrue($noeuds['p'.$aieule]['avecImage']);

        $this->client->request('DELETE', '/api/medias/'.$mediaId, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertSame(['supprime' => true, 'id' => $mediaId], $this->charge());

        $this->requete('/api/medias/'.$mediaId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertSame([], $this->listeDeMedias('/api/medias?subjectKind=personne&subjectId='.$aieule));

        $noeuds = $this->noeudsDuGraphe();
        self::assertFalse($noeuds['p'.$aieule]['avecImage'], 'Le filtre doit retomber avec la preuve.');

        self::assertSame(0, $this->nombreDeMedias());
    }

    /**
     * **Un membre ne supprime pas** — même son propre dépôt : « un refus n'est pas une
     * destruction », et la destruction est une décision d'administration (§ 7.6).
     */
    public function testUnMembreNeSupprimePasSonPropreDepot(): void
    {
        $this->rattacherAUnePersonne('cobalt@example.net', 'ego');
        $this->connexionDe('cobalt@example.net');

        $this->televerser(FabriqueDeFichiers::jpegNu(), [
            'kind' => MediaKind::Preuve->value,
            'subjectKind' => MediaSubject::Personne->value,
            'subjectId' => (string) $this->idDe('aieule'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $mediaId = $this->charge()['id'];

        $this->client->request('DELETE', '/api/medias/'.$mediaId, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(1, $this->nombreDeMedias(), 'Le refus doit précéder toute écriture.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Outillage
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Le `multipart/form-data` du contrat : la partie fichier s'appelle **`fichier`**, et
     * les autres champs sont des champs de formulaire, jamais du JSON.
     *
     * @param array<string, string> $champs
     */
    private function televerser(
        string $octets,
        array $champs,
        string $nomDuFichier = 'acte-de-naissance.jpg',
        string $typeAnnonce = 'image/jpeg',
    ): void {
        $chemin = self::racineDesMedias().'/entree-'.bin2hex(random_bytes(6));

        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0o777, true);
        }

        file_put_contents($chemin, $octets);

        $this->client->request(
            'POST',
            '/api/medias',
            parameters: $champs,
            // `test: true` : le fichier n'est pas passé par `move_uploaded_file()`, ce qui
            // est justement le cas d'un test.
            files: ['fichier' => new UploadedFile($chemin, $nomDuFichier, $typeAnnonce, null, true)],
            server: ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    /**
     * La liste d'un sujet — **un tableau nu**, sans enveloppe (§ 1.5).
     *
     * Elle ne passe pas par `charge()`, qui est typée pour un objet JSON : c'est
     * précisément la forme que cette route n'a pas, et la confondre avec une enveloppe
     * paginée est la première erreur qu'un front commet ici.
     *
     * @return list<array<string, mixed>>
     */
    private function listeDeMedias(string $chemin): array
    {
        $this->requete($chemin);
        self::assertResponseIsSuccessful();

        $contenu = $this->client->getResponse()->getContent();
        self::assertIsString($contenu);

        $decode = json_decode($contenu, true);
        self::assertIsArray($decode);
        self::assertTrue(array_is_list($decode), 'La liste des médias d’un sujet est un tableau nu, jamais une enveloppe.');

        /* @var list<array<string, mixed>> $decode */
        return $decode;
    }

    /** Les octets servis par une route de fichier — `BinaryFileResponse` les diffuse. */
    private function octetsDe(string $chemin): string
    {
        $this->client->request('GET', $chemin, server: ['HTTP_ACCEPT' => '*/*']);

        self::assertResponseIsSuccessful(\sprintf('La route « %s » doit servir des octets.', $chemin));

        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * Les nœuds du graphe, indexés par identifiant.
     *
     * La **taille de l'ensemble est affirmée** : six personnes dans l'arbre du socle, et
     * un graphe qui en rendrait moins ferait passer les assertions suivantes pour de
     * mauvaises raisons.
     *
     * @return array<string, array<string, mixed>>
     */
    private function noeudsDuGraphe(): array
    {
        $this->requete('/api/arbre/graphe');
        self::assertResponseIsSuccessful();

        $noeuds = [];

        foreach ($this->charge()['noeuds'] as $noeud) {
            $noeuds[$noeud['id']] = $noeud;
        }

        $personnes = array_filter($noeuds, static fn (array $n): bool => 'personne' === $n['type']);
        self::assertCount(6, $personnes, 'L’arbre du socle compte six personnes : le graphe doit les rendre toutes.');

        return $noeuds;
    }

    private function nombreDeMedias(): int
    {
        $this->em()->clear();

        return \count($this->em()->getRepository(Media::class)->findAll());
    }

    /**
     * L'arbre du socle **tel que `Membership` le voit**. Voir l'en-tête : sans cette
     * amorce, l'inconnu vaut vivant, donc masqué, et rien de ce fichier ne prouverait ce
     * qu'il prétend prouver.
     *
     * **Aucun nom n'est écrit ici** : les six personnes sont recopiées depuis les entités
     * que le socle vient de semer. Ce n'est pas de l'élégance — c'est ce qui fait que ce
     * fichier ne contient aucune donnée familiale à anonymiser, et qu'il continuera de
     * dire vrai le jour où le socle changera ses libellés. Une liste recopiée à la main
     * ne préviendrait jamais qu'elle a vieilli.
     */
    private function amorcerLaVueDuCercle(): void
    {
        ArbreEnMemoire::reinitialiser();

        self::assertCount(6, $this->personnes, 'Le socle sème six personnes ; la vue du cercle doit les porter toutes.');

        foreach ($this->personnes as $personne) {
            ArbreEnMemoire::ajouterPersonne(
                (int) $personne->getId(),
                (string) $personne->getGivenNames(),
                (string) $personne->getSurname(),
                vivante: !$personne->isDeceased(),
            );
        }

        ArbreEnMemoire::ajouterFamille([$this->idDe('aieul'), $this->idDe('aieule')], [$this->idDe('ego')]);
        ArbreEnMemoire::ajouterFamille([$this->idDe('cousin'), $this->idDe('conjointe')], [$this->idDe('sansDate')]);
    }

    /** Un compte actif **réellement rattaché** : c'est ce qui lui donne un cercle. */
    private function rattacherAUnePersonne(string $courriel, string $personne): void
    {
        $utilisateur = $this->creerUtilisateur($courriel);
        $identifiant = $utilisateur->getId();
        self::assertNotNull($identifiant);

        $membre = new Member(
            $identifiant,
            $courriel,
            $this->idDe($personne),
            $this->personnes[$personne]->getDisplayName(),
            MatchKind::PreLie,
        );
        $membre->activer();

        $em = $this->em();
        $em->persist($membre);
        $em->flush();
    }

    private function connexionDe(string $courriel, bool $administrateur = false): void
    {
        if (null === $this->em()->getRepository(\App\Module\Core\Entity\User::class)->findOneBy(['email' => $courriel])) {
            $this->creerUtilisateur($courriel, roles: $administrateur ? ['ROLE_ADMIN'] : [], nomAffiche: 'Compte de test');
        }

        $this->client->request('POST', '/api/connexion', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $courriel, 'password' => 'motdepasse-de-test'], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful('La connexion de test doit réussir.');
    }

    /** Hors du dépôt, et suffixée du numéro de processus comme le reste du harnais. */
    private static function racineDesMedias(): string
    {
        return \dirname(__DIR__, 2).'/var/test-medias-'.getmypid();
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
