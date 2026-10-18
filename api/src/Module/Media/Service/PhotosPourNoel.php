<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Media\Entity\Media;
use App\Module\Media\Repository\MediaRepository;
use App\Module\Noel\Contract\PhotoDuCompte;
use App\Module\Noel\Contract\PortPhotosPourNoel;

/**
 * Le côté `Media` du contrat `Noel\Contract\PortPhotosPourNoel` : le portrait actif de la
 * fiche du compte, sinon la photo déposée à son inscription (portrait de compte en
 * attente). Seulement des images qui ont une vignette — c'est elle qui est servie.
 */
final readonly class PhotosPourNoel implements PortPhotosPourNoel
{
    public function __construct(
        private MediaRepository $medias,
        private StockageMedia $stockage,
    ) {
    }

    public function avecPhoto(array $comptes): array
    {
        $avec = [];
        $parPersonne = [];

        foreach ($comptes as $userId => $personId) {
            if (null !== $personId) {
                $parPersonne[$personId][] = $userId;
            }
        }

        foreach ($this->medias->portraitsActifsPour(array_keys($parPersonne)) as $media) {
            if (null !== $media->getThumbnailName()) {
                foreach ($parPersonne[(int) $media->getSubjectId()] ?? [] as $userId) {
                    $avec[$userId] = true;
                }
            }
        }

        foreach (array_keys($comptes) as $userId) {
            if (!isset($avec[$userId]) && null !== $this->portraitDuCompte($userId)) {
                $avec[$userId] = true;
            }
        }

        return array_keys($avec);
    }

    public function vignette(int $userId, ?int $personId): ?PhotoDuCompte
    {
        $media = null !== $personId ? $this->medias->portraitActif($personId) : null;
        if (null === $media?->getThumbnailName()) {
            $media = $this->portraitDuCompte($userId);
        }

        $vignette = $media?->getThumbnailName();
        if (null === $media || null === $vignette || !$this->stockage->existe($vignette, vignette: true)) {
            return null;
        }

        return new PhotoDuCompte($this->stockage->chemin($vignette, vignette: true), $media->getMimeType(), 'v'.$media->getSha256());
    }

    private function portraitDuCompte(int $userId): ?Media
    {
        foreach ($this->medias->portraitsEnAttenteDuCompte($userId) as $media) {
            if (null !== $media->getThumbnailName()) {
                return $media;
            }
        }

        return null;
    }
}
