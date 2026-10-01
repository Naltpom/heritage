<?php

declare(strict_types=1);

namespace App\Module\Tirage\Service;

/**
 * La clé du lien personnel d'un participant : sa génération, son empreinte, sa forme.
 *
 * ## Une copie, et non un emprunt
 *
 * C'est l'idiome du jeton d'invitation (`Membership\Service\GenerateurDeJeton`), recopié
 * **à dessein** : ce service est interne à `Membership`, et l'ADR 0002 n'autorise un `use`
 * inter-modules que vers `Core\…` ou un `Contract\`. Vingt lignes sans état ne justifient ni
 * un contrat, ni une montée dans `Core` (règle 3 : ce qui ne sert qu'à deux modules est
 * dupliqué plutôt qu'installé dans le noyau). Si un troisième module en a besoin, c'est le
 * moment de le monter dans `Core`.
 *
 * ## Ce qui est stocké
 *
 * **Le serveur ne garde que `sha256(clé)`, jamais la clé en clair.** Elle n'existe qu'en
 * mémoire à la création ou à la régénération, le temps d'être rendue à celui qui la
 * partage. Qui l'a perdue en **régénère** une : l'ancienne cesse de valoir, et c'est voulu
 * — il n'existe aucune route « relire le lien ».
 *
 * ## La forme
 *
 * 32 octets aléatoires en **base64url sans remplissage**, soit 43 caractères
 * `[A-Za-z0-9_-]` : 256 bits, qu'aucun limiteur n'a besoin de protéger contre la devinette
 * — le limiteur de {@see LimiteurDesLiens} n'est là que par défense en profondeur.
 *
 * ## Pourquoi `hash_equals` n'apparaît nulle part
 *
 * La recherche se fait par `WHERE lien_token_hash = ?` sur un index unique : aucune
 * comparaison de secret en PHP n'est à rendre à temps constant.
 */
final class GenerateurDeCle
{
    public const int LONGUEUR = 43;

    private const int OCTETS = 32;

    public function cle(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::OCTETS)), '+/', '-_'), '=');
    }

    /** `hash('sha256', $cle)` en hexadécimal — les 64 caractères de `trg_participant.lien_token_hash`. */
    public function empreinte(string $cle): string
    {
        return hash('sha256', $cle);
    }

    /**
     * La forme est-elle celle d'une clé ?
     *
     * Le contrôle **évite une requête inutile**, il ne distingue jamais un refus d'un autre :
     * une clé mal formée et une clé inconnue rendent la même `404`, au même corps.
     */
    public function formeValide(string $cle): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{'.self::LONGUEUR.'}$/', $cle);
    }
}
