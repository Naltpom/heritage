<?php

declare(strict_types=1);

namespace App\Tests\Proposal;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Proposal\Entity\ChangeRequest;
use App\Module\Proposal\Enum\RequestStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `heritage:recalculer-les-cles-de-doublon` — la reprise des clés posées par l'ancien
 * repliement.
 *
 * ## L'état de départ simulé est celui de la production
 *
 * Sous la musl d'Alpine, `iconv(…'ASCII//TRANSLIT')` rendait `?` pour chaque accent :
 * « Génévrolle » est en base sous `g n vrolle|…`, et « Génevrolle », la même personne
 * saisie par un cousin qui a oublié un accent, sous `g nevrolle|…`. Deux clés, donc aucun
 * doublon signalé. Ces tests posent exactement ces deux valeurs, puis vérifient que la
 * reprise les remet d'accord.
 *
 * ## Ce que ce fichier protège, et qui n'est pas « la commande marche »
 *
 * - **Le périmètre** : seules les créations **en attente** sont reprises. Une demande
 *   tranchée garde sa clé d'époque — personne ne la compare plus, et la réécrire
 *   changerait l'historique sans rien réparer ; une modification n'a pas de clé à
 *   reprendre.
 * - **Le même calcul qu'à la soumission** : la clé reprise est celle qu'une soumission
 *   poserait aujourd'hui, et le test le vérifie en la comparant à une demande soumise
 *   après le correctif.
 * - **L'idempotence** : rejouée, elle ne trouve plus rien.
 * - **Rien d'autre ne bouge** : ni statut, ni `updated_at` — qui nourrit l'`ETag` du
 *   graphe, et dont le moindre mouvement invaliderait le cache de l'arbre de chaque
 *   auteur pour un affichage inchangé.
 */
final class RecalculDesClesDeDoublonTest extends BaseDeDemandes
{
    private const int JARGON = 55;

    /** La clé que l'ancien code posait en production pour « Génévrolle ». */
    private const string CLE_ALPINE_AVEC_ACCENTS = 'g n vrolle|tourmaline|1889';

    /** …et pour « Génevrolle », la même personne avec un accent de moins. */
    private const string CLE_ALPINE_SANS_SECOND_ACCENT = 'g nevrolle|tourmaline|1889';

    private const string CLE_ATTENDUE = 'genevrolle|tourmaline|1889';

    protected function setUp(): void
    {
        parent::setUp();

        $this->arbre->poser(SubjectKind::Personne, self::JARGON, 'Jargon Sarriette', 3, [
            'givenNames' => 'Jargon',
            'surname' => 'Sarriette',
            'marriedName' => null,
            'nickname' => null,
            'sex' => 'masculin',
            'deceased' => true,
            'birth' => UncertainDate::inconnue(),
            'death' => UncertainDate::inconnue(),
            'forcePrivacy' => false,
            'privacyNote' => null,
        ]);
    }

    /** Sans création en attente, la commande le dit et réussit. */
    public function testSansCreationEnAttenteIlNYARienARecalculer(): void
    {
        $tester = $this->lancer();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('rien à recalculer', $tester->getDisplay());
    }

    /**
     * Des clés déjà posées par le repliement courant — toute demande soumise après le
     * correctif : rien à reprendre, rien d'écrit.
     */
    public function testDesClesDejaConformesNeSontPasReecrites(): void
    {
        $demande = $this->proposer(self::OPALE, 'Génévrolle');
        self::assertSame(self::CLE_ATTENDUE, $this->recharger($demande)->getDedupKey(), 'La soumission pose déjà la clé repliée par Core.');

        $tester = $this->lancer();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Toutes les clés de doublon sont conformes', $tester->getDisplay());
    }

