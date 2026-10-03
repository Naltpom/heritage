<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le stockage des **autorisations signées** de partage de date de naissance (ADR 0016).
 *
 * Calqué sur {@see StockageDesMessagesVocaux} et pour les mêmes raisons : hors racine web,
 * nom aléatoire qui ne dit rien de la personne, aucun chemin fourni par l'appelant. Le
 * fichier n'est servi que par une route d'administration.
 */
final class StockageDesAutorisations
{
    public const string NOM_VALIDE = '/^[0-9a-f]{32}\.(pdf|jpg|png|webp)$/';

    /** Les types acceptés : un écrit scanné, une capture d'écran. */
    public const array EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        #[Autowire(param: 'app.membership.autorisations')]
        private readonly string $racine,
    ) {
    }

    /** Copie le fichier sous un nom aléatoire et rend ce nom. */
    public function deposer(string $cheminSource, string $mime): string
    {
        $extension = self::EXTENSIONS[$mime] ?? throw new \InvalidArgumentException(\sprintf('Type de document inconnu : « %s ».', $mime));
        $nom = bin2hex(random_bytes(16)).'.'.$extension;
        $destination = $this->chemin($nom);
        $repertoire = \dirname($destination);

        if (!is_dir($repertoire) && !@mkdir($repertoire, 0o775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException(\sprintf('Répertoire des autorisations impossible à créer : %s', $repertoire));
        }

        if (!@copy($cheminSource, $destination)) {
            throw new \RuntimeException('Écriture impossible dans le stockage des autorisations.');
        }

        return $nom;
    }

    public function chemin(string $nom): string
    {
        if (1 !== preg_match(self::NOM_VALIDE, $nom)) {
            throw new \InvalidArgumentException('Nom de document invalide.');
        }

        return \sprintf('%s/%s/%s', rtrim($this->racine, '/\\'), substr($nom, 0, 2), $nom);
    }

    public function existe(string $nom): bool
    {
        return 1 === preg_match(self::NOM_VALIDE, $nom) && is_file($this->chemin($nom));
    }

    public function supprimer(string $nom): void
    {
        if (1 === preg_match(self::NOM_VALIDE, $nom) && is_file($chemin = $this->chemin($nom))) {
            @unlink($chemin);
        }
    }
}
