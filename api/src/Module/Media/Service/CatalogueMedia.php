<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Contract\FichesProposeesFermees;
use App\Module\Core\Contract\FichesProposeesInterface;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Repository\MediaRepository;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Security\VisibiliteDesSujetsInterface;

/**
 * La lecture des médias : liste, détail, et l'autorisation de servir les octets.
 *
 * **Tout passe par {@see lisible()}.** Les quatre routes de lecture — liste, détail,
 * fichier, vignette — appellent la même décision. C'est délibéré : une vignette servie
 * selon une règle que la fiche n'applique plus est exactement le genre de divergence qui
 * s'installe quand deux chemins prennent la même décision séparément, et la vignette
 * d'un portrait en dit autant que le portrait.
 *
 * **`404`, jamais `403`.** Une ressource que le lecteur n'a pas le droit de voir est
 * introuvable (§ 15.3 du contrat). Un `403` confirmerait qu'elle existe — et sur un
 * média, cela confirmerait qu'une personne masquée a un portrait.
 *
 * **Le nombre est une donnée.** La liste d'un sujet masqué est vide, pas « filtrée
 * partiellement » : *tout champ dérivé d'une donnée masquée est lui-même une donnée
 * masquée*, et le nombre de médias d'une personne masquée en est un. On ne compte pas
 * avant de filtrer.
 */
final class CatalogueMedia
{
    /**
     * `$fichesProposees` a le **repli fermé** pour valeur par défaut : construit à la main
     * (par plus d'un test), le catalogue applique la règle d'avant le 10/10/2026 — une
     * pièce de proposition se lit par son déposant et l'administration. Le conteneur, lui,
     * injecte l'implémentation de `Proposal`.
     */
    public function __construct(
        private readonly MediaRepository $medias,
        private readonly VisibiliteDesSujetsInterface $visibilite,
        private readonly FichesProposeesInterface $fichesProposees = new FichesProposeesFermees(),
    ) {
    }

    /**
     * Les médias d'un sujet (§ 7.3 du contrat).
     *
     * `status` par défaut : `actif` seul. Un membre qui demande `en_attente` ne reçoit
     * **que les siens** ; un administrateur reçoit toute la file de validation.
     *
     * @return list<Media>
     */
    public function lister(
        MediaSubject $sujetKind,
        int $sujetId,
        LecteurMedia $lecteur,
        ?MediaKind $kind = null,
        ?MediaStatus $statut = null,
    ): array {
        if (!$this->visibilite->estVisible($sujetKind, $sujetId)) {
            // Tableau vide, et non `404` : le § 11.4 du contrat fixe « aucun élément »
            // pour cette porte. La réponse est alors la même que pour un sujet sans
            // média et que pour un sujet inexistant — c'est-à-dire qu'elle n'apprend
            // rien, ce qui est le but.
            //
            // **Aucune dérogation d'administrateur ici.** Une version antérieure rendait
            // `404` à l'administrateur sur un sujet inexistant, au motif qu'il voit tout
            // et qu'une faute de saisie devait lui être signalée. C'était un
            // `if ($estAdmin)` devant le cercle, que le contrat du noyau interdit
            // nommément : un administrateur passe par un cercle complet, il n'a pas de
            // mode « sans filtre ». La commodité ne valait pas la brèche de principe —
            // et une brèche de principe est celle qu'on élargit sans s'en apercevoir.
            return [];
        }

        $statuts = null === $statut ? [MediaStatus::Actif] : [$statut];

        $restreindreAuDeposant = !$lecteur->administrateur
            && \in_array($statut, [MediaStatus::EnAttente, MediaStatus::Refuse], true);

        $trouves = $this->medias->pourSujet(
            $sujetKind,
            $sujetId,
            $statuts,
            $kind,
            $restreindreAuDeposant ? $lecteur->userId : null,
        );

        // Second filtre, volontairement redondant avec le paramètre SQL ci-dessus : la
        // requête sert les index, la décision d'autorisation reste au même endroit que
        // pour le détail et le fichier. Deux gardes valent mieux qu'une quand celle qui
        // saute est silencieuse.
        return array_values(array_filter(
            $trouves,
            fn (Media $media): bool => $this->lisible($media, $lecteur),
        ));
    }

    /**
     * Un média par son identifiant, ou `404` — que l'identifiant n'existe pas ou que le
     * lecteur n'ait rien à y voir.
     */
    public function voir(int $id, LecteurMedia $lecteur): Media
    {
        $media = $this->medias->find($id);

        if (!$media instanceof Media || !$this->lisible($media, $lecteur)) {
            throw new NotFoundException();
        }

        return $media;
    }

    /**
     * Le lecteur a-t-il le droit de voir ce média ?
     *
     * Deux questions, et il faut répondre « oui » aux deux : **l'état** du média d'abord,
     * **le cercle** ensuite. L'ordre n'est pas indifférent et l'enchaînement non plus —
     * ce sont deux filtres successifs, pas deux branches d'une alternative.
     *
     * L'administrateur passe la première et **pas** la seconde : la file de validation
     * est une règle d'état, il la franchit ; le cercle est une règle de confidentialité,
     * il la traverse comme tout le monde, avec le cercle complet que `Membership` lui
     * accorde. C'est le corollaire de l'ADR 0003 qu'aucun appelant n'a le droit
     * d'assouplir : il n'existe pas de mode « sans filtre ».
     */
    public function lisible(Media $media, LecteurMedia $lecteur): bool
    {
        // Filtre 1 — l'état. Un média en attente n'est visible que de son déposant :
        // c'est ce qui fait que le calque « en attente » du § 13 est un calque et non une
        // publication. Un média refusé suit la même règle — son auteur doit pouvoir le
        // relire avec le motif, personne d'autre n'a à savoir qu'il a existé. Les
        // administrateurs le voient parce que c'est leur file de validation.
        $reserve = \in_array($media->getStatus(), [MediaStatus::EnAttente, MediaStatus::Refuse], true);
        $proche = $lecteur->administrateur || $media->getUploadedByUserId() === $lecteur->userId;

        if ($reserve && !$proche && !$this->auteurDeLaProposition($media, $lecteur)) {
            return false;
        }

        $sujetId = $media->getSubjectId();

        if (null === $sujetId) {
            // Rattaché à une personne qui n'existe pas encore : il n'y a pas de cercle où
            // la chercher, et le nœud en attente auquel elle appartient n'est montré qu'à
            // son auteur et aux administrateurs (§ 6.4 du dossier). C'est la seule
            // lecture qui ne passe pas par le cercle, faute de personne à lui soumettre.
            //
            // **Son auteur**, et non seulement le déposant de la pièce : depuis le
            // 10/10/2026, une pièce de la proposition peut venir d'ailleurs — jointe par
            // l'administrateur, ou versée par la fusion d'un complément (ADR 0017).
            return $proche || $this->auteurDeLaProposition($media, $lecteur);
        }

        // Filtre 2 — le cercle, pour tout le monde.
        return $this->visibilite->estVisible($media->getSubjectKind(), $sujetId);
    }

    /**
     * Le lecteur est-il **l'auteur de la proposition** qui porte cette pièce ? Une seule
     * question à `Proposal`, par le noyau (ADR 0002, règle 3), et seulement pour une pièce
     * jointe à une personne proposée — toute autre pièce n'a pas de proposition.
     */
    private function auteurDeLaProposition(Media $media, LecteurMedia $lecteur): bool
    {
        $demandeId = $media->getSubjectRequestId();

        return null === $media->getSubjectId()
            && null !== $demandeId
            && $this->fichesProposees->estAuteurDe($demandeId, $lecteur->userId);
    }
}
