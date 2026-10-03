<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Service\Avertissement;
use App\Module\Media\Service\NormaliseurMedia;
use PHPUnit\Framework\TestCase;

/**
 * **Le test de liste blanche** exigé par l'ADR 0003, pour la porte 5 du § 11.4 du
 * contrat : la charge utile d'un média.
 *
 * Il compare **l'ensemble exact des clés**, pas un sous-ensemble. C'est le point entier :
 * une assertion « contient bien `id` et `kind` » passerait au vert le jour où quelqu'un
 * ajoute `storageName` à la charge. Une assertion sur l'ensemble exact tombe. « Sans lui,
 * le reste est une intention. »
 */
final class NormaliseurMediaTest extends TestCase
{
    /**
     * Les clés d'un média rendu à un lecteur ordinaire. Toute clé de plus est une fuite
     * potentielle ; toute clé de moins est une rupture de contrat côté front.
     *
     * @var list<string>
     */
    private const array CLES_ORDINAIRES = [
        'id', 'kind', 'subjectKind', 'subjectId', 'subjectLabel', 'mimeType', 'byteSize',
        'width', 'height', 'pageCount', 'exifStripped', 'status', 'isActivePortrait',
        'caption', 'taken', 'uploadedByLabel', 'createdAt', 'url', 'vignetteUrl',
        'changeRequestId',
    ];

    private NormaliseurMedia $normaliseur;

    protected function setUp(): void
    {
        $this->normaliseur = new NormaliseurMedia(new \App\Module\Media\Service\FlouDesPortraits(new \App\Tests\Core\Double\ReciprociteEnMemoire()));
    }

    public function testLEnsembleExactDesClesPourUnLecteurOrdinaire(): void
    {
        $charge = $this->normaliseur->normaliser($this->media(), $this->lecteur(8));

        self::assertSame(self::CLES_ORDINAIRES, array_keys($charge));
    }

    public function testLeDeposantEtLAdministrateurRecoiventDeuxClesDePlus(): void
    {
        $attendu = [...self::CLES_ORDINAIRES, 'originalName', 'sha256'];

        // Le déposant : il a besoin de `sha256` pour la valeur d'op `media` du § 6.4.
        self::assertSame($attendu, array_keys($this->normaliseur->normaliser($this->media(), $this->lecteur(7))));
        // L'administrateur : il voit tout.
        self::assertSame($attendu, array_keys($this->normaliseur->normaliser($this->media(), $this->lecteur(1, admin: true))));
    }

