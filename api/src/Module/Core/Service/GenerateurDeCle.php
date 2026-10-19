<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

/**
 * La clé d'un **lien personnel** — celui d'un invité à un événement (`/e/<clé>`) : sa
 * génération, son empreinte, sa forme.
 *
 * ## Une copie devenue commune
 *
 * C'est l'idiome du jeton d'invitation (`Membership\Service\GenerateurDeJeton`). Il a d'abord
 * été recopié dans `Tirage`, **à dessein** : l'ADR 0002 n'autorise un `use` inter-modules que
 * vers `Core\…` ou un `Contract\`, et ce qui ne sert qu'à deux modules est dupliqué plutôt
 * qu'installé dans le noyau (règle 3). L'en-tête de cette copie fixait la suite : « si un
 * troisième module en a besoin, c'est le moment de le monter dans `Core` ». Le module
 * `Evenement` (2026-10-02) est ce troisième : la classe vit donc ici, et `Evenement`
 * l'emploie telle quelle — seul, depuis le retrait du module `Tirage` (2026-10-20), sans
 * que cela justifie de la redescendre. `Membership` garde son générateur de jeton : il porte
 * en plus un préfixe affichable, propre aux invitations, et le déplacer ne servirait personne.
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
 * — le limiteur du module qui s'en sert (`Evenement\Service\LimiteurDesLiens`) n'est là
 * que par défense en profondeur.
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

    /** `hash('sha256', $cle)` en hexadécimal — les 64 caractères de `evt_invitation.lien_token_hash`. */
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