    /**
     * **Le cas de la production.** Deux propositions d'une même personne, l'une avec ses
     * deux accents, l'autre avec un seul : l'ancien code leur avait donné deux clés, et
     * l'administrateur ne voyait aucun doublon. Après la reprise, elles partagent la clé
     * que la soumission pose aujourd'hui, et la vue d'administration les rapproche.
     *
     * Le reste ne bouge pas : une création **refusée** garde sa clé d'époque, une
     * **modification** en attente aussi, et aucune demande ne change d'`updated_at`.
     */
    public function testLaRepriseRemetDAccordLesClesDeLaProductionEtElleSeule(): void
    {
        $opale = $this->proposer(self::OPALE, 'Génévrolle');
        $tuf = $this->proposer(self::TUF, 'Génevrolle');
        $refusee = $this->proposer(self::OPALE, 'Génévrolle');
        $this->refus->refuser($refusee, $this->lecteur(self::ADMIN, administrateur: true), 'Elle rejoint une autre demande.', null);
        $modification = $this->depot->deposer(
            $this->corpsDeModification([$this->opTexte('surname', 'Sureau', 'SUREAU')]),
            $this->lecteur(self::OPALE),
        );

        $this->poserLesClesDeLaProduction([
            $opale->getId() => self::CLE_ALPINE_AVEC_ACCENTS,
            $tuf->getId() => self::CLE_ALPINE_SANS_SECOND_ACCENT,
            $refusee->getId() => self::CLE_ALPINE_AVEC_ACCENTS,
            $modification->getId() => 'cle|posee|a la main',
        ]);

        $horodatages = $this->horodatages([$opale, $tuf, $refusee, $modification]);

        // Avant : l'administrateur ne voit aucun doublon — c'est la panne.
        self::assertSame([], $this->serialiseur->pourAdministration($this->recharger($opale))['doublonsProbables']);

        $tester = $this->lancer();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('2 clé(s) de doublon reprises', $tester->getDisplay());
        self::assertStringContainsString('avant : 0, après : 2', $tester->getDisplay(), 'La commande annonce le nombre de demandes que l’administrateur verra rapprochées.');

        self::assertSame(self::CLE_ATTENDUE, $this->recharger($opale)->getDedupKey());
        self::assertSame(self::CLE_ATTENDUE, $this->recharger($tuf)->getDedupKey());
        self::assertSame(self::CLE_ALPINE_AVEC_ACCENTS, $this->recharger($refusee)->getDedupKey(), 'Une demande tranchée garde sa clé d’époque.');
        self::assertSame('cle|posee|a la main', $this->recharger($modification)->getDedupKey(), 'Seules les créations de personne sont reprises.');
        self::assertSame(RequestStatus::Refusee, $this->recharger($refusee)->getStatus());

        // Après : le doublon apparaît, de part et d'autre.
        $vue = $this->serialiseur->pourAdministration($this->recharger($opale));
        self::assertCount(1, $vue['doublonsProbables']);
        self::assertSame($tuf->getId(), $vue['doublonsProbables'][0]['id']);

        self::assertSame($horodatages, $this->horodatages([$opale, $tuf, $refusee, $modification]), 'Aucun `updated_at` ne bouge : la clé n’est pas une donnée de la demande.');
    }

    /**
     * La clé reprise est **celle qu'une soumission poserait aujourd'hui** — pas une
     * seconde formule. On la compare à une demande soumise après le correctif, sur la
     * même personne : les deux doivent coïncider au caractère près.
     */
    public function testLaCleRepriseEstCelleQuePoseraitLaSoumission(): void
    {
        $ancienne = $this->proposer(self::OPALE, 'Œillet-Génévrolle');
        $this->poserLesClesDeLaProduction([$ancienne->getId() => 'oeillet g n vrolle|tourmaline|1889']);

        $this->lancer();

        $nouvelle = $this->proposer(self::TUF, 'Œillet-Génévrolle');

        self::assertSame('oeillet genevrolle|tourmaline|1889', $this->recharger($nouvelle)->getDedupKey());
        self::assertSame($this->recharger($nouvelle)->getDedupKey(), $this->recharger($ancienne)->getDedupKey());
    }

