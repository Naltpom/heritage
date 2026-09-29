<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Exception\ConflictException;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Security\VisibiliteDesSujetsInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La suppression définitive d'un média — `ADMIN` seulement (§ 7.6 du contrat).
 *
 * Un membre ne supprime pas un média validé : il dépose une demande. C'est la même règle
 * que partout ailleurs (ADR 0004), et elle vaut ici avec une raison de plus — un média
 * supprimé emporte un fichier, ce qu'un `UPDATE` sur une fiche ne fait pas.
 *
 * **C'est, avec la rotation, le seul endroit du module qui efface des octets.** Le refus
 * n'en efface pas (§ 7.6 : « un refus n'est pas une destruction »), la validation non
 * plus, le remplacement d'un portrait non plus — « les précédents sont conservés ».
 * {@see PivotementMedia} efface l'ancien fichier d'une image qu'il vient de réécrire
 * sous un autre nom : ce sont les mêmes pixels, tournés, pas une preuve perdue. Si un
 * jour un autre chemin appelle {@see StockageMedia::supprimer()}, c'est qu'une règle a
 * changé et il faut que cela se voie.
 *
 * La ligne part **après** les fichiers, et pas l'inverse : un fichier orphelin se
 * retrouve et se nettoie, une ligne qui pointe vers un fichier absent casse toutes les
 * lectures de la fiche jusqu'à ce qu'on comprenne pourquoi.
 */
final class SuppressionMedia
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VisibiliteDesSujetsInterface $visibilite,
        private readonly StockageMedia $stockage,
    ) {
    }

    public function supprimer(Media $media, LecteurMedia $lecteur): void
    {
        if (!$lecteur->administrateur) {
            // `403` et non `404` : l'existence du média n'est pas un secret pour qui
            // vient de le lire ; c'est l'action qui lui est refusée (§ 15.3 du contrat).
            throw new ForbiddenException();
        }

        $sujetId = $media->getSubjectId();

        // Une preuve `actif` rattachée à une citation qui existe encore étaye toujours
        // quelque chose : la supprimer laisserait la citation sans son image, sans que
        // personne ne l'apprenne. On refuse, et on dit laquelle.
        if (MediaStatus::Actif === $media->getStatus()
            && MediaSubject::Citation === $media->getSubjectKind()
            && null !== $sujetId
            && null !== $this->visibilite->decrire(MediaSubject::Citation, $sujetId)) {
            throw new ConflictException('media_reference', 'Ce média étaye encore une ou plusieurs citations : il ne peut pas être supprimé.', ['citationIds' => [$sujetId]]);
        }

        $vignette = $media->getThumbnailName();

        $this->stockage->supprimer($media->getStorageName());

        if (null !== $vignette) {
            $this->stockage->supprimer($vignette, vignette: true);
        }

        $this->em->remove($media);
        $this->em->flush();
    }
}
