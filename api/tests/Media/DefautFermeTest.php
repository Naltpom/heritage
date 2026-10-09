<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Contract\FichesProposeesFermees;
use App\Module\Core\Exception\DomainException;
use App\Module\Core\Security\CercleFerme;
use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Security\SujetNonResolu;
use App\Module\Media\Security\VisibiliteParLeCercle;
use App\Module\Media\Service\CatalogueMedia;
use App\Module\Media\Service\DetecteurDeType;
use App\Module\Media\Service\GenerateurDeVignette;
use App\Module\Media\Service\PurgeDesMetadonnees;
use App\Module\Media\Service\RequeteDeTeleversement;
use App\Module\Media\Service\TeleversementMedia;
use Psr\Log\NullLogger;

/**
 * **Aucun adaptateur branché ⇒ une personne vivante n'est pas visible.**.
 *
 * Règle permanente du projet, et c'est ce fichier qui la tient pour le module `Media`.
 * Il n'utilise **aucun double** : il monte l'adaptateur réel
 * {@see VisibiliteParLeCercle} au-dessus des deux implémentations par défaut réelles,
 * `Core\Security\CercleFerme` et {@see SujetNonResolu} — c'est-à-dire l'état exact du
 * conteneur le jour où personne n'a encore câblé le cercle, ou le jour où quelqu'un l'a
 * décâblé sans s'en apercevoir.
 *
 * ## Pourquoi fermé et non ouvert
 *
 * Un défaut **ouvert** transforme une erreur de câblage — un service non déclaré, un
 * alias mal écrit, un module désactivé, une refonte de configuration dans deux ans — en
 * **fuite de données de personnes vivantes, silencieuse et sans message d'erreur**. Rien
 * ne tombe, rien ne s'allume, et personne ne l'apprend avant qu'un membre de la famille
 * s'en plaigne.
 *
 * Un défaut **fermé** transforme la même erreur de câblage en fiches vides : visible tout
 * de suite, corrigé dans l'heure, et jamais au détriment de quelqu'un.
 *
 * Les deux pannes ne coûtent pas le même prix. C'est pour ça que ce fichier existe, et
 * c'est pour ça qu'il ne doit pas être « réparé » en rendant `true` le jour où il gênera.
 *
 * ## Les deux coutures échouent fermé **séparément**
 *
 * Il y en a deux, et il a fallu le vérifier pour chacune : le cercle (`Membership`) et la
 * résolution des sujets (`Tree`). Chacune manquante suffit à fermer le module ; c'est
 * volontaire, parce qu'un test qui ne couvrirait que leur absence simultanée laisserait
 * passer le cas — bien plus probable — où une seule des deux est branchée.
 *
 * Une troisième s'y ajoute depuis le 09/10/2026, pour le seul dépôt sur une personne
 * **proposée** : le droit de joindre une pièce, que tient `Proposal`
 * (`Core\Contract\FichesProposeesInterface`). Elle échoue fermé elle aussi, et seule —
 * cercle et sujets branchés.
 */
final class DefautFermeTest extends CasDeTestMedia
{
    private CatalogueMedia $catalogueFerme;
    private TeleversementMedia $televersementFerme;

