<?php

declare(strict_types=1);

namespace App\Module\Media\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le stockage des fichiers, **hors racine web**.
 *
 * Aucun octet de média ne transite par `api/public/`. La racine par défaut est
 * `%kernel.project_dir%/uploads`, montée en volume nommé par `compose.yaml` — donc hors
 * de l'arborescence du dépôt, « où un `git add .` distrait les emporterait ». Les
 * fichiers sortent **exclusivement** par `/api/medias/{id}/fichier` et `/vignette`, et
 * jamais par une redirection : une `302` vers une URL statique servirait le fichier sans
 * repasser par le cercle de visibilité, et le lien ainsi obtenu serait partageable
 * (§ 7.4 du contrat).
 *
 * ## Le nom est aléatoire, et il est vérifié à chaque accès
 *
 * 32 chiffres hexadécimaux tirés de `random_bytes()`, plus l'extension du type réel
 * constaté. Ni le nom d'origine, ni le sujet, ni l'identifiant de ligne n'y paraissent :
 * un nom devinable rend le stockage énumérable même hors racine web, et il suffirait
 * d'une faute de configuration nginx pour que l'énumération devienne servie.
 *
 * Le nom relu depuis la base est **revalidé** avant d'ouvrir le fichier
 * ({@see NOM_VALIDE}). C'est une garde contre la traversée de répertoire par la base :
 * si un jour un chemin quelconque se retrouve dans `storage_name` — import mal écrit,
 * injection ailleurs —, il ne devient pas une lecture arbitraire du système de fichiers.
 * Le contrôle coûte une expression rationnelle par requête.
 *
 * ## Deux sous-arbres, et un éclatement par préfixe
 *
 * `originaux/` et `vignettes/`, chacun éclaté sur les deux premiers caractères du nom.
 * 274 personnes ne rempliront pas un répertoire, mais un import de preuves d'archives
 * suivi de quelques années d'usage y arrive, et un répertoire à dizaines de milliers
 * d'entrées rend `ls` — donc le diagnostic — pénible sur un VPS modeste.
 */
final class StockageMedia
{
    /**
     * Le nom autorisé sur disque : rien d'autre ne sera jamais ouvert.
     *
     * Volontairement plus strict que nécessaire — pas de tiret, pas de point autre que
     * celui de l'extension, pas de majuscule — parce qu'une expression permissive se
     * relâche à chaque relecture et qu'on ne relit pas celle-ci souvent.
     */
    public const string NOM_VALIDE = '/^[0-9a-f]{32}\.(jpg|png|webp|pdf)$/';

    private const string ORIGINAUX = 'originaux';
    private const string VIGNETTES = 'vignettes';

    /** @var array<string, string> */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * Le paramètre est injecté par **attribut** et non par une définition YAML, et ce
     * n'est pas un goût : `config/services.yaml` importe les fichiers de module **avant**
     * de déclarer sa ressource `App\ : ../src/`, qui réenregistre ensuite chaque classe
     * de `src/` et écrase les définitions de même identifiant. Une définition explicite
     * dans `config/services/media.yaml` serait donc silencieusement perdue — elle l'a
     * été, et le conteneur a répondu « Cannot autowire argument $racine ».
     */
    public function __construct(
        #[Autowire(param: 'app.media.racine')]
        private readonly string $racine,
    ) {
    }

    /**
     * Tire un nom de fichier qui n'apprend rien sur son contenu.
     *
     * 16 octets aléatoires : le même espace qu'un UUID v4, sans le tiret ni la version
     * qui n'auraient rien à faire dans un nom de fichier.
     */
    public function nomAleatoire(string $mime): string
    {
        return bin2hex(random_bytes(16)).'.'.$this->extensionPour($mime);
    }

    public function extensionPour(string $mime): string
    {
        return self::EXTENSIONS[$mime] ?? throw new \InvalidArgumentException(\sprintf('Aucune extension connue pour le type « %s ».', $mime));
    }

    /** Déplace ou copie un fichier temporaire vers le stockage définitif. */
    public function deposer(string $cheminSource, string $nom, bool $vignette = false): void
    {
        $destination = $this->chemin($nom, $vignette);
        $this->creerLeRepertoire(\dirname($destination));

        // `rename()` échoue silencieusement d'un système de fichiers à l'autre (le
        // temporaire de PHP et le volume des médias ne sont pas le même montage en
        // conteneur) : on copie, puis on efface la source.
        if (!@copy($cheminSource, $destination)) {
            throw new \RuntimeException(\sprintf('Écriture impossible dans le stockage des médias (%s).', $nom));
        }

        @unlink($cheminSource);
    }

    public function deposerDesOctets(string $octets, string $nom, bool $vignette = false): void
    {
        $destination = $this->chemin($nom, $vignette);
        $this->creerLeRepertoire(\dirname($destination));

        if (false === @file_put_contents($destination, $octets)) {
            throw new \RuntimeException(\sprintf('Écriture impossible dans le stockage des médias (%s).', $nom));
        }
    }

    public function existe(string $nom, bool $vignette = false): bool
    {
        return is_file($this->chemin($nom, $vignette));
    }

    public function taille(string $nom, bool $vignette = false): int
    {
        $taille = @filesize($this->chemin($nom, $vignette));

        return \is_int($taille) ? $taille : 0;
    }

    /**
     * Le chemin absolu d'un fichier stocké.
     *
     * Public parce que la réponse de fichier le donne à `BinaryFileResponse`, qui sait
     * le servir en flux plutôt que de charger 20 Mio en mémoire pour les recracher.
     */
    public function chemin(string $nom, bool $vignette = false): string
    {
        if (1 !== preg_match(self::NOM_VALIDE, $nom)) {
            throw new \InvalidArgumentException('Nom de fichier de média invalide.');
        }

        return \sprintf(
            '%s/%s/%s/%s',
            rtrim($this->racine, '/\\'),
            $vignette ? self::VIGNETTES : self::ORIGINAUX,
            substr($nom, 0, 2),
            $nom,
        );
    }

    /**
     * Supprime un fichier du stockage.
     *
     * **Aucun appel dans le circuit de refus** : « un refus n'est pas une destruction »
     * (§ 7.6 du contrat), un média `refuse` garde son fichier jusqu'à purge explicite.
     * Cette méthode sert la suppression administrative et le nettoyage d'un dépôt qui a
     * échoué en cours de route.
     */
    public function supprimer(string $nom, bool $vignette = false): void
    {
        $chemin = $this->chemin($nom, $vignette);

        if (is_file($chemin)) {
            @unlink($chemin);
        }
    }

    private function creerLeRepertoire(string $repertoire): void
    {
        if (is_dir($repertoire)) {
            return;
        }

        if (!@mkdir($repertoire, 0o775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException(\sprintf('Répertoire de stockage impossible à créer : %s', $repertoire));
        }
    }
}