    public function testLesNomsDeFichierSurDisqueNeSortentJamaisPourPersonne(): void
    {
        // § 16.1 : « un nom devinable rend le stockage énumérable même hors racine web ».
        // La règle vaut pour tout le monde, administrateur compris — c'est pour ça que ce
        // cas interroge les trois lecteurs et pas seulement le membre de passage.
        foreach ([$this->lecteur(8), $this->lecteur(7), $this->lecteur(1, admin: true)] as $lecteur) {
            $charge = $this->normaliseur->normaliser($this->media(), $lecteur);

            self::assertArrayNotHasKey('storageName', $charge);
            self::assertArrayNotHasKey('thumbnailName', $charge);
            self::assertArrayNotHasKey('originalSha256', $charge);

            // Et la valeur elle-même n'apparaît nulle part, sous aucune autre clé.
            $aplati = json_encode($charge, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg', $aplati);
            self::assertStringNotContainsString('vignette-secrete.jpg', $aplati);
        }
    }

    public function testLesSeulesUrlsSontCellesDesDeuxRoutesAuthentifiees(): void
    {
        $charge = $this->normaliseur->normaliser($this->media(), $this->lecteur(8));

        // Versionnées (`?v=`) pour qu'une image réécrite change d'URL — et la version
        // n'est ni le `sha256`, réservé, ni le nom de stockage, secret.
        self::assertMatchesRegularExpression('#^/api/medias/120/fichier\?v=[0-9a-f]{12}$#', $charge['url']);
        self::assertMatchesRegularExpression('#^/api/medias/120/vignette\?v=[0-9a-f]{12}$#', $charge['vignetteUrl']);
        self::assertStringNotContainsString(substr($this->media()->getSha256(), 0, 12), $charge['url']);
        self::assertStringNotContainsString(substr($this->media()->getStorageName(), 0, 12), $charge['url']);
    }

    public function testLeBlocDateRendSixClesEtAucunPoint(): void
    {
        $media = $this->media();
        $media->setTaken(new UncertainDate(
            'BET 1901 AND 1909',
            DateQualifier::Entre,
            new \DateTimeImmutable('1901-01-01'),
            new \DateTimeImmutable('1909-12-31'),
            'Saule, Ombrage, Sylvanie',
        ));

        $charge = $this->normaliseur->normaliser($media, $this->lecteur(8));
        self::assertIsArray($charge['taken']);

        self::assertSame(['raw', 'qualifier', 'min', 'max', 'place', 'texte'], array_keys($charge['taken']));
        self::assertSame('entre 1901 et 1909', $charge['taken']['texte']);
        self::assertSame('1901-01-01', $charge['taken']['min']);

        // Aucune clé ne rend un point : ni `date`, ni `annee`. C'est le verrou contre
        // l'aplatissement des fourchettes, et il descend jusqu'au JSON.
        self::assertArrayNotHasKey('date', $charge['taken']);
        self::assertArrayNotHasKey('annee', $charge['taken']);
    }

    public function testUnMediaDeBrouillonPorteSaReferenceProvisoire(): void
    {
        $media = $this->media();
        $media->rattacherAuBrouillon('p1', 12);

        $charge = $this->normaliseur->normaliser($media, $this->lecteur(8));

        self::assertSame([...self::CLES_ORDINAIRES, 'subjectDraftRef', 'subjectRequestId'], array_keys($charge));
        self::assertNull($charge['subjectId']);
        self::assertSame('p1', $charge['subjectDraftRef']);
    }

    public function testLesAvertissementsNApparaissentQuALaCreation(): void
    {
        $sans = $this->normaliseur->normaliser($this->media(), $this->lecteur(8));
        self::assertArrayNotHasKey('avertissements', $sans);

        $avec = $this->normaliseur->normaliser(
            $this->media(),
            $this->lecteur(8),
            [new Avertissement('doublon_probable', 'Ce fichier est déjà dans l\'arbre.', 88)],
        );

        self::assertSame(
            [['code' => 'doublon_probable', 'message' => 'Ce fichier est déjà dans l\'arbre.', 'mediaId' => 88]],
            $avec['avertissements'],
        );
    }

    /**
     * Un média construit à la main, sans base : la normalisation ne dépend d'aucune
     * requête, et ce test n'a donc aucune raison d'ouvrir une connexion.
     */
    private function media(): Media
    {
        $media = new Media(
            kind: MediaKind::Preuve,
            subjectKind: MediaSubject::Personne,
            subjectLabel: 'Marne Dolomite Sureau',
            storageName: 'a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg',
            mimeType: 'image/jpeg',
            byteSize: 842_113,
            sha256: str_repeat('ab', 32),
            uploadedByUserId: 7,
            uploadedByLabel: 'Cobalt Romarin',
        );

        $media
            ->rattacherA(17)
            ->setOriginalName('IMG_4321.jpg')
            ->setOriginalSha256(str_repeat('cd', 32))
            ->setThumbnailName('vignette-secrete.jpg')
            ->setExifStripped(true)
            ->setDimensions(2400, 1600)
            ->setCaption('Acte n° 3, Épinaille 1910');

        // `id` est posé par l'ORM à l'insertion ; ici on le force par réflexion, le seul
        // endroit du module où c'est acceptable — un test qui passerait par la base pour
        // obtenir un entier paierait une connexion pour rien.
        $propriete = new \ReflectionProperty(Media::class, 'id');
        $propriete->setValue($media, 120);

        return $media;
    }

    private function lecteur(int $userId, bool $admin = false): LecteurMedia
    {
        return new LecteurMedia($userId, 'Lecteur', $admin);
    }
}
