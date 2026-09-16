<?php

declare(strict_types=1);

namespace App\Tests\Tree;

use App\Module\Core\Enum\Sex;
use App\Module\Tree\ValueObject\FamillePlacable;
use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\PersonnePlacable;

/**
 * Lit `graphe.json` — la sortie de `docs/moteur-retrouve/graphe.py` — et en extrait
 * d'un côté l'**entrée** du moteur, de l'autre la **sortie attendue**.
 *
 * Le fichier vit dans l'atelier généalogique `C:\www\myHeritage`, **hors dépôt et en
 * lecture seule** : il contient des personnes vivantes et n'est pas versionné (§ 9 du
 * dossier de mission, ADR 0006). D'où le chemin configurable et le saut du test quand
 * il est absent.
 *
 * **Pourquoi `graphe.json` peut servir d'entrée autant que de sortie.** Il porte `gens`
 * et `familles`, c'est-à-dire tout ce que le moteur consomme, et il les porte **dans
 * l'ordre du GEDCOM** : `gens_data` et `fams_data` sont construits par
 * `for k in indi` / `for fk, fr in fam.items()`, et `json.dump` conserve l'ordre
 * d'insertion des dictionnaires Python. Cet ordre est une entrée à part entière du
 * moteur (voir `JeuDePlacement`), pas une commodité de lecture.
 *
 * En revanche `gens[k]["enfants"]` et `gens[k]["conjoints"]` sont écrits `sorted(set(…))`
 * à la fin du script, **après** le placement : ce ne sont pas les tables que le moteur a
 * utilisées. On les ignore et on **reconstruit les adjacences depuis `familles`**,
 * exactement comme le fait `graphe.py`. Lire les listes triées à leur place déplace une
 * poignée de nœuds, parce que le repli « je me raccroche à mon premier conjoint » de la
 * phase 2 dépend de l'ordre brut.
 */
final class ChargeurDeGrapheDeReference
{
    public const string VARIABLE_ENV = 'HERITAGE_GRAPHE_REFERENCE';

    /** @param array{gens: array<string, array<string, mixed>>, familles: array<string, array<string, mixed>>, pos: array<string, array<string, mixed>>, aretes: list<array<string, mixed>>, boite: array<string, mixed>, ego: string} $donnees */
    private function __construct(private readonly array $donnees)
    {
    }

    /**
     * Le chemin du fichier de référence : variable d'environnement d'abord, puis
     * l'emplacement conventionnel de l'atelier, voisin de la racine du projet.
     *
     * Jamais de chemin en dur : sur une autre machine, l'atelier est ailleurs ou
     * n'existe pas (§ 0 du dossier de mission).
     */
    public static function chemin(): string
    {
        $configure = $_SERVER[self::VARIABLE_ENV] ?? $_ENV[self::VARIABLE_ENV] ?? null;

        if (\is_string($configure) && '' !== $configure) {
            return $configure;
        }

        // …/heritage/api/tests/Tree → …/heritage/api → …/heritage → … (voisin de l'atelier)
        return \dirname(__DIR__, 4).\DIRECTORY_SEPARATOR.'myHeritage'.\DIRECTORY_SEPARATOR.'graphe.json';
    }

    public static function disponible(): bool
    {
        return is_file(self::chemin());
    }

    public static function charge(): self
    {
        $brut = file_get_contents(self::chemin());

        if (false === $brut) {
            throw new \RuntimeException(\sprintf('Fichier de référence illisible : %s', self::chemin()));
        }

        /** @var array{gens: array<string, array<string, mixed>>, familles: array<string, array<string, mixed>>, pos: array<string, array<string, mixed>>, aretes: list<array<string, mixed>>, boite: array<string, mixed>, ego: string} $donnees */
        $donnees = json_decode($brut, true, 512, \JSON_THROW_ON_ERROR);

        return new self($donnees);
    }

