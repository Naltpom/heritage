<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Service\PortraitsDesComptesEnAttente;
use App\Module\Media\Service\TeleversementMedia;

/**
 * La photo déposée **à l'inscription**, par un compte qui n'a pas encore de fiche
 * validée — ADR 0013, côté `Media`.
 *
 * Trois propriétés, qui se testent sans HTTP :
 *
 * 1. **les mêmes contrôles** qu'un dépôt ordinaire — taille, type réel, purge EXIF,
 *    vignette — alors que le régime du déposant est `Aucun` ;
 * 2. **une photo qui attend sur le compte**, pas sur une fiche : visible de son titulaire
 *    et des administrateurs, de personne d'autre, hors de la file de validation, et
 *    **une seule** à la fois ;
 * 3. au rattachement, elle **devient le portrait** de la fiche par le seul chemin où un
 *    portrait bascule ; au refus, elle disparaît, fichier compris.
 */
final class PortraitDuCompteEnAttenteTest extends CasDeTestMedia
{
    private PortraitsDesComptesEnAttente $portraits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portraits = new PortraitsDesComptesEnAttente($this->em, $this->medias, $this->circuit, $this->televersement);
    }

    public function testUnCompteEnAttenteDeposeSaPhotoSurLuiMeme(): void
    {
        $media = $this->deposer($this->enAttente(42));

        self::assertSame(MediaKind::Portrait, $media->getKind());
        self::assertSame(MediaSubject::Personne, $media->getSubjectKind());
        self::assertSame(MediaStatus::EnAttente, $media->getStatus());
        self::assertSame(42, $media->getSubjectUserId());
        self::assertNull($media->getSubjectId());
        self::assertNull($media->getSubjectDraftRef());
        self::assertFalse($media->isActivePortrait());
        self::assertSame(TeleversementMedia::LIBELLE_PORTRAIT_DE_COMPTE, $media->getSubjectLabel());

        // Les contrôles d'un dépôt ordinaire ont eu lieu.
        self::assertTrue($media->isExifStripped());
        self::assertNotNull($media->getThumbnailName());
        self::assertFileExists($this->stockage->chemin($media->getStorageName()));
    }

    public function testSeulsLeTitulaireEtLesAdministrateursLaVoient(): void
    {
        $media = $this->deposer($this->enAttente(42));

        self::assertTrue($this->catalogue->lisible($media, $this->enAttente(42)), 'Le titulaire revoit sa photo.');
        self::assertTrue($this->catalogue->lisible($media, $this->administrateur()), 'L\'administrateur la voit pour décider.');
        self::assertFalse($this->catalogue->lisible($media, $this->membre(7)), 'Un autre membre ne la voit pas.');
        self::assertFalse($this->catalogue->lisible($media, $this->enAttente(43)), 'Un autre compte en attente non plus.');
    }

    /** Hors de la file de validation : on ne « valide » pas un portrait de compte hors du rattachement. */
    public function testElleNEntrePasDansLaFileDeValidation(): void
    {
        $this->personneDecedee(17, 'Marne Dolomite Sureau');
        $portraitDeCompte = $this->deposer($this->enAttente(42));

        $ordinaire = $this->televersement->televerser(
            new \App\Module\Media\Service\RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'portrait.jpg',
                kind: MediaKind::Portrait,
                subjectKind: MediaSubject::Personne,
                subjectId: 17,
            ),
            $this->membre(),
        )->media;

        $file = array_map(static fn (Media $m): ?int => $m->getId(), $this->medias->fileDAttente());

        self::assertContains($ordinaire->getId(), $file);
        self::assertNotContains($portraitDeCompte->getId(), $file);
    }

    public function testUnSecondDepotRemplaceLePremierFichierCompris(): void
    {
        $premier = $this->deposer($this->enAttente(42));
        $id = $premier->getId();
        $fichier = $this->stockage->chemin($premier->getStorageName());

        $second = $this->deposer($this->enAttente(42), largeur: 140);

        self::assertNull($this->em->find(Media::class, $id));
        self::assertFileDoesNotExist($fichier);
        self::assertSame([$second->getId()], array_map(
            static fn (Media $m): ?int => $m->getId(),
            $this->medias->portraitsEnAttenteDuCompte(42),
        ));

        // Le compte voisin garde la sienne.
        $voisine = $this->deposer($this->enAttente(43));
        self::assertCount(1, $this->medias->portraitsEnAttenteDuCompte(42));
        self::assertSame($voisine->getId(), $this->portraits->portraitEnAttente(43)['mediaId'] ?? null);
    }

    public function testLePortraitEnAttenteSeDecritParSesAdresses(): void
    {
        $avant = $this->portraits->portraitEnAttente(42);
        self::assertNull($avant);

        $media = $this->deposer($this->enAttente(42));
        $decrit = $this->portraits->portraitEnAttente(42);

        self::assertNotNull($decrit);
        self::assertSame(['mediaId', 'url', 'vignetteUrl'], array_keys($decrit));
        self::assertSame($media->getId(), $decrit['mediaId']);
        self::assertStringStartsWith(\sprintf('/api/medias/%d/fichier', (int) $media->getId()), $decrit['url']);
        self::assertStringStartsWith(\sprintf('/api/medias/%d/vignette', (int) $media->getId()), $decrit['vignetteUrl']);
    }

    /**
     * Au rattachement, la photo **devient le portrait** de la fiche — et l'ancien portrait
     * éventuel est conservé en `remplace`, comme après une demande validée.
     */
    public function testAuRattachementElleDevientLePortraitDeLaFiche(): void
    {
        $this->personneDecedee(17, 'Marne Dolomite Sureau');

        $ancien = $this->televersement->televerser(
            new \App\Module\Media\Service\RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'ancien.jpg',
                kind: MediaKind::Portrait,
                subjectKind: MediaSubject::Personne,
                subjectId: 17,
            ),
            $this->administrateur(),
        )->media;
        self::assertTrue($ancien->isActivePortrait());

        $photo = $this->deposer($this->enAttente(42));

        self::assertTrue($this->portraits->rattacherALaFiche(42, 17, 'Marne Dolomite Sureau'));

        self::assertSame(17, $photo->getSubjectId());
        self::assertNull($photo->getSubjectUserId());
        self::assertSame('Marne Dolomite Sureau', $photo->getSubjectLabel());
        self::assertSame(MediaStatus::Actif, $photo->getStatus());
        self::assertTrue($photo->isActivePortrait());
        self::assertSame(MediaStatus::Remplace, $ancien->getStatus());
        self::assertSame($photo->getId(), $this->medias->portraitActif(17)?->getId());

        // Plus rien n'attend sur le compte, et un second rattachement ne trouve rien.
        self::assertNull($this->portraits->portraitEnAttente(42));
        self::assertFalse($this->portraits->rattacherALaFiche(42, 17, 'Marne Dolomite Sureau'));
    }

    /** Au refus, ligne et fichiers disparaissent : on ne garde pas la photo d'un vivant éconduit. */
    public function testAuRefusElleEstEcarteeFichiersCompris(): void
    {
        $media = $this->deposer($this->enAttente(42));
        $id = $media->getId();
        $vignette = $media->getThumbnailName();
        self::assertNotNull($vignette);
        $original = $this->stockage->chemin($media->getStorageName());
        $miniature = $this->stockage->chemin($vignette, vignette: true);

        $effacer = $this->portraits->ecarter(42);

        // La ligne est partie ; les fichiers attendent le commit de l'appelant.
        self::assertNull($this->em->find(Media::class, $id));
        self::assertFileExists($original);
        self::assertFileExists($miniature);

        $effacer();

        self::assertFileDoesNotExist($original);
        self::assertFileDoesNotExist($miniature);

        // Sans photo, écarter ne fait rien — et ne lève rien.
        ($this->portraits->ecarter(42))();
        $this->addToAssertionCount(1);
    }

    /**
     * Un refus **annulé** ne coûte pas la photo : ligne et fichiers sont encore là. Avant,
     * les fichiers partaient dès `ecarter()`, dans la transaction — et une transaction qui
     * échouait ensuite laissait une ligne pointant vers un fichier disparu.
     */
    public function testUnRefusAnnuleGardeLaPhotoFichiersCompris(): void
    {
        $media = $this->deposer($this->enAttente(42));
        $id = $media->getId();
        $vignette = $media->getThumbnailName();
        self::assertNotNull($vignette);
        $original = $this->stockage->chemin($media->getStorageName());
        $miniature = $this->stockage->chemin($vignette, vignette: true);

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        $this->portraits->ecarter(42);
        $connexion->rollBack();
        $this->em->clear();

        self::assertNotNull($this->em->find(Media::class, $id), 'La ligne revient avec l\'annulation.');
        self::assertFileExists($original);
        self::assertFileExists($miniature);
    }

    /** Un second dépôt remplace le premier : les fichiers de l'ancien partent, après le commit. */
    public function testUnSecondDepotEffaceLesFichiersDuPremier(): void
    {
        $premier = $this->deposer($this->enAttente(42));
        $original = $this->stockage->chemin($premier->getStorageName());
        self::assertFileExists($original);

        $second = $this->deposer($this->enAttente(42), largeur: 150);

        self::assertFileDoesNotExist($original);
        self::assertFileExists($this->stockage->chemin($second->getStorageName()));
        self::assertSame($second->getId(), $this->portraits->portraitEnAttente(42)['mediaId'] ?? null);
    }

    private function deposer(\App\Module\Media\Security\LecteurMedia $deposant, int $largeur = 120): Media
    {
        return $this->televersement->televerserLePortraitDuCompte(
            $this->fichierTemporaire(FabriqueDeFichiers::jpegNu($largeur, 90), '.jpg'),
            'moi.jpg',
            $deposant,
        )->media;
    }
}
