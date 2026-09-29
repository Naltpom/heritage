<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Security\LecteurMedia;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Faire pivoter une image déjà déposée, par quarts de tour horaires.
 *
 * ## Qui peut
 *
 * La règle de {@see Proposition\ConstructeurDAjoutDeMedia} : l'administrateur, ou le
 * déposant **tant que son média est en attente**. Un média validé appartient à l'arbre,
 * et un membre n'y écrit pas directement (ADR 0004) — pas même pour redresser une photo.
 *
 * ## Ce qui change, et ce qui ne change pas
 *
 * Le fichier est réécrit sous un **nouveau nom** et la vignette régénérée : `sha256`,
 * `byteSize`, `width`, `height` et `updatedAt` suivent. `originalSha256` reste celui du
 * fichier reçu — c'est lui qui reconnaît un second dépôt du même original. Les URL
 * rendues changent de version ({@see AdresseDuMedia}), ce qui suffit au front pour
 * relire l'image.
 *
 * Aucun cadrage n'est à transformer : le recadrage d'un portrait se fait **dans le
 * navigateur, avant l'envoi** (`app/src/components/medias/cadrage.ts`), et le serveur
 * ne reçoit que le carré déjà découpé.
 *
 * ## L'ordre des écritures
 *
 * Le nouveau fichier d'abord, la ligne ensuite, l'ancien fichier en dernier — la règle de
 * {@see SuppressionMedia} : à aucun moment la ligne ne pointe vers un fichier absent.
 * Si le `flush` échoue, c'est le nouveau fichier qui part, et le média reste intact.
 */
final class PivotementMedia
{
    /** Les seuls angles : un quart de tour ne perd aucun pixel, un angle libre si. */
    public const array ANGLES = [90, 180, 270];

    private const array IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurgeDesMetadonnees $reencodage,
        private readonly StockageMedia $stockage,
        private readonly GenerateurDeVignette $vignettes,
    ) {
    }

    public function pivoter(Media $media, int $angle, LecteurMedia $lecteur): void
    {
        $sien = $media->getUploadedByUserId() === $lecteur->userId
            && MediaStatus::EnAttente === $media->getStatus();

        if (!$lecteur->administrateur && !$sien) {
            // `403` et non `404` : le lecteur vient de lire ce média, son existence
            // n'est pas un secret pour lui ; c'est l'action qui lui est refusée.
            throw new ForbiddenException();
        }

        if (!\in_array($angle, self::ANGLES, true)) {
            throw new ValidationException(['angle' => 'L\'angle doit valoir 90, 180 ou 270 degrés (sens horaire).']);
        }

        $mime = $media->getMimeType();

        if (!\in_array($mime, self::IMAGES, true)) {
            throw new ValidationException(['fichier' => 'Seules les images peuvent être pivotées.']);
        }

        $ancienNom = $media->getStorageName();
        $ancienneVignette = $media->getThumbnailName();

        $resultat = $this->reencodage->pivoter($this->stockage->chemin($ancienNom), $mime, $angle);
        $nom = $this->stockage->nomAleatoire($mime);

        try {
            $empreinte = hash_file('sha256', $resultat->chemin);

            if (false === $empreinte) {
                throw new \RuntimeException('Empreinte du fichier pivoté impossible à calculer.');
            }

            $this->stockage->deposer($resultat->chemin, $nom);
        } catch (\Throwable $erreur) {
            @unlink($resultat->chemin);

            throw $erreur;
        }

        $vignette = $this->vignettes->generer($this->stockage->chemin($nom), $mime);

        try {
            $media
                ->remplacerLeFichier($nom, $this->stockage->taille($nom), $empreinte)
                ->setDimensions($resultat->largeur, $resultat->hauteur)
                ->setThumbnailName($vignette);

            $this->em->flush();
        } catch (\Throwable $erreur) {
            $this->stockage->supprimer($nom);

            if (null !== $vignette) {
                $this->stockage->supprimer($vignette, vignette: true);
            }

            throw $erreur;
        }

        $this->stockage->supprimer($ancienNom);

        if (null !== $ancienneVignette) {
            $this->stockage->supprimer($ancienneVignette, vignette: true);
        }
    }
}
