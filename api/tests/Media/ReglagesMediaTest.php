<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Module\Core\Entity\Setting;
use App\Module\Core\Enum\SettingType;
use App\Module\Media\Service\ReglagesMedia;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Les deux réglages du module, et surtout **leur repli**.
 *
 * Le cas dangereux n'est pas « le réglage est mal lu », c'est « le réglage est lu comme
 * vide ». Une liste blanche vide qui serait prise au mot n'accepterait rien — ou, selon
 * l'implémentation, tout. Ni l'un ni l'autre n'est acceptable pour une valeur que
 * l'administrateur peut casser depuis un écran, et c'est pour ça que le repli est un
 * comportement testé et non une commodité.
 */
final class ReglagesMediaTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            [\dirname(__DIR__, 2).'/src/Module/Core/Entity'],
            isDevMode: true,
        );
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());

        $this->em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration),
            $configuration,
        );

        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(Setting::class)]);
    }

    public function testLesValeursDeLaBaseSontLuesEtCastees(): void
    {
        $this->poser('media.taille_max_octets', '5242880', SettingType::Entier);
        $this->poser('media.mimes_autorises', '["image/jpeg","application/pdf"]', SettingType::Json);

        $reglages = new ReglagesMedia($this->em);

        // Castées selon `valueType`, jamais rendues en texte brut : la colonne est un
        // TEXT, le contrat est typé.
        self::assertSame(5_242_880, $reglages->tailleMaxOctets());
        self::assertSame(['image/jpeg', 'application/pdf'], $reglages->mimesAutorises());
    }

    public function testUneLigneAbsenteRetombeSurLaValeurDeLaMigration(): void
    {
        $reglages = new ReglagesMedia($this->em);

        self::assertSame(ReglagesMedia::TAILLE_MAX_PAR_DEFAUT, $reglages->tailleMaxOctets());
        self::assertSame(ReglagesMedia::MIMES_PAR_DEFAUT, $reglages->mimesAutorises());
    }

    public function testUneValeurCasseeNeVideNiLaListeBlancheNiLePlafond(): void
    {
        // Le scénario réel : quelqu'un édite `core_setting` à la main, ou une migration
        // se passe mal. Le module doit continuer d'appliquer une politique — la même —
        // plutôt que d'accepter n'importe quel type de fichier.
        $this->poser('media.mimes_autorises', '[]', SettingType::Json);
        $this->poser('media.taille_max_octets', 'vingt mégaoctets', SettingType::Entier);

        $reglages = new ReglagesMedia($this->em);

        self::assertSame(ReglagesMedia::MIMES_PAR_DEFAUT, $reglages->mimesAutorises());
        self::assertSame(ReglagesMedia::TAILLE_MAX_PAR_DEFAUT, $reglages->tailleMaxOctets());
    }

    public function testUneValeurLueDeuxFoisDansUneRequeteEstLaMeme(): void
    {
        // Deux contrôles d'un même dépôt doivent appliquer la même valeur, même si
        // l'administrateur la change entre les deux : ce n'est pas une optimisation.
        $this->poser('media.taille_max_octets', '1000', SettingType::Entier);
        $reglages = new ReglagesMedia($this->em);

        self::assertSame(1_000, $reglages->tailleMaxOctets());

        $this->poser('media.taille_max_octets', '2000', SettingType::Entier, remplacer: true);

        self::assertSame(1_000, $reglages->tailleMaxOctets());
    }

    private function poser(string $cle, string $valeur, SettingType $type, bool $remplacer = false): void
    {
        if ($remplacer) {
            // DQL et non `getRepository()` : `SettingRepository` étend
            // `ServiceEntityRepository` et réclame un registre Doctrine, que cet
            // `EntityManager` monté à la main n'a pas.
            $existant = $this->em
                ->createQuery('SELECT s FROM '.Setting::class.' s WHERE s.settingKey = :cle')
                ->setParameter('cle', $cle)
                ->getOneOrNullResult();
            self::assertInstanceOf(Setting::class, $existant);
            $existant->setValue($valeur);
            $this->em->flush();

            return;
        }

        $this->em->persist(new Setting($cle, $valeur, $type, 'Essai'));
        $this->em->flush();
    }
}
