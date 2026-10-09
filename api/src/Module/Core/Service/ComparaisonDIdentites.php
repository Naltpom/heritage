<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\IdentiteComparee;
use App\Module\Core\ValueObject\Ressemblance;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * « Cette personne est peut-être déjà là » — **la** règle de ressemblance entre ce qu'un
 * membre saisit dans « Ajouter une personne » et une fiche existante.
 *
 * Elle sert deux routes qui ne se connaissent pas : `POST /api/arbre/ressemblances`
 * (`Tree`, la fiche est dans l'arbre) et `POST /api/demandes/brouillons/ressemblances`
 * (`Proposal`, la fiche attend une validation). Une règle écrite deux fois, c'est deux
 * écrans qui finissent par se contredire sur la même saisie — « déjà dans l'arbre » d'un
 * côté, rien de l'autre. Elle est donc ici, et les deux modules l'appellent.
 *
 * ## Les filtres — ce qui écarte une fiche
 *
 * 1. **Le nom.** Le nom ou le nom d'usage saisi doit être *proche* ({@see ProximiteDeNoms})
 *    du nom ou du nom d'usage de la fiche — les quatre croisements comptent : une femme
 *    mariée est souvent saisie sous le nom que sa famille emploie.
 * 2. **Un prénom en commun**, surnoms compris. Un seul suffit : l'état civil empile les
 *    prénoms dont l'usage n'en retient qu'un.
 * 3. **Des sexes connus et opposés** écartent ; un sexe inconnu, d'un côté ou de l'autre,
 *    ne dit rien.
 * 4. **Des dates connues des deux côtés et incompatibles** écartent — naissance et décès,
 *    chacun pour soi. Une date absente, inconnue ou non comparable (voir
 *    {@see IdentiteComparee}) n'écarte **rien** et ne rapporte **rien**.
 *
 * ## « Vers » vaut deux ans de part et d'autre — pour comparer, jamais pour stocker
 *
 * `ABT 1934` est stocké `1934-01-01 … 1934-12-31`, exactement comme `1934`
 * (`DateQualifier::Environ`). Comparé tel quel, « vers 1934 » écarterait une fiche née en
 * 1935, ce qui est précisément ce que « vers » refuse de dire. La comparaison élargit donc
 * un « vers » de {@see self::ANNEES_DE_TOLERANCE_D_UN_ENVIRON} ans de chaque côté.
 *
 * Ce n'est pas l'aplatissement que le § 9.1 du modèle interdit : rien n'est écrit, aucune
 * date n'est réinterprétée, et l'élargissement ne va que dans le sens prudent — il retient
 * une fiche de plus, il n'en invente aucune. Les autres qualificatifs (fourchette, avant,
 * après) portent déjà leurs bornes et sont comparés tels quels par
 * {@see UncertainDate::overlaps()}.
 *
 * ## Le lieu est un bonus, jamais un filtre
 *
 * « Sorbière » et « Sorbière, Fougerie, Florie » désignent le même endroit ; « Sorbiere »
 * aussi ; une commune fusionnée ou mal orthographiée dans un acte ne le dit pas. Un lieu
 * différent ne prouve donc rien, un lieu identique ajoute un critère. On compare le
 * **premier segment** (avant la première virgule), replié par {@see CleDeRecherche}.
 *
 * ## Le score
 *
 * Le nombre de critères concordants, sans pondération ({@see Ressemblance}).
 *
 * ## La forme discrète
 *
 * `$discrete = true` est la forme d'une proposition de vivant faite par un autre membre
 * (§ 6.6 quater du contrat) : on ne compare que le nom, les prénoms et le sexe — les dates
 * et lieux des deux côtés sont retirés **ici**, quoi que l'appelant ait passé — et les
 * raisons sont génériques (« nom semblable », « un prénom en commun »). **Aucune valeur de
 * la fiche n'est recopiée dans une raison**, et `nomExact` vaut `false`, pour que même
 * l'ordre des réponses ne dise pas si le nom est exactement celui qu'on a tapé.
 */
