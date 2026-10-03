<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Service\FlouDesPortraits;
use App\Module\Media\Service\GenerateurDeFlou;
use App\Module\Media\Service\NormaliseurMedia;
use App\Module\Media\Service\RequeteDeTeleversement;
use App\Tests\Core\Double\ReciprociteEnMemoire;

/**
 * **La réciprocité des portraits** (ADR 0016) : sans photo de lui, un lecteur ne reçoit des
 * vivants qu'une image floue — **fabriquée sur le serveur**, servie sous une autre adresse,
 * et qui ne dit rien de plus que ses pixels.
 */
final class FlouDesPortraitsTest extends CasDeTestMedia
{
    private const int VIVANTE = 17;

    public function testSansPhotoLePortraitDUnVivantEstFloute(): void
    {
        $media = $this->deposerPortrait($this->administrateur());
        $flous = new FlouDesPortraits(new ReciprociteEnMemoire(photo: false, portraitsFlous: [self::VIVANTE]));

        self::assertTrue($flous->doitFlouter($media, $this->membre()));
        self::assertFalse((new FlouDesPortraits(new ReciprociteEnMemoire(photo: true)))->doitFlouter($media, $this->membre()), 'Avec une photo de soi, les portraits sont nets.');
        self::assertFalse($flous->doitFlouter($media, $this->administrateur()), 'Le déposant voit sa propre photo.');
    }

    public function testUnAdministrateurModereNetteUnePhotoEnAttente(): void
    {
        $this->cercle->lecteurEst(8)->dansLeCercleDe(8, self::VIVANTE);
        $media = $this->deposerPortrait($this->membre(8, 'Opale Romarin'));
        $flous = new FlouDesPortraits(new ReciprociteEnMemoire(photo: false, portraitsFlous: [self::VIVANTE]));

        self::assertFalse($flous->doitFlouter($media, $this->administrateur()), 'On ne valide pas une image qu’on ne voit pas.');
        self::assertTrue($flous->doitFlouter($media, $this->membre()), 'Un autre membre, lui, ne la voit que floue.');
    }

    public function testLeFlouEstIrreversibleEtNeCacheQueDesPixels(): void
    {
        $media = $this->deposerPortrait($this->administrateur());
        $generateur = new GenerateurDeFlou($this->stockage);

        $chemin = $generateur->pour($media->getStorageName(), $media->getMimeType());

        self::assertNotNull($chemin);
        self::assertFileExists($chemin);
        $infos = getimagesize($chemin);
        self::assertIsArray($infos);
        self::assertSame('image/jpeg', $infos['mime']);
        self::assertLessThanOrEqual(GenerateurDeFlou::COTE_SERVI, max($infos[0], $infos[1]));
        self::assertNotSame(hash_file('sha256', $this->stockage->chemin($media->getStorageName())), hash_file('sha256', $chemin));

        // La frontière nette entre le coin rouge et le fond bleu de l'original s'est fondue :
        // à l'endroit où elle passait, le pixel est un mélange des deux.
        $image = imagecreatefromjpeg($chemin);
        self::assertNotFalse($image);
        $c = imagecolorsforindex($image, (int) imagecolorat($image, intdiv($infos[0], 2), intdiv($infos[1], 4)));
        self::assertGreaterThan(40, $c['red']);
        self::assertGreaterThan(40, $c['blue']);

        // Le flou part avec l'original.
        $this->stockage->supprimer($media->getStorageName());
        self::assertFileDoesNotExist($chemin);
    }

    public function testLaChargeDUnePhotoFloueNeDitRienDePlus(): void
    {
        $media = $this->deposerPortrait($this->administrateur());
        $normaliseur = new NormaliseurMedia(new FlouDesPortraits(new ReciprociteEnMemoire(photo: false, portraitsFlous: [self::VIVANTE])));

        $charge = $normaliseur->normaliser($media, new LecteurMedia(9, 'Admin second', true));

        self::assertTrue($charge['flou']);
        self::assertStringEndsWith('-f', $charge['url']);
        self::assertStringEndsWith('-f', $charge['vignetteUrl']);
        self::assertNull($charge['width']);
        self::assertNull($charge['height']);
        self::assertArrayNotHasKey('sha256', $charge, 'Une empreinte retrouverait l’original ailleurs.');
        self::assertArrayNotHasKey('originalName', $charge);
    }

    private function deposerPortrait(LecteurMedia $deposant): Media
    {
        $this->personneVivante(self::VIVANTE, 'Cobalt Cassis');

        return $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegAvecCoinRouge(120, 90), '.jpg'),
                nomOrigine: 'portrait.jpg',
                kind: MediaKind::Portrait,
                subjectKind: MediaSubject::Personne,
                subjectId: self::VIVANTE,
            ),
            $deposant,
        )->media;
    }
}
