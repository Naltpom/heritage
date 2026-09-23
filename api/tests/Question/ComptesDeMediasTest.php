<?php

declare(strict_types=1);

namespace App\Tests\Question;

use App\Module\Media\Entity\Media;
use App\Module\Media\Enum\MediaKind;
use App\Module\Media\Enum\MediaStatus;
use App\Module\Media\Enum\MediaSubject;
use App\Module\Media\Service\ComptesDeMediasPourQuestions;
use App\Tests\ReinitialisationDuSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'adaptateur de `Media` pour la lacune « cette personne n'a aucune photo ».
 *
 * Trente lignes et une agrégation — et **deux décisions qu'un remaniement peut défaire
 * en silence**, ce qui est la seule raison de ce fichier :
 *
 * 1. **tous les statuts sont comptés**, `en_attente` et `refuse` compris. Ajouter un
 *    `WHERE status = 'actif'` paraîtrait un resserrement raisonnable ; il reposerait la
 *    question à celui qui vient d'y répondre, et redemander à quelqu'un ce qu'il vient de
 *    donner est la façon la plus sûre de le perdre ;
 * 2. **un média de brouillon ne compte pour personne** — il vise une fiche *proposée*,
 *    qui n'existe pas encore dans l'arbre.
 *
 * Aucune des deux ne fait rougir un test de comportement le jour où elle tombe : la page
 * continue de servir des questions, simplement les mauvaises.
 */
final class ComptesDeMediasTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        ReinitialisationDuSchema::reinitialiser($this->em);
        $this->retirerLIndexQueSqliteNeSaitPasRendre();
    }

    /**
     * **SQLite n'honore pas la clause `where` d'un `UniqueConstraint` Doctrine.**.
     *
     * `uniq_med_portrait_actif` est déclaré partiel — « un seul portrait **actif** par
     * sujet », et son en-tête dit explicitement qu'il « n'empêche pas d'empiler dix
     * portraits remplacés ». Sur PostgreSQL, c'est ce qui se passe. Sur le SQLite de la
     * suite, le schéma est dressé depuis le mapping et la clause tombe : l'index devient
     * **inconditionnel**, et deux médias sur la même personne deviennent impossibles.
     *
     * Ce n'est pas un défaut du code de production, c'est une limite du moteur de test —
     * la même famille que les contraintes `CHECK`, qui ne jouent pas non plus ici (voir
     * {@see ContraintesDuModeleTest}). On le retire donc **pour ces cas-ci seulement**,
     * parce que ce qu'ils mesurent est une agrégation, pas un index.
     *
     * Le retirer est préférable à contourner la contrainte en variant les sujets : le cas
     * qui compte est précisément « plusieurs médias sur **la même** personne », et un test
     * qui l'éviterait ne mesurerait plus le `GROUP BY`.
     */
    private function retirerLIndexQueSqliteNeSaitPasRendre(): void
    {
        $connexion = $this->em->getConnection();

        if (!str_contains($connexion->getDatabasePlatform()::class, 'SQLite')) {
            return;
        }

        $connexion->executeStatement('DROP INDEX IF EXISTS uniq_med_portrait_actif');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testUnePersonneSansMediaEstAbsenteDuTableau(): void
    {
        self::assertSame([], $this->comptes());
    }

    public function testLesMediasSontComptesParPersonne(): void
    {
        $this->media(sujet: 7);
        $this->media(sujet: 7);
        $this->media(sujet: 9);

        self::assertSame([7 => 2, 9 => 1], $this->comptes());
    }

    /**
     * **Une photo déposée hier et pas encore validée EST une photo.**.
     *
     * La compter pour zéro reposerait la question à celui qui vient d'y répondre. Et un
     * média `refuse` a été **regardé** par l'administrateur : redemander reviendrait à
     * faire redéposer ce qu'on vient d'écarter.
     */
    public function testTousLesStatutsComptent(): void
    {
        $this->media(sujet: 7, statut: MediaStatus::EnAttente);
        $this->media(sujet: 7, statut: MediaStatus::Actif);
        $this->media(sujet: 7, statut: MediaStatus::Refuse);
        $this->media(sujet: 7, statut: MediaStatus::Remplace);

        self::assertSame([7 => 4], $this->comptes());
    }

    /**
     * Un média joint à une personne **proposée** porte `subject_request_id` +
     * `subject_draft_ref` et **pas** `subject_id` (§ 6.1 du modèle). Il ne peut donc pas
     * compter pour une fiche de l'arbre : elle n'existe pas encore.
     */
    public function testUnMediaDeBrouillonNeComptePourPersonne(): void
    {
        $this->media(sujet: 7);
        $this->media(sujet: null);

        self::assertSame([7 => 1], $this->comptes());
    }

    /** Une union ou une citation n'est pas une personne : le compte ne les mêle pas. */
    public function testSeulsLesMediasDePersonnesSontComptes(): void
    {
        $this->media(sujet: 7);
        $this->media(sujet: 7, portee: MediaSubject::Famille);
        $this->media(sujet: 7, portee: MediaSubject::Citation);

        self::assertSame([7 => 1], $this->comptes());
    }

    /** @return array<int, int> */
    private function comptes(): array
    {
        return (new ComptesDeMediasPourQuestions($this->em))->comptesParPersonne();
    }

    private function media(
        ?int $sujet,
        MediaStatus $statut = MediaStatus::Actif,
        MediaSubject $portee = MediaSubject::Personne,
    ): void {
        static $n = 0;
        ++$n;

        $media = new Media(
            // « Preuve » et non « Portrait » : `uniq_med_portrait_actif` n'accepte qu'un
            // seul portrait actif par sujet, et ces cas en empilent plusieurs sur la même
            // personne. La nature du média n'entre pas dans le compte — c'est justement ce
            // que ce fichier vérifie —, et une preuve est un média comme un autre.
            MediaKind::Preuve,
            $portee,
            'Prune Bréval',
            'stockage-'.$n.'.jpg',
            'image/jpeg',
            1024,
            str_pad((string) $n, 64, '0', \STR_PAD_LEFT),
            11,
            'opale@example.test',
        );

        $media->setStatus($statut);

        if (null !== $sujet) {
            $media->rattacherA($sujet);
        }

        $this->em->persist($media);
        $this->em->flush();
    }
}
