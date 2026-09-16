<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Enum\Regime;
use App\Module\Core\Exception\FichierTropVolumineuxException;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\NotFoundException;
use App\Module\Core\Exception\TypeDeFichierRefuseException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Repository\MediaRepository;
use App\Module\Media\Security\LecteurMedia;
use App\Module\Media\Security\SujetMedia;
use App\Module\Media\Security\VisibiliteDesSujetsInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le dépôt d'un média : les six contrôles d'entrée du § 6.5 du dossier, dans l'ordre où
 * ils doivent avoir lieu.
 *
 * ## L'ordre n'est pas indifférent
 *
 * 1. **Régime du déposant** — un compte en attente n'écrit rien (`403 regime_en_attente`).
 * 2. **Taille** — avant tout le reste : refuser 200 Mio ne doit pas coûter un décodage.
 * 3. **Type réel, par les octets d'en-tête** — jamais l'extension, jamais le
 *    `Content-Type` annoncé, et la réponse ne dit pas ce qui a été détecté.
 * 4. **Résolution du sujet auprès de son module propriétaire** — mesure 1 de la
 *    compensation de l'ADR 0002 : aucun identifiant inter-module n'entre en base sans
 *    avoir été confirmé.
 * 5. **Purge des métadonnées par ré-encodage** — EXIF, et surtout les coordonnées GPS.
 * 6. **Empreintes, nom aléatoire, dépôt, vignette.**
 *
 * ## Ce que ce service **ne fait pas** : le circuit de validation
 *
 * Un média déposé par un membre naît `en_attente` et doit passer par le **même circuit
 * de proposition** que les autres modifications (§ 6.5 du dossier) — c'est l'affaire du
 * module `Proposal`, écrit par un autre agent. Ce service s'arrête à la ligne en base et
 * au fichier sur disque. La couture à brancher est décrite en tête de
 * {@see CircuitDeValidationMedia}, qui expose les quatre gestes attendus.
 *
 * Conséquence visible ici : un dépôt de membre **ne touche pas** au portrait actif. Un
 * portrait ne devient actif qu'à la validation, sans quoi un membre remplacerait le
 * portrait d'une fiche sans que personne l'ait approuvé — c'est-à-dire qu'il écrirait
 * dans l'arbre, ce que l'ADR 0004 lui interdit. Un dépôt d'administrateur, lui, naît
 * `actif` (§ 7.1 du contrat) et bascule le portrait immédiatement.
 */
final class TeleversementMedia
{
    /** `p1`, `p12` : une lettre puis un rang. La colonne fait 8 caractères. */
    private const string REF_BROUILLON_VALIDE = '/^[a-z][0-9]{1,6}$/';

