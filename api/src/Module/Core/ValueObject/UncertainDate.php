<?php

declare(strict_types=1);

namespace App\Module\Core\ValueObject;

use App\Module\Core\Enum\DateQualifier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une date généalogique incertaine : les cinq colonnes du § 0.5 du modèle de données,
 * réunies en un objet valeur incrusté (`#[ORM\Embeddable]`).
 *
 * Le motif revient sur sept événements — naissance, décès, mariage, divorce, fait,
 * brouillon de naissance, brouillon de décès — plus la prise de vue d'un média. Il est
 * décrit ici une fois et incrusté partout ailleurs avec un `columnPrefix`.
 *
 * **Il n'existe ni `getDate()`, ni `getYear()`, ni `__toString()` qui rendrait une
 * date.** C'est le verrou principal contre l'aplatissement des fourchettes (§ 9.1), et
 * c'est celui que le développeur rencontre en premier : l'API de l'objet ne propose
 * tout simplement pas le geste interdit. Si la méthode existait, quelqu'un l'appellerait
 * et une fourchette deviendrait une date ferme — c'est exactement le bogue qui faisait
 * naître Marne Dolomite Sureau en 1987, après ses propres enfants.
 *
 * Un appelant qui veut malgré tout un point pour dessiner (le centre d'une barre de la
 * vue chronologique) l'écrit lui-même à partir de {@see getMin()} et {@see getMax()}.
 * C'est délibéré : le calcul doit être visible à l'endroit où il est fait, pas caché
 * dans un accesseur qu'on appellerait sans y penser.
 *
 * Les deux autres verrous sont ailleurs : aucune colonne `*_date` n'existe pour
 * accueillir une date ferme, et une contrainte `CHECK` refuse en base
 * `qualifier = 'entre'` avec `min = max` (§ 0.5, migration initiale).
 */
#[ORM\Embeddable]
class UncertainDate
{
    /**
     * Les mois en français, pour {@see format()}. Table en dur plutôt qu'`IntlDateFormatter` :
     * l'extension `intl` n'est pas une dépendance ferme de l'API, et douze mots ne
     * justifient pas d'en faire une.
     */
    private const array MOIS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /**
     * La chaîne GEDCOM verbatim : `BET 1901 AND 1909`, `ABT 1934`, `11 MAR 1930`.
     * Jamais reconstruite, jamais réécrite par l'application. C'est la provenance, et
     * c'est ce qui reste quand aucune forme connue n'a pu être analysée.
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $raw = null;

    #[ORM\Column(length: 12, enumType: DateQualifier::class, options: ['default' => 'inconnue'])]
    private DateQualifier $qualifier = DateQualifier::Inconnue;

    /** Borne basse **inclusive**. `null` = non bornée à gauche (cas `BEF`). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $min = null;

    /** Borne haute **inclusive**. `null` = non bornée à droite (cas `AFT`, `FROM`). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $max = null;

    /** Le lieu de l'événement, tel qu'écrit (`PLAC`). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $place = null;

    public function __construct(
        ?string $raw = null,
        DateQualifier $qualifier = DateQualifier::Inconnue,
        ?\DateTimeImmutable $min = null,
        ?\DateTimeImmutable $max = null,
        ?string $place = null,
    ) {
        $this->raw = $raw;
        $this->qualifier = $qualifier;
        $this->min = $min;
        $this->max = $max;
        $this->place = $place;
    }

    /** Le cas « pas de date » : c'est la valeur par défaut de tous les blocs date. */
    public static function inconnue(?string $place = null): self
    {
        return new self(null, DateQualifier::Inconnue, null, null, $place);
    }

    public function getRaw(): ?string
    {
        return $this->raw;
    }

    public function getQualifier(): DateQualifier
    {
        return $this->qualifier;
    }

    public function getMin(): ?\DateTimeImmutable
    {
        return $this->min;
    }

