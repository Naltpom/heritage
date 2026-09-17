<?php

declare(strict_types=1);

namespace App\Module\Media\Service\Proposition;

use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Repository\MediaRepository;
use App\Module\Media\Service\CircuitDeValidationMedia;
use App\Module\Proposal\Service\Port\PortMedias;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Les médias joints à une personne **proposée** (§ 6.6 du contrat d'API).
 *
 * C'est la contrepartie du « le demandeur doit la voir dans l'arbre » (§ 6.4 du
 * dossier) : une personne proposée apparaît comme un nœud en attente, et les preuves
 * qu'on lui a jointes s'affichent avec elle, avant toute validation.
 *
 * ## La référence provisoire s'écrit avec son dièse ici, sans lui en base
 *
 * `PortMedias` la passe sous la forme `#p1` — celle du § 6.6 et de `prp_draft_person`.
 * `med_media.subject_draft_ref` la stocke **sans** dièse, sur huit caractères, comme le
 * § 7.1 du contrat le fixe pour la partie `brouillonRef` du téléversement. Le dièse est
 * donc retiré ici, et à cet endroit seulement — c'est la seule ligne du produit où les
 * deux conventions se rencontrent, et la laisser implicite reviendrait à la réinventer
 * dans chaque module qui lit cette colonne.
 *
 * ## Le cercle ne s'applique pas ici, et ce n'est pas un oubli
 *
 * Un média de brouillon n'a **pas de sujet réel** : la personne n'existe pas encore, il
 * n'y a donc aucune personne à soumettre au cercle. Sa visibilité est entièrement portée
 * par la demande qui le contient — le calque « en attente » n'est montré qu'à l'auteur de
 * la demande et aux administrateurs (§ 13 du contrat), et c'est `Proposal` qui l'a déjà
 * vérifié avant d'appeler ce port. C'est la même règle que
 * {@see \App\Module\Media\Service\CatalogueMedia::lisible()} applique de son côté sur un
 * média sans `subject_id`.
 *
 * Le jour où ce port serait appelé depuis un chemin qui n'a pas fait cette vérification,
 * c'est ce commentaire qu'il faudra contredire — pas une garde silencieuse qu'on aurait
 * ajoutée ici par précaution et que personne n'aurait su relire.
 *
 * ## Pourquoi `#[AsDecorator]` et non `#[AsAlias]` — vérifié, pas supposé
 *
 * `config/services/proposal.yaml` aliase `PortMedias` sur son repli `MediasNonBranches`,
 * avec la mention « reste à remplacer ». Remplacer cet alias depuis `Media` demande de
 * gagner contre une déclaration qu'on ne possède pas, et les deux moyens évidents
 * échouent :
 *
 * - le déclarer dans `config/services/media.yaml` : les fichiers de module sont importés
 *   dans l'ordre alphabétique, `media` avant `proposal` — c'est le repli qui gagne ;
 * - `#[AsAlias]` : **essayé, et il ne gagne pas non plus.** Contrairement à
 *   l'enregistrement automatique des classes de `src/`, qui écrase bien les définitions
 *   de service posées par les imports, l'alias d'attribut ne prend pas le pas sur un
 *   alias YAML. `debug:container` continuait de rendre `MediasNonBranches`.
 *
 * `#[AsDecorator]` est traité par un passe de compilation postérieure, et c'est le moyen
 * prévu par Symfony pour substituer un service qu'on ne possède pas. Rien n'est injecté
 * du service décoré : il n'y a rien à envelopper, seulement à remplacer.
 *
 * **La leçon générale, parce qu'elle a coûté deux essais** : l'ordre de précédence du
 * conteneur ne se déduit pas, il se constate — `bin/console debug:container <id>` après
 * chaque tentative. Deux mécanismes qui « devraient » se comporter pareil, l'alias de
 * `#[AsAlias]` et la définition de l'enregistrement automatique, ne se comportent pas
 * pareil face à un import.
 *
 * Quand l'agent `Proposal` retirera sa ligne de repli, cet attribut peut redevenir un
 * simple `#[AsAlias]` — ou disparaître si l'alias passe chez lui.
 */
#[AsDecorator(PortMedias::class)]
final readonly class MediasDeBrouillon implements PortMedias
{
    public function __construct(
        private MediaRepository $medias,
        private CircuitDeValidationMedia $circuit,
    ) {
    }

    /**
     * La passe 6 du § 9.5, côté `Media` : elle **délègue**, elle ne réécrit rien.
     *
     * Tout le travail est dans {@see CircuitDeValidationMedia::resoudreLeBrouillon()},
     * qui est la couture que ce module avait préparée et que rien n'appelait. Cette
     * classe ne fait que traduire la carte de résolution de `Proposal` — indexée par
     * `#p1` — en appels successifs, et retirer le dièse au passage, **ici et à cet
     * endroit seulement**, comme `mediasDeBrouillon()` le fait déjà : c'est la seule
     * ligne du produit où les deux conventions se rencontrent.
     */
    public function resoudreLesBrouillons(int $demandeId, array $creees): int
    {
        $rattaches = 0;

        foreach ($creees as $localRef => $creee) {
            $reference = ltrim($localRef, '#');

            if ('' === $reference) {
                continue;
            }

            $rattaches += $this->circuit->resoudreLeBrouillon(
                $demandeId,
                $reference,
                $creee['personId'],
                $creee['libelle'],
            );
        }

        return $rattaches;
    }

    public function mediasDeBrouillon(int $demandeId, string $localRef): array
    {
        $reference = ltrim($localRef, '#');

        if ('' === $reference) {
            return [];
        }

        return array_map(
            static fn (Media $media): array => [
                'mediaId' => (int) $media->getId(),
                'kind' => $media->getKind()->value,
                'caption' => $media->getCaption(),
                // Les deux seules portes de sortie des octets. Jamais de chemin de
                // disque, jamais de redirection vers un fichier statique (§ 16.1).
                'url' => \sprintf('/api/medias/%d/fichier', (int) $media->getId()),
                'vignetteUrl' => \sprintf('/api/medias/%d/vignette', (int) $media->getId()),
                'status' => $media->getStatus()->value,
            ],
            $this->medias->pourBrouillon($demandeId, $reference),
        );
    }

    /**
     * Le compte de médias visés par un sujet — la moitié « lecture » de ce port.
     *
     * `Proposal` l'appelle avant d'accepter une demande de suppression de fiche :
     * `med_media.subject_id` n'est protégé par aucune clé étrangère, et une personne
     * effacée laisserait ses preuves pointer dans le vide, fichiers compris.
     *
     * **Tous les statuts**, et c'est voulu : une preuve `en_attente` est celle dont la
     * perte serait la plus silencieuse — personne ne l'a encore vue, donc personne ne la
     * réclamerait. Un `refuse` compte aussi, parce qu'un refus n'est pas une destruction
     * (§ 7.6) et que les octets sont toujours là.
     *
     * Une `subjectKind` que `Media` ne connaît pas rend `0` plutôt que de lever :
     * `SubjectKind` en compte six, `MediaSubject` trois, et demander le compte de médias
     * d'une note est une question sans objet, pas une panne.
     */
    public function compterLesMediasDe(string $subjectKind, int $subjectId): int
    {
        $sujet = MediaSubject::tryFrom($subjectKind);

        if (null === $sujet) {
            return 0;
        }

        return \count($this->medias->pourSujet($sujet, $subjectId, MediaStatus::cases()));
    }
}
