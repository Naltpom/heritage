<?php

declare(strict_types=1);

namespace App\Module\Almanach\Contract;

/**
 * Une ligne de `tree_place` traduite dans le vocabulaire d'`Almanach` — une chaîne `PLAC`
 * repliée d'un côté, une position de l'autre.
 *
 * ## La clé de rapprochement est `cle`, et elle n'a pas de formule à elle
 *
 * `cle` est le repliement de la chaîne `PLAC` par
 * {@see \App\Module\Core\Service\CleDeRecherche::replier()} — **la** définition du
 * repliement d'accents du produit, celle qui écrit déjà `tree_person.search_key`. Le
 * normaliseur replie le `PLAC` de chaque événement avec le **même appel** et cherche dans
 * une table indexée par cette clé.
 *
 * En écrire une seconde — même juste, même testée — est le défaut exact que cette classe
 * de `Core` existe pour supprimer : « le jour où elles auraient divergé d'un seul
 * caractère », un lieu cesserait d'être retrouvé sans qu'aucune erreur ne se produise, et
 * une bulle disparaîtrait de la carte sans que rien ne rougisse.
 *
 * ## Le rapprochement se fait en PHP, et c'est mesuré
 *
 * Environ 104 lieux distincts pour l'arbre entier. Une clé étrangère aurait coûté une
 * colonne sur cinq tables — dont deux versionnées, dont la réécriture périme les demandes
 * en attente — pour éviter un `array_flip` sur une centaine d'entrées. Le raisonnement
 * complet est dans l'en-tête de `src/Module/Tree/Entity/Place.php`.
 *
 * ## Une bulle exige **les deux** coordonnées
 *
 * `lat` et `lon` sont non nullables **ici**, alors qu'elles le sont en base. Ce n'est pas
 * une incohérence : une ligne de `tree_place` sans coordonnées est un lieu qu'on n'a pas
 * su résoudre, et elle n'a rien à faire dans cette liste. L'adaptateur ne la fait pas
 * traverser ; le normaliseur la compte dans `resume.lieuNonResolu`. Le type porte donc la
 * règle au lieu de la répéter — un `?float` obligerait chaque lecteur à retester, et le
 * premier qui oublierait poserait une bulle à l'intersection de l'équateur et du méridien
 * de Greenwich.
 *
 * ## `brut` est une **liste**
 *
 * Une même commune arrive souvent sous plusieurs graphies (`Saule`, `Saule, Cher`,
 * `Saule, Cher, France`). Elles se replient sur des clés différentes, donc sur des lignes
 * différentes de `tree_place` — mais le rendu peut légitimement en réunir plusieurs sous
 * une seule bulle si elles pointent la même position. La liste garde alors **toutes** les
 * provenances : c'est ce qu'on affiche quand un lecteur demande d'où vient une bulle, et
 * c'est ce qui permet de corriger la bonne ligne.
 */
final readonly class LieuResolu
{
    /**
     * @param int          $placeId   `tree_place.id`
     * @param string       $cle       `tree_place.raw_key` — le repliement de la chaîne `PLAC`
     * @param string       $libelle   le nom retenu (« Saint-Ronce-de-Verçay (18) »)
     * @param float        $lat       degrés décimaux, WGS 84
     * @param float        $lon       idem
     * @param string       $precision `commune`, `subdivision` ou `pays`
     * @param int          $confiance rang de 0 à 100 posé par la passe d'appariement
     * @param string       $source    `communes-fr`, `villes-monde`, `nominatim` ou `manuel`
     * @param list<string> $brut      les chaînes `PLAC` verbatim que cette ligne couvre
     */
    public function __construct(
        public int $placeId,
        public string $cle,
        public string $libelle,
        public float $lat,
        public float $lon,
        public string $precision,
        public int $confiance,
        public string $source,
        public array $brut = [],
    ) {
    }

    /** @return array<string, mixed> l'objet de la clé `lieux` du § « GET /api/carte » */
    public function enJson(): array
    {
        return [
            'placeId' => $this->placeId,
            'libelle' => $this->libelle,
            'lat' => $this->lat,
            'lon' => $this->lon,
            'precision' => $this->precision,
            'confiance' => $this->confiance,
            'source' => $this->source,
            'brut' => $this->brut,
        ];
    }
}
