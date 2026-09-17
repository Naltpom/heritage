<?php

declare(strict_types=1);

namespace App\Module\Tree\Entity;

use App\Module\Tree\Repository\PlaceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La **résolution** d'un lieu écrit — une chaîne `PLAC` du GEDCOM d'un côté, des
 * coordonnées de l'autre. Environ 104 lignes pour l'arbre de travail.
 *
 * Ce n'est pas un référentiel de lieux : c'est une table de **correspondance**, dont
 * chaque ligne dit « la chaîne que voici a été reconnue comme ceci, par ce moyen, avec
 * cette confiance ». `raw` garde la provenance verbatim ; tout le reste est le résultat
 * d'un appariement qu'on peut rejouer, corriger, ou laisser en échec.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  POURQUOI UNE TABLE À PART, ET NON UN `place_id` DANS `UncertainDate`
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * C'est la première question qu'on se pose en lisant ce fichier, parce que la forme
 * évidente serait d'ajouter la colonne là où le lieu est déjà écrit — à côté de
 * `birth_place`, `marriage_place`, `happened_place`. **Trois raisons indépendantes
 * l'interdisent, et il suffit de la première pour que la question soit close.**
 *
 * **(a) Doctrine n'admet aucune association dans un `#[ORM\Embeddable]`.** Le lieu vit
 * dans {@see \App\Module\Core\ValueObject\UncertainDate}, qui est un objet incrusté ; un
 * `#[ORM\ManyToOne]` y est purement et simplement impossible. `place_id` ne pourrait donc
 * pas y entrer, quelle que soit l'envie qu'on en ait. Il resterait à poser un `int` nu,
 * c'est-à-dire une clé étrangère que rien ne garde — la forme dont l'ADR 0002 dit le mal
 * qu'elle a fait ailleurs.
 *
 * **(b) Ce serait un `use` du noyau vers un module.** `UncertainDate` est dans
 * `Core\ValueObject\` ; nommer une classe de `Tree` depuis ce fichier fait rougir
 * `tests/Frontieres/FrontieresInterModulesTest::testLeNoyauNeReferenceAucunModule()`, qui
 * ferme précisément le mécanisme par lequel `Tree` était devenu un second noyau sans que
 * personne ne l'ait décidé.
 *
 * **(c) {@see Person} porte un `#[ORM\Version]`.** Réécrire une ligne de personne
 * **incrémente sa version, et rend caduques les demandes en attente qui la visaient**
 * (§ 9.6 : leur `base_version` ne correspond plus). Poser un `place_id` sur `tree_person`
 * demanderait de réécrire jusqu'à 274 lignes pour dessiner une carte — et de périmer, au
 * passage, des propositions que des membres attendent. Une table de résolution ne réécrit
 * **aucune** personne : aucune demande en attente ne meurt pour que la carte fonctionne.
 * C'est le même raisonnement que celui de
 * {@see \App\Module\Tree\Command\RecalculerLesClesDeRechercheCommand}, qui l'énonce pour
 * les clés de recherche — à ceci près qu'ici on peut l'éviter tout à fait.
 *
 * ### L'échelle du problème, recomptée sur le vrai code
 *
 * `UncertainDate` est incrusté à **10 sites de mapping**, dans **5 entités** de **3
 * modules** : `Tree\Person` (×2, `birth_`/`death_`), `Tree\Family` (×2,
 * `marriage_`/`divorce_`), `Tree\Fact` (`happened_`), `Media\Media` (`taken_`),
 * `Proposal\DraftPerson` (×2), `Proposal\DraftLink` (×2). Un champ ajouté à l'objet
 * incrusté est **dix colonnes de plus**, sur trois modules dont deux n'ont que faire d'une
 * carte, et une migration qui touche cinq tables.
 *
 * **Aucune clé étrangère, aucune colonne ajoutée à une table existante.** Les cinq
 * colonnes `*_place` en `TEXT` restent exactement ce qu'elles sont. Le rapprochement se
 * fait **en PHP**, sur `raw_key`, à la sérialisation — une centaine d'entrées, un
 * `array_flip` et une lecture de table de hachage.
 *
 * ## `raw_key`, et pourquoi elle n'a pas de formule à elle
 *
 * Elle est produite par {@see \App\Module\Core\Service\CleDeRecherche::replier()}, qui est
 * **la** définition du repliement d'accents du produit. En écrire une seconde ici — même
 * juste, même testée — est exactement le défaut que cette classe existe pour supprimer :
 * « le jour où elles auraient divergé d'un seul caractère », un lieu cesserait d'être
 * retrouvé sans qu'aucune erreur ne se produise.
 *
 * ## Les contraintes `CHECK` sont **PostgreSQL seulement**
 *
 * La migration pose quatre `CHECK` : `(lat IS NULL) = (lon IS NULL)`, les bornes de
 * latitude et de longitude, et `confiance BETWEEN 0 AND 100`. Doctrine ne sait pas les
 * exprimer dans le mapping, donc **le schéma de test ne les porte pas** : `SchemaTool`
 * construit la base SQLite depuis les métadonnées, et ces quatre règles n'en font pas
 * partie. Un test qui poserait une latitude à 900 passerait au vert ici et exploserait en
 * production. Les invariants qui comptent sont donc tenus **aussi** par le code qui écrit
 * cette table — {@see \App\Module\Tree\Service\AppariementDesLieux} ne produit jamais une
 * coordonnée sans l'autre, et jamais une confiance hors de [0, 100].
 *
 * ## `source` est une chaîne, pas une énumération
 *
 * Ses valeurs sont des **noms de fichiers de référence** (`communes-fr`, `villes-monde`),
 * plus deux états (`aucune`, `manuel`). Une énumération figerait dans le code PHP la liste
 * des fichiers de données qu'un exploitant peut passer en option de la commande ; et la
 * valeur qui compte vraiment, `manuel`, n'est pas un fichier du tout. Les quatre valeurs
 * en usage sont les constantes ci-dessous.
 */
