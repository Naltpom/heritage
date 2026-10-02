<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le stockage des **messages vocaux** de l'inscription sans fiche — hors racine web.
 *
 * Calqué sur `Media\Service\StockageMedia`, et pour les mêmes raisons ; recopié plutôt
 * qu'importé, parce qu'un module n'emprunte pas les services d'un autre (ADR 0002) et que
 * ces fichiers ne sont **pas** des médias : ils n'appartiennent à aucune fiche, n'entrent
 * dans aucun circuit de validation, et disparaissent avec la déclaration.
 *
 * - **Hors racine web** : sous `messages-vocaux/` du volume des téléversements
 *   (`app.membership.messages_vocaux`, dérivé de `MEDIA_RACINE`), jamais sous
 *   `api/public/`. Ils ne sortent que par
 *   `GET /api/administration/rattachements/{id}/message-vocal`, route `ROLE_ADMIN`.
 * - **Un nom aléatoire**, 32 hexadécimaux tirés de `random_bytes()` et l'extension du type
 *   réel : ni l'identité ni le courriel du candidat n'y paraissent, et le dossier n'est pas
 *   énumérable.
 * - **Le nom relu de la base est revalidé** ({@see NOM_VALIDE}) avant d'ouvrir quoi que ce
 *   soit : une valeur étrangère dans `voice_message_name` ne devient pas une lecture
 *   arbitraire du disque.
 * - **Un éclatement par préfixe** de deux caractères, comme les médias.
 *
 * **Ce sont des voix de vivants qui parlent d'autres vivants.** Le fichier part au
 * rattachement comme au refus (`ServiceDesMembres`), et rien ne le journalise.
 */
final class StockageDesMessagesVocaux
{
    /** Le seul nom qui sera jamais ouvert. Volontairement plus strict que nécessaire. */
    public const string NOM_VALIDE = '/^[0-9a-f]{32}\.(webm|ogg|m4a)$/';

    /** @var array<string, string> */
    private const array EXTENSIONS = [
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
    ];

    public function __construct(
        #[Autowire(param: 'app.membership.messages_vocaux')]
        private readonly string $racine,
    ) {
    }

    /**
     * Copie le fichier temporaire sous un nom neuf, et rend ce nom.
     *
     * Copie puis effacement, et non `rename()` : le temporaire de PHP et le volume des
     * téléversements ne sont pas le même montage en conteneur.
     */
    public function deposer(string $cheminSource, string $mime): string
    {
        $extension = self::EXTENSIONS[$mime] ?? throw new \InvalidArgumentException(\sprintf('Type de message vocal inconnu : « %s ».', $mime));
        $nom = bin2hex(random_bytes(16)).'.'.$extension;
        $destination = $this->chemin($nom);
        $repertoire = \dirname($destination);

        if (!is_dir($repertoire) && !@mkdir($repertoire, 0o775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException(\sprintf('Répertoire des messages vocaux impossible à créer : %s', $repertoire));
        }

        if (!@copy($cheminSource, $destination)) {
            throw new \RuntimeException('Écriture impossible dans le stockage des messages vocaux.');
        }

        @unlink($cheminSource);

        return $nom;
    }

    /**
     * Le chemin absolu d'un message stocké — public parce que `BinaryFileResponse` le sert
     * en flux, requêtes partielles (`Range`) comprises.
     */
    public function chemin(string $nom): string
    {
        if (1 !== preg_match(self::NOM_VALIDE, $nom)) {
            throw new \InvalidArgumentException('Nom de message vocal invalide.');
        }

        return \sprintf('%s/%s/%s', rtrim($this->racine, '/\\'), substr($nom, 0, 2), $nom);
    }

    public function existe(string $nom): bool
    {
        return is_file($this->chemin($nom));
    }

    /**
     * Efface un message, s'il y en a un. Un nom invalide est ignoré plutôt que levé : on
     * l'appelle **après** un commit, où une exception n'aurait plus rien à défaire.
     */
    public function supprimer(?string $nom): void
    {
        if (null === $nom || 1 !== preg_match(self::NOM_VALIDE, $nom)) {
            return;
        }

        $chemin = $this->chemin($nom);

        if (is_file($chemin)) {
            @unlink($chemin);
        }
    }
}
