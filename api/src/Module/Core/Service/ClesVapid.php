<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\VapidKey;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * La paire de clés VAPID (RFC 8292) avec laquelle le serveur signe ses envois push.
 *
 * ## La règle : la clé publique ne change JAMAIS d'un déploiement à l'autre
 *
 * C'est la panne que ce service existe pour empêcher : « le jeton de notification casse à
 * chaque mise en prod ». Un navigateur abonné l'est **avec une clé publique donnée** ; si
 * le serveur en change, le service push refuse tous les envois suivants, et chaque
 * téléphone doit se réabonner — ce que personne ne fait, puisque rien ne le signale.
 *
 * D'où l'ordre de résolution, du plus explicite au plus automatique :
 *
 *  1. `VAPID_PUBLIC_KEY` + `VAPID_PRIVATE_KEY` dans l'environnement, **les deux** — pour
 *     qui veut reprendre une paire existante ou la gérer comme un secret ;
 *  2. sinon, la ligne unique de `core_vapid_key`, relue à chaque requête ;
 *  3. sinon — la toute première fois —, une paire **générée puis écrite en base**, et
 *     plus jamais regénérée.
 *
 * Jamais de génération « en mémoire » ni dans le cache : l'un et l'autre disparaissent
 * au redéploiement, et c'est précisément ce qui cassait.
 *
 * ## Le sujet n'est pas stocké
 *
 * Le `sub` du jeton VAPID (un `mailto:` ou une URL de contact) n'engage pas les
 * abonnements : il se recalcule à chaque envoi. `VAPID_SUBJECT` s'il est posé, sinon
 * `mailto:` + l'adresse de `MAILER_EXPEDITEUR`, sinon l'URL publique du site. Changer
 * d'expéditeur ne casse donc rien.
 */
final class ClesVapid
{
    /** @var array{publique: string, privee: string}|null */
    private ?array $paire = null;

    public function __construct(
        private readonly Connection $connexion,
        private readonly UrlPublique $urlPublique,
        #[Autowire(env: 'default::VAPID_PUBLIC_KEY')]
        private readonly ?string $publiqueImposee = null,
        #[Autowire(env: 'default::VAPID_PRIVATE_KEY')]
        private readonly ?string $priveeImposee = null,
        #[Autowire(env: 'default::VAPID_SUBJECT')]
        private readonly ?string $sujetImpose = null,
        #[Autowire(env: 'default::MAILER_EXPEDITEUR')]
        private readonly ?string $expediteur = null,
    ) {
    }

    /** Base64url non paddé, point P-256 non compressé — ce que `PushManager.subscribe()` attend. */
    public function clePublique(): string
    {
        return $this->paire()['publique'];
    }

    /**
     * Le triplet au format de `minishlink/web-push` (`['VAPID' => …]`).
     *
     * @return array{subject: string, publicKey: string, privateKey: string}
     */
    public function pourSigner(): array
    {
        $paire = $this->paire();

        return [
            'subject' => $this->sujet(),
            'publicKey' => $paire['publique'],
            'privateKey' => $paire['privee'],
        ];
    }

    public function sujet(): string
    {
        $impose = trim((string) $this->sujetImpose);
        if ('' !== $impose) {
            return $impose;
        }

        $expediteur = trim((string) $this->expediteur);
        if ('' !== $expediteur) {
            try {
                // `MAILER_EXPEDITEUR` s'écrit volontiers `Heritage <x@exemple.fr>` : le
                // sujet VAPID n'accepte que l'adresse nue.
                return 'mailto:'.Address::create($expediteur)->getAddress();
            } catch (\Throwable) {
                // Adresse illisible : on passe au repli suivant plutôt que de signer avec
                // un sujet que les services push refuseraient.
            }
        }

        try {
            return $this->urlPublique->base();
        } catch (\LogicException) {
            // Ni expéditeur ni URL publique : environnement de développement. Un sujet
            // syntaxiquement valide vaut mieux qu'aucun envoi du tout.
            return 'mailto:heritage@localhost';
        }
    }

    /** @return array{publique: string, privee: string} */
    private function paire(): array
    {
        if (null !== $this->paire) {
            return $this->paire;
        }

        $publique = trim((string) $this->publiqueImposee);
        $privee = trim((string) $this->priveeImposee);

        if ('' !== $publique && '' !== $privee) {
            return $this->paire = ['publique' => $publique, 'privee' => $privee];
        }

        return $this->paire = $this->lire() ?? $this->creer();
    }