    /**
     * Le libellé posé sur un média de brouillon.
     *
     * Volontairement **anonyme** : la personne n'existe pas encore, et son nom proposé
     * appartient à `prp_draft_person`. Le recopier ici en ferait un second exemplaire à
     * filtrer, dans un module qui n'a pas de quoi le filtrer. `Proposal` le remplace par
     * le vrai libellé à la validation, en appelant
     * {@see CircuitDeValidationMedia::resoudreLeBrouillon()}.
     */
    private const string LIBELLE_BROUILLON = 'Personne proposée';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaRepository $medias,
        private readonly ReglagesMediaInterface $reglages,
        private readonly DetecteurDeType $detecteur,
        private readonly PurgeDesMetadonnees $purge,
        private readonly StockageMedia $stockage,
        private readonly GenerateurDeVignette $vignettes,
        private readonly VisibiliteDesSujetsInterface $visibilite,
        /**
         * Le dépôt d'un administrateur est **auto-approuvé** (§ 6.3 du dossier) : il
         * passe par le même service de validation que les autres, plutôt que de refaire
         * la bascule de portrait ici. C'est ce qui permet d'écrire, et de tenir, que le
         * portrait ne bascule qu'à un seul endroit du module.
         */
        private readonly CircuitDeValidationMedia $circuit,
    ) {
    }

    public function televerser(RequeteDeTeleversement $requete, LecteurMedia $deposant): ResultatDeTeleversement
    {
        // 1 — régime. § 12.1 du contrat : l'écriture est fermée à un compte dont le
        // rattachement n'est pas établi, parce qu'une demande sans auteur identifié
        // obligerait l'administrateur à arbitrer deux choses à la fois.
        if (Regime::Aucun === $this->visibilite->regimeDuLecteur()) {
            throw ForbiddenException::regimeEnAttente();
        }

        $this->verifierLaCoherenceDuSujet($requete);

        // 2 — taille.
        $octets = @filesize($requete->cheminTemporaire);

        if (false === $octets) {
            throw new ValidationException(['fichier' => 'Fichier illisible.']);
        }

        $plafond = $this->reglages->tailleMaxOctets();

        if ($octets > $plafond) {
            throw new FichierTropVolumineuxException($plafond);
        }

        if (0 === $octets) {
            throw new ValidationException(['fichier' => 'Le fichier est vide.']);
        }

        // 3 — type réel. Le reniflage d'abord, la liste blanche ensuite : c'est le
        // type constaté qu'on confronte à la liste, jamais celui qu'on nous annonce.
        $mime = $this->detecteur->detecter($requete->cheminTemporaire);
        $autorises = $this->reglages->mimesAutorises();

        if (null === $mime || !\in_array($mime, $autorises, true)) {
            throw new TypeDeFichierRefuseException($autorises);
        }

        // 4 — le sujet, vu par son module propriétaire.
        $sujet = $this->resoudreLeSujet($requete);

        // 5 — purge. Ce qui est déposé n'est jamais le fichier reçu : c'est sa réécriture.
        $resultat = $this->purge->purger($requete->cheminTemporaire, $mime);

        try {
            // 6 — empreintes et dépôt.
            $empreinteStockee = hash_file('sha256', $resultat->chemin);
            $empreinteOrigine = hash_file('sha256', $requete->cheminTemporaire);

            if (false === $empreinteStockee) {
                throw new \RuntimeException('Empreinte du fichier stocké impossible à calculer.');
            }

            $nom = $this->stockage->nomAleatoire($mime);
            $this->stockage->deposer($resultat->chemin, $nom);
        } catch (\Throwable $erreur) {
            @unlink($resultat->chemin);

            throw $erreur;
        }

        $media = $this->construire(
            $requete,
            $deposant,
            $sujet,
            $nom,
            $mime,
            $this->stockage->taille($nom),
            $empreinteStockee,
            false === $empreinteOrigine ? null : $empreinteOrigine,
            $resultat,
        );

        // La vignette après le dépôt, sur le fichier déjà purgé : elle n'a donc aucune
        // métadonnée à hériter, et un échec ici ne coûte pas le média.
        $media->setThumbnailName($this->vignettes->generer($this->stockage->chemin($nom), $mime));

        $avertissements = $this->avertirDuDoublon(
            $empreinteStockee,
            false === $empreinteOrigine ? null : $empreinteOrigine,
            $deposant,
        );

        $this->em->persist($media);
        $this->em->flush();

        // Auto-approbation du dépôt d'administrateur : c'est ici que le portrait
        // bascule, en passant par le seul service qui sait le faire. Un membre, lui,
        // repart avec un média `en_attente` — c'est `Proposal` qui l'appellera.
        if ($deposant->administrateur) {
            $this->circuit->valider($media);
        }

        return new ResultatDeTeleversement($media, $avertissements);
    }

    /**
     * Les règles que la base tiendra de toute façon (`ck_med_media_sujet_unique`,
     * `ck_med_media_brouillon_contexte`, `ck_med_media_portrait`), vérifiées ici pour
     * qu'elles rendent un `422` lisible plutôt qu'une violation de contrainte en `500`.
     */
    private function verifierLaCoherenceDuSujet(RequeteDeTeleversement $requete): void
    {
        $surLigne = null !== $requete->subjectId;
        $surBrouillon = null !== $requete->brouillonRef;

        if ($surLigne === $surBrouillon) {
            throw new ValidationException(['subjectId' => 'Indiquez soit un sujet existant, soit une référence de brouillon avec sa demande.']);
        }

        if ($surBrouillon) {
            if (null === $requete->demandeId) {
                throw new ValidationException([/* Une référence provisoire n'a de sens que dans le contexte de *sa* */ /* demande : `p1` de la demande 12 et `p1` de la demande 13 ne */ /* désignent pas la même personne. */ 'demandeId' => 'Une référence de brouillon exige la demande qui la porte.']);
            }

            if (1 !== preg_match(self::REF_BROUILLON_VALIDE, (string) $requete->brouillonRef)) {
                throw new ValidationException(['brouillonRef' => 'Référence de brouillon invalide (attendu : « p1 », sans dièse).']);
            }

            if (MediaSubject::Personne !== $requete->subjectKind) {
                throw new ValidationException(['subjectKind' => 'Seule une personne peut être désignée par une référence de brouillon.']);
            }
        }

        if (MediaKind::Portrait === $requete->kind && MediaSubject::Personne !== $requete->subjectKind) {
            throw new ValidationException(['kind' => 'Un portrait ne se rattache qu\'à une personne.']);
        }

        if (null !== $requete->caption && mb_strlen($requete->caption) > 255) {
            throw new ValidationException(['caption' => 'La légende dépasse 255 caractères.']);
        }
    }

    private function resoudreLeSujet(RequeteDeTeleversement $requete): ?SujetMedia
    {
        if (null === $requete->subjectId) {
            return null;
        }

        $sujet = $this->visibilite->decrire($requete->subjectKind, $requete->subjectId);

        if (null === $sujet) {
            // Le sujet n'existe pas, ou le déposant n'a rien à y faire — indistinguable
            // par construction (§ 15.3 du contrat). Un `403` ici confirmerait qu'une
            // personne masquée existe sous cet identifiant.
            throw new NotFoundException();
        }

        return $sujet;
    }

    private function construire(
        RequeteDeTeleversement $requete,
        LecteurMedia $deposant,
        ?SujetMedia $sujet,
        string $nomDeStockage,
        string $mime,
        int $taille,
        string $sha256,
        ?string $shaOrigine,
        ResultatDePurge $purge,
    ): Media {
        $media = new Media(
            kind: $requete->kind,
            subjectKind: $requete->subjectKind,
            subjectLabel: $sujet->libelle ?? self::LIBELLE_BROUILLON,
            storageName: $nomDeStockage,
            mimeType: $mime,
            byteSize: $taille,
            sha256: $sha256,
            uploadedByUserId: $deposant->userId,
            uploadedByLabel: $deposant->libelle,
        );

        if (null !== $sujet) {
            $media->rattacherA($sujet->id);
        } else {
            $media->rattacherAuBrouillon((string) $requete->brouillonRef, (int) $requete->demandeId);
        }

        $media
            ->setOriginalName(null === $requete->nomOrigine ? null : mb_substr($requete->nomOrigine, 0, 255))
            ->setOriginalSha256($shaOrigine)
            ->setExifStripped($purge->purgee)
            ->setDimensions($purge->largeur, $purge->hauteur)
            ->setPageCount($purge->pages)
            ->setCaption($requete->caption);

        if (null !== $requete->taken) {
            $media->setTaken($requete->taken);
        }

        // § 7.1 du contrat : « Un dépôt direct d'administrateur naît `actif` ». Le dépôt
        // d'un membre naît `en_attente` et attend sa demande.
        if ($deposant->administrateur) {
            $media->setStatus(MediaStatus::Actif);
        }

        return $media;
    }

    /**
     * Détection de doublon — et la raison pour laquelle elle se tait parfois.
     *
     * Le message du § 7.2 nomme la fiche sur laquelle le fichier se trouve déjà. Or ce
     * message est une **porte de sortie** au sens du § 16.2 : si le doublon est rattaché
     * à une personne vivante hors du cercle du déposant, le nommer publie exactement ce
     * que le cercle protège — et le seul `mediaId` suffirait à confirmer l'existence
     * d'un média sur une fiche masquée.
     *
     * On applique donc la règle générale du projet : *tout champ dérivé d'une donnée
     * masquée est lui-même une donnée masquée*. Un doublon invisible ne produit **aucun**
     * avertissement — pas un avertissement anonyme, qui resterait un oracle permettant
     * de tester la présence d'un fichier qu'on possède déjà.
     *
     * @return list<Avertissement>
     */
    private function avertirDuDoublon(string $sha256, ?string $shaOrigine, LecteurMedia $deposant): array
    {
        $avertissements = [];

        foreach ($this->medias->doublons($sha256, $shaOrigine) as $existant) {
            $sujetId = $existant->getSubjectId();

            if (null === $sujetId
                || !$this->visibilite->estVisible($existant->getSubjectKind(), $sujetId)) {
                continue;
            }

            $avertissements[] = new Avertissement(
                'doublon_probable',
                \sprintf(
                    'Ce fichier est déjà dans l\'arbre, sur la fiche de %s.',
                    $existant->getSubjectLabel(),
                ),
                $existant->getId(),
            );
        }

        return $avertissements;
    }
}
