<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Media\Entity\Media;
use App\Module\Media\Security\LecteurMedia;

/**
 * La charge utile d'un média (§ 7.2 du contrat).
 *
 * ## La liste blanche se construit, elle ne se retire pas
 *
 * Aucune méthode ici ne construit la charge complète pour en `unset()` ensuite les clés
 * interdites. Le § 1.3 du contrat l'interdit nommément, et pour une raison mécanique :
 * une implémentation par retrait laisse passer **le champ ajouté demain**, puisque
 * personne ne pense à l'inscrire dans la liste des retraits. Une implémentation par
 * ajout ne peut pas se tromper dans ce sens-là — le champ oublié est absent, pas fuité.
 *
 * ## Ce qui ne sort jamais, pour personne
 *
 * `storageName`, `thumbnailName`, `originalSha256`, et tout chemin de disque : § 16.1.
 * « Un nom devinable rend le stockage énumérable même hors racine web. » Les médias
 * sortent exclusivement par `/api/medias/{id}/fichier` et `/vignette`.
 *
 * ## Ce qui ne sort que pour certains
 *
 * - `originalName` — déposant et administrateurs seulement. Un nom de fichier d'appareil
 *   photo porte souvent une date, parfois un nom, et il traverserait sinon le cercle
 *   sans être passé par lui.
 * - `sha256` — administrateurs, et le déposant sur **son** média (il en a besoin pour la
 *   valeur d'op `media` du § 6.4). Un tiers ne le reçoit pas : c'est une empreinte, elle
 *   permet de tester la présence d'un fichier qu'on possède déjà.
 *
 * Le filtrage du **sujet** n'est pas fait ici : un média dont le sujet n'est pas visible
 * n'est pas normalisé du tout, il n'apparaît pas dans la liste et son identifiant rend
 * `404`. C'est {@see CatalogueMedia} qui l'applique, une fois, en amont — et c'est ce
 * qui ferme d'un seul geste la porte 5 et la porte 8 du § 11.4 (la clé `subjectLabel`
 * ne peut pas fuiter si l'objet entier est absent).
 */
final class NormaliseurMedia
{
    /**
     * @param list<Avertissement> $avertissements présents à la création seulement
     *
     * @return array<string, mixed>
     */
    public function normaliser(Media $media, LecteurMedia $lecteur, array $avertissements = []): array
    {
        $id = $media->getId();
        $sien = $media->getUploadedByUserId() === $lecteur->userId;
        $privilegie = $lecteur->administrateur || $sien;

        $charge = [
            'id' => $id,
            'kind' => $media->getKind()->value,
            'subjectKind' => $media->getSubjectKind()->value,
            'subjectId' => $media->getSubjectId(),
            'subjectLabel' => $media->getSubjectLabel(),
            'mimeType' => $media->getMimeType(),
            'byteSize' => $media->getByteSize(),
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
            'pageCount' => $media->getPageCount(),
            'exifStripped' => $media->isExifStripped(),
            'status' => $media->getStatus()->value,
            'isActivePortrait' => $media->isActivePortrait(),
            'caption' => $media->getCaption(),
            'taken' => $this->bloc($media->getTaken()),
            'uploadedByLabel' => $media->getUploadedByLabel(),
            'createdAt' => $media->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'url' => \sprintf('/api/medias/%d/fichier', (int) $id),
            'vignetteUrl' => \sprintf('/api/medias/%d/vignette', (int) $id),
            'changeRequestId' => $media->getChangeRequestId(),
        ];

        // Hors contrat écrit, et assumé : un média rattaché à une personne pas encore
        // créée n'a pas de `subjectId`, et le front doit pouvoir l'afficher sous le nœud
        // en attente auquel il appartient (§ 6.4 du dossier). Les deux clés portent le
        // nom de leur propriété Doctrine, conformément au § 1.2 du contrat. Signalé dans
        // le livrable : c'est un ajout au § 7.2, pas une interprétation.
        if (null !== $media->getSubjectDraftRef()) {
            $charge['subjectDraftRef'] = $media->getSubjectDraftRef();
            $charge['subjectRequestId'] = $media->getSubjectRequestId();
        }

        if ($privilegie) {
            $charge['originalName'] = $media->getOriginalName();
            $charge['sha256'] = $media->getSha256();
        }

        if ([] !== $avertissements) {
            $charge['avertissements'] = array_map(
                static fn (Avertissement $a): array => [
                    'code' => $a->code,
                    'message' => $a->message,
                    'mediaId' => $a->mediaId,
                ],
                $avertissements,
            );
        }

        return $charge;
    }

    /**
     * Le bloc date à six clés du § 14.1 : les cinq colonnes, plus `texte` en lecture
     * seule. `texte` n'a rien à faire dans un corps de requête — un back qui l'accepte
     * en entrée accepte une sixième clé que la comparaison `JSONB` de la détection de
     * conflit fera diverger.
     *
     * Aucune clé ne rend un **point** : ni `date`, ni `annee`. C'est le verrou du § 9.1
     * du modèle, et il descend jusqu'ici.
     *
     * @return array<string, string|null>
     */
    private function bloc(UncertainDate $date): array
    {
        return [
            'raw' => $date->getRaw(),
            'qualifier' => $date->getQualifier()->value,
            'min' => $date->getMin()?->format('Y-m-d'),
            'max' => $date->getMax()?->format('Y-m-d'),
            'place' => $date->getPlace(),
            'texte' => $date->format(),
        ];
    }
}
