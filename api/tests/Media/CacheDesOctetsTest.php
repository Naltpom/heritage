<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Exception\DomainException;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Service\RequeteDeTeleversement;

/**
 * **Le voter s'exécute avant la comparaison d'`ETag`. Toujours.**.
 *
 * C'est la règle qui rend sûr le passage de `no-store` à `private, no-cache` sur les deux
 * routes d'octets, et c'est **exactement la règle qu'un profilage suggérera de
 * contourner** : comparer l'empreinte d'abord épargnerait une requête par vignette sur
 * une fiche qui en aligne dix.
 *
 * Elle ne se contourne pas, parce qu'**un `304` est une réponse**. Le servir à quelqu'un
 * qui vient de perdre l'accès — une personne repassée en vivante, un membre sorti du
 * cercle, un média repassé `en_attente` — lui apprend deux choses qu'il n'a plus le droit
 * de savoir : que la ressource existe encore, et que ses octets n'ont pas changé depuis
 * la dernière fois qu'il l'a vue. Il reçoit `404`, quel que soit son `If-None-Match`.
 *
 * Ce fichier porte ce nom pour qu'on le trouve en cherchant « cache », et il est cité
 * dans le code du contrôleur pour qu'on le trouve en lisant l'optimisation qu'on
 * s'apprête à faire.
 */
final class CacheDesOctetsTest extends CasDeTestMedia
{
    public function testUnLecteurQuiPerdLAccesRecoitQuatreCentQuatreEtNonTroisCentQuatre(): void
    {
        // Le média est d'abord visible du membre : il a donc pu lire ses octets, et son
        // navigateur en garde l'empreinte.
        $this->personneVivante(99, 'Aïeule vivante');
        $this->cercle->dansLeCercleDe(7, 99)->dansLeCercleDe(1, 99);

        $media = $this->deposer(99);
        $empreinte = $media->getSha256();

        self::assertTrue($this->catalogue->lisible($media, $this->membre(7)));

        // Puis le cercle change — la personne est sortie du cercle du membre 7.
        $this->cercle->horsDuCercleDe(7, 99);

        // Le média n'est plus lisible : c'est cette décision-là qui doit tomber en
        // premier, avant que qui que ce soit regarde l'empreinte que le client renvoie.
        self::assertFalse($this->catalogue->lisible($media, $this->membre(7)));

        try {
            $this->catalogue->voir((int) $media->getId(), $this->membre(7));
            self::fail('Le média n\'est plus visible : la lecture doit être refusée.');
        } catch (DomainException $erreur) {
            self::assertSame(404, $erreur->statutHttp());
            self::assertSame('introuvable', $erreur->codeErreur());
        }

        // L'empreinte, elle, n'a pas bougé — et c'est bien le piège : elle correspond
        // toujours, un client qui la renvoie en `If-None-Match` la renvoie juste, et un
        // code qui la comparerait avant le cercle rendrait `304`.
        self::assertSame($empreinte, $media->getSha256());
    }

    public function testLEmpreinteNeChangePasQuandLAutorisationChange(): void
    {
        // La prémisse du régime `no-cache` : les octets sont **immuables**, seule
        // l'autorisation bouge. Si cette assertion tombait un jour — un ré-encodage
        // en place, une rotation d'image — le régime de cache serait à revoir, pas
        // l'assertion.
        $this->personneDecedee(17, 'Marne Dolomite Sureau');
        $media = $this->deposer(17);

        $avant = $media->getSha256();
        $this->circuit->valider($media, changeRequestId: 4);

        self::assertSame($avant, $media->getSha256(), 'La validation ne réécrit pas les octets.');
        self::assertSame(
            $avant,
            hash_file('sha256', $this->stockage->chemin($media->getStorageName())),
            'L\'empreinte en base doit rester celle du fichier sur disque.',
        );
    }

    public function testLaVignetteEtLOriginalNOntPasLaMemeEmpreinte(): void
    {
        // Deux fichiers différents derrière deux URL différentes : leur servir le même
        // `ETag` ferait rendre `304` à la vignette pour un original déjà en cache, et le
        // navigateur afficherait l'acte entier à la place de sa miniature.
        $this->personneDecedee(17, 'Marne Dolomite Sureau');
        $media = $this->deposer(17);

        $vignette = $media->getThumbnailName();
        self::assertNotNull($vignette);

        self::assertNotSame(
            hash_file('sha256', $this->stockage->chemin($media->getStorageName())),
            hash_file('sha256', $this->stockage->chemin($vignette, vignette: true)),
        );
    }

    private function deposer(int $sujetId): Media
    {
        return $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(200, 150), '.jpg'),
                nomOrigine: 'acte.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                subjectId: $sujetId,
            ),
            $this->administrateur(),
        )->media;
    }
}
