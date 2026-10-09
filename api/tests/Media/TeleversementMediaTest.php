<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Enum\DepotSurFicheProposee;
use App\Module\Core\Exception\DomainException;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Service\DetecteurDeType;
use App\Module\Media\Service\GenerateurDeVignette;
use App\Module\Media\Service\PurgeDesMetadonnees;
use App\Module\Media\Service\RequeteDeTeleversement;
use App\Module\Media\Service\StockageMedia;
use App\Module\Media\Service\TeleversementMedia;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

/**
 * Le dépôt d'un média, du fichier reçu à la ligne en base.
 *
 * Les cas heureux sont là pour que les cas d'attaque veuillent dire quelque chose ; ce
 * sont les seconds qui justifient le fichier.
 */
final class TeleversementMediaTest extends CasDeTestMedia
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->personneDecedee(17, 'Marne Dolomite Sureau');
    }

    // ---------------------------------------------------------------------------------
    // Les contrôles d'entrée
    // ---------------------------------------------------------------------------------

    /**
     * Deux fichiers dont le nom et le `Content-Type` annoncés mentent, refusés en `415`.
     *
     * ── Pourquoi deux charges, et pourquoi plus de porte dérobée ────────────────────
     *
     * Ce test n'en avait qu'une, et elle était **illisible** : l'antivirus de Windows
     * verrouillait le fichier à l'écriture, `DetecteurDeType::detecter()` sortait sur son
     * garde d'entrée sans lire un octet, et le `415` tombait pour une raison qui n'avait
     * rien à voir avec le reniflage. Le test passait donc en décrivant l'inverse de ce
     * qu'il établissait. Le journal complet est dans
     * `FabriqueDeFichiers::texteDeguiseEnImage()`.
     *
     * Les deux charges d'aujourd'hui se relisent partout — `verifierRelisible()` le
     * vérifie avant la première assertion — et la seconde est le cas réellement retors :
     * un PDF précédé de six octets de rebut, ce que la spécification tolère sur 1 024
     * octets et que ce module refuse, parce que c'est la marge où se loge un polyglotte.
     *
     * @param non-empty-string $octets
     * @param non-empty-string $nomAnnonce
     */
    #[DataProvider('chargesDontLeNomMent')]
    public function testUnFichierDontLExtensionMentSurLeContenuEstRefuse(string $octets, string $nomAnnonce, string $suffixe): void
    {
        $chemin = $this->fichierTemporaire($octets, $suffixe);

        // Avant tout le reste : si la charge ne se relit pas, le `415` qui suit ne
        // prouverait que l'existence d'un verrou sur le fichier.
        FabriqueDeFichiers::verifierRelisible($chemin);

        try {
            $this->televersement->televerser($this->requete($chemin, $nomAnnonce), $this->membre());
            self::fail(\sprintf('« %s » n’est pas une image : le dépôt aurait dû être refusé.', $nomAnnonce));
        } catch (DomainException $erreur) {
            self::assertSame(415, $erreur->statutHttp());
            self::assertSame('type_de_fichier_refuse', $erreur->codeErreur());

            // Le message ne dit pas ce qui a été détecté : ce serait un service de
            // reniflage gratuit offert à qui téléverse n'importe quoi.
            self::assertStringNotContainsStringIgnoringCase('php', $erreur->getMessage());
            self::assertStringNotContainsStringIgnoringCase('text/', $erreur->getMessage());
            self::assertStringNotContainsStringIgnoringCase('pdf', $erreur->getMessage());

            // En revanche il dit ce qui est accepté : refuser sans dire quoi faire
            // oblige l'utilisateur à deviner.
            self::assertSame(['mimesAutorises' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']], $erreur->details());
        }

        self::assertSame([], $this->medias->findAll(), 'Rien ne doit rester en base après un refus.');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function chargesDontLeNomMent(): iterable
    {
        yield 'du texte qui se dit JPEG' => [
            FabriqueDeFichiers::texteDeguiseEnImage(),
            'acte-de-naissance.jpg',
            '.jpg',
        ];

        yield 'un polyglotte GIF89a + PDF' => [
            FabriqueDeFichiers::pdfPrecedeDeRebut(),
            'acte-de-naissance.pdf',
            '.pdf',
        ];
    }

    public function testUnFichierTropVolumineuxEstRefuseAvantToutDecodage(): void
    {
        $televersement = $this->televersementAvecPlafond(1_000);
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(300, 300), '.jpg');

        try {
            $televersement->televerser($this->requete($chemin), $this->membre());
            self::fail('Le fichier dépasse le plafond, il aurait dû être refusé.');
        } catch (DomainException $erreur) {
            self::assertSame(413, $erreur->statutHttp());
            self::assertSame('fichier_trop_volumineux', $erreur->codeErreur());
            self::assertSame(['maxOctets' => 1_000], $erreur->details());
        }
    }

    public function testUnCompteEnAttenteNeDeposeRien(): void
    {
        // § 12.1 du contrat : la lecture est ouverte, l'écriture est fermée. Une demande
        // déposée par un compte dont le rattachement n'est pas établi serait une
        // contribution sans auteur identifié.
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg');

        try {
            $this->televersement->televerser($this->requete($chemin), $this->enAttente());
            self::fail('Un compte en attente ne dépose pas de média.');
        } catch (DomainException $erreur) {
            self::assertSame(403, $erreur->statutHttp());
            self::assertSame('regime_en_attente', $erreur->codeErreur());
        }
    }

    public function testUnDepotSurUneFicheHorsCercleEstIntrouvable(): void
    {
        $this->personneVivante(99, 'Personne vivante');
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg');

        try {
            $this->televersement->televerser(
                $this->requete($chemin, sujetId: 99),
                $this->membre(),
            );
            self::fail('Le sujet est hors cercle : le dépôt aurait dû rendre 404.');
        } catch (DomainException $erreur) {
            // `404` et non `403` : un `403` confirmerait qu'une personne existe sous cet
            // identifiant, ce qui est exactement ce que le cercle protège.
            self::assertSame(404, $erreur->statutHttp());
            self::assertSame('introuvable', $erreur->codeErreur());
            self::assertStringNotContainsString('Personne vivante', $erreur->getMessage());
        }
    }

    // ---------------------------------------------------------------------------------
    // Ce que le dépôt écrit
    // ---------------------------------------------------------------------------------

    public function testLeFichierStockeNePorteNiLeNomDOrigineNiSesCoordonnees(): void
    {
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegAvecGps(400, 300), '.jpg');

        $resultat = $this->televersement->televerser(
            $this->requete($chemin, 'IMG_20240712_Maison_Epinaille.jpg'),
            $this->membre(),
        );

        $media = $resultat->media;

        // Le nom d'origine est conservé **comme libellé**, jamais comme chemin.
        self::assertSame('IMG_20240712_Maison_Epinaille.jpg', $media->getOriginalName());
        self::assertMatchesRegularExpression(StockageMedia::NOM_VALIDE, $media->getStorageName());
        self::assertStringNotContainsString('Epinaille', $media->getStorageName());

        // Deux dépôts du même fichier, sur le même sujet, par le même compte, donnent
        // deux noms différents : le nom ne dérive de rien d'autre que du hasard. (On ne
        // cherche pas « 17 » dans le nom : 32 chiffres hexadécimaux contiennent
        // n'importe quelle paire une fois sur huit, et l'assertion serait instable sans
        // rien prouver.)
        $second = $this->televersement->televerser(
            $this->requete($this->fichierTemporaire(FabriqueDeFichiers::jpegAvecGps(400, 300), '.jpg')),
            $this->membre(),
        )->media;
        self::assertNotSame($media->getStorageName(), $second->getStorageName());

        // Et le fichier réellement posé sur le disque n'a plus de coordonnées.
        $octets = (string) file_get_contents($this->stockage->chemin($media->getStorageName()));
        self::assertFalse(FabriqueDeFichiers::porteDesCoordonneesGps($octets));
        self::assertTrue($media->isExifStripped());

        // L'empreinte est celle du fichier stocké, et elle est bien calculée sur lui.
        self::assertSame(hash('sha256', $octets), $media->getSha256());
        // Celle du fichier reçu est conservée à part : le ré-encodage rend deux dépôts
        // du même original différents octet pour octet.
        self::assertNotSame($media->getSha256(), $media->getOriginalSha256());

        self::assertSame(400, $media->getWidth());
        self::assertSame(300, $media->getHeight());
    }

    public function testUneVignetteEstGovernerAuDepot(): void
    {
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(1200, 800), '.jpg');

        $media = $this->televersement->televerser($this->requete($chemin), $this->membre())->media;
        $vignette = $media->getThumbnailName();

        self::assertNotNull($vignette, 'Une image doit avoir sa vignette dès l\'enregistrement.');
        self::assertTrue($this->stockage->existe($vignette, vignette: true));

        $taille = getimagesize($this->stockage->chemin($vignette, vignette: true));
        self::assertIsArray($taille);
        self::assertSame(320, $taille[0], 'Le grand côté de la vignette doit être ramené à 320 px.');
        self::assertLessThanOrEqual(320, $taille[1]);
    }

    public function testUnPdfNAPasDeVignetteEtNAffirmePasEtrePurge(): void
    {
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::pdfAvecMetadonnees(), '.pdf');

        $media = $this->televersement->televerser($this->requete($chemin, 'livret.pdf'), $this->membre())->media;

        self::assertSame('application/pdf', $media->getMimeType());
        self::assertNull($media->getThumbnailName());
        self::assertFalse($media->isExifStripped());
        self::assertSame(1, $media->getPageCount());
    }

    public function testLeDepotDUnMembreNaitEnAttenteEtCeluiDUnAdministrateurNaitActif(): void
    {
        $membre = $this->televersement->televerser(
            $this->requete($this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg')),
            $this->membre(),
        )->media;

        $admin = $this->televersement->televerser(
            $this->requete($this->fichierTemporaire(FabriqueDeFichiers::pngTransparent(), '.png')),
            $this->administrateur(),
        )->media;

        self::assertSame(MediaStatus::EnAttente, $membre->getStatus());
        self::assertSame(MediaStatus::Actif, $admin->getStatus());
    }

    // ---------------------------------------------------------------------------------
    // Doublons
    // ---------------------------------------------------------------------------------

    public function testDeuxDepotsDuMemeFichierSontSignalesCommeDoublon(): void
    {
        $original = FabriqueDeFichiers::jpegAvecGps(200, 150);

        $premier = $this->televersement->televerser(
            $this->requete($this->fichierTemporaire($original, '.jpg'), 'acte.jpg'),
            $this->membre(),
        );
        self::assertSame([], $premier->avertissements, 'Le premier dépôt n\'a pas de doublon.');

        $second = $this->televersement->televerser(
            $this->requete($this->fichierTemporaire($original, '.jpg'), 'acte-copie.jpg'),
            $this->membre(),
        );

        self::assertCount(1, $second->avertissements);
        self::assertSame('doublon_probable', $second->avertissements[0]->code);
        self::assertSame($premier->media->getId(), $second->avertissements[0]->mediaId);
        self::assertStringContainsString('Marne Dolomite Sureau', $second->avertissements[0]->message);

        // Le doublon est un **avertissement**, pas un refus : le même acte de mariage
        // étaye légitimement les fiches des deux époux et de leurs quatre parents.
        self::assertNotNull($second->media->getId());
        self::assertNotSame($premier->media->getId(), $second->media->getId());
    }

    public function testUnDoublonSurUneFicheHorsCercleNeProduitAucunAvertissement(): void
    {
        // Le message du § 7.2 nomme la fiche où le fichier se trouve déjà : c'est une
        // porte de sortie. Si ce fichier est rattaché à une personne masquée, le message
        // publie exactement ce que le cercle protège — et le seul `mediaId` suffirait à
        // confirmer qu'un média existe sur une fiche masquée.
        $this->personneVivante(99, 'Aïeule vivante');

        $original = FabriqueDeFichiers::jpegNu(120, 90);

        // L'administrateur voit tout : c'est lui qui dépose sur la fiche masquée.
        $this->televersement->televerser(
            $this->requete($this->fichierTemporaire($original, '.jpg'), sujetId: 99),
            $this->administrateur(),
        );

        $second = $this->televersement->televerser(
            $this->requete($this->fichierTemporaire($original, '.jpg')),
            $this->membre(),
        );

        self::assertSame([], $second->avertissements, 'Un doublon invisible ne doit produire aucun avertissement.');
    }

    // ---------------------------------------------------------------------------------
    // Cohérence du sujet
    // ---------------------------------------------------------------------------------

    public function testUnPortraitNeSeRattacheQuAUnePersonne(): void
    {
        $this->citation(3, 'Acte de mariage, 1910', [17]);
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg');

        try {
            $this->televersement->televerser(
                new RequeteDeTeleversement(
                    cheminTemporaire: $chemin,
                    nomOrigine: 'portrait.jpg',
                    kind: MediaKind::Portrait,
                    subjectKind: MediaSubject::Citation,
                    subjectId: 3,
                ),
                $this->membre(),
            );
            self::fail('Un portrait de citation n\'existe pas.');
        } catch (DomainException $erreur) {
            self::assertSame(422, $erreur->statutHttp());
            self::assertArrayHasKey('kind', (array) $erreur->champs());
        }
    }

    public function testUnSujetEtUnBrouillonSExcluent(): void
    {
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg');

        try {
            $this->televersement->televerser(
                new RequeteDeTeleversement(
                    cheminTemporaire: $chemin,
                    nomOrigine: 'acte.jpg',
                    kind: MediaKind::Preuve,
                    subjectKind: MediaSubject::Personne,
                    subjectId: 17,
                    demandeId: 12,
                    brouillonRef: 'p1',
                ),
                $this->membre(),
            );
            self::fail('Le sujet et la référence de brouillon s\'excluent.');
        } catch (DomainException $erreur) {
            self::assertSame(422, $erreur->statutHttp());
        }
    }

    public function testUneReferenceDeBrouillonSansSaDemandeEstRefusee(): void
    {
        // « `p1` de la demande 12 et `p1` de la demande 13 ne désignent pas la même
        // personne » : une référence provisoire hors contexte ne veut rien dire.
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg');

        try {
            $this->televersement->televerser(
                new RequeteDeTeleversement(
                    cheminTemporaire: $chemin,
                    nomOrigine: 'acte.jpg',
                    kind: MediaKind::Preuve,
                    subjectKind: MediaSubject::Personne,
                    brouillonRef: 'p1',
                ),
                $this->membre(),
            );
            self::fail('Une référence de brouillon exige sa demande.');
        } catch (DomainException $erreur) {
            self::assertSame(422, $erreur->statutHttp());
            self::assertArrayHasKey('demandeId', (array) $erreur->champs());
        }
    }

    public function testUnMediaDeBrouillonSEnregistreSansToucherAuxPersonnesExistantes(): void
    {
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg');

        $media = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $chemin,
                nomOrigine: 'acte-du-grand-oncle.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                demandeId: 12,
                brouillonRef: 'p1',
            ),
            $this->membre(),
        )->media;

        self::assertNull($media->getSubjectId());
        self::assertSame('p1', $media->getSubjectDraftRef());
        self::assertSame(12, $media->getSubjectRequestId());
        // Le libellé est anonyme : le nom proposé appartient à `prp_draft_person`, et le
        // recopier ici en ferait un second exemplaire à filtrer.
        self::assertSame('Personne proposée', $media->getSubjectLabel());

        // **`subjectKind` vaut « personne », jamais « brouillon »** (§ 7.1, tranché).
        // `med_media.subject_kind` est `NOT NULL` et décrit le **type de sujet** ; l'état
        // de résolution est porté par la paire `subject_id` / `subject_draft_ref`, et par
        // elle seule. Une ligne qui porterait « brouillon » devrait être réécrite à la
        // validation, et l'oubli ferait disparaître le média de la fiche sans erreur.
        self::assertSame(MediaSubject::Personne, $media->getSubjectKind());
    }

    public function testLaResolutionDUnBrouillonNeTouchePasAuTypeDeSujet(): void
    {
        $media = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'acte.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                demandeId: 12,
                brouillonRef: 'p1',
            ),
            $this->membre(),
        )->media;

        $this->circuit->resoudreLeBrouillon(12, 'p1', 275, 'Lave Sarriette');

        // La validation pose `subject_id` et efface `subject_draft_ref`. `subject_kind`
        // ne bouge pas — il ne bougeait déjà pas avant, et c'est ce que ce cas verrouille.
        self::assertSame(MediaSubject::Personne, $media->getSubjectKind());
        self::assertSame(275, $media->getSubjectId());
        self::assertNull($media->getSubjectDraftRef());
    }

    // ---------------------------------------------------------------------------------
    // Le droit de joindre une pièce à une personne proposée — la traduction du verdict
    // ---------------------------------------------------------------------------------
    //
    // La règle elle-même (qui peut, sur quel statut) appartient à `Proposal` et se teste
    // là-bas, sur une vraie base : `tests/Proposal/FichesProposeesPourLesModulesTest.php`.
    // De bout en bout, câblage compris : `TeleversementSurFicheProposeeTest`. Ici, ce que
    // ce service fait du verdict — et surtout **quand** il le demande.

    /**
     * **Le refus tombe avant le fichier.** Le fichier envoyé est un texte déguisé en
     * image, que le contrôle de type refuserait en `415` : si c'est le `404` qui sort,
     * c'est que la question a été posée avant que le moindre octet soit lu. Et le
     * stockage est resté vide — ni original, ni vignette.
     */
    public function testUnRefusSurUneFicheProposeeTombeAvantToutContactAvecLeFichier(): void
    {
        $this->fichesProposees->repondre(DepotSurFicheProposee::Introuvable);
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::texteDeguiseEnImage(), '.jpg');

        try {
            $this->televersement->televerser($this->requeteSurBrouillon($chemin), $this->membre());
            self::fail('La fiche proposée n’est pas joignable : le dépôt aurait dû rendre 404.');
        } catch (DomainException $erreur) {
            self::assertSame(404, $erreur->statutHttp(), 'Le 404 de la fiche proposée, pas le 415 du fichier : la question précède la lecture.');
            self::assertSame('introuvable', $erreur->codeErreur());
        }

        self::assertSame([], $this->fichiersStockes(), 'Un dépôt refusé n’écrit rien sur le disque.');
        self::assertSame([], $this->medias->findAll(), 'Ni aucune ligne en base.');
    }

    /**
     * `Tranchee` ⇒ `409 demande_deja_decidee`, **sans** `details` : ce module ne connaît
     * pas les statuts de `Proposal`, seulement le verdict — et un statut inventé ici serait
     * pire qu'une clé absente.
     */
    public function testUneDemandeTrancheeRend409DemandeDejaDecidee(): void
    {
        $this->fichesProposees->repondre(DepotSurFicheProposee::Tranchee);
        $chemin = $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg');

        try {
            $this->televersement->televerser($this->requeteSurBrouillon($chemin), $this->membre());
            self::fail('La demande est tranchée : le dépôt aurait dû rendre 409.');
        } catch (DomainException $erreur) {
            self::assertSame(409, $erreur->statutHttp());
            self::assertSame('demande_deja_decidee', $erreur->codeErreur());
            self::assertNull($erreur->details());
        }

        self::assertSame([], $this->fichiersStockes());
    }

    /**
     * La question porte **ce que le service tient** : la demande, la référence telle
     * qu'elle est arrivée (`p1`, sans dièse — c'est au contrat d'absorber l'écart), le
     * compte et son rôle. Un rôle mal transmis ferait d'un administrateur un membre, ou
     * l'inverse, et la règle déciderait juste sur une question fausse.
     */
    public function testLaQuestionPorteLaDemandeLaReferenceLeCompteEtLeRole(): void
    {
        $this->televersement->televerser(
            $this->requeteSurBrouillon($this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg')),
            $this->membre(7),
        );
        $this->televersement->televerser(
            $this->requeteSurBrouillon($this->fichierTemporaire(FabriqueDeFichiers::jpegNu(64, 48), '.jpg')),
            $this->administrateur(1),
        );

        self::assertSame(
            [
                ['demandeId' => 12, 'localRef' => 'p1', 'userId' => 7, 'administrateur' => false],
                ['demandeId' => 12, 'localRef' => 'p1', 'userId' => 1, 'administrateur' => true],
            ],
            $this->fichesProposees->questions(),
        );
    }

    /** Un dépôt sur une ligne existante n'a rien à demander à `Proposal`. */
    public function testUnDepotSurUneLigneExistanteNeConsultePasLesFichesProposees(): void
    {
        $this->fichesProposees->repondre(DepotSurFicheProposee::Introuvable);

        $this->televersement->televerser(
            $this->requete($this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg')),
            $this->membre(),
        );

        self::assertSame([], $this->fichesProposees->questions());
    }

    // ---------------------------------------------------------------------------------

    private function requeteSurBrouillon(string $chemin): RequeteDeTeleversement
    {
        return new RequeteDeTeleversement(
            cheminTemporaire: $chemin,
            nomOrigine: 'acte.jpg',
            kind: MediaKind::Preuve,
            subjectKind: MediaSubject::Personne,
            demandeId: 12,
            brouillonRef: 'p1',
        );
    }

    /**
     * Tout ce que le stockage porte, originaux et vignettes confondus.
     *
     * @return list<string>
     */
    private function fichiersStockes(): array
    {
        if (!is_dir($this->racine)) {
            return [];
        }

        $fichiers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS)) as $fichier) {
            \assert($fichier instanceof \SplFileInfo);
            $fichiers[] = $fichier->getPathname();
        }

        return $fichiers;
    }

    private function requete(string $chemin, ?string $nom = 'acte.jpg', int $sujetId = 17): RequeteDeTeleversement
    {
        return new RequeteDeTeleversement(
            cheminTemporaire: $chemin,
            nomOrigine: $nom,
            kind: MediaKind::Preuve,
            subjectKind: MediaSubject::Personne,
            subjectId: $sujetId,
        );
    }

    private function televersementAvecPlafond(int $octets): TeleversementMedia
    {
        return new TeleversementMedia(
            $this->em,
            $this->medias,
            $this->reglages($octets),
            new DetecteurDeType(),
            new PurgeDesMetadonnees(sys_get_temp_dir()),
            $this->stockage,
            new GenerateurDeVignette($this->stockage, new NullLogger()),
            $this->visibilite,
            $this->circuit,
            $this->fichesProposees,
        );
    }
}
