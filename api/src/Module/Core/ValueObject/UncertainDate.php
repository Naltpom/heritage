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
