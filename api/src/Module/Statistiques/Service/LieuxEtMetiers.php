<?php

declare(strict_types=1);

namespace App\Module\Statistiques\Service;

use App\Module\Statistiques\Contract\LieuDuReleve;
use App\Module\Statistiques\Contract\NatureDuFait;

/**
 * Section 4 — **les lieux et les métiers**.
 *
 * Les lieux sont ceux de la carte : la chaîne `PLAC` d'un événement, repliée, retrouvée
 * dans `tree_place` (`heritage:resoudre-les-lieux` les a appariés au référentiel des
 * communes, puis à Nominatim). Un lieu que l'appariement n'a pas su reconnaître ne compte
 * nulle part ici : il est déjà compté, et énumérable, sur la carte.
 *
 * Tous les palmarès sont des **comptes** sur toute la population, sous le seuil de
 * discrétion (une commune qui ne compterait que la naissance d'une fiche confidentielle
 * ne sort pas) — voir {@see Echantillon}.
 *
 * - **Communes et départements de naissance** : le libellé retenu par l'appariement
 *   (« Saint-Ronce-de-Verçay (18) ») ; le département est la subdivision d'un lieu français.
 * - **Distance naissance → décès** : à vol d'oiseau (formule de haversine), entre deux
 *   lieux qui ont tous deux des coordonnées, par siècle de naissance. Moyenne et médiane :
 *   un seul départ pour l'Algérie suffit à tirer une moyenne, la médiane dit le cas courant.
 * - **Métiers par époque** : les faits « Profession », rangés par le siècle du fait s'il
 *   compte, sinon par le siècle de naissance de la personne (la plupart des professions
 *   relevées dans les actes ne sont pas datées). Une personne compte une fois par métier.
 * - **Migrations** : les personnes dont un événement — naissance, décès, mariage,
 *   résidence — a eu lieu **hors de France**, par pays, avec les régions les plus citées.
 */
final class LieuxEtMetiers
{
    private const string FRANCE = 'FR';

    /** @return array<string, mixed> */
    public function calculer(Population $population): array
    {
        return [
            ...$this->naissances($population),
            'distance' => $this->distance($population),
            'metiers' => $this->metiers($population),
            'migrations' => $this->migrations($population),
        ];
    }

    /** @return array{communes: array<string, mixed>, departements: array<string, mixed>} */
    private function naissances(Population $population): array
    {
        $communes = new Decompte();
        $departements = new Decompte();

        foreach ($population->personnes as $personne) {
            $lieu = $population->lieuDe($personne->naissance);

            if (null === $lieu) {
                continue;
            }

            $masque = $population->masquee($personne->id);

            if (null !== $lieu->commune) {
                $communes->ajouter($lieu->cle, $lieu->libelle, $masque);
            }

            if (null !== $lieu->subdivision && (null === $lieu->pays || self::FRANCE === $lieu->pays)) {
                $departements->ajouter(mb_strtolower($lieu->subdivision), $lieu->subdivision, $masque);
            }
        }

        return [
            'communes' => ['palmares' => $communes->palmares(10), 'n' => $communes->total(), 'distinctes' => $communes->distincts()],
            'departements' => ['palmares' => $departements->palmares(10), 'n' => $departements->total(), 'distincts' => $departements->distincts()],
        ];
    }

    /** @return list<array{siecle: int, moyenne: float|null, mediane: float|null, n: int}> */
    private function distance(Population $population): array
    {
        /** @var array<int, Echantillon> $parSiecle */
        $parSiecle = [];

        foreach ($population->personnes as $personne) {
            $annee = DateRetenue::annee($personne->naissance);
            $depart = $population->lieuDe($personne->naissance);
            $arrivee = $population->lieuDe($personne->deces);

            if (null === $annee || null === $depart || null === $arrivee) {
                continue;
            }

            $km = self::kilometres($depart, $arrivee);

            if (null === $km) {
                continue;
            }

            ($parSiecle[DateRetenue::siecle($annee)] ??= new Echantillon())->ajouter($km, $population->masquee($personne->id));
        }

        ksort($parSiecle);
        $lignes = [];

        foreach ($parSiecle as $siecle => $echantillon) {
            if (0 === $echantillon->n()) {
                continue;
            }

            $lignes[] = ['siecle' => $siecle, 'moyenne' => $echantillon->moyenne(), 'mediane' => $echantillon->mediane(), 'n' => $echantillon->n()];
        }

        return $lignes;
    }

    /** La distance à vol d'oiseau, en kilomètres, ou `null` si un bout n'est pas situé. */
    public static function kilometres(LieuDuReleve $a, LieuDuReleve $b): ?float
    {
        if (null === $a->lat || null === $a->lon || null === $b->lat || null === $b->lon) {
            return null;
        }

        $rayon = 6371.0;
        $dLat = deg2rad($b->lat - $a->lat);
        $dLon = deg2rad($b->lon - $a->lon);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * sin($dLon / 2) ** 2;

        return 2 * $rayon * asin(min(1.0, sqrt($h)));
    }

