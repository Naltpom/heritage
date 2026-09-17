<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Almanach\Contract\EvenementDeLArbre;
use App\Module\Almanach\Contract\TypeDEvenement;
use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Service\SettingService;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Place;
use App\Module\Tree\Enum\FactType;
use App\Module\Tree\Service\ReglagesDeLArbreParSettings;
use App\Module\Tree\Service\ReglePersonneVivante;
use App\Module\Tree\Service\VueDeLArbrePourAlmanach;

/**
 * La couture par laquelle `Almanach` lit l'arbre — éprouvée **sur une vraie base**.
 *
 * ## Pourquoi ce test est ici et non dans `tests/Almanach/`
 *
 * `config/services/almanach.yaml` remplace le port par un flux en mémoire sous `when@test` :
 * c'est ce qui permet aux tests de la carte et du calendrier de tourner sans monter la
 * moindre table `tree_*`, et il ne faut pas le défaire. Passer par l'interface là-bas
 * éprouverait donc leur double, pas cet adaptateur. Le trou se comble ici, avec le vrai
 * schéma et le vrai arbre de {@see ArbreWebTestCase} — exactement le partage que
 * `AdaptateursPourLesAutresModulesTest` a déjà retenu pour `Membership` et `Media`.
 *
 * L'adaptateur s'assemble donc à la main. Ses trois dépendances sont des services sans
 * état, et c'est justement ce qui rend le geste sûr : il n'y a pas de configuration cachée
 * à reproduire. L'alias de production, lui, est vérifié par `lint:container`.
 *
 * ## Le mode de défaillance que ces cas gardent
 *
 * **Une traduction incomplète ne se voit pas.** Un fait de famille oublié, ce sont des
 * bulles en moins sur une carte dont personne ne connaît par cœur le contenu attendu :
 * aucune exception, aucune réponse malformée, rien de rouge. C'est le même mode que celui
 * des adaptateurs de `Membership` et de `Media`, et c'est pourquoi il se garde par un test
 * de traduction plutôt que par un test d'écran.
 */
