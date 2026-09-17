<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Almanach\Contract\EvenementDeLArbre;
use App\Module\Almanach\Contract\FluxDEvenements;
use App\Module\Almanach\Contract\LieuResolu;
use App\Module\Almanach\Contract\PortEvenementsArbre;
use App\Module\Almanach\Contract\SujetDeLEvenement;
use App\Module\Almanach\Contract\TypeDEvenement;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Tree\Entity\Fact;
use App\Module\Tree\Entity\Family;
use App\Module\Tree\Entity\Person;
use App\Module\Tree\Entity\Place;
use App\Module\Tree\Enum\FactType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'implémentation, côté `Tree`, de {@see PortEvenementsArbre} — tout ce que la carte et
 * le calendrier lisent de l'arbre.
 *
 * C'est une **traduction, pas un calcul**, comme {@see VueDeLArbrePourChronologie} dont ce
 * fichier est le cousin. Tout existe déjà : les personnes dans `tree_person`, les unions
 * dans `tree_family`, les faits dans `tree_fact`, l'état vivant dans
 * {@see ReglePersonneVivante}, les lieux résolus dans `tree_place`. Ce fichier les met dans
 * le vocabulaire d'`Almanach`, et rien d'autre.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LE PIÈGE DE CE FICHIER — à lire avant de recopier quoi que ce soit du voisin
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * `VueDeLArbrePourChronologie::chargeLesFaits()` écrit :
 *
 * ```php
 * ->innerJoin('f.person', 'p')
 * ```
 *
 * **Le recopier ici ferait disparaître tous les faits de famille**, et la panne serait
 * muette : pas d'exception, pas de test rouge, seulement des bulles en moins sur une carte
 * dont personne ne connaît par cœur le contenu attendu. Là-bas c'est une **décision**,
 * écrite en toutes lettres dans l'en-tête de `FaitDeLaChronologie` : le jalon du § 9.1
 * exige un `personId`, et un fait de famille n'en a pas à y mettre.
 *
 * **Ici cette contrainte n'existe pas.** {@see EvenementDeLArbre} est identifié par une
 * clé `"<origine>:<id>"` qui nomme une **ligne**, pas une personne, et il porte une
 * **liste** de sujets. Un fait de famille traverse donc entier, avec ses deux époux comme
 * sujets. Les jointures ci-dessous sont des `leftJoin` — `tree_fact` admet exactement un
 * sujet (`ck_tree_fact_sujet_unique`), donc l'un des deux côtés est toujours nul.
 *
 * ── LA RÈGLE QU'IL NE FAUT SURTOUT PAS « AMÉLIORER » ─────────────────────────────
 *
 * **Le flux rendu est COMPLET, sans aucun filtre de confidentialité.** C'est
 * contre-intuitif pour un port qui alimente deux vues soumises au cercle, et c'est
 * pourtant la seule forme juste : le filtrage a lieu **une seule fois**, dans
 * `Almanach\Service\FluxVisible`. Filtrer ici priverait au passage la bascule « voir comme
 * un membre » de son objet, qui suppose un jeu de départ complet.
 *
 * ── LES DATES TRAVERSENT EN `UncertainDate`, JAMAIS EN ANNÉES NI EN JOURS ────────
 *
 * Le port pourrait rendre `?int $annee` ou `?int $jour` ; ce serait l'aplatissement que
 * l'en-tête d'`UncertainDate` ferme. `Almanach` fait les deux calculs lui-même, en clair,
 * chacun à l'endroit où il sert — et la règle du jour d'anniversaire, qui est la plus
 * délicate du produit, reste lisible d'un seul écran dans
 * `Almanach\Service\JourAnniversaire`.
 *
 * ── L'ÉTAT VIVANT PASSE PAR `ReglePersonneVivante`, JAMAIS PAR `deceased` ────────
 *
 * `tree_person.deceased` dit seulement qu'un `DEAT` figurait dans le GEDCOM — 78 personnes
 * n'en ont aucun. « Vivant » est la propriété **calculée** du § 9.2, avec son seuil et sa
 * médiane par génération, et *« un décès inconnu vaut vivant, donc masqué »*. Il n'existe
 * qu'une définition de « vivant » dans le produit ; une seconde, écrite ici, déciderait un
 * jour autrement de ce que le cercle masque.
 *
 * ── LA TRADUCTION DES TYPES EST UN `match` EXHAUSTIF ─────────────────────────────
 *
 * Voir {@see self::typeDuFait()}. `TypeDEvenement::from($fait->getType()->value)`
 * marcherait aujourd'hui et lèverait en production le jour où `FactType` gagne un
 * huitième cas.
 *
 * ── MÉMORISÉ POUR LA DURÉE DE LA REQUÊTE ─────────────────────────────────────────
 *
 * Une requête HTTP sert une seule vue ; la mémorisation est là pour le jour où elle en
 * servirait deux, et pour que le coût reste quatre lectures quoi qu'il arrive.
 */
