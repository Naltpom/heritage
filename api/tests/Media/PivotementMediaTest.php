<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Exception\DomainException;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Service\AdresseDuMedia;
use App\Module\Media\Service\GenerateurDeVignette;
use App\Module\Media\Service\PivotementMedia;
use App\Module\Media\Service\PurgeDesMetadonnees;
use App\Module\Media\Service\RequeteDeTeleversement;
use Psr\Log\NullLogger;

/**
 * `POST /api/medias/{id}/pivoter`, au niveau du service : qui peut, ce qui change en
 * base et sur disque, et ce qui ne change pas.
 */
final class PivotementMediaTest extends CasDeTestMedia
{
    private PivotementMedia $pivotement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pivotement = new PivotementMedia(
            $this->em,
            new PurgeDesMetadonnees(sys_get_temp_dir()),
            $this->stockage,
            new GenerateurDeVignette($this->stockage, new NullLogger()),
        );

        $this->personneDecedee(17, 'Aïeule Romarin');
    }

    public function testLeFichierLaVignetteEtLesColonnesSuivent(): void
    {
        $media = $this->deposer($this->administrateur());

        $avant = [
            'nom' => $media->getStorageName(),
            'vignette' => $media->getThumbnailName(),
            'sha' => $media->getSha256(),
            'origine' => $media->getOriginalSha256(),
            'url' => AdresseDuMedia::vignette($media),
        ];

        $this->pivotement->pivoter($media, 90, $this->administrateur());

        self::assertSame(80, $media->getWidth());
        self::assertSame(120, $media->getHeight());
        self::assertNotSame($avant['sha'], $media->getSha256());
        self::assertSame($avant['origine'], $media->getOriginalSha256(), '`originalSha256` décrit le fichier reçu : il ne bouge pas.');
        self::assertSame(hash_file('sha256', $this->stockage->chemin($media->getStorageName())), $media->getSha256());
        self::assertSame($this->stockage->taille($media->getStorageName()), $media->getByteSize());
        self::assertNotSame($avant['url'], AdresseDuMedia::vignette($media), 'Sans nouvelle URL, une page ouverte garde l\'ancienne image.');

        // Le nouveau fichier est réellement tourné — dans le sens horaire.
        $image = imagecreatefromjpeg($this->stockage->chemin($media->getStorageName()));
        self::assertNotFalse($image);
        self::assertSame('rouge', FabriqueDeFichiers::couleurA($image, 70, 10));

        // La vignette aussi, et les anciens octets sont partis.
        $vignette = $media->getThumbnailName();
        self::assertNotNull($vignette);
        $taille = getimagesize($this->stockage->chemin($vignette, vignette: true));
        self::assertIsArray($taille);
        self::assertSame([80, 120], [$taille[0], $taille[1]]);
        self::assertFalse($this->stockage->existe($avant['nom']));
        self::assertFalse($this->stockage->existe((string) $avant['vignette'], vignette: true));

        // Et c'est en base, pas seulement dans l'objet.
        $this->em->clear();
        $relu = $this->em->find(Media::class, $media->getId());
        self::assertNotNull($relu);
        self::assertSame(80, $relu->getWidth());
        self::assertSame($media->getSha256(), $relu->getSha256());
    }

    public function testLeDeposantPivoteSonMediaEnAttente(): void
    {
        $media = $this->deposer($this->membre(7));
        self::assertSame(MediaStatus::EnAttente, $media->getStatus());

        $this->pivotement->pivoter($media, 180, $this->membre(7));

        self::assertSame(120, $media->getWidth());
    }

    public function testUnAutreMembreEstRefuse(): void
    {
        $media = $this->deposer($this->membre(7));

        $this->expectException(ForbiddenException::class);
        $this->pivotement->pivoter($media, 90, $this->membre(8));
    }

    public function testLeDeposantNeTouchePlusUnMediaValide(): void
    {
        // Un média validé appartient à l'arbre : un membre n'y écrit pas directement.
        $media = $this->deposer($this->membre(7));
        $this->circuit->valider($media);

        $this->expectException(ForbiddenException::class);
        $this->pivotement->pivoter($media, 90, $this->membre(7));
    }

    public function testUnAngleQuelconqueEstRefuse(): void
    {
        $media = $this->deposer($this->administrateur());

        $this->assertRefus('angle', fn () => $this->pivotement->pivoter($media, 45, $this->administrateur()));
    }

    public function testUnPdfNePivotePas(): void
    {
        $media = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::pdfAvecMetadonnees(), '.pdf'),
                nomOrigine: 'acte.pdf',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                subjectId: 17,
            ),
            $this->administrateur(),
        )->media;

        $this->assertRefus('fichier', fn () => $this->pivotement->pivoter($media, 90, $this->administrateur()));
    }

    private function deposer(LecteurMedia $deposant): Media
    {
        return $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegAvecCoinRouge(120, 80), '.jpg'),
                nomOrigine: 'photo.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                subjectId: 17,
            ),
            $deposant,
        )->media;
    }

    private function assertRefus(string $champ, callable $action): void
    {
        try {
            $action();
            self::fail('Un 422 était attendu.');
        } catch (DomainException $erreur) {
            self::assertSame(422, $erreur->statutHttp());
            self::assertArrayHasKey($champ, (array) $erreur->champs());
        }
    }
}
