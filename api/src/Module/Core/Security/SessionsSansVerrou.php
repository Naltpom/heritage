<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Les sessions en fichiers, **sans verrou** — mêmes fichiers, même format que le
 * gestionnaire natif de PHP (`files`), qu'il remplace sans déconnecter personne.
 *
 * ## Pourquoi il existe
 *
 * Le gestionnaire natif pose un `flock` exclusif sur `sess_<id>` à l'ouverture de la
 * session et ne le relâche qu'à la fin de la requête. Toutes les requêtes d'un même
 * compte passent donc **une par une**. Mesuré en production le 07/10/2026 (HAR d'un F5
 * sur l'arbre, 3 904 personnes) : six requêtes parties ensemble finissaient en escalier
 * — 2 125, 2 184, 2 252, 3 255, 5 358, 5 464 ms —, et `GET /api/arbre/graphe` attendait
 * **1,9 s** que la vignette d'un portrait rende le verrou avant de commencer ses 2,1 s de
 * travail. Le temps d'attente valait presque le temps de calcul.
 *
 * ## Ce qu'on perd, et pourquoi c'est acceptable ici
 *
 * Sans verrou, deux requêtes qui **modifient** la même session en même temps : la
 * dernière écrite gagne. C'est le contrat des gestionnaires Redis et Memcached de Symfony,
 * qui ne verrouillent pas non plus. Il reste étroit ici :
 *
 * - `session.lazy_write` (actif par défaut) : une session lue et **non modifiée** n'est
 *   pas réécrite — PHP appelle {@see updateTimestamp()}, qui ne fait que la dater. Or
 *   presque toutes les requêtes sont des lectures.
 * - Chaque écriture est **atomique** (fichier temporaire, puis `rename`) : un lecteur voit
 *   l'ancienne session ou la nouvelle, jamais une moitié.
 * - **Une session détruite ne ressuscite pas.** Le seul écrasement qui compterait vraiment
 *   est celui d'une déconnexion par une requête partie avant elle, qui réécrirait le
 *   jeton. Une session lue non vide, puis disparue avant l'écriture, a été détruite
 *   entre-temps : on ne la recrée pas.
 */
final class SessionsSansVerrou implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private const string PREFIXE = 'sess_';

    /**
     * Les sessions lues non vides pendant cette requête, par identifiant.
     *
     * @var array<string, true>
     */
    private array $luesNonVides = [];

    public function __construct(
        // Le répertoire du gestionnaire natif, monté sur le volume `sessions` : les sessions
        // ouvertes avant le déploiement restent valides.
        #[Autowire('%kernel.project_dir%/var/sessions/%kernel.environment%')]
        private readonly string $repertoire,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        if (!is_dir($this->repertoire) && !@mkdir($this->repertoire, 0o700, true) && !is_dir($this->repertoire)) {
            return false;
        }

        return true;
    }

    public function close(): bool
    {
        $this->luesNonVides = [];

        return true;
    }

    public function read(string $id): string
    {
        $chemin = $this->chemin($id);

        if (null === $chemin || !is_file($chemin)) {
            return '';
        }

        $donnees = @file_get_contents($chemin);

        if (false === $donnees) {
            return '';
        }

        if ('' !== $donnees) {
            $this->luesNonVides[$id] = true;
        }

        return $donnees;
    }

    public function write(string $id, string $data): bool
    {
        $chemin = $this->chemin($id);

        if (null === $chemin) {
            return false;
        }

        // Détruite pendant cette requête par une autre (une déconnexion) : on ne la
        // recrée pas. Voir l'en-tête.
        if (isset($this->luesNonVides[$id]) && !is_file($chemin)) {
            return true;
        }

        $temporaire = @tempnam($this->repertoire, '.'.self::PREFIXE);

        if (false === $temporaire) {
            return false;
        }

        if (false === @file_put_contents($temporaire, $data) || !@chmod($temporaire, 0o600) || !@rename($temporaire, $chemin)) {
            @unlink($temporaire);

            return false;
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        $chemin = $this->chemin($id);

        if (null !== $chemin && is_file($chemin)) {
            @unlink($chemin);
        }

        unset($this->luesNonVides[$id]);

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        $limite = time() - $max_lifetime;
        $supprimees = 0;

        foreach (glob($this->repertoire.'/'.self::PREFIXE.'*') ?: [] as $chemin) {
            $date = @filemtime($chemin);

            if (false !== $date && $date < $limite && @unlink($chemin)) {
                ++$supprimees;
            }
        }

        return $supprimees;
    }

    public function validateId(string $id): bool
    {
        $chemin = $this->chemin($id);

        return null !== $chemin && is_file($chemin);
    }

    /** Une session non modifiée : on la date, sans la réécrire — et sans la recréer. */
    public function updateTimestamp(string $id, string $data): bool
    {
        $chemin = $this->chemin($id);

        if (null === $chemin) {
            return false;
        }

        return !is_file($chemin) || @touch($chemin);
    }

    /** `null` pour un identifiant qui ne serait pas un nom de fichier sûr. */
    private function chemin(string $id): ?string
    {
        if (1 !== preg_match('/^[A-Za-z0-9,-]{1,256}$/', $id)) {
            return null;
        }

        return $this->repertoire.'/'.self::PREFIXE.$id;
    }
}