    protected function setUp(): void
    {
        parent::setUp();

        // On part d'un média déjà en base — le cas du jour où le câblage se casse après
        // que des médias ont été déposés, qui est le seul cas réaliste.
        $this->personneVivante(99, 'Aïeule vivante');
        $this->cercle->dansLeCercleDe(1, 99);

        $this->televersement->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'portrait.jpg',
                kind: MediaKind::Portrait,
                subjectKind: MediaSubject::Personne,
                subjectId: 99,
            ),
            $this->administrateur(),
        );

        // Puis on remplace tout le câblage de visibilité par les implémentations par
        // défaut réelles : celles qui refusent.
        $ferme = new VisibiliteParLeCercle(new CercleFerme(new NullLogger()), new SujetNonResolu(new NullLogger()));

        $this->catalogueFerme = new CatalogueMedia($this->medias, $ferme);
        $this->televersementFerme = new TeleversementMedia(
            $this->em,
            $this->medias,
            $this->reglages(),
            new DetecteurDeType(),
            new PurgeDesMetadonnees(sys_get_temp_dir()),
            $this->stockage,
            new GenerateurDeVignette($this->stockage, new NullLogger()),
            $ferme,
            $this->circuit,
            new FichesProposeesFermees(),
        );
    }

    public function testSansAdaptateurUnePersonneVivanteNEstPasVisible(): void
    {
        self::assertSame([], $this->catalogueFerme->lister(MediaSubject::Personne, 99, $this->membre()));
    }

    public function testSansAdaptateurLeMediaDejaDeposeDevientIntrouvable(): void
    {
        $media = $this->medias->portraitActif(99);
        self::assertInstanceOf(Media::class, $media);
        self::assertSame(MediaStatus::Actif, $media->getStatus());

        self::assertFalse($this->catalogueFerme->lisible($media, $this->membre()));

        $this->expectException(DomainException::class);
        $this->catalogueFerme->voir((int) $media->getId(), $this->membre());
    }

    public function testSansAdaptateurUnCompteEnAttenteNeVoitRienNonPlus(): void
    {
        self::assertSame([], $this->catalogueFerme->lister(MediaSubject::Personne, 99, $this->enAttente()));
    }

    public function testSansAdaptateurAucunDepotNAboutit(): void
    {
        // Le module devient inerte plutôt que permissif : on ne peut même plus déposer,
        // parce que le sujet ne peut pas être résolu. C'est la panne visible qu'on veut.
        $this->expectException(DomainException::class);

        $this->televersementFerme->televerser(
            new RequeteDeTeleversement(
                cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                nomOrigine: 'acte.jpg',
                kind: MediaKind::Preuve,
                subjectKind: MediaSubject::Personne,
                subjectId: 99,
            ),
            $this->administrateur(),
        );
    }

    /**
     * **L'administrateur n'est pas une exception, et ce cas est là pour l'empêcher d'en
     * redevenir une.**.
     *
     * Une version antérieure de `CatalogueMedia` ouvrait sur un
     * `if ($lecteur->administrateur) { return true; }`, et ce test-ci affirmait alors
     * l'inverse de ce qu'il affirme aujourd'hui. Le contrat du noyau l'interdit
     * nommément : « un administrateur passe par un cercle complet, jamais par un
     * `if ($estAdmin) { return true; }` ».
     *
     * La différence n'est pas théorique, elle est exactement celle que ce cas mesure :
     * avec la dérogation, une panne de câblage laissait l'administrateur — donc l'écran
     * d'administration, donc les exports — continuer à servir des médias en se réclamant
     * d'un cercle que personne n'avait consulté. Sans elle, la panne est totale et se
     * voit.
     */
    public function testMemeLAdministrateurNeVoitRienQuandRienNEstBranche(): void
    {
        $media = $this->medias->portraitActif(99);
        self::assertInstanceOf(Media::class, $media);

        self::assertFalse($this->catalogueFerme->lisible($media, $this->administrateur()));
        self::assertSame([], $this->catalogueFerme->lister(MediaSubject::Personne, 99, $this->administrateur()));
    }

    /**
     * Le cercle est branché, la résolution des sujets ne l'est pas : fermé quand même.
     *
     * C'est le cas le plus probable des deux — deux agents ne finissent pas le même jour.
     */
    public function testLeCercleSeulNeSuffitPasAOuvrirLeModule(): void
    {
        $cercleOuvert = new VisibiliteParLeCercle($this->cercle, new SujetNonResolu(new NullLogger()));
        $catalogue = new CatalogueMedia($this->medias, $cercleOuvert);

        $media = $this->medias->portraitActif(99);
        self::assertInstanceOf(Media::class, $media);

        self::assertFalse($catalogue->lisible($media, $this->administrateur()));
    }

    /**
     * **La troisième couture : `Proposal`.** Le cercle et les sujets sont branchés, le
     * droit de joindre une pièce à une personne proposée ne l'est pas : fermé quand même.
     *
     * C'est le repli réel du noyau, `Core\Contract\FichesProposeesFermees`, monté tel que
     * le conteneur le monterait si l'alias de `proposal.yaml` sautait. Un repli ouvert
     * remettrait en service la faille que ce contrat ferme — n'importe quel membre
     * joignant une pièce à la proposition d'un autre, rattachée à la fiche validée — et
     * rien ne le montrerait. Le repli fermé rend `404` au premier dépôt : on le voit.
     *
     * Même l'administrateur, pour la raison que donne
     * {@see self::testMemeLAdministrateurNeVoitRienQuandRienNEstBranche()} : une dérogation
     * à son profit transformerait la panne de câblage en fonctionnement apparent.
     */
    public function testSansProposalAucunePieceNeSeJointAUnePersonneProposee(): void
    {
        $televersement = new TeleversementMedia(
            $this->em,
            $this->medias,
            $this->reglages(),
            new DetecteurDeType(),
            new PurgeDesMetadonnees(sys_get_temp_dir()),
            $this->stockage,
            new GenerateurDeVignette($this->stockage, new NullLogger()),
            $this->visibilite,
            $this->circuit,
            new FichesProposeesFermees(),
        );

        $avant = \count($this->medias->findAll());

        try {
            $televersement->televerser(
                new RequeteDeTeleversement(
                    cheminTemporaire: $this->fichierTemporaire(FabriqueDeFichiers::jpegNu(), '.jpg'),
                    nomOrigine: 'acte.jpg',
                    kind: MediaKind::Preuve,
                    subjectKind: MediaSubject::Personne,
                    demandeId: 12,
                    brouillonRef: 'p1',
                ),
                $this->administrateur(),
            );
            self::fail('Sans `Proposal` branché, aucune pièce ne doit se joindre à une personne proposée.');
        } catch (DomainException $erreur) {
            self::assertSame(404, $erreur->statutHttp());
            self::assertSame('introuvable', $erreur->codeErreur());
        }

        self::assertCount($avant, $this->medias->findAll(), 'Le refus ne laisse aucune ligne.');
    }

    /**
     * L'inverse : les sujets se résolvent, le cercle n'est pas branché. Fermé aussi.
     */
    public function testLaResolutionSeuleNeSuffitPasAOuvrirLeModule(): void
    {
        $sujetsOuverts = new VisibiliteParLeCercle(new CercleFerme(new NullLogger()), $this->sujets);
        $catalogue = new CatalogueMedia($this->medias, $sujetsOuverts);

        $media = $this->medias->portraitActif(99);
        self::assertInstanceOf(Media::class, $media);

        self::assertFalse($catalogue->lisible($media, $this->administrateur()));
    }
}