    /** L'entrée du moteur, dans l'ordre du fichier. */
    public function jeu(): JeuDePlacement
    {
        $personnes = [];

        foreach ($this->donnees['gens'] as $id => $g) {
            $personnes[] = new PersonnePlacable(
                id: (string) $id,
                prenom: self::chaine($g, 'prenom'),
                nom: self::chaine($g, 'nom'),
                usage: self::chaine($g, 'usage'),
                sexe: match (self::chaine($g, 'sexe')) {
                    'M' => Sex::Masculin,
                    'F' => Sex::Feminin,
                    default => Sex::Inconnu,
                },
                nais: self::chaine($g, 'nais'),
                deces: self::chaine($g, 'deces'),
                anNais: \is_int($g['an_nais'] ?? null) ? $g['an_nais'] : null,
                // Sur le fichier de référence, `prive` est la marque `RESN` du GEDCOM.
                // Dans l'application ce sera l'état vivant calculé (§ 9.2) — la
                // sémantique change, la conséquence sur la largeur est la même.
                vivant: true === ($g['prive'] ?? false),
                generation: \is_int($g['gen'] ?? null) ? $g['gen'] : 0,
            );
        }

        $familles = [];

        foreach ($this->donnees['familles'] as $id => $f) {
            $familles[] = new FamillePlacable(
                id: (string) $id,
                epoux: self::identifiants($f, 'epoux'),
                enfants: self::identifiants($f, 'enfants'),
                aDivorce: true === ($f['a_divorce'] ?? false),
            );
        }

        return new JeuDePlacement($personnes, $familles, $this->donnees['ego']);
    }

    /**
     * Les positions attendues, dans l'ordre du fichier.
     *
     * @return array<string, array{x: float, y: float, w: float, h: float}>
     */
    public function positionsAttendues(): array
    {
        $attendu = [];

        foreach ($this->donnees['pos'] as $id => $p) {
            $attendu[(string) $id] = [
                'x' => self::flottant($p, 'x'),
                'y' => self::flottant($p, 'y'),
                'w' => self::flottant($p, 'w'),
                'h' => self::flottant($p, 'h'),
            ];
        }

        return $attendu;
    }

    /** @return list<array{de: string, vers: string, genre: string, divorce: bool|null}> */
    public function aretesAttendues(): array
    {
        $attendu = [];

        foreach ($this->donnees['aretes'] as $a) {
            $attendu[] = [
                'de' => self::chaine($a, 'de'),
                'vers' => self::chaine($a, 'vers'),
                'genre' => self::chaine($a, 'genre'),
                // Absent sur les filiations : `graphe.py` n'écrit la clé que pour les unions.
                'divorce' => \array_key_exists('divorce', $a) ? true === $a['divorce'] : null,
            ];
        }

        return $attendu;
    }

    /** @return array{x: float, y: float, w: float, h: float} */
    public function boiteAttendue(): array
    {
        $b = $this->donnees['boite'];

        return [
            'x' => self::flottant($b, 'x'),
            'y' => self::flottant($b, 'y'),
            'w' => self::flottant($b, 'w'),
            'h' => self::flottant($b, 'h'),
        ];
    }

    public function ego(): string
    {
        return $this->donnees['ego'];
    }

    /**
     * Les lignes `gens` telles quelles, **dans l'ordre du fichier**.
     *
     * Le moteur n'a pas besoin de tout ce qu'elles portent — `mort`, notamment, qui ne
     * l'intéresse pas — mais {@see BaseDArbreEnMemoire} en a besoin pour peupler des
     * entités réalistes : `deceased` est une entrée de la règle « personne vivante »
     * (§ 9.2), que le fournisseur doit calculer et non recopier.
     *
     * @return array<string, array<string, mixed>>
     */
    public function personnesBrutes(): array
    {
        return $this->donnees['gens'];
    }

    /** @return array<string, array<string, mixed>> */
    public function famillesBrutes(): array
    {
        return $this->donnees['familles'];
    }

    /** @param array<string, mixed> $source */
    private static function chaine(array $source, string $cle): string
    {
        $v = $source[$cle] ?? '';

        return \is_string($v) ? $v : '';
    }

    /** @param array<string, mixed> $source */
    private static function flottant(array $source, string $cle): float
    {
        $v = $source[$cle] ?? 0;

        return \is_int($v) || \is_float($v) ? (float) $v : 0.0;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<string>
     */
    private static function identifiants(array $source, string $cle): array
    {
        $v = $source[$cle] ?? [];

        if (!\is_array($v)) {
            return [];
        }

        $sortie = [];

        foreach ($v as $id) {
            if (\is_string($id)) {
                $sortie[] = $id;
            }
        }

        return $sortie;
    }
}
