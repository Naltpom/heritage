<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Exception\DomainException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Service\RequeteDeTeleversement;

/**
 * Le cercle de visibilité, appliqué aux médias.
 *
 * La question à laquelle ces cas répondent n'est pas « le média est-il caché » mais
 * « **la réponse apprend-elle qu'il existe** ». Les deux ne sont pas la même chose : un
 * `403` cache le contenu et confirme l'existence, et sur un média cela confirmerait
 * qu'une personne masquée a un portrait.
 */
final class VisibiliteDesMediasTest extends CasDeTestMedia
{
    private int $mediaMasque;
    private int $mediaVisible;

    protected function setUp(): void
    {
        parent::setUp();

        // 17 est décédée : visible de tous. 99 est vivante et hors du cercle du membre.
        $this->personneDecedee(17, 'Jargon Sarriette');
        $this->personneVivante(99, 'Aïeule vivante');

        $this->mediaVisible = $this->deposer(17);
        $this->mediaMasque = $this->deposer(99);
    }

    public function testLeMediaDUnePersonneHorsCercleEstIntrouvable(): void
    {
        $this->expectException(DomainException::class);

        $this->catalogue->voir($this->mediaMasque, $this->membre());
    }

    public function testLaReponseNeRevelePasQueLeMediaExiste(): void
    {
        // Un média masqué et un identifiant qui n'a jamais existé doivent produire la
        // **même** réponse, au message près : si les deux diffèrent d'un mot, la
        // différence est un oracle, et l'oracle est exactement ce que le cercle ferme.
        $masque = $this->erreur(fn () => $this->catalogue->voir($this->mediaMasque, $this->membre()));
        $inexistant = $this->erreur(fn () => $this->catalogue->voir(999_999, $this->membre()));

        self::assertSame($inexistant->statutHttp(), $masque->statutHttp());
        self::assertSame(404, $masque->statutHttp());
        self::assertSame($inexistant->codeErreur(), $masque->codeErreur());
        self::assertSame($inexistant->getMessage(), $masque->getMessage());
        self::assertSame($inexistant->champs(), $masque->champs());
        self::assertSame($inexistant->details(), $masque->details());

        // Et surtout : aucun nom de personne dans le message. « Les messages d'erreur ne
        // nomment jamais une personne : ils nomment un champ, un code, un identifiant. »
        self::assertStringNotContainsString('Aïeule', $masque->getMessage());
    }

    public function testLesOctetsDuFichierEtDeLaVignetteSuiventLaMemeRegleQueLaFiche(): void
    {
        // Les quatre routes de lecture passent par la même décision. Si la vignette
        // suivait une règle à part, elle finirait par diverger — et la vignette d'un
        // portrait en dit autant que le portrait.
        $media = $this->em->find(Media::class, $this->mediaMasque);
        self::assertInstanceOf(Media::class, $media);

        self::assertFalse($this->catalogue->lisible($media, $this->membre()));
        self::assertTrue($this->catalogue->lisible($media, $this->administrateur()));
    }

    public function testLaListeDUnePersonneMasqueeEstVideEtNonPartiellementFiltree(): void
    {
        // « Le nombre de médias d'une personne masquée est lui-même une information. »
        // On ne rend pas « 0 sur 3 », on ne rend pas non plus un `404` qui dirait que la
        // fiche existe : on rend la même chose que pour une personne sans média.
        self::assertSame([], $this->catalogue->lister(MediaSubject::Personne, 99, $this->membre()));
        self::assertSame([], $this->catalogue->lister(MediaSubject::Personne, 12_345, $this->membre()));

        // L'administrateur, lui, voit la liste réelle.
        self::assertCount(1, $this->catalogue->lister(MediaSubject::Personne, 99, $this->administrateur()));
    }

