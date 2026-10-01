<?php

declare(strict_types=1);

namespace App\Module\Media\Service\Proposition;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Service\CatalogueMedia;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Service\ConstructeurDeDemande;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * `ajout_media` — le dépôt de la demande (§ 6.4 du contrat d'API).
 *
 * Le § 6.5 du dossier de mission le veut en une phrase : *un média déposé par un membre
 * suit le **même circuit de validation** que les autres modifications*. Il n'y a donc pas
 * de file d'attente des médias à côté de celle des demandes — il y a des demandes, dont
 * certaines portent un média.
 *
 * ## L'ordre est inversé par rapport à ce qu'on attend, et c'est voulu
 *
 * **Le fichier est téléversé d'abord** (`POST /api/medias`, § 7.1), il naît `en_attente`,
 * et la demande le **désigne** ensuite par son identifiant. Pas l'inverse. La raison est
 * pratique : un téléversement est long et peut échouer sur vingt mégaoctets ; le faire
 * porter par le corps JSON d'une demande obligerait à rejouer le transfert à chaque
 * erreur de saisie du formulaire. Conséquence à assumer : un média déposé dont la demande
 * n'est jamais créée reste `en_attente` sans demande — c'est un orphelin, visible de son
 * seul déposant, et c'est le prix de l'ordre inverse.
 *
 * ## Ce que ce constructeur vérifie, et pourquoi chaque contrôle est là
 *
 * 1. **Le média existe et son déposant le voit** — sinon `422` sur le chemin de l'op, pas
 *    `404` : on est dans la validation d'un formulaire, et l'auteur ne peut de toute
 *    façon désigner que ses propres dépôts.
 * 2. **Il a été déposé par l'auteur de la demande** — sans quoi un membre joindrait à sa
 *    demande le média en attente d'un autre, et le ferait valider à son insu.
 * 3. **Il est encore `en_attente`** — un média déjà validé n'a pas à repasser par une
 *    demande, et un média refusé ne se ressuscite pas en le redésignant.
 * 4. **Le `sha256` annoncé correspond** — c'est le seul contrôle qui ne protège de rien
 *    d'autre que d'une erreur : le front le reçoit à la réponse du téléversement et le
 *    renvoie ici. S'il diverge, deux téléversements se sont croisés dans l'écran.
 * 5. **La cible de la demande et le sujet du média concordent** — sinon l'écran
 *    d'administration afficherait une demande sur Marne Dolomite portant une preuve
 *    rattachée à Jargon.
 *
 * ## `change_request_id` n'est pas posé ici
 *
 * La demande n'a pas encore d'identifiant à ce stade — `POST /api/demandes` construit et
 * soumet avant le premier `flush`, et c'est ce qui rend le dépôt atomique sans
 * transaction. La colonne est posée **à la validation** par
 * {@see \App\Module\Media\Service\CircuitDeValidationMedia::valider()}, ce qui est de
 * toute façon son sens : le § 6.1 du modèle la définit comme « la demande qui a **validé**
 * ce média ».
 */
final readonly class ConstructeurDAjoutDeMedia implements ConstructeurDeDemande
{
    public function __construct(
        private EntityManagerInterface $em,
        private CatalogueMedia $catalogue,
    ) {
    }

    public function natures(): array
    {
        return [RequestKind::AjoutMedia];
    }

    public function construire(ChangeRequest $demande, array $corps, Lecteur $lecteur): void
    {
        $brut = $corps['ops'] ?? [];

        if (!\is_array($brut) || [] === $brut) {
            throw DonneesInvalides::pourChamp('ops', 'Une demande d\'ajout de média désigne au moins un média.');
        }

        $lecteurMedia = $this->lecteurMedia($lecteur);

        /** @var list<Media> $designes */
        $designes = [];

        foreach (array_values($brut) as $index => $donnees) {
            if (!\is_array($donnees)) {
                throw DonneesInvalides::pourChamp("ops[$index]", 'Chaque op est un objet.');
            }

            $chemin = "ops[$index]";
            $media = $this->mediaDesigne($donnees, $chemin, $lecteurMedia, $lecteur);

            if (\in_array($media, $designes, true)) {
                // Deux ops sur le même média produiraient deux révisions pour un seul
                // fichier, et la seconde basculerait un portrait déjà basculé.
                throw DonneesInvalides::pourChamp("$chemin.newValue.mediaId", 'Ce média est déjà désigné par cette demande.');
            }

            $designes[] = $media;

            $this->em->persist($this->op($demande, $donnees, $index, $media, $chemin));
        }

        $this->poserLaCible($demande, $corps, $designes[0]);
    }

    /**
     * Une demande d'ajout de média sans op ne désigne rien : elle ne dit pas ce qu'elle
     * demande. Contrôlé à la soumission, comme « au moins un lien » pour une création —
     * un brouillon incomplet doit pouvoir s'enregistrer.
     */
    public function verifierAvantSoumission(ChangeRequest $demande): void
    {
        if ([] === $this->opsDe($demande)) {
            throw DonneesInvalides::pourChamp('ops', 'Une demande d\'ajout de média désigne au moins un média.');
        }
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function mediaDesigne(array $donnees, string $chemin, LecteurMedia $lecteurMedia, Lecteur $lecteur): Media
    {
        $valeur = $donnees['newValue'] ?? null;

        if (!\is_array($valeur)) {
            throw DonneesInvalides::pourChamp("$chemin.newValue", 'La valeur d\'un op de média est un objet.');
        }

        $id = $valeur['mediaId'] ?? null;

        if (!\is_int($id) || $id < 1) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.mediaId", 'Identifiant de média attendu.');
        }

        try {
            $media = $this->catalogue->voir($id, $lecteurMedia);
        } catch (NotFoundException) {
            // `422` et non `404` : on valide un formulaire, et l'auteur ne peut désigner
            // que ses propres dépôts — lui dire « introuvable » sur son propre média
            // serait un diagnostic faux.
            throw DonneesInvalides::pourChamp("$chemin.newValue.mediaId", 'Ce média n\'existe pas, ou il ne vous appartient pas.');
        }

        // Le contrôle qui compte : joindre à sa demande le média en attente d'un autre le
        // ferait valider à son insu. Un administrateur peut le faire — c'est lui qui
        // arbitre —, un membre non.
        if (!$lecteur->administrateur && $media->getUploadedByUserId() !== $lecteur->userId) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.mediaId", 'Ce média n\'existe pas, ou il ne vous appartient pas.');
        }

        if (MediaStatus::EnAttente !== $media->getStatus()) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.mediaId", 'Ce média a déjà été traité : seul un média en attente peut faire l\'objet d\'une demande.');
        }

        // Un portrait posé sur un **compte** sans fiche (ADR 0013) ne se joint à aucune
        // demande : il ne rejoint une fiche qu'au rattachement du compte, sur décision d'un
        // administrateur. Le joindre ici — même par son propre titulaire devenu membre, ou
        // par un administrateur — le ferait valider hors de ce rattachement. Même message
        // que « introuvable » : la file de ces portraits n'a pas à se laisser deviner.
        if (null !== $media->getSubjectUserId()) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.mediaId", 'Ce média n\'existe pas, ou il ne vous appartient pas.');
        }

        $empreinte = $valeur['sha256'] ?? null;

        if (\is_string($empreinte) && $empreinte !== $media->getSha256()) {
            throw DonneesInvalides::pourChamp("$chemin.newValue.sha256", 'L\'empreinte annoncée ne correspond pas à ce média.');
        }

        return $media;
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function op(ChangeRequest $demande, array $donnees, int $index, Media $media, string $chemin): ChangeRequestOp
    {
        $nature = $donnees['opKind'] ?? OpKind::AjoutMedia->value;

        if (OpKind::AjoutMedia->value !== $nature) {
            throw DonneesInvalides::pourChamp("$chemin.opKind", 'Une demande d\'ajout de média ne porte que des ops « ajout_media ».');
        }

        if (null !== ($donnees['field'] ?? null)) {
            // `ck_prp_op_champ` : `field` est `null` pour tout `opKind` autre que
            // `champ`. Le dire en 422 plutôt que de laisser la contrainte rendre une 500.
            throw DonneesInvalides::pourChamp("$chemin.field", 'Un op de média ne vise aucun champ.');
        }

        $seq = $donnees['seq'] ?? $index + 1;

        $op = new ChangeRequestOp(
            $demande,
            \is_int($seq) ? $seq : $index + 1,
            OpKind::AjoutMedia,
            SubjectKind::Media,
            ValueKind::Media,
        );

        // Le sujet de l'op est le **média**, pas la personne : c'est lui que l'op crée
        // dans l'arbre des preuves, et c'est sa ligne que la révision nommera.
        $op->viserLigneExistante((int) $media->getId(), null);

        // `oldValue` est `null` : il n'y avait pas de média avant. La forme de
        // `newValue` est celle du § 6.4, recopiée depuis la ligne plutôt que depuis le
        // corps — un client qui annoncerait une légende différente de celle du média
        // ferait diverger le diff de ce qui sera réellement appliqué.
        $op->setValues(null, [
            'mediaId' => $media->getId(),
            'kind' => $media->getKind()->value,
            'caption' => $media->getCaption(),
            'sha256' => $media->getSha256(),
        ]);

        return $op;
    }

    /**
     * La cible de la demande suit le sujet du média, et doit concorder si le client
     * l'annonce.
     *
     * Une demande dont la cible contredit son média afficherait, dans la file
     * d'administration, une demande sur une fiche portant la preuve d'une autre.
     *
     * @param array<string, mixed> $corps
     */
    private function poserLaCible(ChangeRequest $demande, array $corps, Media $media): void
    {
        $sujetId = $media->getSubjectId();

        if (null === $sujetId) {
            // Média rattaché à une personne **pas encore créée** : la demande n'a pas de
            // cible, et c'est légal (`ck_prp_request_cible` n'exige que la cohérence
            // mutuelle de `targetKind` et `targetId`). C'est le cas d'une preuve jointe
            // à une fiche proposée dans une autre demande.
            return;
        }

        $kind = match ($media->getSubjectKind()) {
            MediaSubject::Personne => SubjectKind::Personne,
            MediaSubject::Famille => SubjectKind::Famille,
            MediaSubject::Citation => SubjectKind::Citation,
        };

        $annonceKind = $corps['targetKind'] ?? null;
        $annonceId = $corps['targetId'] ?? null;

        if ((\is_string($annonceKind) && $annonceKind !== $kind->value)
            || (\is_int($annonceId) && $annonceId !== $sujetId)) {
            throw DonneesInvalides::pourChamp('targetId', 'La cible annoncée ne correspond pas au sujet du média désigné.');
        }

        $demande->setTarget($kind, $sujetId, $media->getSubjectLabel());
    }

    /**
     * Les ops de la demande, **qu'elle soit déjà en base ou non**.
     *
     * Même motif que chez `ConstructeurDeCreation` : `soumettre: true` construit et
     * soumet dans le même geste, avant le premier `flush`. Interroger l'unité de travail
     * est ce qui garde le dépôt atomique sans transaction — un corps refusé n'a rien
     * écrit, parce que rien n'est encore parti.
     *
     * @return list<ChangeRequestOp>
     */
    private function opsDe(ChangeRequest $demande): array
    {
        $ops = [];

        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof ChangeRequestOp && $entite->getRequest() === $demande) {
                $ops[] = $entite;
            }
        }

        if ([] === $ops && null !== $demande->getId()) {
            return $this->em->getRepository(ChangeRequestOp::class)->findBy(['request' => $demande], ['seq' => 'ASC']);
        }

        return $ops;
    }

    /**
     * Le lecteur de `Proposal` traduit en lecteur de `Media`.
     *
     * Les deux modules ont chacun le leur, et c'est la conséquence assumée de la règle 2
     * de l'ADR 0002 : aucun des deux n'expose son objet valeur à l'autre. La traduction
     * tient en trois champs, et `identiteEffective()` est reprise telle quelle — c'est
     * elle qui porte la bascule « voir comme un membre ».
     */
    private function lecteurMedia(Lecteur $lecteur): LecteurMedia
    {
        return new LecteurMedia(
            $lecteur->identiteEffective(),
            $lecteur->libelle,
            $lecteur->administrateur && null === $lecteur->membreEmprunteUserId,
        );
    }
}
