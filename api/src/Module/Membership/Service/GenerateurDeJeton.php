<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

/**
 * Le jeton d'invitation : sa génération, son empreinte, sa forme.
 *
 * **Le serveur ne stocke que `hash(jeton)`, jamais le jeton en clair** (§ 6.1 du dossier,
 * § 4.1 du modèle). Le jeton n'existe qu'en mémoire à la création, le temps d'être affiché
 * à l'administrateur et envoyé par courriel. Un administrateur qui perd le lien **révoque
 * et recrée** ; il ne peut pas le relire, et c'est voulu.
 *
 * Conséquence qu'il faut connaître pour ne pas la chercher : **il n'existe aucune route
 * « renvoyer l'invitation »**, et le front ne doit pas proposer ce bouton. C'est le piège
 * le plus probable de ce module — la route paraît manquante alors qu'elle est impossible
 * (§ 3.2 du contrat).
 *
 * ## La forme, fixée par le contrat
 *
 * 32 octets aléatoires en **base64url sans remplissage**, soit 43 caractères
 * `[A-Za-z0-9_-]`. `tokenPrefix` en est les 8 premiers, et ne sert qu'à distinguer deux
 * lignes dans la liste d'administration : 8 caractères sur 43 ne reconstruisent rien.
 *
 * ## Pourquoi `hash_equals` n'apparaît nulle part
 *
 * La recherche se fait par `WHERE token_hash = ?` sur un index unique — il n'y a pas de
 * comparaison de secret en PHP à rendre à temps constant. C'est l'index qui porte la
 * propriété, et le § 4.1 du modèle le dit : un défaut d'index se paierait en temps de
 * réponse mesurable, donc en canal auxiliaire.
 */
final class GenerateurDeJeton
{
    public const int LONGUEUR = 43;
    public const int LONGUEUR_PREFIXE = 8;

    /** 32 octets : 256 bits d'entropie, le même ordre de grandeur que l'empreinte qui les range. */
    private const int OCTETS = 32;

    public function jeton(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::OCTETS)), '+/', '-_'), '=');
    }

    /** `hash('sha256', $jeton)` en hexadécimal — les 64 caractères de `mbr_invitation.token_hash`. */
    public function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    public function prefixe(string $jeton): string
    {
        return substr($jeton, 0, self::LONGUEUR_PREFIXE);
    }

    /**
     * La forme est-elle celle d'un jeton ?
     *
     * Le contrôle sert à **éviter une requête inutile**, jamais à distinguer un refus d'un
     * autre : un jeton mal formé et un jeton inconnu rendent tous deux `404`, avec le même
     * corps et le même message (§ 4 du contrat). Un `422 "jeton mal formé"` dirait au
     * porteur qu'il a mal recopié, donc que la route existe et attend une forme précise.
     */
    public function formeValide(string $jeton): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{'.self::LONGUEUR.'}$/', $jeton);
    }
}