    public function testUnMembreDuCercleVoitLeMediaDUnePersonneVivante(): void
    {
        // La contrepartie : le cercle n'est pas un mur, c'est un périmètre. Le membre 7
        // n'y est pas, le membre 8 y est.
        $this->cercle->dansLeCercleDe(8, 99);

        self::assertCount(1, $this->catalogue->lister(MediaSubject::Personne, 99, $this->membre(8)));
        self::assertSame([], $this->catalogue->lister(MediaSubject::Personne, 99, $this->membre(7)));
    }

    public function testUnCompteEnAttenteNeVoitAucunMediaDePersonneVivante(): void
    {
        // Régime distinct, pas cercle réduit : il ne voit aucune personne vivante, « y
        // compris celle à laquelle il prétend se rattacher ».
        self::assertSame([], $this->catalogue->lister(MediaSubject::Personne, 99, $this->enAttente()));

        // Les personnes décédées, en revanche, lui sont ouvertes : 256 fiches sur 274.
        // Il faut valider le média d'abord — la liste par défaut ne montre que l'`actif`,
        // et un média de membre naît `en_attente`.
        $media = $this->em->find(Media::class, $this->mediaVisible);
        self::assertInstanceOf(Media::class, $media);
        $this->circuit->valider($media, changeRequestId: 4);

        self::assertCount(1, $this->catalogue->lister(MediaSubject::Personne, 17, $this->enAttente()));
    }

    public function testUnMediaEnAttenteNEstVisibleQueDeSonDeposantEtDesAdministrateurs(): void
    {
        $enAttente = $this->em->find(Media::class, $this->mediaVisible);
        self::assertInstanceOf(Media::class, $enAttente);
        self::assertSame(MediaStatus::EnAttente, $enAttente->getStatus());

        // Déposé par le membre 7 (voir `deposer()`).
        self::assertTrue($this->catalogue->lisible($enAttente, $this->membre(7)));
        self::assertFalse($this->catalogue->lisible($enAttente, $this->membre(8)));
        self::assertTrue($this->catalogue->lisible($enAttente, $this->administrateur()));

        // Et la liste par défaut ne le montre pas : `status` vaut `actif` seul.
        self::assertSame([], $this->catalogue->lister(MediaSubject::Personne, 17, $this->membre(8)));
    }

    public function testUnMembreDemandantLaFileDAttenteNeRecoitQueLesSiens(): void
    {
        $this->deposer(17, membre: 8);

        $vusParSept = $this->catalogue->lister(
            MediaSubject::Personne, 17, $this->membre(7), null, MediaStatus::EnAttente,
        );
        $vusParLAdmin = $this->catalogue->lister(
            MediaSubject::Personne, 17, $this->administrateur(), null, MediaStatus::EnAttente,
        );

        self::assertCount(1, $vusParSept);
        self::assertSame(7, $vusParSept[0]->getUploadedByUserId());
        self::assertCount(2, $vusParLAdmin, 'L\'administrateur voit toute la file de validation.');
    }

    public function testUnMediaDeBrouillonNEstVisibleQueDeSonAuteur(): void
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
            $this->membre(7),
        )->media;

        self::assertTrue($this->catalogue->lisible($media, $this->membre(7)));
        self::assertFalse($this->catalogue->lisible($media, $this->membre(8)));
        self::assertTrue($this->catalogue->lisible($media, $this->administrateur()));
    }

    /** @param \Closure(): mixed $appel */
    private function erreur(\Closure $appel): DomainException
    {
        try {
            $appel();
        } catch (DomainException $erreur) {
            return $erreur;
        }

        self::fail('Une DomainException était attendue.');
    }

    private function deposer(int $sujetId, int $membre = 7): int
    {
        $media = $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(40 + $sujetId, 30), '.jpg'),
                nomOrigine: 'acte.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                subjectId: $sujetId,
            ),
            // Le dépôt sur une fiche masquée passe par l'administrateur : un membre hors
            // cercle n'y arriverait pas, et c'est déjà testé ailleurs.
            99 === $sujetId ? $this->administrateur() : $this->membre($membre),
        )->media;

        $id = $media->getId();
        self::assertIsInt($id);

        return $id;
    }
}
