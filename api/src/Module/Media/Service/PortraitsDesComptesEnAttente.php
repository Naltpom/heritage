<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Media\Repository\MediaRepository;
use App\Module\Membership\Contract\PortraitsDesComptes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La photo d'un compte **sans fiche validée**, du côté `Media` — l'implémentation du
 * contrat `Membership\Contract\PortraitsDesComptes` (ADR 0013).
 *
 * Trois gestes, et `Membership` décide du moment de chacun :
 *
 * - **lire** — l'écran d'attente veut savoir s'il y en a une, l'onglet « À rattacher »
 *   et la file des membres (écran Utilisateurs) veulent l'afficher ;
 * - **rattacher** — à l'activation du compte, la photo passe sur la fiche et **devient
 *   son portrait**. Elle passe par {@see CircuitDeValidationMedia::valider()}, le seul
 *   endroit du module où un portrait bascule : l'ancien portrait éventuel est conservé en
 *   `remplace`, exactement comme après une demande validée ;
 * - **écarter** — au refus de l'inscription, ligne et fichiers sont supprimés (les fichiers
 *   **après** le commit du refus, par la fermeture que `ecarter()` rend). Ce n'est pas
 *   le « un refus n'est pas une destruction » du circuit ordinaire, et c'est voulu : il n'y
 *   a ici aucune demande à relire, et le compte refusé ne se connectera plus pour la voir.
 *   On ne garde pas la photo d'un vivant qu'on vient d'éconduire.
 */
final readonly class PortraitsDesComptesEnAttente implements PortraitsDesComptes
{
    public function __construct(
        private EntityManagerInterface $em,
        private MediaRepository $medias,
        private CircuitDeValidationMedia $circuit,
        private TeleversementMedia $televersement,
    ) {
    }

    public function portraitEnAttente(int $userId): ?array
    {
        $media = $this->medias->portraitsEnAttenteDuCompte($userId)[0] ?? null;
        $id = $media?->getId();

        if (null === $media || null === $id) {
            return null;
        }

        return [
            'mediaId' => $id,
            'url' => AdresseDuMedia::fichier($media),
            'vignetteUrl' => AdresseDuMedia::vignette($media),
        ];
    }

    public function rattacherALaFiche(int $userId, int $personId, string $personLabel): bool
    {
        $media = $this->medias->portraitsEnAttenteDuCompte($userId)[0] ?? null;

        if (null === $media) {
            return false;
        }

        // `rattacherA()` **puis** `valider()` — l'ordre de `resoudreLeBrouillon()`, et pour la
        // même raison : `valider()` ne bascule un portrait que sur un média qui a déjà un
        // sujet, et `ck_med_media_compte` refuserait un portrait de compte actif.
        $media->rattacherA($personId)->setSubjectLabel(mb_substr($personLabel, 0, 255))->toucher();
        $this->circuit->valider($media);

        return true;
    }

    /**
     * Retire les lignes **dans la transaction de l'appelant** (le refus), et rend
     * l'effacement des fichiers, que l'appelant joue après le commit — voir
     * {@see TeleversementMedia::supprimerLaLigne()} : effacer avant, c'était, sur un refus
     * annulé, garder un compte en attente dont la photo pointe vers un fichier disparu.
     */
    public function ecarter(int $userId): \Closure
    {
        $portraits = $this->medias->portraitsEnAttenteDuCompte($userId);
        $effacements = [];

        foreach ($portraits as $media) {
            $effacements[] = $this->televersement->supprimerLaLigne($media);
        }

        if ([] !== $portraits) {
            $this->em->flush();
        }

        return static function () use ($effacements): void {
            foreach ($effacements as $effacer) {
                $effacer();
            }
        };
    }
}
