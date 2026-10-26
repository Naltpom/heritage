<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use App\Module\Core\Entity\User;
use App\Module\Core\Exception\ForbiddenException;
use App\Module\Core\Exception\ValidationException;
use App\Module\Core\Security\JetonDApiAuthentifie;
use App\Module\Core\Security\RequeteParJetonDApi;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * « Prouvez que c'est bien vous, **maintenant** » — avant d'ajouter une clé d'accès.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  UNE SESSION NE PROUVE PAS QU'ON EST LE TITULAIRE. ELLE PROUVE QU'IL L'A ÉTÉ.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un onglet resté ouvert, un poste partagé, un cookie volé : la session tient, et la
 * personne devant l'écran n'est peut-être plus celle qui s'est connectée. Pour la
 * plupart des gestes, c'est sans conséquence durable — la session finira par tomber.
 * Pour **ajouter une clé d'accès**, c'est l'inverse : la clé est un moyen de connexion
 * **permanent**, qui survit à la session, et qui rouvrirait le compte juste après un
 * changement de mot de passe fait en urgence. Un accès temporaire deviendrait un accès
 * permanent. Le dépôt exige déjà le mot de passe actuel pour changer le mot de passe et
 * le courriel ; la clé d'accès est de la même famille.
 *
 * Deux preuves, au choix :
 *
 * 1. **Une connexion récente** — moins de {@see self::FRAICHEUR} secondes, par mot de
 *    passe ou par clé d'accès (vérification de l'utilisateur exigée : Face ID,
 *    empreinte, code de l'appareil). C'est le cas de l'invitation qui suit la
 *    connexion : on ne redemande pas un mot de passe tapé il y a dix secondes.
 * 2. **Le mot de passe actuel**, dans le corps (`motDePasseActuel`). Faux ou absent :
 *    `422` sur ce champ, **et non `401`** — même arbitrage que § 2.1 : le compte est
 *    authentifié, une `401` ferait déconnecter le front. Juste : il compte comme une
 *    connexion récente, pour que l'essai suivant (annulation de Face ID…) ne le
 *    redemande pas aussitôt.
 *
 * L'heure de la preuve est gardée **en session** ({@see self::CLE_DE_SESSION}),
 * posée par `PreuveDIdentiteSubscriber` à chaque connexion réussie. Une session ouverte
 * avant que ce marquage existe n'en porte pas : elle doit donner le mot de passe, ce
 * qui est le sens prudent. Une incarnation n'en pose pas — et les routes des clés la
 * refusent de toute façon.
 *
 * Pas de limiteur dédié aux mots de passe faux ici, comme pour le changement de
 * courriel : il faut déjà une session, et la question ne rend rien d'autre qu'un oui
 * ou un non sur le mot de passe du compte connecté.
 *
 * ## Un jeton d'API ne prouve jamais une identité récente
 *
 * ADR 0021 § 4. Un jeton se vole et se rejoue sans son titulaire : s'il valait preuve,
 * un jeton volé réglerait ce que le mot de passe protège. Sous un jeton,
 * {@see self::exiger()} refuse donc **toujours** — `403 acces_refuse`, quel que soit
 * l'état de la session et même avec le bon mot de passe dans le corps : ce n'est plus le
 * titulaire qui le tape, c'est un programme qui l'aurait gardé. Et {@see self::marquer()}
 * ne note rien : une requête sans état n'a pas de session où écrire, et une marque posée
 * là ouvrirait une session, donc un cookie, sur la réponse.
 *
 * Deux signes désignent une requête par jeton, et l'un suffit — fermé quand c'est
 * douteux : l'en-tête `Authorization: Bearer` qui l'envoie au pare-feu `api_jetons`
 * ({@see RequeteParJetonDApi}), avant même l'authentification ; et les attributs que ce
 * pare-feu pose sur le jeton de sécurité ({@see JetonDApiAuthentifie}), après.
 */
final readonly class PreuveDIdentiteRecente
{
    /** Dix minutes : assez pour l'invitation qui suit la connexion, et pour un essai
     *  annulé puis repris ; trop court pour un onglet oublié. */
    public const int FRAICHEUR = 600;

    public const string CLE_DE_SESSION = '_heritage_preuve_d_identite_le';

    public function __construct(
        private RequestStack $requetes,
        private UserPasswordHasherInterface $hacheur,
        private ClockInterface $horloge,
        private TokenStorageInterface $jetonsDeSecurite,
        private RequeteParJetonDApi $requeteParJeton,
    ) {
    }

    /**
     * Une preuve vient d'être donnée : connexion, ou mot de passe vérifié. Sans effet
     * sous un jeton d'API (voir l'en-tête).
     */
    public function marquer(?SessionInterface $session = null): void
    {
        if ($this->parJetonDApi()) {
            return;
        }

        ($session ?? $this->requetes->getSession())->set(self::CLE_DE_SESSION, $this->horloge->now()->getTimestamp());
    }

    /**
     * Passe si la session porte une preuve fraîche, ou si `$motDePasse` est le bon.
     *
     * @throws ForbiddenException  `403 acces_refuse` : la requête vient d'un jeton d'API
     * @throws ValidationException `422` sur `motDePasseActuel` : absent, ou faux
     */
    public function exiger(User $compte, mixed $motDePasse): void
    {
        // Avant la session et avant le mot de passe : un jeton ne prouve rien (ADR 0021 § 4).
        if ($this->parJetonDApi()) {
            throw new ForbiddenException();
        }

        if ($this->estFraiche()) {
            return;
        }

        if (!\is_string($motDePasse) || '' === $motDePasse) {
            throw new ValidationException(['motDePasseActuel' => 'Confirmez votre mot de passe pour ajouter une clé d’accès.']);
        }

        if (!$this->hacheur->isPasswordValid($compte, $motDePasse)) {
            throw new ValidationException(['motDePasseActuel' => 'Le mot de passe actuel est incorrect.']);
        }

        $this->marquer();
    }

    private function estFraiche(): bool
    {
        $le = $this->requetes->getSession()->get(self::CLE_DE_SESSION);

        if (!\is_int($le)) {
            return false;
        }

        $age = $this->horloge->now()->getTimestamp() - $le;

        // Un âge négatif (horloge revenue en arrière) ne vaut pas preuve.
        return $age >= 0 && $age <= self::FRAICHEUR;
    }

    /** L'en-tête `Bearer` de la requête principale, ou les attributs du jeton de sécurité. */
    private function parJetonDApi(): bool
    {
        $requete = $this->requetes->getMainRequest();

        if (null !== $requete && $this->requeteParJeton->matches($requete)) {
            return true;
        }

        return null !== JetonDApiAuthentifie::depuisLeJetonDeSecurite($this->jetonsDeSecurite->getToken());
    }
}