    /**
     * `--dry-run` compte et n'écrit pas — c'est ce qui permet de lire le nombre avant de
     * le confirmer, sur la production.
     */
    public function testLeDryRunCompteSansEcrire(): void
    {
        $opale = $this->proposer(self::OPALE, 'Génévrolle');
        $tuf = $this->proposer(self::TUF, 'Génevrolle');
        $this->poserLesClesDeLaProduction([
            $opale->getId() => self::CLE_ALPINE_AVEC_ACCENTS,
            $tuf->getId() => self::CLE_ALPINE_SANS_SECOND_ACCENT,
        ]);

        $tester = $this->lancer(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('2 clé(s) seraient réécrites', $tester->getDisplay());
        self::assertStringContainsString('avant : 0, après : 2', $tester->getDisplay());

        self::assertSame(self::CLE_ALPINE_AVEC_ACCENTS, $this->recharger($opale)->getDedupKey(), '--dry-run n’écrit pas.');
        self::assertSame(self::CLE_ALPINE_SANS_SECOND_ACCENT, $this->recharger($tuf)->getDedupKey());
    }

    /**
     * Rejouée, elle ne trouve plus rien : c'est ce qui la rend sûre à lancer quand on
     * doute de l'état de la colonne — et sûre à relancer après un déploiement raté.
     */
    public function testLaCommandeEstIdempotente(): void
    {
        $opale = $this->proposer(self::OPALE, 'Génévrolle');
        $this->poserLesClesDeLaProduction([$opale->getId() => self::CLE_ALPINE_AVEC_ACCENTS]);

        $premier = $this->lancer();
        self::assertStringContainsString('1 clé(s) de doublon reprises', $premier->getDisplay());

        $second = $this->lancer();
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('Toutes les clés de doublon sont conformes', $second->getDisplay());
        self::assertSame(self::CLE_ATTENDUE, $this->recharger($opale)->getDedupKey());
    }

    /**
     * Les clés portent des noms de personnes proposées : elles ne s'affichent qu'en
     * `-v`, demande par demande, et jamais dans la sortie par défaut.
     */
    public function testLesClesNeSAffichentQuEnModeVerbeux(): void
    {
        $opale = $this->proposer(self::OPALE, 'Génévrolle');
        $this->poserLesClesDeLaProduction([$opale->getId() => self::CLE_ALPINE_AVEC_ACCENTS]);

        $muet = $this->lancer(['--dry-run' => true]);
        self::assertStringNotContainsString('genevrolle', $muet->getDisplay());

        $verbeux = $this->lancer(['--dry-run' => true], OutputInterface::VERBOSITY_VERBOSE);
        self::assertStringContainsString('n° '.$opale->getId(), $verbeux->getDisplay());
        self::assertStringContainsString(self::CLE_ALPINE_AVEC_ACCENTS, $verbeux->getDisplay());
        self::assertStringContainsString(self::CLE_ATTENDUE, $verbeux->getDisplay());
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Pose les clés **telles que l'ancien code les a laissées en base** — directement en
     * SQL : aucun chemin applicatif ne les écrit plus, et c'est bien le but.
     *
     * @param array<int|null, string> $cles demande ⇒ clé
     */
    private function poserLesClesDeLaProduction(array $cles): void
    {
        foreach ($cles as $id => $cle) {
            $this->em->getConnection()->executeStatement(
                'UPDATE prp_change_request SET dedup_key = :cle WHERE id = :id',
                ['cle' => $cle, 'id' => $id],
            );
        }

        $this->em->clear();
    }

    /**
     * @param list<ChangeRequest> $demandes
     *
     * @return array<int, int> demande ⇒ `updated_at`, en secondes
     */
    private function horodatages(array $demandes): array
    {
        $horodatages = [];

        foreach ($demandes as $demande) {
            $horodatages[(int) $demande->getId()] = $this->recharger($demande)->getUpdatedAt()->getTimestamp();
        }

        return $horodatages;
    }

    /** @param array<string, mixed> $options */
    private function lancer(array $options = [], int $verbosite = OutputInterface::VERBOSITY_NORMAL): CommandTester
    {
        $this->em->clear();

        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('heritage:recalculer-les-cles-de-doublon'));
        $tester->execute($options, ['verbosity' => $verbosite]);

        $this->em->clear();

        return $tester;
    }

    private function proposer(int $auteur, string $nom): ChangeRequest
    {
        return $this->depot->deposer([
            'kind' => 'creation_personne',
            'soumettre' => true,
            'brouillons' => [[
                'localRef' => '#p1',
                'givenNames' => 'Tourmaline',
                'surname' => $nom,
                'sex' => 'feminin',
                'deceased' => true,
                'birth' => $this->blocDate($this->annee(1889)),
            ]],
            'liens' => [[
                'seq' => 1,
                'kind' => 'conjoint_de',
                'subjectDraftRef' => '#p1',
                'otherPersonId' => self::JARGON,
            ]],
        ], $this->lecteur($auteur));
    }
}
