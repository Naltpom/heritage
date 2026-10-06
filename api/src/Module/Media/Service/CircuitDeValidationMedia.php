<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Exception\ConflictException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Repository\MediaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * # LA COUTURE AVEC LE CIRCUIT DE VALIDATION — à brancher par l'agent `Proposal`.
 *
 * Le module `Media` **n'implémente pas** le circuit de proposition : un média déposé par
 * un membre suit le même circuit que les autres modifications (§ 6.5 du dossier), et ce
 * circuit appartient à `Proposal`. Ce qui est fait ici, c'est le côté `Media` de la
 * couture : les quatre gestes que `Proposal` appellera, et rien d'autre.
 *
 * ## Ce que `Proposal` doit brancher
 *
 * 1. **Au dépôt** — `POST /api/medias` rend un média `en_attente` portant un `id`. Le
 *    front enchaîne sur `POST /api/demandes` avec `kind: "ajout_media"` et une op
 *    `valueKind: "media"` portant `{mediaId, sha256, …}` (§ 6.4 du contrat). Rien à
 *    appeler ici à ce stade : le média existe déjà et attend.
 * 2. **Dès la demande créée** — appeler {@see rattacherALaDemande()} pour inscrire
 *    `change_request_id`. Sans ce rattachement, un média en attente est orphelin : il
 *    n'a plus de motif de refus à afficher à son auteur, et la file de validation le
 *    montre sans contexte.
 * 3. **À la validation** — appeler {@see valider()}. C'est là, et **seulement là**, que
 *    le portrait bascule : le nouveau devient actif, l'ancien passe `remplace`. Aucun
 *    dépôt de membre ne touche au portrait avant ce point.
 * 4. **Au refus** — appeler {@see refuser()}. Le statut passe `refuse` et **le fichier
 *    reste sur disque** : un refus n'est pas une destruction (§ 7.6 du contrat).
 * 5. **À la validation d'une `creation_personne`** — appeler
 *    {@see resoudreLeBrouillon()} pour chaque référence provisoire, avec l'identifiant
 *    définitif et le libellé. C'est la résolution du § 9.5 du modèle ; sans elle, les
 *    preuves déposées avec la personne restent attachées à un brouillon que plus rien
 *    ne désigne.
 *
 * ## Ce qu'il ne faut pas faire à la place
 *
 * - **Ne pas écrire dans `med_media` depuis `Proposal`.** Règle 5 de l'ADR 0002 :
 *   lectures transverses assumées, écritures strictement dans leur module. Ces quatre
 *   méthodes existent pour qu'il n'y ait pas de raison de la franchir.
 * - **Ne pas supprimer le fichier au refus.** Voir plus haut : la purge est un geste
 *   explicite et séparé, pas un effet de bord d'une décision d'administration.
 * - **Ne pas basculer le portrait à la demande.** Le calque « en attente » (§ 13 du
 *   contrat) montre au demandeur ce qu'il a proposé ; il ne le lui applique pas.
 */
