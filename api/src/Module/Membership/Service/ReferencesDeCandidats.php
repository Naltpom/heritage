<?php

declare(strict_types=1);

namespace App\Module\Membership\Service;

use App\Module\Membership\Enum\MatchKind;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Les `ref` opaques rendus à l'étape 1b, et relus à l'étape 2 — **divergence 2 du § 17.1
 * du contrat d'API**, la plus coûteuse de ce parcours.
 *
 * > Le front a besoin d'un identifiant à renvoyer pour dire « c'est ce candidat-là ». Le
 * > choix naturel, côté back comme côté front, est `personId`. Il donne au porteur d'un
 * > jeton **l'identifiant réel d'une personne vivante**, et donc le point de départ d'une
 * > énumération par incrémentation sur `/api/arbre/personnes/{id}` une fois le compte
 * > créé.
 *
 * D'où cette indirection : un `ref` **opaque, aléatoire, à usage unique, lié à la session
 * de cette tentative, valable 15 minutes**. La correspondance `ref → personId` ne quitte
 * jamais le serveur.
 *
 * ## Quatre propriétés, et ce qui les porte
 *
 * - **opaque et aléatoire** — 8 octets tirés au sort. Le préfixe `c` est cosmétique : il
 *   fait ressembler la valeur aux `c1` / `c2` de l'exemple du § 4.2 sans rien encoder ;
 * - **à usage unique** — {@see self::consommer()} efface tout le jeu, pas seulement le
 *   `ref` retenu. Un candidat qui a choisi ne reviendra pas sur les deux autres ;
 * - **lié à la tentative** — chaque appel à {@see self::deposer()} **remplace** le jeu
 *   précédent. Un `ref` d'une recherche antérieure est mort, ce qui est la lecture stricte
 *   de « issu de **la même** tentative » ;
 * - **lié au jeton** — le jeu porte l'empreinte de l'invitation. Un `ref` obtenu avec un
 *   lien ne vaut rien avec un autre, même dans la même session de navigateur.
 *
 * ## Le stockage est la session, et non la base
 *
 * Il n'y a **pas** de table de `ref`. Le § 0.6 du modèle écarte les tables de session ; et
 * une durée de 15 minutes à usage unique ne justifie pas une table qu'il faudrait ensuite
 * purger — une purge oubliée deviendrait une liste d'identifiants de personnes vivantes
 * associés à des jetons d'invitation, c'est-à-dire l'inverse de ce que cette indirection
 * existe pour empêcher.
 *
 * ## Pourquoi un `ref` inconnu rend `404` et non `422`
 *
 * « On ne confirme rien sur le contenu de l'étape précédente » (§ 4.3). Un `422` dirait
 * au porteur que sa recherche avait bien rendu quelque chose, et qu'il a seulement laissé
 * passer le délai — donc que la personne existe.
 */
final readonly class ReferencesDeCandidats
{
    private const string CLE_SESSION = 'membership.candidats';
    private const int DUREE = 900;

    public function __construct(private RequestStack $requetes)
    {
    }

    /**
     * Range le jeu de candidats de **cette** tentative, en remplaçant le précédent.
     *
     * @param array<string, int> $parReference `ref` ⇒ `tree_person.id`
     */
    public function deposer(string $empreinteDuJeton, array $parReference, MatchKind $nature): void
    {
        $session = $this->session();

        if (null === $session) {
            return;
        }

        $session->set(self::CLE_SESSION, [
            'jeton' => $empreinteDuJeton,
            'nature' => $nature->value,
            'expire' => time() + self::DUREE,
            'candidats' => $parReference,
        ]);
    }

    public function reference(): string
    {
        return 'c'.bin2hex(random_bytes(8));
    }

    /**
     * Résout un `ref` et **efface le jeu entier**, quel que soit le résultat.
     *
     * L'effacement inconditionnel est la mise en œuvre de l'usage unique : sans lui, un
     * `ref` refusé pourrait être rejoué, et le jeu resterait exploitable pendant quinze
     * minutes après un premier essai.
     *
     * @return array{personId: int, nature: MatchKind}|null `null` si le `ref` est inconnu,
     *                                                      expiré, ou issu d'une autre
     *                                                      tentative — trois cas
     *                                                      indistinguables pour l'appelant,
     *                                                      qui rend `404` dans les trois
     */
    public function consommer(string $empreinteDuJeton, string $reference): ?array
    {
        $session = $this->session();

        if (null === $session) {
            return null;
        }

        $jeu = $session->get(self::CLE_SESSION);
        $session->remove(self::CLE_SESSION);

        if (!\is_array($jeu)) {
            return null;
        }

        $expire = $jeu['expire'] ?? 0;

        if (!\is_int($expire) || $expire < time()) {
            return null;
        }

        if (($jeu['jeton'] ?? null) !== $empreinteDuJeton) {
            return null;
        }

        $candidats = $jeu['candidats'] ?? null;

        if (!\is_array($candidats) || !\is_int($candidats[$reference] ?? null)) {
            return null;
        }

        $nature = \is_string($jeu['nature'] ?? null) ? MatchKind::tryFrom($jeu['nature']) : null;

        return ['personId' => $candidats[$reference], 'nature' => $nature ?? MatchKind::RechercheConfirmee];
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $requete = $this->requetes->getCurrentRequest();

        if (null === $requete || !$requete->hasSession()) {
            return null;
        }

        return $requete->getSession();
    }
}
