<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\ArbreDEssaiFixtures;
use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\SettingType;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Service\ReglagesDeLArbreParSettings;
use App\Tests\ReinitialisationDuSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'arbre d'essai — le point de départ d'une installation neuve depuis la suppression de
 * l'import GEDCOM (ADR 0018), et ce qui ensemence la base PostgreSQL de la CI.
 *
 * Il doit tenir trois promesses : écrire l'arbre entier, poser la personne racine et les
 * colonnes dérivées comme l'application le fait, et **refuser** une base qui porte déjà
 * des personnes — l'écrasement silencieux de données réelles est la raison même de la
 * suppression de l'import.
 */
final class ArbreDEssaiFixturesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        ReinitialisationDuSchema::reinitialiser($this->em);

        // La migration initiale sème ce réglage à 0 ; elle ne joue pas sur SQLite.
        $this->em->persist(new Setting(
            ReglagesDeLArbreParSettings::CLE_PERSONNE_RACINE,
            '0',
            SettingType::Entier,
            'Personne racine de l’arbre',
        ));
        $this->em->flush();
    }

    public function testIlEcritLArbreEntierEtPoseLaRacine(): void
    {
        $this->charger();

        self::assertSame(11, $this->em->getRepository(Person::class)->count([]));
        self::assertSame(5, $this->em->getRepository(Family::class)->count([]));
        self::assertSame(7, $this->em->getRepository(Child::class)->count([]));

        $ego = $this->em->getRepository(Person::class)->findOneBy(['gedcomXref' => ArbreDEssaiFixtures::XREF_EGO]);
        self::assertInstanceOf(Person::class, $ego);

        $racine = $this->em->getRepository(Setting::class)->findOneBy(['settingKey' => ReglagesDeLArbreParSettings::CLE_PERSONNE_RACINE]);
        self::assertInstanceOf(Setting::class, $racine);
        self::assertSame((string) $ego->getId(), $racine->getValue());

        // Les colonnes dérivées sont calculées depuis la racine, comme après toute
        // écriture structurante de l'application.
        self::assertSame(0, $ego->getGeneration());
        $gustave = $this->em->getRepository(Person::class)->findOneBy(['gedcomXref' => '@I8@']);
        self::assertInstanceOf(Person::class, $gustave);
        self::assertSame(-3, $gustave->getGeneration());

        $inconnu = $this->em->getRepository(Person::class)->findOneBy(['gedcomXref' => '@I10@']);
        self::assertInstanceOf(Person::class, $inconnu);
        self::assertSame('?', $inconnu->getDisplayName());

        // Les trois natures d'une union (ADR 0020) : une date dit le mariage, F3 est
        // mariée sans date, F4 est une union libre, F5 ne dit rien.
        $natures = [];
        foreach ($this->em->getRepository(Family::class)->findAll() as $famille) {
            $natures[(string) $famille->getGedcomXref()] = $famille->isMarried();
        }
        ksort($natures);
        self::assertSame(['@F1@' => true, '@F2@' => true, '@F3@' => true, '@F4@' => false, '@F5@' => null], $natures);
    }

    public function testIlRefuseUnArbreDejaPeuple(): void
    {
        $this->em->persist((new Person('Quelqu’un'))->setGedcomXref('@I500001@'));
        $this->em->flush();

        try {
            $this->charger();
            self::fail('Un arbre déjà peuplé doit être refusé.');
        } catch (\RuntimeException $refus) {
            self::assertStringContainsString('n’est pas vide', $refus->getMessage());
        }

        self::assertSame(1, $this->em->getRepository(Person::class)->count([]), 'Rien ne doit avoir été écrit.');
    }

    private function charger(): void
    {
        $fixture = self::getContainer()->get(ArbreDEssaiFixtures::class);
        self::assertInstanceOf(ArbreDEssaiFixtures::class, $fixture);

        ob_start();
        try {
            $fixture->load($this->em);
        } finally {
            ob_end_clean();
        }

        $this->em->clear();
    }
}