final class VueDeLArbrePourAlmanach implements PortEvenementsArbre
{
    private ?FluxDEvenements $memoire = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglePersonneVivante $regleVivante,
        private readonly ReglagesDeLArbre $reglages,
    ) {
    }

    public function flux(): FluxDEvenements
    {
        return $this->memoire ??= $this->charger();
    }

    private function charger(): FluxDEvenements
    {
        $personnes = $this->chargeLesPersonnes();

        // Toute la population d'un coup : le repli du § 9.2 passe par la médiane des
        // années de naissance de chaque génération, qui est un calcul global.
        $vivants = $this->regleVivante->pourTous(
            $personnes,
            $this->reglages->seuilVivantAnnees(),
            new \DateTimeImmutable(),
        );

        $sujets = [];

        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null === $id) {
                continue;
            }

            $sujets[$id] = new SujetDeLEvenement(
                personId: $id,
                displayName: $personne->getDisplayName(),
                sex: $personne->getSex(),
                // « Un décès inconnu vaut vivant, donc masqué » : c'est la règle qui
                // tranche, jamais l'absence de date lue ici.
                vivant: $vivants[$id] ?? true,
            );
        }

        $evenements = [
            ...$this->evenementsDesPersonnes($personnes, $sujets),
            ...$this->evenementsDesFamilles($sujets),
            ...$this->evenementsDesFaits($sujets),
        ];

        return new FluxDEvenements($evenements, $this->chargeLesLieux());
    }

    /**
     * Naissances et décès — les deux blocs date de `tree_person`.
     *
     * @param list<Person>                  $personnes
     * @param array<int, SujetDeLEvenement> $sujets
     *
     * @return list<EvenementDeLArbre>
     */
    private function evenementsDesPersonnes(array $personnes, array $sujets): array
    {
        $evenements = [];

        foreach ($personnes as $personne) {
            $id = $personne->getId();

            if (null === $id || !isset($sujets[$id])) {
                continue;
            }

            // Une liste de couples et non un tableau associatif : une énumération n'est pas
            // une clé de tableau valide en PHP, et l'écrire ainsi lèverait
            // « Illegal offset type » à la première personne chargée.
            foreach ([[TypeDEvenement::Naissance, $personne->getBirth()], [TypeDEvenement::Deces, $personne->getDeath()]] as [$type, $date]) {
                if (!self::porteQuelqueChose($date)) {
                    continue;
                }

                $evenements[] = new EvenementDeLArbre(
                    cle: $type->origine().':'.$id,
                    type: $type,
                    sujets: [$sujets[$id]],
                    date: $date,
                );
            }
        }

        return $evenements;
    }

    /**
     * Mariages et divorces — les deux blocs date de `tree_family`.
     *
     * **Les deux époux sont sujets, pas seulement le mari.** Une date de mariage est la
     * date des deux, et c'est ce qui permet à `FluxVisible` d'appliquer sa règle du « ET ».
     * Trois familles sur 103 n'ont qu'un époux (`WIFE` sans `HUSB`) : leur union sort avec
     * un seul sujet, ce qui est juste. Une famille sans aucun époux est refusée en base
     * (`ck_tree_family_au_moins_un_epoux`) ; si elle existait quand même, sa liste de
     * sujets vide la ferait écarter par le repli fermé de `FluxVisible`.
     *
     * @param array<int, SujetDeLEvenement> $sujets
     *
     * @return list<EvenementDeLArbre>
     */
    private function evenementsDesFamilles(array $sujets): array
    {
        $evenements = [];

        foreach ($this->chargeLesFamilles() as $famille) {
            $id = $famille->getId();

            if (null === $id) {
                continue;
            }

            $epoux = $this->epouxDe($famille, $sujets);

            foreach ([[TypeDEvenement::Mariage, $famille->getMarriage()], [TypeDEvenement::Divorce, $famille->getDivorce()]] as [$type, $date]) {
                if (!self::porteQuelqueChose($date)) {
                    continue;
                }

                $evenements[] = new EvenementDeLArbre(
                    cle: $type->origine().':'.$id,
                    type: $type,
                    sujets: $epoux,
                    date: $date,
                    familyId: $id,
                );
            }
        }

        return $evenements;
    }

    /**
     * Les `tree_fact`, **de sujet `person` comme de sujet `family`**.
     *
     * Lire le piège en tête de fichier avant de resserrer ces jointures : un `innerJoin`
     * sur `f.person` écarterait tous les faits de famille, en silence.
     *
     * @param array<int, SujetDeLEvenement> $sujets
     *
     * @return list<EvenementDeLArbre>
     */
    private function evenementsDesFaits(array $sujets): array
    {
        $evenements = [];

        foreach ($this->chargeLesFaits() as $fait) {
            $id = $fait->getId();

            if (null === $id) {
                continue;
            }

            $date = $fait->getHappened();

            if (!self::porteQuelqueChose($date)) {
                continue;
            }

            $famille = $fait->getFamily();
            $personneId = $fait->getPerson()?->getId();

            // Exactement un des deux sujets est renseigné — `ck_tree_fact_sujet_unique`
            // l'impose avec `num_nonnulls(...) = 1`. On lit les deux sans supposer lequel.
            $sujetsDuFait = null !== $famille
                ? $this->epouxDe($famille, $sujets)
                : (null !== $personneId && isset($sujets[$personneId]) ? [$sujets[$personneId]] : []);

            $evenements[] = new EvenementDeLArbre(
                cle: 'fait:'.$id,
                type: self::typeDuFait($fait->getType()),
                sujets: $sujetsDuFait,
                date: $date,
                libelle: $fait->getLabel(),
                familyId: $famille?->getId(),
            );
        }

        return $evenements;
    }

    /**
     * Les époux d'une famille, dans l'ordre mari puis épouse, sans les absents.
     *
     * @param array<int, SujetDeLEvenement> $sujets
     *
     * @return list<SujetDeLEvenement>
     */
    private function epouxDe(Family $famille, array $sujets): array
    {
        $epoux = [];

        foreach ([$famille->getHusband(), $famille->getWife()] as $personne) {
            $id = $personne?->getId();

            if (null !== $id && isset($sujets[$id])) {
                $epoux[] = $sujets[$id];
            }
        }

        return $epoux;
    }

    /**
     * Cet événement a-t-il quelque chose à montrer ?
     *
     * Une date entièrement inconnue **sans lieu** n'est pas un événement : c'est une
     * colonne vide. Les 274 naissances existent toutes en base, mais toutes ne portent pas
     * de donnée ; les faire traverser gonflerait `resume.sansDate` et `resume.sansLieu` de
     * plusieurs centaines d'entrées qui n'apprennent rien — et un compteur qui compte du
     * vide n'est plus une mesure, c'est du bruit.
     *
     * En revanche une date inconnue **avec** un lieu traverse : « je sais où, pas quand »
     * est une information réelle, et c'est exactement ce que `resume.sansDate` doit
     * compter. La réciproque vaut pour `resume.sansLieu`.
     */
    private static function porteQuelqueChose(UncertainDate $date): bool
    {
        if ($date->isKnown()) {
            return true;
        }

        $lieu = $date->getPlace();

        return null !== $lieu && '' !== trim($lieu);
    }

    /**
     * `FactType` → `TypeDEvenement`, par un `match` **exhaustif**.
     *
     * Les sept valeurs de *backing* sont identiques de part et d'autre, donc
     * `TypeDEvenement::from($type->value)` rendrait le même résultat en une ligne. **Il ne
     * faut pas l'écrire.** Le jour où `FactType` gagne un huitième cas :
     *
     * - `from()` lève un `ValueError` **en production**, dans la sérialisation d'une
     *   requête HTTP, sur la donnée d'un membre, et la carte répond `500` ;
     * - ce `match` lève `UnhandledMatchError` **au premier test qui passe par cette
     *   ligne** — c'est-à-dire avant la livraison, sur le poste de qui a ajouté le cas.
     *
     * Une ligne de moins contre un incident de production : l'échange est vite fait. C'est
     * le seul endroit du produit où les deux vocabulaires se rencontrent, et son en-tête
     * dit pourquoi ils restent deux.
     */
    private static function typeDuFait(FactType $type): TypeDEvenement
    {
        return match ($type) {
            FactType::Profession => TypeDEvenement::Profession,
            FactType::Residence => TypeDEvenement::Residence,
            FactType::Inhumation => TypeDEvenement::Inhumation,
            FactType::Recensement => TypeDEvenement::Recensement,
            FactType::BaptemeReligieux => TypeDEvenement::BaptemeReligieux,
            FactType::Bapteme => TypeDEvenement::Bapteme,
            FactType::Autre => TypeDEvenement::Autre,
        };
    }

    /** @return list<Person> */
    private function chargeLesPersonnes(): array
    {
        /** @var list<Person> $personnes */
        $personnes = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->orderBy('p.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $personnes;
    }

    /**
     * Les unions, avec leurs deux époux **joints**.
     *
     * Sans ces deux `leftJoin`, chaque `getHusband()->getId()` déclencherait une requête :
     * 103 familles, donc jusqu'à 206 allers-retours pour une seule réponse.
     *
     * @return list<Family>
     */
    private function chargeLesFamilles(): array
    {
        /** @var list<Family> $familles */
        $familles = $this->em->createQueryBuilder()
            ->select('fam', 'mari', 'epouse')
            ->from(Family::class, 'fam')
            ->leftJoin('fam.husband', 'mari')
            ->leftJoin('fam.wife', 'epouse')
            ->orderBy('fam.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $familles;
    }

    /**
     * Les faits, **des deux sujets**, avec ce qu'il faut pour ne pas repasser par la base.
     *
     * Quatre `leftJoin` et non un `innerJoin` : voir le piège en tête de fichier. Aucun
     * `WHERE` sur la date — un fait non daté mais **situé** est une bulle légitime, et
     * c'est `Almanach` qui décide ce qu'il sait rendre.
     *
     * @return list<Fact>
     */
    private function chargeLesFaits(): array
    {
        /** @var list<Fact> $faits */
        $faits = $this->em->createQueryBuilder()
            ->select('f', 'p', 'fam', 'mari', 'epouse')
            ->from(Fact::class, 'f')
            ->leftJoin('f.person', 'p')
            ->leftJoin('f.family', 'fam')
            ->leftJoin('fam.husband', 'mari')
            ->leftJoin('fam.wife', 'epouse')
            ->orderBy('f.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $faits;
    }

    /**
     * Les lignes de `tree_place` **qui portent des coordonnées**.
     *
     * Celles qui n'en portent pas ne traversent pas : une bulle exige une position, et
     * {@see LieuResolu} type ses coordonnées non nullables précisément pour qu'aucun
     * lecteur n'ait à retester. Les lieux non résolus ne sont pas perdus pour autant — le
     * normaliseur les compte dans `resume.lieuNonResolu`, par l'absence de correspondance.
     *
     * @return list<LieuResolu>
     */
    private function chargeLesLieux(): array
    {
        /** @var list<Place> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('l')
            ->from(Place::class, 'l')
            ->where('l.lat IS NOT NULL AND l.lon IS NOT NULL')
            ->orderBy('l.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        $lieux = [];

        foreach ($lignes as $ligne) {
            $id = $ligne->getId();
            $lat = $ligne->getLat();
            $lon = $ligne->getLon();

            if (null === $id || null === $lat || null === $lon) {
                continue;
            }

            $lieux[] = new LieuResolu(
                placeId: $id,
                cle: $ligne->getRawKey(),
                libelle: $ligne->getLibelle(),
                lat: $lat,
                lon: $lon,
                precision: self::precisionDe($ligne),
                confiance: $ligne->getConfiance(),
                source: $ligne->getSource(),
                brut: [$ligne->getRaw()],
            );
        }

        return $lieux;
    }

    /**
     * La finesse de la résolution, déduite du champ le plus précis qui ait été reconnu.
     *
     * Elle n'est pas stockée : elle **se lit** des colonnes, et une colonne de plus qui
     * dirait la même chose pourrait un jour ne plus la dire. L'ordre est du plus fin au
     * plus grossier — une commune reconnue l'emporte sur son département.
     */
    private static function precisionDe(Place $place): string
    {
        return match (true) {
            null !== $place->getCommune() => 'commune',
            null !== $place->getSubdivision() => 'subdivision',
            null !== $place->getPays() => 'pays',
            default => 'inconnue',
        };
    }
}