final class CircuitDeValidationMedia
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaRepository $medias,
    ) {
    }

    /** Inscrit la demande qui porte ce média, sans changer son statut. */
    public function rattacherALaDemande(Media $media, int $changeRequestId): void
    {
        $media->setChangeRequestId($changeRequestId)->toucher();
        $this->em->flush();
    }

    /**
     * Le média devient `actif`. Si c'est un portrait, il devient **le** portrait, et le
     * précédent est **conservé** en `remplace`.
     *
     * La démotion précède la promotion, et les deux tiennent dans une transaction :
     * `uniq_med_portrait_actif` est un index unique partiel, il refuserait deux portraits
     * actifs simultanés sur la même personne — l'ordre inverse échouerait donc en base
     * une fois sur une, selon l'ordre de vidage de l'unité de travail.
     */
    public function valider(Media $media, ?int $changeRequestId = null): void
    {
        $this->em->wrapInTransaction(function () use ($media, $changeRequestId): void {
            if (null !== $changeRequestId) {
                $media->setChangeRequestId($changeRequestId);
            }

            $media->setStatus(MediaStatus::Actif)->toucher();

            $sujetId = $media->getSubjectId();

            if (MediaKind::Portrait !== $media->getKind()
                || MediaSubject::Personne !== $media->getSubjectKind()
                || null === $sujetId) {
                $this->em->flush();

                return;
            }

            $precedent = $this->medias->portraitActif($sujetId);

            if (null !== $precedent && $precedent->getId() !== $media->getId()) {
                // « les précédents sont conservés, pas écrasés » (§ 6.5 du dossier) : on
                // change un statut, on ne touche ni à la ligne ni au fichier.
                $precedent->setActivePortrait(false)->setStatus(MediaStatus::Remplace)->toucher();
                $this->em->flush();
            }

            $media->setActivePortrait(true);
            $this->em->flush();
        });
    }

    /**
     * Un **ancien portrait** (`remplace`) redevient le portrait de la personne ; celui
     * qui était en place passe à son tour `remplace`. Rien n'est détruit, ni d'un côté
     * ni de l'autre : c'est le même échange que {@see valider()}, dans l'autre sens.
     *
     * Le cas qui l'a motivé (05/10/2026) : deux portraits de la même aïeule, déposés par
     * deux membres de la famille ; la validation du second a relégué le premier, et
     * aucun geste ne permettait de revenir en arrière.
     *
     * Seul un portrait `remplace` d'une personne s'y prête : un portrait en attente
     * passe par la validation de sa demande, et un refusé n'a pas à revenir par ici.
     *
     * @throws ConflictException `portrait_non_remplace` sinon
     */
    public function remettreEnPortrait(Media $media): void
    {
        if (MediaKind::Portrait !== $media->getKind()
            || MediaSubject::Personne !== $media->getSubjectKind()
            || MediaStatus::Remplace !== $media->getStatus()
            || null === $media->getSubjectId()) {
            throw new ConflictException('portrait_non_remplace', 'Seul un ancien portrait peut redevenir le portrait de la personne.');
        }

        $this->em->wrapInTransaction(function () use ($media): void {
            $actuel = $this->medias->portraitActif((int) $media->getSubjectId());

            // La démotion d'abord, vidée seule : voir `valider()` pour l'index partiel.
            if (null !== $actuel && $actuel->getId() !== $media->getId()) {
                $actuel->setActivePortrait(false)->setStatus(MediaStatus::Remplace)->toucher();
                $this->em->flush();
            }

            $media->setStatus(MediaStatus::Actif)->setActivePortrait(true)->toucher();
            $this->em->flush();
        });
    }

    /**
     * Le média est refusé. Le motif vit dans la demande, pas ici.
     *
     * **Le fichier n'est pas supprimé.** Un refus n'est pas une destruction : l'auteur
     * doit pouvoir consulter sa demande refusée avec le motif, et un administrateur qui
     * a refusé par erreur doit pouvoir revenir dessus sans redemander le fichier.
     */
    public function refuser(Media $media, ?int $changeRequestId = null): void
    {
        if (null !== $changeRequestId) {
            $media->setChangeRequestId($changeRequestId);
        }

        $media->setActivePortrait(false)->setStatus(MediaStatus::Refuse)->toucher();
        $this->em->flush();
    }

    /**
     * Résout les références provisoires d'une demande de création une fois la personne
     * créée : les médias attachés à `#<demande>/<ref>` passent sur l'identifiant réel,
     * **et deviennent visibles**.
     *
     * ── Le statut, et pourquoi il manquait ──────────────────────────────────────────
     *
     * Cette méthode posait `subject_id` et `subject_label`, puis s'arrêtait. Le § 9.5 du
     * modèle demande aussi `status = 'actif'`, et l'écart n'était pas cosmétique :
     * `CatalogueMedia::lister()` ne rend par défaut que les `actif`. Une preuve résolue
     * mais restée `en_attente` serait donc passée de « invisible parce qu'orpheline » à
     * « invisible parce qu'en attente » — le même écran vide, pour une seconde raison.
     * Le déposant l'aurait vue, personne d'autre.
     *
     * Et c'est **juste** de la rendre active ici : l'administrateur vient de valider la
     * demande **qui la portait**. Les preuves jointes à une création font partie de ce
     * qu'il a approuvé ; elles n'ont pas de circuit d'approbation séparé.
     *
     * ── Pourquoi on passe par `valider()` et non par un `setStatus()` ───────────────
     *
     * Parce que l'en-tête de cette classe dit que le portrait bascule **là, et seulement
     * là**. Un brouillon peut porter un `kind = portrait` ; poser le statut à la main
     * ici laisserait `is_active_portrait` à `false` et rouvrirait un second endroit où le
     * portrait se décide — exactement l'invariant que ce fichier protège. `valider()`
     * sort tôt tant que `subject_id` est nul : l'ordre `rattacherA()` **puis**
     * `valider()` n'est donc pas indifférent.
     *
     * @return int le nombre de médias rattachés
     */
    public function resoudreLeBrouillon(int $demandeId, string $brouillonRef, int $personId, string $libelle): int
    {
        $medias = $this->medias->pourBrouillon($demandeId, $brouillonRef);

        foreach ($medias as $media) {
            $media->rattacherA($personId)->setSubjectLabel(mb_substr($libelle, 0, 255))->toucher();

            // Après le rattachement : `valider()` ne sait basculer un portrait que sur un
            // média qui a déjà un sujet.
            $this->valider($media);
        }

        return \count($medias);
    }
}