    /** @return array{publique: string, privee: string}|null */
    private function lire(): ?array
    {
        $ligne = $this->connexion->fetchAssociative(
            'SELECT public_key, private_key FROM core_vapid_key WHERE id = ?',
            [VapidKey::ID_UNIQUE],
        );

        if (false === $ligne || !\is_string($ligne['public_key'] ?? null) || !\is_string($ligne['private_key'] ?? null)) {
            return null;
        }

        return ['publique' => $ligne['public_key'], 'privee' => $ligne['private_key']];
    }

    /**
     * Génère et **écrit** une paire, puis relit la base.
     *
     * La relecture n'est pas une précaution de style : si une autre requête a gagné la
     * course, son `INSERT` a été retenu et le nôtre ignoré (`ON CONFLICT DO NOTHING`, que
     * PostgreSQL et SQLite comprennent tous deux). C'est la paire **en base** qui fait
     * foi — celle qu'on vient de générer, jamais distribuée, est jetée.
     *
     * @return array{publique: string, privee: string}
     */
    private function creer(): array
    {
        $generee = self::genererUnePaire();

        $this->connexion->executeStatement(
            'INSERT INTO core_vapid_key (id, public_key, private_key, created_at) VALUES (?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
            [VapidKey::ID_UNIQUE, $generee['publique'], $generee['privee'], new \DateTimeImmutable()],
            [Types::INTEGER, Types::STRING, Types::STRING, Types::DATETIMETZ_IMMUTABLE],
        );

        return $this->lire() ?? throw new \RuntimeException('La paire VAPID n’a pas pu être écrite en base.');
    }

    /**
     * Une paire P-256 fraîche, encodée comme le veulent la Push API et la RFC 8292.
     *
     * Écrite ici plutôt que déléguée à `VAPID::createVapidKeys()` de la bibliothèque pour
     * une seule raison : le PHP Windows du poste de développement n'a pas de
     * `OPENSSL_CONF`, et `openssl_pkey_new()` y échoue sans fichier de configuration. On
     * retente donc avec celui que PHP livre à côté de son exécutable ; sous Linux
     * (conteneur, CI), le premier appel réussit et le second n'est jamais tenté.
     *
     * @return array{publique: string, privee: string}
     */
    public static function genererUnePaire(): array
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC];

        $cle = @openssl_pkey_new($options);

        if (false === $cle) {
            $configuration = \dirname(\PHP_BINARY).'/extras/ssl/openssl.cnf';
            if (is_file($configuration)) {
                $cle = @openssl_pkey_new($options + ['config' => $configuration]);
            }
        }

        // Vider la pile d'erreurs d'OpenSSL : un message laissé là ressortirait dans le
        // prochain appel qui la lit, à des kilomètres de sa cause.
        while (false !== openssl_error_string()) {
        }

        if (false === $cle) {
            throw new \RuntimeException('OpenSSL ne sait pas générer de clé P-256 sur ce serveur.');
        }

        $details = openssl_pkey_get_details($cle);
        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;

        if (!\is_array($ec) || !\is_string($ec['x'] ?? null) || !\is_string($ec['y'] ?? null) || !\is_string($ec['d'] ?? null)) {
            throw new \RuntimeException('OpenSSL a rendu une clé P-256 illisible.');
        }

        // Les coordonnées perdent leurs zéros de tête : on les rend à 32 octets, sans quoi
        // une clé sur 256 environ serait refusée par le navigateur.
        $x = str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT);
        $y = str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT);
        $d = str_pad($ec['d'], 32, "\0", \STR_PAD_LEFT);

        return [
            'publique' => self::base64Url("\x04".$x.$y),
            'privee' => self::base64Url($d),
        ];
    }

    public static function base64Url(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    /** Rend `null` sur une chaîne qui n'est pas du base64url (paddé ou non). */
    public static function depuisBase64Url(string $texte): ?string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]*={0,2}$/', $texte)) {
            return null;
        }

        $decode = base64_decode(strtr(rtrim($texte, '='), '-_', '+/'), true);

        return false === $decode ? null : $decode;
    }
}