    /** @return list<array{siecle: int, metiers: list<array{libelle: string, n: int}>, n: int}> */
    private function metiers(Population $population): array
    {
        /** @var array<int, Decompte> $parSiecle */
        $parSiecle = [];
        $dejaVus = [];

        foreach ($population->releve->faits as $fait) {
            if (NatureDuFait::Profession !== $fait->nature || null === $fait->personId) {
                continue;
            }

            $personne = $population->personnes[$fait->personId] ?? null;
            $libelle = self::metier($fait->libelle);

            if (null === $personne || null === $libelle) {
                continue;
            }

            $annee = DateRetenue::annee($fait->date) ?? DateRetenue::annee($personne->naissance);

            if (null === $annee) {
                continue;
            }

            $siecle = DateRetenue::siecle($annee);
            $cle = mb_strtolower($libelle);
            $vu = $personne->id.'|'.$siecle.'|'.$cle;

            if (isset($dejaVus[$vu])) {
                continue;
            }

            $dejaVus[$vu] = true;
            ($parSiecle[$siecle] ??= new Decompte())->ajouter($cle, $libelle, $population->masquee($personne->id));
        }

        ksort($parSiecle);
        $lignes = [];

        foreach ($parSiecle as $siecle => $decompte) {
            $palmares = $decompte->palmares(6);

            if ([] !== $palmares) {
                $lignes[] = ['siecle' => $siecle, 'metiers' => $palmares, 'n' => $decompte->total()];
            }
        }

        return $lignes;
    }

    /** « tisserand » → « Tisserand » ; un libellé vide ou sans lettre n'est pas un métier. */
    public static function metier(?string $libelle): ?string
    {
        $propre = trim(preg_replace('/\s+/u', ' ', (string) $libelle) ?? '');

        if (mb_strlen($propre) < 2 || 1 !== preg_match('/\p{L}/u', $propre)) {
            return null;
        }

        return mb_strtoupper(mb_substr($propre, 0, 1)).mb_substr($propre, 1);
    }

    /** @return array<string, mixed> */
    private function migrations(Population $population): array
    {
        /** @var array<int, array<string, LieuDuReleve>> $parPersonne personne → pays → un lieu */
        $parPersonne = [];
        $situees = [];
        $neesAilleurs = 0;
        $neesAilleursMasquees = 0;

        $noter = function (int $personId, ?LieuDuReleve $lieu) use (&$parPersonne, &$situees): void {
            if (null === $lieu || null === $lieu->pays) {
                return;
            }

            $situees[$personId] = true;

            if (self::FRANCE !== $lieu->pays) {
                $parPersonne[$personId][$lieu->pays] ??= $lieu;
            }
        };

        foreach ($population->personnes as $personne) {
            $naissance = $population->lieuDe($personne->naissance);
            $noter($personne->id, $naissance);
            $noter($personne->id, $population->lieuDe($personne->deces));

            if (null !== $naissance && null !== $naissance->pays && self::FRANCE !== $naissance->pays) {
                ++$neesAilleurs;
                $neesAilleursMasquees += $population->masquee($personne->id) ? 1 : 0;
            }
        }

        foreach ($population->familles as $famille) {
            foreach ([$famille->mariId, $famille->epouseId] as $epoux) {
                if (null !== $epoux) {
                    $noter($epoux, $population->lieuDe($famille->mariage));
                }
            }
        }

        foreach ($population->releve->faits as $fait) {
            if (NatureDuFait::Residence === $fait->nature && null !== $fait->personId) {
                $noter($fait->personId, $population->lieuDe($fait->date));
            }
        }

        $pays = new Decompte();
        /** @var array<string, Decompte> $regions */
        $regions = [];
        $noms = [];

        foreach ($parPersonne as $personId => $lieux) {
            $masque = $population->masquee($personId);

            foreach ($lieux as $code => $lieu) {
                $noms[$code] = $lieu->nomDuPays ?? $code;
                $pays->ajouter($code, $code, $masque);

                if (null !== $lieu->subdivision) {
                    ($regions[$code] ??= new Decompte())->ajouter(mb_strtolower($lieu->subdivision), $lieu->subdivision, $masque);
                }
            }
        }

        return [
            'pays' => array_map(static fn (array $ligne): array => [
                'code' => $ligne['libelle'],
                'nom' => $noms[$ligne['libelle']] ?? $ligne['libelle'],
                'personnes' => $ligne['n'],
                'regions' => isset($regions[$ligne['libelle']]) ? $regions[$ligne['libelle']]->palmares(3) : [],
            ], $pays->palmares(12)),
            'neesHorsDeFrance' => Echantillon::comptePublie($neesAilleurs, $neesAilleursMasquees),
            'n' => \count($situees),
        ];
    }
}
