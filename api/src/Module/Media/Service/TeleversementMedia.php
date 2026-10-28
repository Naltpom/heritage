<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use App\Module\Core\Contract\FichesProposeesInterface;
use App\Module\Core\Enum\DepotSurFicheProposee;
use App\Module\Core\Enum\Regime;
use App\Module\Core\Exception\ConflictException;
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
 *    Puis la cohérence du formulaire (`422`), qui ne lit que les champs.
 * 1 bis. **Le droit de joindre une pièce à une personne proposée**, quand le dépôt vise
 *    un brouillon (`demandeId` + `brouillonRef`) — demandé à `Proposal` par
 *    {@see FichesProposeesInterface}, `404` ou `409 demande_deja_decidee`. **Avant le
 *    fichier**, et c'est le point : un dépôt refusé ne doit avoir rien lu, rien purgé,
 *    rien écrit sur le disque. Voir {@see self::verifierLeDroitSurLaFicheProposee()}.
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
 * `actif` (§ 7.1 du contrat) et bascule le portrait immédiatement — sauf sous un jeton
 * d'API qui ne décide pas, où il arrive ici en membre
 * ({@see \App\Module\Media\Security\FabriqueDeLecteur::deposant()}, ADR 0021).
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

    /**
     * Le libellé d'un portrait posé sur un **compte** sans fiche (ADR 0013). Anonyme pour
     * la même raison que {@see self::LIBELLE_BROUILLON} : l'identité déclarée appartient à
     * `mbr_member`, et le recopier ici en ferait une copie qui survivrait à sa purge. Le
     * vrai libellé est posé au rattachement.
     */
    public const string LIBELLE_PORTRAIT_DE_COMPTE = 'Portrait en attente de rattachement';

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
        /**
         * Le seul moyen de savoir si le déposant a le droit de joindre une pièce à une
         * personne **proposée** : les demandes appartiennent à `Proposal`, que ce module
         * n'a pas le droit de lire (ADR 0002, règle 3). Repli fermé dans `core.yaml`.
         */
        private readonly FichesProposeesInterface $fichesProposees,
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

        // 1 bis — une personne proposée n'accepte de pièce que de son auteur, ou d'un
        // administrateur qui instruit la demande. Avant le fichier : un refus ne coûte
        // ni lecture, ni ré-encodage, ni octet sur le disque.
        $this->verifierLeDroitSurLaFicheProposee($requete, $deposant);

        // 2 et 3 — taille, puis type réel.
        $mime = $this->controlerLeFichier($requete->cheminTemporaire);

        // 4 — le sujet, vu par son module propriétaire.
        $sujet = $this->resoudreLeSujet($requete);

        // 5 et 6 — purge, empreintes et dépôt.
        [$nom, $empreinteStockee, $empreinteOrigine, $resultat] = $this->deposerLeFichier($requete->cheminTemporaire, $mime);

        $media = $this->construire(
            $requete,
            $deposant,
            $sujet,
            $nom,
            $mime,
            $this->stockage->taille($nom),
            $empreinteStockee,
            $empreinteOrigine,
            $resultat,
        );

        // La vignette après le dépôt, sur le fichier déjà purgé : elle n'a donc aucune
        // métadonnée à hériter, et un échec ici ne coûte pas le média.
        $media->setThumbnailName($this->vignettes->generer($this->stockage->chemin($nom), $mime));

        $avertissements = $this->avertirDuDoublon(
            $empreinteStockee,
            $empreinteOrigine,
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
     * **La photo d'un compte qui n'a pas encore de fiche validée** — l'étape « Votre
     * photo » de l'inscription (ADR 0013, `POST /api/medias/portrait-du-compte`).
     *
     * ## Ce qui change, et ce qui ne change pas
     *
     * **Le contrôle 1 saute** : le compte est au régime `Aucun`, qui n'écrit rien — mais
     * ce n'est pas une écriture dans l'arbre. La photo ne touche à aucune fiche : elle
     * attend **sur le compte** (`subject_user_id`), `en_attente`, visible de son titulaire
     * et des administrateurs seulement. Elle ne rejoindra une fiche qu'au rattachement,
     * sur décision d'un administrateur (`PortraitsDesComptesEnAttente::rattacherALaFiche()`).
     *
     * **Le contrôle 4 saute** aussi : il n'y a pas de sujet de l'arbre à résoudre.
     *
     * **Tout le reste est identique** — taille, reniflage du type, purge EXIF par
     * ré-encodage, nom aléatoire, empreintes, vignette. Une photo de téléphone porte des
     * coordonnées GPS aussi sûrement à l'inscription qu'après.
     *
     * Aucune alerte de doublon : elle nommerait une fiche à un compte qui n'a pas de cercle.
     *
     * ## Un seul portrait en attente par compte
     *
     * Un second dépôt **remplace** le premier, fichier compris : c'est un choix de photo
     * qu'on refait, pas une file de propositions. `uniq_med_media_portrait_de_compte` le
     * garantit en base ; on supprime l'ancien **avant** d'insérer le nouveau.
     */
    public function televerserLePortraitDuCompte(
        string $cheminTemporaire,
        ?string $nomOrigine,
        LecteurMedia $deposant,
    ): ResultatDeTeleversement {
        $mime = $this->controlerLeFichier($cheminTemporaire);

        [$nom, $empreinteStockee, $empreinteOrigine, $purge] = $this->deposerLeFichier($cheminTemporaire, $mime);

        $media = new Media(
            kind: MediaKind::Portrait,
            subjectKind: MediaSubject::Personne,
            subjectLabel: self::LIBELLE_PORTRAIT_DE_COMPTE,
            storageName: $nom,
            mimeType: $mime,
            byteSize: $this->stockage->taille($nom),
            sha256: $empreinteStockee,
            uploadedByUserId: $deposant->userId,
            uploadedByLabel: $deposant->libelle,
        );

        $media
            ->rattacherAuCompte($deposant->userId)
            ->setOriginalName(null === $nomOrigine ? null : mb_substr($nomOrigine, 0, 255))
            ->setOriginalSha256($empreinteOrigine)
            ->setExifStripped($purge->purgee)
            ->setDimensions($purge->largeur, $purge->hauteur)
            ->setPageCount($purge->pages)
            ->setStatus(MediaStatus::EnAttente);

        $media->setThumbnailName($this->vignettes->generer($this->stockage->chemin($nom), $mime));

        // Retrait de l'ancien et insertion du nouveau **dans une même transaction** : deux
        // `flush()` en autocommit laissaient, sur un échec du second, le compte sans photo
        // du tout. Et les fichiers de l'ancien ne s'effacent qu'**après** le commit — voir
        // {@see supprimerLaLigne()}.
        $effacements = $this->em->wrapInTransaction(function () use ($media, $deposant): array {
            $effacements = [];

            foreach ($this->medias->portraitsEnAttenteDuCompte($deposant->userId) as $ancien) {
                $effacements[] = $this->supprimerLaLigne($ancien);
            }

            // Le retrait d'abord, vidé seul : `uniq_med_media_portrait_de_compte` refuserait
            // le nouveau tant que l'ancien est encore en base.
            $this->em->flush();

            $this->em->persist($media);
            $this->em->flush();

            return $effacements;
        });

        foreach ($effacements as $effacer) {
            $effacer();
        }

        return new ResultatDeTeleversement($media, []);
    }

    /**
     * Retire la ligne d'un portrait de compte remplacé ou écarté — qui n'a jamais été
     * visible de personne d'autre que son titulaire et les administrateurs — et **rend**
     * l'effacement de ses deux fichiers, sans le faire.
     *
     * ## Les fichiers après le commit, jamais avant
     *
     * La suppression de la ligne vit dans une transaction ; celle des fichiers, non — un
     * `unlink()` ne se rejoue pas. Effacer d'abord, c'était, sur une transaction annulée
     * (un refus qui échoue sur une contrainte, un verrou, une coupure), garder en base une
     * ligne qui pointe vers un fichier disparu : une photo cassée, au mieux. L'appelant
     * joue donc la fermeture rendue **une fois la transaction validée**, et l'oublie si
     * elle échoue — un fichier orphelin vaut mieux qu'une ligne orpheline.
     *
     * **Ne vide pas** l'unité de travail : l'appelant le fait.
     *
     * @return \Closure(): void l'effacement des deux fichiers, à jouer après le commit
     */
    public function supprimerLaLigne(Media $media): \Closure
    {
        $original = $media->getStorageName();
        $vignette = $media->getThumbnailName();

        $this->em->remove($media);

        return function () use ($original, $vignette): void {
            $this->stockage->supprimer($original);

            if (null !== $vignette) {
                $this->stockage->supprimer($vignette, vignette: true);
            }
        };
    }

    /**
     * Contrôles 2 et 3 : la taille, puis le type **réel** — par les octets d'en-tête,
     * jamais l'extension ni le `Content-Type` annoncé. Rend le type constaté.
     */
    private function controlerLeFichier(string $chemin): string
    {
        $octets = @filesize($chemin);

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

        // Le reniflage d'abord, la liste blanche ensuite : c'est le type constaté qu'on
        // confronte à la liste, jamais celui qu'on nous annonce.
        $mime = $this->detecteur->detecter($chemin);
        $autorises = $this->reglages->mimesAutorises();

        if (null === $mime || !\in_array($mime, $autorises, true)) {
            throw new TypeDeFichierRefuseException($autorises);
        }

        return $mime;
    }

    /**
     * Contrôles 5 et 6 : la purge par ré-encodage — ce qui est déposé n'est jamais le
     * fichier reçu, c'est sa réécriture —, puis les empreintes et le dépôt sous un nom
     * aléatoire.
     *
     * @return array{0: string, 1: string, 2: string|null, 3: ResultatDePurge} nom de stockage,
     *                                                                         empreinte stockée,
     *                                                                         empreinte d'origine,
     *                                                                         résultat de la purge
     */
    private function deposerLeFichier(string $chemin, string $mime): array
    {
        $resultat = $this->purge->purger($chemin, $mime);

        try {
            $empreinteStockee = hash_file('sha256', $resultat->chemin);
            $empreinteOrigine = hash_file('sha256', $chemin);

            if (false === $empreinteStockee) {
                throw new \RuntimeException('Empreinte du fichier stocké impossible à calculer.');
            }

            $nom = $this->stockage->nomAleatoire($mime);
            $this->stockage->deposer($resultat->chemin, $nom);
        } catch (\Throwable $erreur) {
            @unlink($resultat->chemin);

            throw $erreur;
        }

        return [$nom, $empreinteStockee, false === $empreinteOrigine ? null : $empreinteOrigine, $resultat];
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

    /**
     * **Le déposant a-t-il le droit de joindre une pièce à cette personne proposée ?**
     *
     * ## La faille que ce contrôle ferme
     *
     * Avant lui, ce service vérifiait la **forme** du couple `demandeId` + `brouillonRef`
     * ({@see self::verifierLaCoherenceDuSujet()}) et rien d'autre : ni que la demande
     * existe, ni son statut, ni que le déposant en soit l'auteur. Or à la validation,
     * `Proposal` rattache **tous** les médias du brouillon à la personne créée. N'importe
     * quel membre pouvait donc glisser une pièce dans la proposition d'un autre — un
     * numéro de demande se devine, `p1` est la référence de presque toutes —, et la voir
     * arriver sur la fiche validée comme si l'auteur l'avait jointe.
     *
     * ## La règle n'est pas ici
     *
     * Elle appartient à `Proposal`, qui possède les demandes : l'auteur sur `brouillon`
     * ou `en_attente`, un administrateur sur `en_attente`, et rien d'autre. Ce service ne
     * fait que traduire le verdict — le contrat le décrit en entier
     * ({@see FichesProposeesInterface}).
     *
     * ## Les deux réponses, et pourquoi elles diffèrent
     *
     * - `Introuvable` ⇒ **`404`**, le même que pour un sujet hors cercle
     *   ({@see self::resoudreLeSujet()}) : la demande n'existe pas, la référence n'y existe
     *   pas, ou elle n'est pas au déposant — indistinguables par construction (§ 15.3).
     *   Un `403` sur « pas à vous » et un `404` sur « n'existe pas » feraient de cette
     *   route un moyen d'énumérer les demandes des autres membres.
     * - `Tranchee` ⇒ **`409 demande_deja_decidee`** : la demande est la sienne, il sait
     *   qu'elle existe, et elle vient d'être tranchée sous ses yeux — validée pendant qu'il
     *   choisissait sa photo, le plus souvent. Le même code que valider, refuser ou
     *   retirer une demande tranchée (§ 15.2), que le front classe déjà en `recharger`
     *   (`app/src/lib/api/erreurs.ts`, `conduiteATenir()`).
     *   `details` est `null` et non `{statut}` comme sur ces trois routes : ce module ne
     *   connaît pas les statuts de `Proposal`, seulement le verdict.
     *
     * ## Avant le fichier, et c'est vérifié
     *
     * L'appel précède {@see self::controlerLeFichier()}. Un dépôt refusé ici n'a donc rien
     * lu, rien ré-encodé et rien écrit sur le disque — pas même le fichier temporaire de
     * la purge. `tests/Media/TeleversementSurFicheProposeeTest.php` le vérifie en
     * comptant les fichiers du stockage, et en envoyant un fichier que le contrôle de
     * type aurait refusé : c'est le `404` qui doit sortir, pas le `415`.
     */
    private function verifierLeDroitSurLaFicheProposee(RequeteDeTeleversement $requete, LecteurMedia $deposant): void
    {
        // `verifierLaCoherenceDuSujet()` a déjà exigé que les deux aillent ensemble ; un
        // dépôt sur une ligne existante (`subjectId`) n'a rien à demander à `Proposal`.
        if (null === $requete->brouillonRef || null === $requete->demandeId) {
            return;
        }

        $verdict = $this->fichesProposees->depot(
            $requete->demandeId,
            $requete->brouillonRef,
            $deposant->userId,
            $deposant->administrateur,
        );

        match ($verdict) {
            DepotSurFicheProposee::Joignable => null,
            DepotSurFicheProposee::Introuvable => throw new NotFoundException(),
            DepotSurFicheProposee::Tranchee => throw new ConflictException(
                'demande_deja_decidee',
                'Cette demande a déjà été tranchée : on ne peut plus y joindre de pièce.',
            ),
        };
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
            // Le couple a été confirmé par `Proposal` avant toute écriture
            // (`verifierLeDroitSurLaFicheProposee()`) : la demande existe, la référence
            // y désigne une fiche, et le déposant a le droit d'y joindre cette pièce.
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
        // d'un membre naît `en_attente` et attend sa demande — celui d'un administrateur
        // sous un jeton `contribution` aussi : son `$deposant` n'est pas administrateur.
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
