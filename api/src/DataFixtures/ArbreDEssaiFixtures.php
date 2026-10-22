<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\Service\SettingService;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Child;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Repository\PersonRepository;
use App\Module\Tree\Service\NormalisateurNom;
use App\Module\Tree\Service\RecalculDesColonnesDerivees;
use App\Module\Tree\Service\ReglagesDeLArbreParSettings;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * L'arbre d'essai : onze personnes **inventées**, cinq familles, trois générations.
 *
 * C'est le point de départ d'une installation neuve (poste de développement, pile de
 * démonstration, base PostgreSQL de l'intégration continue). Les vraies données ne sont
 * jamais ici : elles vivent dans la base de production et dans ses sauvegardes, et se
 * reprennent en restaurant un dump (ADR 0006, ADR 0018). Le dépôt est public : aucun
 * nom, aucune date, aucun lieu de cette classe ne désigne quelqu'un.
 *
 * Il remplace le fichier `arbre-essai.ged` que l'import GEDCOM chargeait avant sa
 * suppression (ADR 0018), et en reprend les personnes, les familles et la variété des
 * dates — exacte, au mois, à l'année, « environ », « avant », « entre », et une chaîne
 * qu'aucune forme connue ne lit. C'est cette variété que les tests sur PostgreSQL
 * exigent (booléens, horodatages, dates nues), pas un nombre de personnes.
 *
 * ## À jouer seul, en ajout, sur une base neuve
 *
 *     bin/console doctrine:migrations:migrate --no-interaction
 *     bin/console doctrine:fixtures:load --group=essai --append --no-interaction
 *
 * `--append` n'est pas une option de confort : sans lui, `doctrine:fixtures:load`
 * **purge la base**, y compris `core_setting`, que la migration initiale a semé — et
 * l'arbre se retrouverait sans personne racine. Le groupe `essai` évite d'entraîner
 * l'amorçage du compte administrateur ({@see AmorcageFixtures}).
 *
 * **Refuse un arbre déjà peuplé.** Charger des personnes inventées au milieu des vraies
 * est exactement l'écrasement silencieux que la suppression de l'import a voulu rendre
 * impossible : la fixture s'arrête avant d'écrire quoi que ce soit.
 *
 * Comme {@see AmorcageFixtures}, cette classe compose plusieurs modules (`Core` pour la
 * personne racine, `Tree` pour l'arbre) : `src/DataFixtures/` est une racine de
 * composition, pas un module (ADR 0002).
 */
final class ArbreDEssaiFixtures extends Fixture implements FixtureGroupInterface
{
    /** La personne racine de l'arbre d'essai : toutes les branches se calculent depuis elle. */
    public const string XREF_EGO = '@I1@';

    private const string CEDRE = 'Cèdre, Fougerie, Florie';

    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return ['essai'];
    }

    public function __construct(
        private readonly PersonRepository $personnes,
        private readonly NormalisateurNom $noms,
        private readonly SettingService $reglages,
        private readonly RecalculDesColonnesDerivees $recalcul,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        if ($this->personnes->count([]) > 0) {
            throw new \RuntimeException('L’arbre n’est pas vide : l’arbre d’essai ne se charge que sur une base neuve. Rien n’a été écrit.');
        }

        // Dans l'ordre des identifiants : c'est l'ordre que le moteur de placement prend
        // pour départager les égalités (`ORDER BY id`).
        $camille = $this->personne($manager, '@I1@', 'Camille', 'Persil', Sex::Feminin, surnom: 'Cam',
            naissance: $this->jour('12 MAR 1990', '1990-03-12', self::CEDRE));
        $theo = $this->personne($manager, '@I11@', 'Théo', 'Persil', Sex::Masculin,
            naissance: $this->jour('5 SEP 1993', '1993-09-05'));
        $julien = $this->personne($manager, '@I2@', 'Julien', 'Persil', Sex::Masculin,
            naissance: new UncertainDate('ABT 1960', DateQualifier::Environ, $this->d('1960-01-01'), $this->d('1960-12-31')),
            deces: $this->jour('3 FEB 2019', '2019-02-03', 'Genêtière, Fougerie, Florie'));
        $helene = $this->personne($manager, '@I3@', 'Hélène', 'Thym', Sex::Feminin, nomDUsage: 'Persil',
            naissance: $this->annee(1962));
        $antoine = $this->personne($manager, '@I4@', 'Antoine', 'Persil', Sex::Masculin,
            naissance: new UncertainDate('BEF 1935', DateQualifier::Avant, null, $this->d('1934-12-31')),
            deces: UncertainDate::inconnue());
        $marthe = $this->personne($manager, '@I5@', 'Marthe', 'Bréval', Sex::Feminin, nomDUsage: 'Persil',
            naissance: new UncertainDate('BET 1930 AND 1934', DateQualifier::Entre, $this->d('1930-01-01'), $this->d('1934-12-31')),
            deces: $this->annee(1999));
        $onyx = $this->personne($manager, '@I6@', 'Onyx', 'Thym', Sex::Masculin,
            naissance: new UncertainDate('JAN 1931', DateQualifier::Mois, $this->d('1931-01-01'), $this->d('1931-01-31')),
            deces: $this->annee(2005));
        $agate = $this->personne($manager, '@I7@', 'Agate', 'Bréval', Sex::Feminin, nomDUsage: 'Thym',
            naissance: $this->annee(1933), deces: $this->annee(2010));
        $gustave = $this->personne($manager, '@I8@', 'Gustave', 'Bréval', Sex::Masculin,
            naissance: $this->annee(1905), deces: $this->annee(1980));
        $silice = $this->personne($manager, '@I9@', 'Silice', 'Sarrat', Sex::Feminin,
            // Une chaîne qu'aucune forme connue ne lit : elle est gardée telle quelle,
            // sans bornes — la seule forme que `ck_tree_person_*` admet pour `inconnue`.
            naissance: new UncertainDate('vers la Saint-Jean', DateQualifier::Inconnue),
            deces: UncertainDate::inconnue());
        $inconnu = $this->personne($manager, '@I10@', '', '', Sex::Inconnu, deces: UncertainDate::inconnue());

        $manager->flush();

        $f1 = $this->famille($manager, '@F1@', $julien, $helene, [$camille, $theo],
            $this->jour('4 JUL 1988', '1988-07-04', self::CEDRE));
        $f1->setDivorced(true)->setDivorce($this->jour('9 NOV 2001', '2001-11-09'));
        $this->famille($manager, '@F2@', $antoine, $marthe, [$julien],
            new UncertainDate('BET 1955 AND 1958', DateQualifier::Entre, $this->d('1955-01-01'), $this->d('1958-12-31')));
        // Les trois natures d'une union (ADR 0020), pour que l'écran les montre et que la
        // CI éprouve `ck_tree_family_mariage_coherent` en chargeant cet arbre : F1 et F2
        // sont mariées par leur date, F3 l'est sans date — l'acte manque —, F4 est une
        // union libre, F5 ne dit rien.
        $this->famille($manager, '@F3@', $onyx, $agate, [$helene])->setMarried(true);
        $this->famille($manager, '@F4@', $gustave, $silice, [$marthe, $agate])->setMarried(false);
        $this->famille($manager, '@F5@', $inconnu, null, [$antoine]);

        $manager->flush();

        echo '  [essai] '.$this->poserLaRacine($manager, $camille)."\n";
    }

    /**
     * Pose `tree.personne_racine_id` sur l'ego, puis recalcule générations, branches et
     * rangs — ce que fait l'application après toute écriture structurante.
     */
    private function poserLaRacine(ObjectManager $manager, Person $ego): string
    {
        $reglage = $this->reglages->trouver(ReglagesDeLArbreParSettings::CLE_PERSONNE_RACINE);

        if (null === $reglage) {
            // Le jeu de réglages est semé par la migration initiale : son absence veut
            // dire que la base n'est pas migrée. L'arbre est écrit, sans racine.
            return 'Réglage tree.personne_racine_id absent (base non migrée) : arbre écrit sans personne racine.';
        }

        $reglage->setValue((string) $ego->getId())->setUpdatedBy(null, 'fixtures');
        $manager->flush();

        $this->recalcul->recalculer();
        $manager->flush();

        return \sprintf('Arbre d’essai écrit : 11 personnes, 5 familles, racine %s (#%d).', self::XREF_EGO, (int) $ego->getId());
    }

    private function personne(
        ObjectManager $manager,
        string $xref,
        string $prenoms,
        string $nom,
        Sex $sexe,
        ?UncertainDate $naissance = null,
        ?UncertainDate $deces = null,
        ?string $surnom = null,
        ?string $nomDUsage = null,
    ): Person {
        $personne = (new Person($this->noms->composerAffichage($prenoms, $nom, $surnom)))
            ->setGedcomXref($xref)
            ->setGivenNames($prenoms)
            ->setSurname($nom)
            ->setNickname($surnom)
            ->setMarriedName($nomDUsage)
            ->setSearchKey($this->noms->cleDeRecherche($prenoms, $nom, $surnom, $nomDUsage))
            ->setSex($sexe)
            ->setBirth($naissance ?? UncertainDate::inconnue())
            ->setDeceased(null !== $deces)
            ->setDeath($deces ?? UncertainDate::inconnue());

        $manager->persist($personne);

        return $personne;
    }

    /**
     * @param list<Person> $enfants dans l'ordre de la fratrie
     */
    private function famille(
        ObjectManager $manager,
        string $xref,
        ?Person $mari,
        ?Person $femme,
        array $enfants,
        ?UncertainDate $mariage = null,
    ): Family {
        $famille = (new Family($mari, $femme))
            ->setGedcomXref($xref)
            ->setMarriage($mariage ?? UncertainDate::inconnue());

        $manager->persist($famille);

        foreach ($enfants as $rang => $enfant) {
            $manager->persist(new Child($famille, $enfant, $rang));
        }

        return $famille;
    }

    private function jour(string $brut, string $date, ?string $lieu = null): UncertainDate
    {
        return new UncertainDate($brut, DateQualifier::Exacte, $this->d($date), $this->d($date), $lieu);
    }

    private function annee(int $annee): UncertainDate
    {
        return new UncertainDate(
            (string) $annee,
            DateQualifier::Annee,
            $this->d(\sprintf('%d-01-01', $annee)),
            $this->d(\sprintf('%d-12-31', $annee)),
        );
    }

    private function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }
}
