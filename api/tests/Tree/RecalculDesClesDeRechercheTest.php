<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Tree\Entity\Person;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `heritage:recalculer-les-cles-de-recherche` — la reprise de `tree_person.search_key`.
 *
 * ## Ce que ce fichier protège, et qui n'est pas « la commande marche »
 *
 * **Qu'elle n'écrive QUE ce qui change.** `Person` porte un `#[ORM\Version]` : chaque
 * ligne réécrite incrémente sa version et **périme les demandes en attente qui la
 * visaient** (§ 9.6). Une reprise qui repose la même valeur sur 279 lignes rendrait
 * caduques toutes les demandes ouvertes de l'arbre — sans rien corriger. C'est le mode de
 * défaillance à éviter ici, bien avant l'exactitude de la clé.
 *
 * C'est aussi pourquoi la commande existe au lieu d'une migration : le repliement
 * d'accents n'a **qu'une** définition ({@see \App\Module\Core\Service\CleDeRecherche}), et
 * la réécrire en SQL en créerait une seconde — la panne silencieuse que cette classe
 * existe pour supprimer. Le détail est dans l'en-tête de la commande.
 */
final class RecalculDesClesDeRechercheTest extends ArbreWebTestCase
{
    /**
     * Le socle est déjà semé par la formule courante : il n'y a donc **rien** à reprendre,
     * et la commande doit le dire sans toucher à une seule version.
     */
    public function testSurUnArbreConformeLaCommandeNEcritRien(): void
    {
        $aieule = $this->em()->getRepository(Person::class)->find($this->idDe('aieule'));
        self::assertInstanceOf(Person::class, $aieule);
        $versionAvant = $aieule->getVersion();

        $tester = $this->lancer();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Clés à reprendre', $tester->getDisplay());
        self::assertStringContainsString('0', $tester->getDisplay());

        $this->em()->clear();
        $relue = $this->em()->getRepository(Person::class)->find($this->idDe('aieule'));
        self::assertInstanceOf(Person::class, $relue);

        self::assertSame(
            $versionAvant,
            $relue->getVersion(),
            'Aucune version ne doit bouger : une reprise qui réécrit l’identique périme les demandes en attente pour rien (§ 9.6).',
        );
    }

    /**
     * Une clé laissée dans l'ancienne formule — celle d'avant le surnom — est reprise,
     * **et elle seule**.
     *
     * C'est le cas réel de la base de travail : 12 fiches portent un surnom, 3 seulement
     * l'ont hors de leur clé. Les 9 autres ne doivent pas être réécrites.
     */
    public function testSeuleLaLigneQuiChangeEstReecrite(): void
    {
        $em = $this->em();

        $aieule = $em->getRepository(Person::class)->find($this->idDe('aieule'));
        $aieul = $em->getRepository(Person::class)->find($this->idDe('aieul'));
        self::assertInstanceOf(Person::class, $aieule);
        self::assertInstanceOf(Person::class, $aieul);

        // On pose un surnom SANS toucher à la clé : exactement l'état qu'a laissé le
        // défaut — `display_name` piloté par le surnom, `search_key` qui l'ignore.
        $aieule->setNickname('Silice')->setDisplayName('Silice Sureau');
        $versionAieuleAvant = $aieule->getVersion();
        $versionAieulAvant = $aieul->getVersion();
        $em->flush();
        $em->clear();

        $tester = $this->lancer();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em->clear();
        $relue = $em->getRepository(Person::class)->find($this->idDe('aieule'));
        $relu = $em->getRepository(Person::class)->find($this->idDe('aieul'));
        self::assertInstanceOf(Person::class, $relue);
        self::assertInstanceOf(Person::class, $relu);

        self::assertSame(
            'SUREAU MARNE DOLOMITE SILICE',
            $relue->getSearchKey(),
            'La clé reprend la formule courante, surnom compris, et en dernier.',
        );
        self::assertGreaterThan($versionAieuleAvant, $relue->getVersion(), 'La ligne reprise change bien de version.');
        self::assertSame(
            $versionAieulAvant,
            $relu->getVersion(),
            'Une fiche déjà conforme ne doit PAS être réécrite : c’est tout l’objet de la comparaison avant écriture.',
        );
    }

    /**
     * `--dry-run` compte et n'écrit pas. C'est ce qui permet de lire le nombre de versions
     * qu'on s'apprête à incrémenter **avant** de le faire.
     */
    public function testLeDryRunCompteSansEcrire(): void
    {
        $em = $this->em();

        $aieule = $em->getRepository(Person::class)->find($this->idDe('aieule'));
        self::assertInstanceOf(Person::class, $aieule);

        $aieule->setNickname('Silice');
        $cleAvant = $aieule->getSearchKey();
        $em->flush();

        // **Après le `flush`, jamais avant** : poser le surnom est déjà une écriture, qui
        // incrémente la version d'elle-même. Relever la version d'avant ferait échouer ce
        // test sur notre propre écriture, et non sur celle qu'il surveille.
        $versionAvant = $aieule->getVersion();
        $em->clear();

        $tester = $this->lancer(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('seraient réécrites', $tester->getDisplay());

        $em->clear();
        $relue = $em->getRepository(Person::class)->find($this->idDe('aieule'));
        self::assertInstanceOf(Person::class, $relue);

        self::assertSame($cleAvant, $relue->getSearchKey(), '--dry-run n’écrit pas.');
        self::assertSame($versionAvant, $relue->getVersion());
    }

    /**
     * Rejouée, elle ne trouve plus rien : c'est ce qui la rend sûre à lancer quand on
     * doute de l'état de la colonne.
     */
    public function testLaCommandeEstIdempotente(): void
    {
        $em = $this->em();

        $aieule = $em->getRepository(Person::class)->find($this->idDe('aieule'));
        self::assertInstanceOf(Person::class, $aieule);
        $aieule->setNickname('Silice');
        $em->flush();
        $em->clear();

        $this->lancer();

        $em->clear();
        $versionApresPremierPassage = $em->getRepository(Person::class)->find($this->idDe('aieule'))?->getVersion();

        $tester = $this->lancer();
        self::assertStringContainsString('conformes', $tester->getDisplay());

        $em->clear();
        self::assertSame(
            $versionApresPremierPassage,
            $em->getRepository(Person::class)->find($this->idDe('aieule'))?->getVersion(),
            'Le second passage ne doit toucher à rien.',
        );
    }

    /** @param array<string, mixed> $options */
    private function lancer(array $options = []): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('heritage:recalculer-les-cles-de-recherche'));

        $tester->execute($options);

        return $tester;
    }
}
