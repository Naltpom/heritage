<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Contract\Lecteur;
use App\Module\Core\Enum\SubjectKind;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Service\Proposition\ApplicateurDOperationMedia;
use App\Module\Media\Service\Proposition\ConstructeurDAjoutDeMedia;
use App\Module\Media\Service\Proposition\MediasDeBrouillon;
use App\Module\Media\Service\Proposition\ValidationDesMediasDeDemande;
use App\Module\Media\Service\RequeteDeTeleversement;
use App\Module\Proposal\Contract\DemandeEnCours;
use App\Module\Proposal\Contract\OpEnAttente;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Entity\ChangeRequestOp;
use App\Module\Proposal\Enum\OpKind;
use App\Module\Proposal\Enum\RequestKind;
use App\Module\Proposal\Enum\RequestStatus;
use App\Module\Proposal\Enum\ValueKind;
use App\Module\Proposal\Exception\DonneesInvalides;
use App\Module\Proposal\Repository\ChangeRequestOpRepository;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * `ajout_media` de bout en bout : le téléversement, la demande, la validation.
 *
 * Le § 6.5 du dossier de mission en une phrase : *un média déposé par un membre suit le
 * **même circuit de validation** que les autres modifications*. Ce fichier est ce qui le
 * prouve — et surtout, ce qui prouve le **négatif** : tant que la demande n'est pas
 * validée, la preuve reste `en_attente` et le portrait en place ne bouge pas.
 *
 * Il monte les tables de `Proposal` à côté de `med_media`, parce que la couture est
 * exactement là et qu'un test qui la doublerait ne testerait plus rien.
 */
final class CircuitDeValidationTest extends CasDeTestMedia
{
    private const int OPALE = 7;

    private ConstructeurDAjoutDeMedia $constructeur;
    private ApplicateurDOperationMedia $applicateur;
    private ChangeRequestOpRepository $opsDeDemande;

