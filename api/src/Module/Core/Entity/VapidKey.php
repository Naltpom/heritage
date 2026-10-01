<?php

declare(strict_types=1);

namespace App\Module\Core\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La paire de clés VAPID du serveur (RFC 8292) — **une seule ligne, pour toujours**.
 *
 * ## Pourquoi en base, et pourquoi c'est ce qui répare « ça casse à chaque mise en prod »
 *
 * Chaque abonnement push est lié, chez le service push, à la **clé publique** avec
 * laquelle le navigateur s'est abonné. Si le serveur signe ensuite avec une autre clé, le
 * service répond `403`/`410` et le téléphone ne reçoit plus rien — sans que personne
 * ne s'en aperçoive avant d'avoir attendu une notification qui ne vient pas.
 *
 * Une clé générée au démarrage, gardée dans le cache ou dans l'image, change à chaque
 * redéploiement : c'est exactement la panne décrite. En base, elle survit à tous les
 * redéploiements, comme les comptes et les abonnements qu'elle signe. Elle est créée à
 * la première demande (`Service\ClesVapid`), puis relue.
 *
 * Les variables d'environnement `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`, si elles sont
 * posées, priment sur cette ligne — pour qui veut reprendre une paire existante.
 *
 * ## Pas un réglage
 *
 * Délibérément **hors** de `core_setting` : l'écran de réglages rend toutes ses lignes à
 * `GET /api/administration/reglages`, et la clé privée n'a rien à faire dans une réponse
 * HTTP. La table a son propre nom et aucune route ne la lit en entier.
 *
 * `id` vaut toujours `1` (contrainte `CHECK` dans la migration) : l'insertion se fait par
 * `INSERT … ON CONFLICT (id) DO NOTHING`, si bien que deux premières requêtes simultanées
 * ne peuvent pas produire deux paires dont l'une serait déjà distribuée aux navigateurs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_vapid_key')]
class VapidKey
{
    public const int ID_UNIQUE = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID_UNIQUE;

    /** Base64url non paddé, point P-256 non compressé (65 octets décodés). */
    #[ORM\Column(length: 100)]
    private string $publicKey;

    /** Base64url non paddé, scalaire P-256 (32 octets décodés). Ne sort jamais du serveur. */
    #[ORM\Column(length: 100)]
    private string $privateKey;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $publicKey, string $privateKey)
    {
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
