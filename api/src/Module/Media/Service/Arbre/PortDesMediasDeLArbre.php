<?php

declare(strict_types=1);

namespace App\Module\Media\Service\Arbre;

use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Repository\MediaRepository;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Security\VisibiliteDesSujetsInterface;
use App\Module\Media\Service\CatalogueMedia;
use App\Module\Tree\Serializer\LecteurDeLArbre;
use App\Module\Tree\Serializer\PortDesMedias;

/**
 * Ce que `Tree` demande à `Media` pour sérialiser une fiche et un graphe.
 *
 * ## Le cercle est appliqué **deux fois**, et ce n'est pas une redondance inutile
 *
 * `Tree` a déjà décidé si le lecteur voit la personne avant d'appeler ce port ; ce port
 * le redemande pour le **sujet du média**. Les deux questions ne sont pas la même : la
 * seconde couvre les sujets qui ne sont pas la personne — une preuve d'union suit ses
 * deux époux, une citation suit la fiche qu'elle étaye. Un port qui ferait confiance à
 * l'appelant servirait la preuve d'un mariage dont un seul conjoint est dans le cercle.
 *
 * Le coût est nul dans le cas courant : la seconde question passe par le même cercle,
 * déjà calculé et mis en cache pour la requête.
 *
 * ## Une question groupée, jamais une boucle
 *
 * {@see personnesAvecMedia()} et {@see mediasDesCitations()} prennent des listes parce
 * que leurs appelants en ont : le graphe interroge les 274 personnes de l'arbre à chaque
 * affichage, une fiche bien sourcée porte six citations. Les écrire en boucle aurait
 * marché sur les fixtures et se serait vu en production.
 */
final readonly class PortDesMediasDeLArbre implements PortDesMedias
{
    public function __construct(
        private MediaRepository $medias,
        private CatalogueMedia $catalogue,
        private VisibiliteDesSujetsInterface $visibilite,
    ) {
    }

    public function portraitDe(int $personneId, LecteurDeLArbre $lecteur): ?array
    {
        $portrait = $this->medias->portraitActif($personneId);

        if (null === $portrait || !$this->catalogue->lisible($portrait, $this->traduire($lecteur))) {
            return null;
        }

        return [
            'mediaId' => (int) $portrait->getId(),
            'url' => $this->url($portrait, 'fichier'),
            'vignetteUrl' => $this->url($portrait, 'vignette'),
            'caption' => $portrait->getCaption(),
        ];
    }

    public function mediasDePersonne(int $personneId, LecteurDeLArbre $lecteur): array
    {
        $medias = $this->catalogue->lister(
            MediaSubject::Personne,
            $personneId,
            $this->traduire($lecteur),
        );

        return array_map(
            fn (Media $media): array => [
                'mediaId' => (int) $media->getId(),
                'kind' => $media->getKind()->value,
                'caption' => $media->getCaption(),
                'url' => $this->url($media, 'fichier'),
                'vignetteUrl' => $this->url($media, 'vignette'),
            ],
            $medias,
        );
    }

    public function mediasDesCitations(array $citationIds, LecteurDeLArbre $lecteur): array
    {
        if ([] === $citationIds) {
            return [];
        }

        $lecteurMedia = $this->traduire($lecteur);
        $visibles = $this->visibilite->filtrerVisibles(MediaSubject::Citation, $citationIds);
        $parCitation = [];

        foreach ($visibles as $citationId) {
            $medias = $this->medias->pourSujet(
                MediaSubject::Citation,
                $citationId,
                [MediaStatus::Actif],
            );

            foreach ($medias as $media) {
                if (!$this->catalogue->lisible($media, $lecteurMedia)) {
                    continue;
                }

                $parCitation[$citationId][] = [
                    'mediaId' => (int) $media->getId(),
                    'kind' => $media->getKind()->value,
                    'vignetteUrl' => $this->url($media, 'vignette'),
                ];
            }
        }

        return $parCitation;
    }

    public function personnesAvecMedia(array $personneIds, LecteurDeLArbre $lecteur): array
    {
        if ([] === $personneIds) {
            return [];
        }

        // Deux requêtes en tout : celles qui portent un média actif, puis celles que le
        // lecteur voit. Ni l'une ni l'autre ne rapatrie de média — la réponse est un
        // booléen par personne, et ne dit rien des médias eux-mêmes : ni identifiant, ni
        // légende, ni vignette.
        $avecMedia = $this->medias->personnesAvecMediaActif($personneIds);

        if ([] === $avecMedia) {
            return [];
        }

        $reponse = [];

        foreach ($this->visibilite->filtrerVisibles(MediaSubject::Personne, $avecMedia) as $personneId) {
            $reponse[$personneId] = true;
        }

        // Une personne sans média — ou dont les médias ne lui sont pas visibles — est
        // **absente**, jamais présente à `false`. C'est la règle « absent contre null »
        // du § 1.3 appliquée ici : `false` affirmerait « cette personne n'a pas d'image »,
        // ce qui est une information sur une fiche qu'on n'a peut-être pas le droit de
        // lire. L'absence, elle, n'affirme rien.
        return $reponse;
    }

    public function vignettesDePortraits(array $personneIds, LecteurDeLArbre $lecteur): array
    {
        if ([] === $personneIds) {
            return [];
        }

        // Une requête pour les portraits actifs, une pour la visibilité — et la seconde
        // ne porte que sur les personnes qui ont un portrait. C'est le même dessin que
        // `personnesAvecMedia()`, avec une vignette au lieu d'un booléen : une personne
        // masquée pour ce lecteur est absente de la réponse, comme là-bas.
        $portraits = $this->medias->portraitsActifsPour($personneIds);

        if ([] === $portraits) {
            return [];
        }

        $parPersonne = [];

        foreach ($portraits as $portrait) {
            $sujet = $portrait->getSubjectId();

            if (null !== $sujet) {
                $parPersonne[$sujet] = $portrait;
            }
        }

        $reponse = [];

        foreach ($this->visibilite->filtrerVisibles(MediaSubject::Personne, array_keys($parPersonne)) as $personneId) {
            $reponse[$personneId] = $this->url($parPersonne[$personneId], 'vignette');
        }

        return $reponse;
    }

    private function url(Media $media, string $segment): string
    {
        return \sprintf('/api/medias/%d/%s', (int) $media->getId(), $segment);
    }

    /**
     * Le lecteur de `Tree` traduit en lecteur de `Media`.
     *
     * `identiteEffective()` plutôt que `userId` : c'est elle qui porte la bascule « voir
     * comme un membre ». Un port qui lirait `userId` servirait à l'administrateur ses
     * propres médias pendant que l'écran prétend montrer ceux d'un membre — la bascule
     * ne vérifierait alors plus rien, ce qui est son unique raison d'être.
     *
     * Le drapeau `administrateur` tombe sous bascule, pour la même raison : emprunter
     * l'identité d'un membre et garder ses propres droits ne montre pas ce que ce membre
     * voit.
     */
    private function traduire(LecteurDeLArbre $lecteur): LecteurMedia
    {
        return new LecteurMedia(
            $lecteur->identiteEffective() ?? $lecteur->userId,
            $lecteur->libelle,
            $lecteur->administrateur && !$lecteur->sousBascule(),
        );
    }
}
