<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Security\ReciprociteInterface;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\LecteurMedia;

/**
 * **Faut-il servir cette photo floue à ce lecteur ?** — la réciprocité des portraits
 * (ADR 0016) : sans photo de lui, un lecteur ne voit les vivants que floutés.
 *
 * Le seul endroit du module qui le décide : le contrôleur s'y fie pour choisir les
 * octets, les normaliseurs pour choisir l'URL. Une photo floue dans l'un et nette dans
 * l'autre serait une photo nette.
 *
 * ## Ce qui est flouté
 *
 * Les **images** (portrait ou document) dont le sujet est une **personne vivante**, quand
 * la réciprocité dit non. Pas les PDF, pas les photos de famille ou de citation — leur sujet
 * n'est pas une personne —, pas les défunts.
 *
 * ## Deux exceptions, qui ne touchent pas à la règle
 *
 * - **le déposant** voit sa propre photo nette : il l'a sur son téléphone ;
 * - **l'administrateur** voit nette une photo **en attente** de validation : il la modère,
 *   et l'on ne valide pas une image qu'on ne voit pas. Une fois validée, elle suit la règle
 *   commune — l'option « ignorer la restriction » ne couvre pas les photos.
 */
final readonly class FlouDesPortraits
{
    public function __construct(private ReciprociteInterface $reciprocite)
    {
    }

    public function doitFlouter(Media $media, LecteurMedia $lecteur): bool
    {
        $sujet = $media->getSubjectId();

        if (MediaSubject::Personne !== $media->getSubjectKind() || null === $sujet) {
            return false;
        }

        if (!str_starts_with($media->getMimeType(), 'image/')) {
            return false;
        }

        if ($media->getUploadedByUserId() === $lecteur->userId) {
            return false;
        }

        if ($lecteur->administrateur && MediaStatus::EnAttente === $media->getStatus()) {
            return false;
        }

        return !$this->reciprocite->portraitNet($sujet);
    }
}