final readonly class ComparaisonDIdentites
{
    /**
     * L'élargissement d'un « vers », de chaque côté — la marge retenue par le plan de la
     * fonction (09/10/2026). Un « vers » vient le plus souvent d'un âge déclaré dans un
     * acte (« âgé d'environ soixante ans »), qui manque la naissance d'un an ou deux ; deux
     * ans restent assez étroits pour qu'un « vers 1934 » n'attire pas toute une génération.
     */
    public const int ANNEES_DE_TOLERANCE_D_UN_ENVIRON = 2;

    public function __construct(private ProximiteDeNoms $noms = new ProximiteDeNoms())
    {
    }

    public function comparer(IdentiteComparee $saisie, IdentiteComparee $fiche, bool $discrete = false): ?Ressemblance
    {
        if ($discrete) {
            // Retirés **ici** et pas seulement chez l'appelant : c'est la garantie que la
            // forme discrète ne dépend d'aucune date, même si un appelant oublie de les ôter.
            $saisie = $saisie->sansDatesNiLieux();
            $fiche = $fiche->sansDatesNiLieux();
        }

        $nom = $this->nom($saisie, $fiche);

        if (null === $nom) {
            return null;
        }

        $prenom = $this->prenom($saisie, $fiche);

        if (null === $prenom) {
            return null;
        }

        $criteres = [Ressemblance::NOM, Ressemblance::PRENOM];
        $raisons = $discrete
            ? ['nom semblable', 'un prénom en commun']
            : [$nom['raison'], $prenom];

        if (Sex::Inconnu !== $saisie->sexe && Sex::Inconnu !== $fiche->sexe) {
            if ($saisie->sexe !== $fiche->sexe) {
                return null;
            }

            $criteres[] = Ressemblance::SEXE;
            $raisons[] = 'même sexe';
        }

        $sexe = Sex::Inconnu !== $fiche->sexe ? $fiche->sexe : $saisie->sexe;

        foreach ([
            Ressemblance::NAISSANCE => [$saisie->naissance, $fiche->naissance],
            Ressemblance::DECES => [$saisie->deces, $fiche->deces],
        ] as $critere => [$dateSaisie, $dateFiche]) {
            $concordance = $this->concordance($dateSaisie, $dateFiche);

            if (false === $concordance) {
                return null;
            }

            if (true === $concordance) {
                \assert(null !== $dateSaisie && null !== $dateFiche);
                $criteres[] = $critere;
                $raisons[] = $this->raisonDeDate($critere, $dateSaisie, $dateFiche, $sexe);
            }
        }

        foreach ([
            'naissance' => [$saisie->naissance, $fiche->naissance],
            'décès' => [$saisie->deces, $fiche->deces],
        ] as $evenement => [$dateSaisie, $dateFiche]) {
            $lieu = $this->lieuCommun($dateSaisie?->getPlace(), $dateFiche?->getPlace());

            if (null !== $lieu) {
                $criteres[] = Ressemblance::LIEU;
                $raisons[] = \sprintf('même lieu de %s : %s', $evenement, $lieu);
            }
        }

        return new Ressemblance($criteres, $raisons, !$discrete && $nom['exact']);
    }

    /**
     * Le meilleur des quatre croisements de noms, ou `null` si aucun n'est proche.
     *
     * Un croisement **identique** est préféré à un croisement seulement proche, même s'il
     * vient plus loin dans l'ordre : « Tilleul » saisi devant une fiche « Tilleule », née
     * Tilleul, doit dire « même nom », pas « nom proche ».
     *
     * @return array{exact: bool, raison: string}|null
     */
    private function nom(IdentiteComparee $saisie, IdentiteComparee $fiche): ?array
    {
        $croisements = [
            [$saisie->nom, $fiche->nom, 'même nom', 'nom proche : %s / %s'],
            [$saisie->nomDUsage, $fiche->nomDUsage, 'même nom d\'usage', 'nom d\'usage proche : %s / %s'],
            [$saisie->nom, $fiche->nomDUsage, 'même nom, porté comme nom d\'usage dans la fiche', 'nom proche du nom d\'usage de la fiche : %s / %s'],
            [$saisie->nomDUsage, $fiche->nom, 'même nom, nom de naissance dans la fiche', 'nom d\'usage proche du nom de la fiche : %s / %s'],
        ];

        $proche = null;

        foreach ($croisements as [$deLaSaisie, $deLaFiche, $siIdentique, $siProche]) {
            $cleSaisie = $this->noms->normaliser($deLaSaisie ?? '');
            $cleFiche = $this->noms->normaliser($deLaFiche ?? '');

            if ('' === $cleSaisie || '' === $cleFiche) {
                continue;
            }

            if ($cleSaisie === $cleFiche) {
                return ['exact' => true, 'raison' => $siIdentique];
            }

            if (null === $proche && $this->noms->proche($cleSaisie, $cleFiche)) {
                $proche = [
                    'exact' => false,
                    'raison' => \sprintf($siProche, self::lisible((string) $deLaSaisie), self::lisible((string) $deLaFiche)),
                ];
            }
        }

        return $proche;
    }

    /** La raison du prénom en commun, ou `null` s'il n'y en a aucun. */
    private function prenom(IdentiteComparee $saisie, IdentiteComparee $fiche): ?string
    {
        $deLaSaisie = $this->prenomsEtSurnom($saisie);
        $deLaFiche = $this->prenomsEtSurnom($fiche);

        $couple = $this->noms->prenomEnCommun(
            array_column($deLaSaisie, 0),
            array_column($deLaFiche, 0),
        );

        if (null === $couple) {
            return null;
        }

        [, $graphieSaisie] = $deLaSaisie[$couple[0]];
        [, $graphieFiche, $nature] = $deLaFiche[$couple[1]];

        return $couple[2]
            ? \sprintf('%s %s en commun', $nature, $graphieFiche)
            : \sprintf('%s proche : %s / %s', $nature, $graphieSaisie, $graphieFiche);
    }

    /**
     * Les prénoms puis le surnom, chacun avec sa graphie et sa nature.
     *
     * Le surnom compte comme un prénom parce que c'est lui qu'affiche une fiche qui en porte
     * un (`display_name` = `nickname ?? given_names`) : un membre qui saisit « Cobalt » pour
     * la fiche affichée « Cobalt Sureau » doit la retrouver.
     *
     * @return list<array{0: string, 1: string, 2: string}> `[clé repliée, graphie, nature]`
     */
    private function prenomsEtSurnom(IdentiteComparee $identite): array
    {
        $jetons = [];

        foreach ($this->noms->jetonsAvecGraphie($identite->prenoms) as [$cle, $graphie]) {
            $jetons[] = [$cle, $graphie, 'prénom'];
        }

        foreach ($this->noms->jetonsAvecGraphie($identite->surnom ?? '') as [$cle, $graphie]) {
            $jetons[] = [$cle, $graphie, 'surnom'];
        }

        return $jetons;
    }

    /**
     * `true` compatibles, `false` incompatibles, `null` non comparables.
     *
     * Non comparable : une date absente (`null`), inconnue, ou sans aucune borne. Ce dernier
     * cas n'est pas théorique — `UncertainDate::overlaps()` répond `false` pour une date
     * sans bornes (« elle recouvrirait sinon tout ») ; la prendre pour une incompatibilité
     * écarterait une fiche sur une absence d'information.
     */
    private function concordance(?UncertainDate $saisie, ?UncertainDate $fiche): ?bool
    {
        if (!self::comparable($saisie) || !self::comparable($fiche)) {
            return null;
        }

        \assert(null !== $saisie && null !== $fiche);

        return self::elargie($saisie)->overlaps(self::elargie($fiche));
    }

    private static function comparable(?UncertainDate $date): bool
    {
        return null !== $date
            && $date->isKnown()
            && (null !== $date->getMin() || null !== $date->getMax());
    }

    /** Un « vers » élargi de deux ans de chaque côté ; toute autre date, telle quelle. */
    private static function elargie(UncertainDate $date): UncertainDate
    {
        if (DateQualifier::Environ !== $date->getQualifier()) {
            return $date;
        }

        $ecart = self::ANNEES_DE_TOLERANCE_D_UN_ENVIRON;

        return new UncertainDate(
            null,
            DateQualifier::Environ,
            $date->getMin()?->modify(\sprintf('-%d years', $ecart)),
            $date->getMax()?->modify(\sprintf('+%d years', $ecart)),
        );
    }

    /**
     * « né vers 1934, comme dans la fiche » quand les deux dates s'écrivent pareil ;
     * « naissance compatible : vers 1933 dans la fiche » sinon.
     *
     * La date de la fiche citée ici est celle que l'appelant a passée — pour un vivant,
     * la date **réduite** aux composantes que le lecteur voit déjà. Rien de plus fin ne
     * peut donc sortir par cette phrase.
     */
    private function raisonDeDate(string $critere, UncertainDate $saisie, UncertainDate $fiche, Sex $sexe): string
    {
        $texteFiche = $fiche->format();
        $naissance = Ressemblance::NAISSANCE === $critere;

        if ('' !== $texteFiche && $saisie->format() === $texteFiche) {
            $participe = match ($sexe) {
                Sex::Feminin => $naissance ? 'née' : 'décédée',
                Sex::Masculin => $naissance ? 'né' : 'décédé',
                Sex::Inconnu => $naissance ? 'né(e)' : 'décédé(e)',
            };

            return \sprintf('%s %s, comme dans la fiche', $participe, self::enProse($fiche));
        }

        $evenement = $naissance ? 'naissance' : 'décès';

        return '' === $texteFiche
            ? \sprintf('%s compatible avec la fiche', $evenement)
            : \sprintf('%s compatible : %s dans la fiche', $evenement, $texteFiche);
    }

    /** « le 11 mars 1930 », « en 1934 », « en mars 1930 », « vers 1934 », « entre … ». */
    private static function enProse(UncertainDate $date): string
    {
        $texte = $date->format();

        return match ($date->getQualifier()) {
            DateQualifier::Exacte => 'le '.(string) preg_replace('/^1 /', '1er ', $texte),
            DateQualifier::Annee, DateQualifier::Mois => 'en '.$texte,
            default => $texte,
        };
    }

    /**
     * Le premier segment du lieu de la fiche, s'il est celui de la saisie ; `null` sinon.
     *
     * Égalité stricte des clés repliées, sans tolérance d'une lettre : deux communes
     * voisines se distinguent souvent d'une seule (« Saule » et « Sault »), et un bonus
     * accordé à tort classerait devant une fiche qui ne le mérite pas.
     */
    private function lieuCommun(?string $saisie, ?string $fiche): ?string
    {
        $segmentSaisie = self::premierSegment($saisie);
        $segmentFiche = self::premierSegment($fiche);
        $cle = $this->noms->normaliser($segmentSaisie);

        if ('' === $cle || $cle !== $this->noms->normaliser($segmentFiche)) {
            return null;
        }

        return $segmentFiche;
    }

    private static function premierSegment(?string $lieu): string
    {
        return self::lisible(explode(',', $lieu ?? '', 2)[0]);
    }

    /** Une valeur saisie, rognée et à espaces simples, pour être citée dans une phrase. */
    private static function lisible(string $valeur): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $valeur));
    }
}
