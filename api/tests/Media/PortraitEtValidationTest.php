<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Exception\DomainException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Service\RequeteDeTeleversement;

/**
 * « Un seul portrait actif par personne, **les précédents sont conservés** » — et la
 * couture avec le circuit de validation, du côté `Media`.
 *
 * Le cas qui compte : un second portrait ne détruit pas le premier. Ni la ligne, ni le
 * fichier. C'est une demande explicite du § 6.5 du dossier, et c'est la différence entre
 * « remplacer » et « écraser » : la fiche d'une personne garde l'historique de ses
 * portraits, comme elle garde l'historique de ses champs.
 */
final class PortraitEtValidationTest extends CasDeTestMedia
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->personneDecedee(17, 'Marne Dolomite Sureau');
    }

    public function testUnSecondPortraitNeDetruitPasLePremier(): void
    {
        $premier = $this->deposerPortrait($this->administrateur());
        self::assertTrue($premier->isActivePortrait());

        $cheminPremier = $this->stockage->chemin($premier->getStorageName());
        self::assertFileExists($cheminPremier);

        $second = $this->deposerPortrait($this->administrateur());

        // Le nouveau est le portrait, l'ancien reste en base avec son statut d'archive.
        self::assertTrue($second->isActivePortrait());
        self::assertFalse($premier->isActivePortrait());
        self::assertSame(MediaStatus::Remplace, $premier->getStatus());

        // Et surtout : la ligne et le fichier du premier sont toujours là.
        self::assertNotNull($premier->getId());
        self::assertInstanceOf(Media::class, $this->em->find(Media::class, $premier->getId()));
        self::assertFileExists($cheminPremier, 'Le fichier du portrait remplacé a été détruit.');

        // Un seul actif, et les deux toujours rattachés à la même personne.
        self::assertCount(2, $this->medias->pourSujet(
            MediaSubject::Personne, 17, [MediaStatus::Actif, MediaStatus::Remplace], MediaKind::Portrait,
        ));
        self::assertSame($second->getId(), $this->medias->portraitActif(17)?->getId());
    }

    public function testLeDepotDUnMembreNeBasculePasLePortraitAvantValidation(): void
    {
        // C'est la conséquence directe de « un membre ne modifie jamais directement »
        // (ADR 0004) : sans cette règle, un membre remplacerait le portrait d'une fiche
        // sans que personne l'ait approuvé, c'est-à-dire qu'il écrirait dans l'arbre.
        $enPlace = $this->deposerPortrait($this->administrateur());
        $propose = $this->deposerPortrait($this->membre());

        self::assertSame(MediaStatus::EnAttente, $propose->getStatus());
        self::assertFalse($propose->isActivePortrait());
        self::assertTrue($enPlace->isActivePortrait(), 'Le portrait en place ne doit pas avoir bougé.');
        self::assertSame($enPlace->getId(), $this->medias->portraitActif(17)?->getId());

        // La validation, elle, bascule.
        $this->circuit->valider($propose, changeRequestId: 15);

        self::assertSame(MediaStatus::Actif, $propose->getStatus());
        self::assertTrue($propose->isActivePortrait());
        self::assertSame(15, $propose->getChangeRequestId());
        self::assertSame(MediaStatus::Remplace, $enPlace->getStatus());
    }

    public function testUnRefusNeDetruitPasLeFichier(): void
    {
        // « Un refus n'est pas une destruction » : l'auteur doit pouvoir relire sa
        // demande refusée, et un administrateur qui s'est trompé doit pouvoir revenir
        // dessus sans redemander le fichier.
        $media = $this->deposerPortrait($this->membre());
        $chemin = $this->stockage->chemin($media->getStorageName());

        $this->circuit->refuser($media, changeRequestId: 16);

        self::assertSame(MediaStatus::Refuse, $media->getStatus());
        self::assertFalse($media->isActivePortrait());
        self::assertFileExists($chemin, 'Le fichier d\'un média refusé doit rester sur disque.');
    }

    public function testLaResolutionDUnBrouillonRattacheLesMediasALaPersonneCreee(): void
    {
        $media = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'acte-du-grand-oncle.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                demandeId: 12,
                brouillonRef: 'p1',
            ),
            $this->membre(),
        )->media;

        $rattaches = $this->circuit->resoudreLeBrouillon(12, 'p1', 275, 'Lave Sarriette');

        self::assertSame(1, $rattaches);
        self::assertSame(275, $media->getSubjectId());
        self::assertSame('Lave Sarriette', $media->getSubjectLabel());
        // La référence provisoire disparaît : `ck_med_media_sujet_unique` exige qu'un
        // seul des deux sujets soit renseigné.
        self::assertNull($media->getSubjectDraftRef());
        self::assertNull($media->getSubjectRequestId());
    }

    public function testUnAncienPortraitRedevientLePortrait(): void
    {
        // Le cas signalé le 05/10/2026 : la validation du second portrait a relégué le
        // premier, et il faut pouvoir revenir en arrière — sans rien détruire.
        $premier = $this->deposerPortrait($this->administrateur());
        $second = $this->deposerPortrait($this->administrateur());
        self::assertSame(MediaStatus::Remplace, $premier->getStatus());

        $this->circuit->remettreEnPortrait($premier);

        self::assertSame(MediaStatus::Actif, $premier->getStatus());
        self::assertTrue($premier->isActivePortrait());
        self::assertSame(MediaStatus::Remplace, $second->getStatus());
        self::assertFalse($second->isActivePortrait());
        self::assertSame($premier->getId(), $this->medias->portraitActif(17)?->getId());

        // Et c'est en base : un seul portrait actif, les deux toujours là.
        $this->em->clear();
        self::assertCount(2, $this->medias->pourSujet(
            MediaSubject::Personne, 17, [MediaStatus::Actif, MediaStatus::Remplace], MediaKind::Portrait,
        ));
        self::assertSame($premier->getId(), $this->medias->portraitActif(17)?->getId());
    }

    public function testSeulUnAncienPortraitPeutEtreRemisEnPlace(): void
    {
        $actif = $this->deposerPortrait($this->administrateur());
        $enAttente = $this->deposerPortrait($this->membre());

        foreach ([$actif, $enAttente] as $media) {
            try {
                $this->circuit->remettreEnPortrait($media);
                self::fail('Seul un portrait `remplace` se remet en place.');
            } catch (DomainException $erreur) {
                self::assertSame(409, $erreur->statutHttp());
                self::assertSame('portrait_non_remplace', $erreur->codeErreur());
            }
        }

        // Rien n'a bougé.
        self::assertSame($actif->getId(), $this->medias->portraitActif(17)?->getId());
        self::assertSame(MediaStatus::EnAttente, $enAttente->getStatus());
    }

    public function testLaSuppressionAdministrativeEmporteLesDeuxFichiers(): void
    {
        $media = $this->deposerPortrait($this->administrateur());
        $vignette = $media->getThumbnailName();
        self::assertNotNull($vignette);

        $original = $this->stockage->chemin($media->getStorageName());
        $miniature = $this->stockage->chemin($vignette, vignette: true);
        $id = $media->getId();

        $this->suppression->supprimer($media, $this->administrateur());

        self::assertFileDoesNotExist($original);
        self::assertFileDoesNotExist($miniature);
        self::assertNull($this->em->find(Media::class, $id));
    }

    public function testUnMembreNeSupprimePasUnMedia(): void
    {
        $media = $this->deposerPortrait($this->administrateur());
        $chemin = $this->stockage->chemin($media->getStorageName());

        try {
            $this->suppression->supprimer($media, $this->membre());
            self::fail('Un membre ne supprime pas un média : il dépose une demande.');
        } catch (DomainException $erreur) {
            // `403` et non `404` : il vient de lire ce média, son existence n'est pas un
            // secret pour lui. C'est l'action qui lui est refusée.
            self::assertSame(403, $erreur->statutHttp());
            self::assertSame('acces_refuse', $erreur->codeErreur());
        }

        self::assertFileExists($chemin);
    }

    public function testUnePreuveEncoreRattacheeAUneCitationNeSeSupprimePas(): void
    {
        $this->citation(3, 'Acte de mariage, Épinaille 1910', [17]);

        $media = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'acte.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Citation,
                subjectId: 3,
            ),
            $this->administrateur(),
        )->media;

        try {
            $this->suppression->supprimer($media, $this->administrateur());
            self::fail('Une preuve qui étaye encore une citation ne se supprime pas.');
        } catch (DomainException $erreur) {
            self::assertSame(409, $erreur->statutHttp());
            self::assertSame('media_reference', $erreur->codeErreur());
            self::assertSame(['citationIds' => [3]], $erreur->details());
        }
    }

    private function deposerPortrait(\App\Module\Media\Security\LecteurMedia $deposant): Media
    {
        return $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(
                    FabriqueDeFichiers::jpegNu(100 + random_int(1, 60), 80),
                    '.jpg',
                ),
                nomOrigine: 'portrait.jpg',
                kind: MediaKind::Portrait,
                subjectKind: MediaSubject::Personne,
                subjectId: 17,
            ),
            $deposant,
        )->media;
    }
}
