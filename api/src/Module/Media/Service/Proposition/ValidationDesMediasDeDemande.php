<?php

declare(strict_types=1);

namespace App\Module\Media\Service\Proposition;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Repository\MediaRepository;
use App\Module\Media\Service\CircuitDeValidationMedia;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\EcritureDeRevision;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Enum\RevisionOperation;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `ajout_media` — l'application à la validation.
 *
 * C'est ici, et **seulement ici**, qu'un média déposé par un membre devient `actif`. Tout
 * le reste du module l'a laissé `en_attente` exprès : le calque du § 13 montre au
 * demandeur ce qu'il a proposé, il ne le lui applique pas.
 *
 * Le travail réel tient en un appel à
 * {@see CircuitDeValidationMedia::valider()} — la couture que ce module avait préparée
 * en attendant `Proposal`. C'est elle qui bascule le portrait dans une transaction, et
 * qui conserve le précédent en `remplace` : « les précédents sont conservés, pas
 * écrasés » (§ 6.5 du dossier).
 *
 * ## Le sujet de la révision est le média, pas la personne
 *
 * `prp_revision` reçoit `subject_kind = 'media'`. La personne n'a pas changé — ses
 * colonnes sont intactes, sa `version` n'a pas bougé, et écrire une révision sur elle
 * ferait apparaître dans son historique une modification qui n'a pas eu lieu. Le lien
 * vers la fiche se lit par la demande, qui porte sa cible.
 *
 * Conséquence directe, et voulue : **aucun verrou optimiste ne s'exerce ici**. Joindre
 * une preuve à une fiche ne peut pas entrer en conflit avec une modification de cette
 * fiche, puisque les deux n'écrivent pas au même endroit — deux cousins peuvent joindre
 * un acte pendant qu'un troisième corrige une date, et les trois demandes passent.
 *
 * ## Ce que l'applicateur n'écrit pas
 *
 * Il ne touche ni `tree_person`, ni `tree_citation`, ni aucune table d'un autre module :
 * le rattachement du média à son sujet a été fait au téléversement, résolu par `Tree` à
 * ce moment-là (mesure 1 de la compensation de l'ADR 0002). La validation ne fait que
 * changer un statut.
 */
final readonly class ValidationDesMediasDeDemande
{
    public function __construct(
        private MediaRepository $medias,
        private CircuitDeValidationMedia $circuit,
    ) {
    }

    /**
     * @param list<OpEnAttente> $ops les ops `ajout_media` de la demande, dans l'ordre de `seq`
     *
     * @return list<EcritureDeRevision>
     */
    public function appliquer(DemandeEnCours $demande, array $ops): array
    {
        $ecritures = [];

        foreach ($ops as $op) {
            $media = $this->mediaDe($op);

            if (null === $media) {
                // La ligne a disparu entre le dépôt et la validation — un administrateur
                // l'a supprimée. On ne fabrique pas de révision pour un fichier qui
                // n'existe plus ; l'op est marquée appliquée par le validateur et la
                // demande reste cohérente.
                continue;
            }

            $avant = $media->getStatus()->value;
            $portraitAvant = $this->portraitRemplace($media);

            $this->circuit->valider($media, $demande->id);

            $ecritures[] = new EcritureDeRevision(
                SubjectKind::Media,
                (int) $media->getId(),
                $this->libelle($media),
                RevisionOperation::Creation,
                $this->diff($media, $avant, $portraitAvant),
            );
        }

        return $ecritures;
    }

    /**
     * Le média visé par l'op.
     *
     * Relu depuis la base plutôt que depuis `newValue` : la valeur d'op est un
     * instantané du dépôt, et trois jours ont pu passer. C'est la ligne qui fait foi.
     */
    private function mediaDe(OpEnAttente $op): ?Media
    {
        return null === $op->subjectId ? null : $this->medias->find($op->subjectId);
    }

    /**
     * Le portrait que celui-ci va remplacer, **lu avant la bascule**.
     *
     * Après l'appel à `valider()`, `portraitActif()` rend le nouveau : le diff serait
     * alors « X → X ». C'est le genre d'erreur qui ne se voit qu'à la relecture de
     * l'historique, six mois plus tard, quand plus personne ne sait ce qui a été
     * remplacé.
     */
    private function portraitRemplace(Media $media): ?int
    {
        $sujetId = $media->getSubjectId();

        if (MediaKind::Portrait !== $media->getKind() || null === $sujetId) {
            return null;
        }

        $precedent = $this->medias->portraitActif($sujetId);

        return $precedent?->getId() === $media->getId() ? null : $precedent?->getId();
    }

    /**
     * Le diff de `prp_revision`, dans la forme de `core_audit_log.changes` (§ 5.5).
     *
     * **Aucun chemin de disque, aucune empreinte** : le § 16.1 les exclut pour tout le
     * monde, et une révision se relit longtemps. Le libellé du sujet non plus n'est pas
     * une ligne de dates — c'est le `display_name`, et rien d'autre (§ 9.2).
     *
     * @return array<string, mixed>
     */
    private function diff(Media $media, string $statutAvant, ?int $portraitRemplace): array
    {
        $changes = [
            'status' => ['avant' => $statutAvant, 'apres' => $media->getStatus()->value],
            'kind' => ['avant' => null, 'apres' => $media->getKind()->value],
            'caption' => ['avant' => null, 'apres' => $media->getCaption()],
        ];

        if (null !== $portraitRemplace) {
            // Ce que l'historique doit pouvoir dire : « le portrait n° 88 a été remplacé
            // par le n° 120, et le 88 est toujours là ».
            $changes['isActivePortrait'] = ['avant' => $portraitRemplace, 'apres' => $media->getId()];
        }

        return $changes;
    }

    /**
     * Le libellé de la révision.
     *
     * Le nom du fichier d'origine n'y figure **pas** : il n'est rendu qu'au déposant et
     * aux administrateurs (§ 7.2), et une révision est relue par tous ceux qui ouvrent
     * l'historique d'une fiche. Un nom d'appareil photo porte souvent une date, parfois
     * un nom.
     */
    private function libelle(Media $media): string
    {
        $legende = $media->getCaption();

        if (null !== $legende && '' !== trim($legende)) {
            return mb_substr(trim($legende), 0, 255);
        }

        return \sprintf('%s n° %d', $media->getKind()->label(), (int) $media->getId());
    }
}