final class PortDesEvenementsPourAlmanachTest extends ArbreWebTestCase
{
    private function port(): VueDeLArbrePourAlmanach
    {
        $reglages = self::getContainer()->get(SettingService::class);
        \assert($reglages instanceof SettingService);

        return new VueDeLArbrePourAlmanach(
            $this->em(),
            new ReglePersonneVivante(),
            new ReglagesDeLArbreParSettings($reglages),
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     *  LE CAS QUI GARDE L'ERREUR LA PLUS PROBABLE DE TOUT CE TRAVAIL.
     * ═══════════════════════════════════════════════════════════════════════════.
     *
     * `VueDeLArbrePourChronologie::chargeLesFaits()` écrit `->innerJoin('f.person', 'p')`.
     * Là-bas c'est une **décision**, écrite en toutes lettres : un jalon du § 9.1 exige un
     * `personId`, et un fait de famille n'en a pas à y mettre.
     *
     * Ici cette contrainte n'existe pas — un {@see EvenementDeLArbre} est identifié par une
     * clé qui nomme une **ligne** et porte une **liste** de sujets. Recopier l'`innerJoin`
     * ferait disparaître tous les faits de famille **en silence** : pas d'exception, pas de
     * test d'écran rouge, seulement des bulles qui manquent.
     *
     * Le cas pose un recensement sur la famille des aïeux et vérifie les trois choses qui
     * se perdraient ensemble : que le fait traverse, qu'il porte **ses deux époux**, et
     * qu'il porte son `familyId`.
     */
    public function testUnFaitDeFamilleTraverseLePortAvecSesDeuxEpoux(): void
    {
        $famille = $this->familles['aieux'];

        $recensement = new Fact(FactType::Recensement, null, $famille);
        $recensement->setHappened(self::annee(1936, 'Saule, Ombrage, Sylvanie'));

        $this->em()->persist($recensement);
        $this->em()->flush();

        $evenements = $this->parCle($this->port()->flux()->evenements);
        $cle = 'fait:'.$recensement->getId();

        self::assertArrayHasKey(
            $cle,
            $evenements,
            'Un fait de sujet `family` doit traverser le port. Un innerJoin sur f.person recopié de '
            .'la chronologie le ferait disparaître sans qu’aucune erreur ne se produise.',
        );

        $fait = $evenements[$cle];

        self::assertSame(TypeDEvenement::Recensement, $fait->type);
        self::assertSame($famille->getId(), $fait->familyId, 'Le fait relève de sa famille.');
        self::assertSame(
            [$this->idDe('aieul'), $this->idDe('aieule')],
            $fait->identifiantsDesSujets(),
            'Ses deux sujets sont les deux époux, dans l’ordre mari puis épouse.',
        );
    }

    /**
     * Le pendant : un fait de sujet `person` traverse aussi, avec **un** sujet.
     *
     * Sans lui, le cas précédent resterait vert si l'adaptateur s'était mis à ne charger
     * que les faits de famille — l'erreur symétrique, et tout aussi muette.
     */
    public function testUnFaitDePersonneTraverseAvecUnSeulSujet(): void
    {
        $profession = new Fact(FactType::Profession, $this->personnes['aieul']);
        $profession
            ->setLabel('Tisserand')
            ->setHappened(self::annee(1930, 'Saule, Ombrage, Sylvanie'));

        $this->em()->persist($profession);
        $this->em()->flush();

        $fait = $this->parCle($this->port()->flux()->evenements)['fait:'.$profession->getId()] ?? null;

        self::assertNotNull($fait);
        self::assertSame([$this->idDe('aieul')], $fait->identifiantsDesSujets());
        self::assertNull($fait->familyId, 'Un fait de personne ne relève d’aucune famille.');
        self::assertSame('Tisserand', $fait->libelle);
    }

    /**
     * **Le flux rendu est COMPLET, sans aucun filtre de confidentialité**.
     *
     * C'est contre-intuitif pour un port qui alimente deux vues soumises au cercle, et c'est
     * pourtant la seule forme juste : le filtrage a lieu **une seule fois**, dans
     * `Almanach\Service\FluxVisible`. Filtrer ici priverait au passage la bascule « voir
     * comme un membre » de son objet, qui suppose un jeu de départ complet — et **deux
     * filtres valent moins qu'un**, celui qu'on oublie de corriger étant toujours le second.
     *
     * Pyroxène Tilleul est vivant et hors de tout cercle. Sa naissance doit être là, avec
     * son lieu, et son sujet doit se déclarer `vivant`.
     */
    public function testLeFluxNAppliqueAucunFiltreDeConfidentialite(): void
    {
        $evenements = $this->parCle($this->port()->flux()->evenements);
        $naissance = $evenements['naissance:'.$this->idDe('cousin')] ?? null;

        self::assertNotNull($naissance, 'La naissance d’un vivant hors cercle traverse le port : c’est au module d’en décider.');
        self::assertSame('Sorbière, Fougerie, Florie', $naissance->date->getPlace());
        self::assertTrue($naissance->sujets[0]->vivant, 'L’état vivant vient de ReglePersonneVivante, pas de `deceased`.');

        // Et l'union du couple mixte aussi — c'est elle que `FluxVisible` devra fermer.
        $mariage = $evenements['mariage:'.$this->familles['mixte']->getId()] ?? null;
        self::assertNotNull($mariage);
        self::assertSame(
            [$this->idDe('cousin'), $this->idDe('conjointe')],
            $mariage->identifiantsDesSujets(),
            'Une union porte ses deux époux, y compris quand l’un est un vivant hors cercle.',
        );
    }

    /**
     * Les dates traversent **non aplaties**, et l'aïeule en est le cas de contrôle.
     *
     * `BET 1901 AND 1909` doit arriver intacte : c'est le bogue qui faisait naître Marne
     * Dolomite Sureau en 1987, après ses propres enfants, et c'est ce que l'en-tête
     * d'`UncertainDate` ferme en n'exposant ni `getDate()` ni `getYear()`.
     */
    public function testLesDatesTraversentSansEtreAplaties(): void
    {
        $naissance = $this->parCle($this->port()->flux()->evenements)['naissance:'.$this->idDe('aieule')] ?? null;

        self::assertNotNull($naissance);
        self::assertSame('BET 1901 AND 1909', $naissance->date->getRaw());
        self::assertSame('1901-01-01', $naissance->date->getMin()?->format('Y-m-d'));
        self::assertSame('1909-12-31', $naissance->date->getMax()?->format('Y-m-d'));
        self::assertTrue($naissance->date->isRange());
    }

    /**
     * Les sept `FactType` se traduisent, **et le `match` est exhaustif**.
     *
     * L'intérêt n'est pas de vérifier sept correspondances triviales : c'est que le jour où
     * `FactType` gagne un huitième cas, ce test lève `UnhandledMatchError` **ici**, sur le
     * poste de qui a ajouté le cas, plutôt qu'en production dans la sérialisation d'une
     * requête. C'est exactement ce que `TypeDEvenement::from($type->value)` ne ferait pas.
     *
     * Les sept valeurs de *backing* sont aussi comparées une à une : elles sont identiques
     * de part et d'autre par **décision**, et une divergence ferait recevoir au front un
     * `type` qu'il ne connaît pas, sans erreur d'aucune sorte.
     */
    public function testLaTraductionDesTypesDeFaitEstExhaustiveEtConserveLesValeurs(): void
    {
        $personne = $this->personnes['aieul'];
        $poses = [];

        foreach (FactType::cases() as $type) {
            $fait = new Fact($type, $personne);
            $fait->setHappened(self::annee(1930, 'Saule, Ombrage, Sylvanie'));
            $this->em()->persist($fait);
            $poses[] = $fait;
        }

        $this->em()->flush();

        $evenements = $this->parCle($this->port()->flux()->evenements);

        self::assertCount(7, $poses, 'FactType compte sept cas ; si ce nombre change, la traduction est à relire.');

        foreach ($poses as $fait) {
            $traduit = $evenements['fait:'.$fait->getId()] ?? null;

            self::assertNotNull($traduit, 'Chaque type de fait doit traverser.');
            self::assertSame(
                $fait->getType()->value,
                $traduit->type->value,
                'Les valeurs de backing sont identiques des deux côtés, par décision.',
            );
        }
    }

    /**
     * Les lignes de `tree_place` **sans coordonnées ne traversent pas**.
     *
     * `LieuResolu` type ses coordonnées non nullables précisément pour qu'aucun lecteur
     * n'ait à retester : le premier qui oublierait poserait une bulle à l'intersection de
     * l'équateur et du méridien de Greenwich. Le lieu non résolu n'est pas perdu pour
     * autant — le normaliseur le compte par l'**absence** de correspondance.
     */
    public function testSeulsLesLieuxAvecCoordonneesTraversent(): void
    {
        $resolu = new Place('Saule, Ombrage, Sylvanie', 'SAULE OMBRAGE SYLVANIE', 'Saule (18)');
        $resolu->poserLesCoordonnees(46.12, 2.56)->setCommune('Saule')->setPays('FR')->setConfiance(100);

        $introuvable = new Place('Hameau introuvable', 'HAMEAU INTROUVABLE', 'Hameau introuvable');

        $this->em()->persist($resolu);
        $this->em()->persist($introuvable);
        $this->em()->flush();

        $lieux = $this->port()->flux()->lieux;

        self::assertCount(1, $lieux, 'Seul le lieu résolu traverse.');
        self::assertSame('SAULE OMBRAGE SYLVANIE', $lieux[0]->cle);
        self::assertSame('commune', $lieux[0]->precision, 'La finesse se lit des colonnes, elle n’est pas stockée.');
        self::assertSame(46.12, $lieux[0]->lat);
    }

    /**
     * Une date-année, telle que l'import l'écrit : `min` au 1<sup>er</sup> janvier, `max`
     * au 31 décembre.
     *
     * Écrite ici plutôt que reprise d'un socle partagé : c'est **la** forme que la carte
     * doit savoir dater et que le calendrier doit refuser de placer, et un test qui la pose
     * doit la montrer.
     */
    private static function annee(int $annee, ?string $lieu = null): UncertainDate
    {
        return new UncertainDate(
            (string) $annee,
            DateQualifier::Annee,
            new \DateTimeImmutable($annee.'-01-01'),
            new \DateTimeImmutable($annee.'-12-31'),
            $lieu,
        );
    }

    /**
     * @param list<EvenementDeLArbre> $evenements
     *
     * @return array<string, EvenementDeLArbre>
     */
    private function parCle(array $evenements): array
    {
        $parCle = [];

        foreach ($evenements as $evenement) {
            self::assertArrayNotHasKey(
                $evenement->cle,
                $parCle,
                'Les clés d’événement sont uniques PAR CONSTRUCTION : un doublon signale une origine mal choisie.',
            );

            $parCle[$evenement->cle] = $evenement;
        }

        return $parCle;
    }
}