#[ORM\Entity(repositoryClass: PlaceRepository::class)]
#[ORM\Table(name: 'tree_place')]
// Une chaîne repliée, une ligne. C'est cet index qui rend la commande **idempotente** :
// relancée, elle retrouve la ligne au lieu d'en créer une seconde.
#[ORM\UniqueConstraint(name: 'uniq_tree_place_cle', columns: ['raw_key'])]
class Place
{
    /** Aucun appariement n'a abouti : la ligne existe, elle porte le compte, sans coordonnées. */
    public const string SOURCE_AUCUNE = 'aucune';

    /** `ressources/gazetteer/communes-fr.csv`. */
    public const string SOURCE_COMMUNES = 'communes-fr';

    /** `ressources/gazetteer/villes-monde.csv`. */
    public const string SOURCE_VILLES = 'villes-monde';

    /**
     * Corrigé à la main, dans la base.
     *
     * **Cette valeur n'est jamais écrasée par la commande, pas même avec `--forcer`.** La
     * vraie base vit sur le VPS et le dépôt Git ne porte aucune donnée familiale : une
     * correction manuelle n'a **nulle part ailleurs où exister**. L'écraser, c'est la
     * perdre pour de bon.
     */
    public const string SOURCE_MANUEL = 'manuel';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * La chaîne `PLAC` **verbatim**, telle que le GEDCOM l'écrit : `Saint-Ronce-de-Verçay,
     * Cher, Centre-Val de Loire, France`. C'est la provenance, et c'est elle qu'on affiche
     * dans une bulle — pas le libellé normalisé. En `TEXT` comme les colonnes `*_place`
     * dont elle est copiée : tronquer la provenance serait la perdre.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $raw;

    /**
     * `CleDeRecherche::replier($raw)` — sans accents, en majuscules, ponctuation réduite à
     * des espaces simples. La même longueur que `tree_person.search_key`, parce que c'est
     * la même formule qui l'écrit.
     */
    #[ORM\Column(length: 200)]
    private string $rawKey;

    /**
     * Le nom **retenu** après appariement : `Saint-Ronce-de-Verçay (18)`. Il sert
     * l'étiquette d'une bulle et le regroupement de plusieurs graphies.
     *
     * Quand rien n'a été reconnu, il vaut la première partie de `raw` : mieux vaut
     * afficher ce que la donnée dit qu'un vide.
     */
    #[ORM\Column(length: 160)]
    private string $libelle;

    /** La commune reconnue, quand il y en a une. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $commune = null;

    /**
     * Le département ou la région reconnue — le **nom**, pas le code INSEE : c'est ce
     * qu'on montre, et la table ne sert pas à faire de la géographie administrative.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $subdivision = null;

    /** Le code ISO 3166-1 alpha-2 du pays : `FR`, `DZ`, `CH`. */
    #[ORM\Column(length: 2, nullable: true, options: ['fixed' => true])]
    private ?string $pays = null;

    /**
     * Latitude et longitude en degrés décimaux, WGS 84.
     *
     * **Les deux sont nulles ou aucune ne l'est** — un `CHECK` l'impose en PostgreSQL, et
     * l'appariement ne sait de toute façon pas en produire une seule. Une bulle a besoin
     * des deux ; une demi-coordonnée n'est pas une position à demi connue, c'est une
     * donnée fausse.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lat = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lon = null;

    /** L'une des quatre constantes `SOURCE_*` ci-dessus. */
    #[ORM\Column(length: 16, options: ['default' => self::SOURCE_AUCUNE])]
    private string $source = self::SOURCE_AUCUNE;

    /**
     * De 0 à 100, par passe d'appariement : 100 commune + département, 90 commune +
     * région, 70 commune française unique, 60 ville du monde, 0 rien.
     *
     * Ce n'est pas une probabilité — personne ne l'a calibrée —, c'est un **rang**, et il
     * sert à trier ce qu'on relit en premier quand on corrige à la main.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $confiance = 0;

    /**
     * Le nombre d'événements de l'arbre qui portent cette chaîne.
     *
     * Il donne l'ordre de relecture — corriger le lieu cité 31 fois avant celui cité une
     * — et il ne sert à rien d'autre : le rayon d'une bulle se calcule à la sérialisation,
     * **après le cercle de visibilité**, jamais sur ce compte-ci qui l'ignore.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $occurrences = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $resoluLe;

    public function __construct(string $raw, string $rawKey, string $libelle)
    {
        $this->raw = $raw;
        $this->rawKey = $rawKey;
        $this->libelle = $libelle;
        $this->resoluLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function setRaw(string $raw): self
    {
        $this->raw = $raw;

        return $this;
    }

    public function getRawKey(): string
    {
        return $this->rawKey;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getCommune(): ?string
    {
        return $this->commune;
    }

    public function setCommune(?string $commune): self
    {
        $this->commune = $commune;

        return $this;
    }

    public function getSubdivision(): ?string
    {
        return $this->subdivision;
    }

    public function setSubdivision(?string $subdivision): self
    {
        $this->subdivision = $subdivision;

        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): self
    {
        $this->pays = $pays;

        return $this;
    }

    public function getLat(): ?float
    {
        return $this->lat;
    }

    public function getLon(): ?float
    {
        return $this->lon;
    }

    /**
     * Les deux coordonnées ensemble, **ou rien** — il n'existe volontairement pas de
     * `setLat()` seul. C'est le `CHECK` `(lat IS NULL) = (lon IS NULL)` rendu
     * inatteignable depuis PHP, et c'est ce qui le tient encore sur SQLite, où la
     * contrainte n'existe pas.
     */
    public function poserLesCoordonnees(?float $lat, ?float $lon): self
    {
        if ((null === $lat) !== (null === $lon)) {
            throw new \InvalidArgumentException('Une latitude sans longitude, ou l\'inverse, n\'est pas une position : les deux se posent ensemble ou pas du tout.');
        }

        $this->lat = $lat;
        $this->lon = $lon;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /** Une correction faite à la main dans la base — voir {@see self::SOURCE_MANUEL}. */
    public function estManuel(): bool
    {
        return self::SOURCE_MANUEL === $this->source;
    }

    public function getConfiance(): int
    {
        return $this->confiance;
    }

    public function setConfiance(int $confiance): self
    {
        if ($confiance < 0 || $confiance > 100) {
            throw new \InvalidArgumentException(\sprintf('La confiance est un rang de 0 à 100, reçu : %d.', $confiance));
        }

        $this->confiance = $confiance;

        return $this;
    }

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function setOccurrences(int $occurrences): self
    {
        $this->occurrences = $occurrences;

        return $this;
    }

    public function getResoluLe(): \DateTimeImmutable
    {
        return $this->resoluLe;
    }

    public function toucher(): self
    {
        $this->resoluLe = new \DateTimeImmutable();

        return $this;
    }
}
