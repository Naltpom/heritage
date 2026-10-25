<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

/**
 * Le jeton d'API personnel : sa génération, son empreinte, son préfixe, sa forme (ADR 0021,
 * § 1.9 du contrat d'API).
 *
 * ## Une copie, à dessein
 *
 * C'est l'idiome de `Membership\Service\GenerateurDeJeton` et de {@see GenerateurDeCle} :
 * l'ADR 0002 interdit d'importer celui de `Membership`, et le jeton d'API porte en plus un
 * préfixe de reconnaissance (`hrt_`) que ni l'un ni l'autre n'ont.
 *
 * ## Ce qui est stocké
 *
 * **Le serveur ne garde que `sha256(jeton)`, jamais le jeton en clair.** Il n'existe en
 * mémoire que le temps d'être affiché, une seule fois, à celui qui le crée. Qui l'a perdu
 * en crée un autre et révoque l'ancien : il n'existe aucune route « relire le jeton ».
 *
 * ## La forme
 *
 * `hrt_` puis 32 octets aléatoires en **base64url sans remplissage**, soit 43 caractères
 * `[A-Za-z0-9_-]` : 256 bits. Le préfixe `hrt_` n'ajoute pas d'entropie ; il sert à
 * reconnaître un jeton d'Heritage d'un coup d'œil (et à le faire repérer par les outils
 * d'analyse de secrets) avant toute requête en base.
 *
 * ## Pourquoi `hash_equals` n'apparaît nulle part
 *
 * La recherche se fait par `WHERE token_hash = ?` sur un index unique : aucune comparaison
 * de secret en PHP n'est à rendre à temps constant.
 */
final class GenerateurDeJetonDApi
{
    public const string PREFIXE = 'hrt_';

    /** Les caractères base64url qui suivent `hrt_`. */
    public const int LONGUEUR_SECRET = 43;

    /**
     * Ce qu'on affiche pour reconnaître un jeton : `hrt_` et les 8 premiers caractères du
     * secret. 8 sur 43 ne reconstruisent rien.
     */
    public const int LONGUEUR_PREFIXE = 12;

    private const int OCTETS = 32;

    /**
     * Un jeton neuf : le clair (à montrer une fois), son empreinte et son préfixe.
     *
     * @return array{jeton: string, empreinte: string, prefixe: string}
     */
    public function generer(): array
    {
        $jeton = self::PREFIXE.rtrim(strtr(base64_encode(random_bytes(self::OCTETS)), '+/', '-_'), '=');

        return [
            'jeton' => $jeton,
            'empreinte' => $this->empreinte($jeton),
            'prefixe' => $this->prefixe($jeton),
        ];
    }

    /** `hash('sha256', $jeton)` en hexadécimal — les 64 caractères de `core_api_token.token_hash`. */
    public function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    public function prefixe(string $jeton): string
    {
        return substr($jeton, 0, self::LONGUEUR_PREFIXE);
    }

    /**
     * La forme est-elle celle d'un jeton d'API ?
     *
     * Le contrôle **évite une requête inutile**, il ne distingue jamais un refus d'un autre :
     * un jeton mal formé, inconnu, expiré ou révoqué rendent tous le même `401`.
     */
    public function formeValide(string $jeton): bool
    {
        return 1 === preg_match('/^'.self::PREFIXE.'[A-Za-z0-9_-]{'.self::LONGUEUR_SECRET.'}\z/', $jeton);
    }
}