    protected function setUp(): void
    {
        parent::setUp();

        // Les trois tables de `Proposal` que la couture touche. Le reste du module —
        // brouillons, liens, révisions — n'est pas monté : ce test ne les écrit pas, et
        // les ajouter ferait payer un schéma pour rien.
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(ChangeRequest::class),
            $this->em->getClassMetadata(ChangeRequestOp::class),
        ]);

        $this->opsDeDemande = new ChangeRequestOpRepository(new DoublureDeRegistre($this->em));

        $this->constructeur = new ConstructeurDAjoutDeMedia($this->em, $this->catalogue);
        $this->applicateur = new ApplicateurDOperationMedia(
            new ValidationDesMediasDeDemande($this->medias, $this->circuit),
        );

        $this->personneDecedee(17, 'Marne Dolomite Sureau');
    }

    public function testUnePreuveNeDevientActiveQuALaValidation(): void
    {
        $media = $this->deposer(MediaKind::Preuve);
        self::assertSame(MediaStatus::EnAttente, $media->getStatus());

        $demande = $this->demande($media->getId());

        // La demande existe, le média est toujours en attente : le calque du § 13 montre
        // au demandeur ce qu'il a proposé, il ne le lui applique pas.
        self::assertSame(MediaStatus::EnAttente, $media->getStatus());

        $ecritures = $this->applicateur->appliquer($this->vue($demande), $this->opsEnAttente($demande), $this->lecteur());

        self::assertSame(MediaStatus::Actif, $media->getStatus());
        self::assertSame($demande->getId(), $media->getChangeRequestId());

        // Une révision, et son sujet est le **média** — pas la personne, dont aucune
        // colonne n'a changé.
        self::assertCount(1, $ecritures);
        self::assertSame(SubjectKind::Media, $ecritures[0]->subjectKind);
        self::assertSame($media->getId(), $ecritures[0]->subjectId);
    }

    public function testUnPortraitProposeNeRemplaceRienAvantValidation(): void
    {
        $enPlace = $this->deposer(MediaKind::Portrait, administrateur: true);
        self::assertTrue($enPlace->isActivePortrait());

        $propose = $this->deposer(MediaKind::Portrait);
        $demande = $this->demande($propose->getId());

        // Le point que le dossier demande : un membre ne remplace pas le portrait d'une
        // fiche sans que personne l'ait approuvé.
        self::assertTrue($enPlace->isActivePortrait());
        self::assertFalse($propose->isActivePortrait());

        $this->applicateur->appliquer($this->vue($demande), $this->opsEnAttente($demande), $this->lecteur());

        self::assertTrue($propose->isActivePortrait());
        self::assertFalse($enPlace->isActivePortrait());
        // « Les précédents sont conservés, pas écrasés. »
        self::assertSame(MediaStatus::Remplace, $enPlace->getStatus());
        self::assertFileExists($this->stockage->chemin($enPlace->getStorageName()));
    }

    public function testLaCibleDeLaDemandeSuitLeSujetDuMedia(): void
    {
        $media = $this->deposer(MediaKind::Preuve);
        $demande = $this->demande($media->getId());

        self::assertSame(SubjectKind::Personne, $demande->getTargetKind());
        self::assertSame(17, $demande->getTargetId());
        self::assertSame('Marne Dolomite Sureau', $demande->getTargetLabel());
    }

    public function testUneCibleQuiContreditLeMediaEstRefusee(): void
    {
        $this->personneDecedee(18, 'Jargon Sarriette');
        $media = $this->deposer(MediaKind::Preuve);

        $this->expectException(DonneesInvalides::class);

        $this->construire([
            'kind' => 'ajout_media',
            'targetKind' => 'personne',
            'targetId' => 18,
            'ops' => [$this->op($media->getId())],
        ]);
    }

    public function testOnNeJointPasLeMediaEnAttenteDUnAutre(): void
    {
        // Sans ce contrôle, un membre ferait valider à son insu le dépôt d'un autre.
        $media = $this->deposer(MediaKind::Preuve, deposant: 8);

        try {
            $this->construire([
                'kind' => 'ajout_media',
                'ops' => [$this->op($media->getId())],
            ]);
            self::fail('Un membre ne joint que ses propres dépôts.');
        } catch (DonneesInvalides $erreur) {
            $champs = (array) $erreur->champs();
            self::assertArrayHasKey('ops[0].newValue.mediaId', $champs);
            // Le message ne distingue pas « n'existe pas » de « pas à vous » : les deux
            // réponses sont identiques, sinon elles disent qui a déposé quoi. Le contrôle
            // porte sur `champs` et non sur `message`, parce que c'est `champs` que le
            // front affiche sous le champ fautif (§ 15.1).
            self::assertSame(
                'Ce média n\'existe pas, ou il ne vous appartient pas.',
                $champs['ops[0].newValue.mediaId'],
            );
        }
    }

    public function testUnMediaDejaValideNeRepasseParAucuneDemande(): void
    {
        $media = $this->deposer(MediaKind::Preuve, administrateur: true);
        self::assertSame(MediaStatus::Actif, $media->getStatus());

        $this->expectException(DonneesInvalides::class);

        $this->construire([
            'kind' => 'ajout_media',
            'ops' => [$this->op($media->getId())],
        ]);
    }

    public function testUneEmpreinteQuiDivergeEstRefusee(): void
    {
        $media = $this->deposer(MediaKind::Preuve);

        $this->expectException(DonneesInvalides::class);

        $this->construire([
            'kind' => 'ajout_media',
            'ops' => [[...$this->op($media->getId()), 'newValue' => [
                'mediaId' => $media->getId(),
                'sha256' => str_repeat('00', 32),
            ]]],
        ]);
    }

    public function testUneDemandeSansOpNeDesigneRien(): void
    {
        $this->expectException(DonneesInvalides::class);

        $this->construire(['kind' => 'ajout_media', 'ops' => []]);
    }

    public function testLeMemeMediaNEstPasDesigneDeuxFois(): void
    {
        $media = $this->deposer(MediaKind::Preuve);

        $this->expectException(DonneesInvalides::class);

        $this->construire([
            'kind' => 'ajout_media',
            'ops' => [$this->op($media->getId()), $this->op($media->getId(), seq: 2)],
        ]);
    }

    public function testLesPreuvesDUneFicheProposeeSuiventLeurBrouillon(): void
    {
        // Le § 6.6 : le nœud en attente porte ses médias, avant toute validation. La
        // référence s'écrit `#p1` côté `Proposal` et `p1` en base — c'est le port qui
        // fait la conversion, et c'est le seul endroit du produit où les deux
        // conventions se rencontrent.
        $media = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'livret.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                demandeId: 12,
                brouillonRef: 'p1',
                caption: 'Livret de famille, p. 2',
            ),
            $this->membre(self::OPALE),
        )->media;

        $port = new MediasDeBrouillon($this->medias, $this->circuit);
        $rendus = $port->mediasDeBrouillon(12, '#p1');

        self::assertCount(1, $rendus);
        self::assertSame($media->getId(), $rendus[0]['mediaId']);
        self::assertSame('Livret de famille, p. 2', $rendus[0]['caption']);
        self::assertSame('en_attente', $rendus[0]['status']);
        self::assertSame(\sprintf('/api/medias/%d/fichier', (int) $media->getId()), $rendus[0]['url']);

        // Et rien pour une autre demande : `#p1` de la demande 12 et `#p1` de la
        // demande 13 ne désignent pas la même personne.
        self::assertSame([], $port->mediasDeBrouillon(13, '#p1'));
    }

    /**
     * **La passe 6 du § 9.5, par le port** — le geste que `Proposal` appelle à la
     * validation d'une `creation_personne`.
     *
     * Ce test couvre la moitié `Media` de la couture. La preuve qu'elle est **appelée**,
     * elle, est dans `Proposal\CreationDePersonneTest` : une méthode qui fonctionne et
     * qu'on invoque à la main ne prouve rien sur le fait qu'elle soit branchée — c'est
     * exactement ce qui a laissé `resoudreLeBrouillon()` sans appelant de production
     * pendant toute une livraison, avec deux tests verts pour la couvrir.
     */
    public function testLaResolutionParLePortRattacheEtRendVisible(): void
    {
        $media = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'livret.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                demandeId: 12,
                brouillonRef: 'p1',
                caption: 'Livret de famille, p. 2',
            ),
            $this->membre(self::OPALE),
        )->media;

        self::assertNull($media->getSubjectId(), 'Avant : aucun sujet réel.');
        self::assertSame('en_attente', $media->getStatus()->value);

        $port = new MediasDeBrouillon($this->medias, $this->circuit);

        // La carte que `ApplicateurDeCreation` tient en mémoire : `#p1` avec son dièse.
        $rattaches = $port->resoudreLesBrouillons(12, [
            '#p1' => ['personId' => 575, 'libelle' => 'Pyrite Chardon'],
            // Un brouillon sans média joint ne doit rien casser : c'est le cas courant.
            '#p2' => ['personId' => 576, 'libelle' => 'Cobalt Fenouil'],
        ]);

        self::assertSame(1, $rattaches);

        self::assertSame(575, $media->getSubjectId(), 'Le sujet réel remplace la référence provisoire.');
        self::assertNull($media->getSubjectDraftRef(), 'La référence provisoire est effacée : plus rien ne la désigne.');
        self::assertNull($media->getSubjectRequestId());
        self::assertSame('Pyrite Chardon', $media->getSubjectLabel(), 'Le libellé est repris sur la personne créée, pas sur la constante du dépôt.');
        self::assertSame(
            'actif',
            $media->getStatus()->value,
            'Sans ce passage à `actif`, la preuve resterait invisible de tous sauf du déposant : `CatalogueMedia::lister()` ne rend que les actifs.',
        );

        // Et le brouillon ne porte plus rien.
        self::assertSame([], $port->mediasDeBrouillon(12, '#p1'));
    }

    // ---------------------------------------------------------------------------------

    private function deposer(MediaKind $kind, bool $administrateur = false, int $deposant = self::OPALE): Media
    {
        return $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(
                    FabriqueDeFichiers::jpegNu(100 + random_int(1, 80), 80),
                    '.jpg',
                ),
                nomOrigine: 'acte.jpg',
                kind: $kind,
                subjectKind: MediaSubject::Personne,
                subjectId: 17,
                caption: 'Acte n° 3, Épinaille 1910',
            ),
            $administrateur ? $this->administrateur() : $this->membre($deposant),
        )->media;
    }

    private function demande(?int $mediaId): ChangeRequest
    {
        $demande = $this->construire([
            'kind' => 'ajout_media',
            'ops' => [$this->op($mediaId)],
        ]);

        $this->em->flush();

        return $demande;
    }

    /** @param array<string, mixed> $corps */
    private function construire(array $corps): ChangeRequest
    {
        $demande = new ChangeRequest(
            RequestKind::AjoutMedia,
            self::OPALE,
            'Cobalt Romarin',
        );
        $demande->setStatus(RequestStatus::EnAttente);

        $this->em->persist($demande);
        $this->constructeur->construire($demande, $corps, $this->lecteur());

        return $demande;
    }

    /** @return array<string, mixed> */
    private function op(?int $mediaId, int $seq = 1): array
    {
        return [
            'seq' => $seq,
            'opKind' => OpKind::AjoutMedia->value,
            'subjectKind' => SubjectKind::Media->value,
            'field' => null,
            'valueKind' => ValueKind::Media->value,
            'oldValue' => null,
            'newValue' => ['mediaId' => $mediaId, 'kind' => 'preuve'],
        ];
    }

    private function lecteur(int $userId = self::OPALE, bool $administrateur = false): Lecteur
    {
        return new Lecteur($userId, 'Cobalt Romarin', $administrateur);
    }

    /**
     * La demande telle que `Proposal` la passe désormais : un objet de transfert en
     * lecture seule, sans `setStatus()` ni `decider()`.
     */
    private function vue(ChangeRequest $demande): DemandeEnCours
    {
        return new DemandeEnCours(
            $demande->getId(),
            $demande->getKind()->value,
            $demande->getAuthorUserId(),
            $demande->getAuthorLabel(),
        );
    }

    /**
     * Idem pour les ops. Ce test les relisait par `ChangeRequestOpRepository` — le dépôt
     * d'un autre module — parce que l'ancienne signature ne les fournissait pas ; elle
     * les fournit maintenant.
     *
     * @return list<OpEnAttente>
     */
    private function opsEnAttente(ChangeRequest $demande): array
    {
        return array_map(
            static fn (ChangeRequestOp $op): OpEnAttente => new OpEnAttente(
                $op->getSeq(),
                $op->getOpKind()->value,
                $op->getSubjectKind()->value,
                $op->getSubjectId(),
                $op->getSubjectDraftRef(),
                $op->getField(),
                $op->getValueKind()->value,
                $op->getOldValue(),
                $op->getNewValue(),
                $op->getBaseVersion(),
            ),
            $this->opsDeDemande->pourDemande($demande),
        );
    }
}