    public function getMax(): ?\DateTimeImmutable
    {
        return $this->max;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    /** Une date est « connue » dès qu'elle porte autre chose que l'absence. */
    public function isKnown(): bool
    {
        return DateQualifier::Inconnue !== $this->qualifier;
    }

    /**
     * La date désigne-t-elle autre chose qu'un jour unique ?
     *
     * Vrai pour une fourchette (`BET`), pour une borne ouverte (`BEF`, `AFT`, `FROM`)
     * et pour toute granularité plus large que le jour (`1930`, `JAN 1920`, `ABT 1934`).
     * C'est ce que l'affichage doit hachurer (§ 7.4 du dossier de mission).
     */
    public function isRange(): bool
    {
        if (!$this->isKnown()) {
            return false;
        }

        if (null === $this->min || null === $this->max) {
            return true;
        }

        return $this->min->format('Y-m-d') !== $this->max->format('Y-m-d');
    }

    /**
     * Les deux dates peuvent-elles désigner un même instant ?
     *
     * Une borne nulle est une borne ouverte, donc infinie de ce côté. Une date
     * entièrement dépourvue de bornes ne recouvre **rien** : elle recouvrirait sinon
     * tout, et l'appelant croirait avoir appris quelque chose.
     */
    public function overlaps(self $other): bool
    {
        if ((null === $this->min && null === $this->max) || (null === $other->min && null === $other->max)) {
            return false;
        }

        $apresLeDebutDeLAutre = null === $this->min || null === $other->max || $this->min <= $other->max;
        $avantLaFinDeLAutre = null === $other->min || null === $this->max || $other->min <= $this->max;

        return $apresLeDebutDeLAutre && $avantLaFinDeLAutre;
    }

    /**
     * Le rendu français, **jamais un point quand il y a un intervalle** : « entre 1901
     * et 1909 », « vers 1934 », « 11 mars 1930 », « avant 1920 ».
     *
     * C'est cette méthode qui produit la `ligne_dates` de l'ancien visualiseur à la
     * sérialisation, au lieu de la stocker — ce qui garantit qu'elle ne peut pas
     * afficher un point là où la base a un intervalle (§ 9.1).
     *
     * Quand les bornes manquent pour rendre la forme annoncée, on retombe sur la chaîne
     * GEDCOM verbatim : montrer la provenance vaut mieux qu'inventer une lecture.
     */
    public function format(): string
    {
        $repli = $this->raw ?? '';

        return match ($this->qualifier) {
            DateQualifier::Inconnue => '',
            DateQualifier::Exacte => $this->rendreBorne($this->min ?? $this->max, 'jour') ?? $repli,
            DateQualifier::Annee => $this->rendreBorne($this->min ?? $this->max, 'annee') ?? $repli,
            DateQualifier::Mois => $this->rendreBorne($this->min ?? $this->max, 'mois') ?? $repli,
            DateQualifier::Environ => null !== ($rendu = $this->rendreBorne($this->min ?? $this->max))
                ? 'vers '.$rendu
                : $repli,
            DateQualifier::Entre => null !== $this->min && null !== $this->max
                ? \sprintf('entre %s et %s', $this->avecArticle($this->min), $this->avecArticle($this->max))
                : $repli,
            // `BEF 1920` est stocké `max = 1919-12-31` : on rend la borne exclue, donc le
            // lendemain, ce qui restitue « avant 1920 » et non « avant le 31 décembre 1919 ».
            DateQualifier::Avant => null !== $this->max
                ? 'avant '.$this->avecArticle($this->max->modify('+1 day'))
                : $repli,
            DateQualifier::Apres => null !== $this->min
                ? 'après '.$this->avecArticle($this->min->modify('-1 day'))
                : $repli,
            DateQualifier::Depuis => null !== $this->min
                ? 'depuis '.$this->avecArticle($this->min)
                : $repli,
            DateQualifier::JusquA => null !== $this->max
                ? "jusqu'".$this->avecArticle($this->max, 'au ', 'à ')
                : $repli,
        };
    }

    // ── La réciprocité des vivants (ADR 0016) ──────────────────────────────────────
    //
    // Une naissance de personne vivante ne sort qu'à travers les composantes que le
    // lecteur a le droit de voir. Les trois méthodes ci-dessous sont le **seul** endroit
    // où une date se dégrade : elles travaillent sur les bornes, **jamais sur `raw`**,
    // qui porte toutes les composantes d'un coup (« 12 MAR 1985 »).

    /** Le nom français d'un mois (1 à 12). */
    public static function nomDuMois(int $mois): string
    {
        return self::MOIS[$mois] ?? '';
    }

    /**
     * Les composantes **certaines** de la date : celles que la granularité du
     * qualificatif établit. Une fourchette, une borne ouverte ou un « vers » n'en
     * établissent aucune — « vers 1934 » ne dit pas que l'année est 1934.
     *
     * @return array{annee: int|null, mois: int|null, jour: int|null}
     */
    public function composantesSures(): array
    {
        $rien = ['annee' => null, 'mois' => null, 'jour' => null];
        $point = $this->min ?? $this->max;

        if (null === $point) {
            return $rien;
        }

        return match ($this->qualifier) {
            DateQualifier::Exacte => ['annee' => (int) $point->format('Y'), 'mois' => (int) $point->format('n'), 'jour' => (int) $point->format('j')],
            DateQualifier::Mois => ['annee' => (int) $point->format('Y'), 'mois' => (int) $point->format('n'), 'jour' => null],
            DateQualifier::Annee => ['annee' => (int) $point->format('Y'), 'mois' => null, 'jour' => null],
            default => $rien,
        };
    }

    /**
     * La même date, **dégradée** aux composantes permises — une date valide, sans `raw`,
     * lieu conservé, que les consommateurs existants lisent sans le savoir.
     *
     * - tout permis : la date elle-même, inchangée ;
     * - sans l'année : `null` — un mois et un jour sans année ne s'écrivent pas comme une
     *   date de l'arbre (voir {@see formatPartiel()} pour leur prose) ;
     * - année + mois sur une date au jour ou au mois : une date au mois ;
     * - sinon : la date ramenée à l'année. Une fourchette ou une borne ouverte garde son
     *   qualificatif et voit ses bornes **élargies** à l'année entière, ce qui reste vrai
     *   (« avant le 15 mars 1920 » devient « avant 1921 ») sans rien dire de plus fin.
     */
    public function reduite(ComposantesDeDate $permises): ?self
    {
        if ($permises->estComplete()) {
            return $this;
        }

        if (!$this->isKnown()) {
            return self::inconnue($this->place);
        }

        if (!$permises->annee) {
            return null;
        }

        $debut = static fn (?\DateTimeImmutable $d): ?\DateTimeImmutable => $d?->setDate((int) $d->format('Y'), 1, 1);
        $fin = static fn (?\DateTimeImmutable $d): ?\DateTimeImmutable => $d?->setDate((int) $d->format('Y'), 12, 31);
        $point = $this->min ?? $this->max;

        switch ($this->qualifier) {
            case DateQualifier::Exacte:
            case DateQualifier::Mois:
                if (null === $point) {
                    return self::inconnue($this->place);
                }
                if ($permises->mois) {
                    $premier = $point->setDate((int) $point->format('Y'), (int) $point->format('n'), 1);

                    return new self(null, DateQualifier::Mois, $premier, $premier->modify('last day of this month'), $this->place);
                }

                return new self(null, DateQualifier::Annee, $debut($point), $fin($point), $this->place);

            case DateQualifier::Annee:
                return null === $point
                    ? self::inconnue($this->place)
                    : new self(null, DateQualifier::Annee, $debut($point), $fin($point), $this->place);

            case DateQualifier::Entre:
                $min = $debut($this->min);
                $max = $fin($this->max);
                if (null !== $min && null !== $max && $min->format('Y') === $max->format('Y')) {
                    return new self(null, DateQualifier::Annee, $min, $max, $this->place);
                }

                return new self(null, DateQualifier::Entre, $min, $max, $this->place);

            case DateQualifier::Avant:
                // `BEF X` est stocké `max = X - 1 jour` : on élargit le lendemain à la fin de
                // son année, de sorte que `format()` rende « avant <année suivante> ».
                $borne = $this->max?->modify('+1 day');
                $max = null !== $borne && '01-01' !== $borne->format('m-d')
                    ? $borne->setDate((int) $borne->format('Y'), 12, 31)
                    : $this->max;

                return new self(null, DateQualifier::Avant, null, $max, $this->place);

            case DateQualifier::Apres:
                $borne = $this->min?->modify('-1 day');
                $min = null !== $borne && '12-31' !== $borne->format('m-d')
                    ? $borne->setDate((int) $borne->format('Y'), 1, 1)
                    : $this->min;

                return new self(null, DateQualifier::Apres, $min, null, $this->place);

            default:
                // Environ, Depuis, JusquA : bornes élargies à l'année, qualificatif gardé.
                return new self(null, $this->qualifier, $debut($this->min), $fin($this->max), $this->place);
        }
    }

    /**
     * Le rendu français des seules composantes permises — le `texte` d'une naissance de
     * vivant vue partiellement : « 1985 », « mars 1985 », « 12 mars », « mars »,
     * « vers 1934 ». Chaîne vide quand rien de certain n'est permis.
     */
    public function formatPartiel(ComposantesDeDate $permises): string
    {
        if ($permises->estComplete()) {
            return $this->format();
        }

        if ($permises->annee) {
            return $this->reduite($permises)?->format() ?? '';
        }

        $sures = $this->composantesSures();
        $mois = $permises->mois ? $sures['mois'] : null;
        $jour = $permises->jour ? $sures['jour'] : null;

        if (null !== $mois && null !== $jour) {
            return (1 === $jour ? '1er' : (string) $jour).' '.self::MOIS[$mois];
        }

        if (null !== $mois) {
            return self::MOIS[$mois];
        }

        return null !== $jour ? 'le '.(1 === $jour ? '1er' : (string) $jour) : '';
    }

    /**
     * Les composantes **sures et permises**, pour les écrans qui calculent (anniversaires).
     *
     * @return array{annee?: int, mois?: int, jour?: int}
     */
    public function composantesVisibles(ComposantesDeDate $permises): array
    {
        $sures = $this->composantesSures();
        $visibles = [];

        if ($permises->annee && null !== $sures['annee']) {
            $visibles['annee'] = $sures['annee'];
        }
        if ($permises->mois && null !== $sures['mois']) {
            $visibles['mois'] = $sures['mois'];
        }
        if ($permises->jour && null !== $sures['jour']) {
            $visibles['jour'] = $sures['jour'];
        }

        return $visibles;
    }

    /**
     * Rend une borne à la granularité demandée, ou, à défaut, à celle que ses
     * composantes trahissent : un 1er janvier ou un 31 décembre est une année, un 1er du
     * mois ou une fin de mois est un mois, le reste est un jour. C'est l'inverse de la
     * table de conversion du § 0.5, donc `1930` revient bien « 1930 » et non
     * « 1er janvier 1930 ».
     *
     * La granularité est imposée quand le qualificatif la connaît — sans quoi
     * `1 JAN 1900`, pourtant exacte, se rendrait « 1900 ».
     *
     * @param 'annee'|'mois'|'jour'|null $grain
     */
    private function rendreBorne(?\DateTimeImmutable $date, ?string $grain = null): ?string
    {
        if (null === $date) {
            return null;
        }

        return match ($grain ?? $this->granularite($date)) {
            'annee' => $date->format('Y'),
            'mois' => self::MOIS[(int) $date->format('n')].' '.$date->format('Y'),
            default => $date->format('j').' '.self::MOIS[(int) $date->format('n')].' '.$date->format('Y'),
        };
    }

    /** Comme {@see rendreBorne()}, mais préfixé de l'article qu'appelle un jour précis. */
    private function avecArticle(\DateTimeImmutable $date, string $articleJour = 'le ', string $articleAutre = ''): string
    {
        $rendu = (string) $this->rendreBorne($date);

        return ('jour' === $this->granularite($date) ? $articleJour : $articleAutre).$rendu;
    }

    /** @return 'annee'|'mois'|'jour' */
    private function granularite(\DateTimeImmutable $date): string
    {
        $jour = (int) $date->format('j');
        $mois = (int) $date->format('n');
        $dernierJourDuMois = (int) $date->format('t');

        if ((1 === $jour && 1 === $mois) || (31 === $jour && 12 === $mois)) {
            return 'annee';
        }

        if (1 === $jour || $jour === $dernierJourDuMois) {
            return 'mois';
        }

        return 'jour';
    }
}
