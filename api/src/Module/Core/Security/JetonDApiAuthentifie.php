<?php

declare(strict_types=1);

namespace App\Module\Core\Security;

use App\Module\Core\Entity\JetonDApi;
use App\Module\Core\Enum\PorteeDeJeton;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Le jeton d'API qui a authentifié la requête : son id, son libellé, sa portée (ADR 0021).
 *
 * ## Le chemin des attributs
 *
 * 1. {@see JetonDApiHandler} les pose sur le `UserBadge` ({@see self::attributs()}).
 * 2. {@see \App\Module\Core\EventSubscriber\AttributsDuJetonDApiSubscriber} les recopie
 *    sur le jeton de sécurité, à sa création (`AuthenticationTokenCreatedEvent`).
 * 3. Qui veut savoir si la requête vient d'un jeton d'API, et lequel, appelle
 *    {@see self::depuisLeJetonDeSecurite()} : le journal d'audit, et après lui la liste
 *    blanche des portées et la règle de l'auteur de `DemandeVoter`.
 *
 * Les trois noms d'attributs ne sont écrits qu'ici. Ils valent sur le badge comme sur le
 * jeton de sécurité.
 *
 * ## Fermé quand c'est douteux
 *
 * `null` veut dire « pas de jeton d'API » — donc, pour la liste blanche des portées,
 * « session ordinaire ». Un attribut d'id présent mais des compagnons absents ou faux ne
 * rendent donc **pas** `null` : ils lèvent une `LogicException`. Un repli silencieux
 * ouvrirait à un jeton mal décrit tout ce qu'ouvre une session.
 */
final readonly class JetonDApiAuthentifie
{
    public const string ATTRIBUT_ID = 'jeton_api_id';

    public const string ATTRIBUT_LIBELLE = 'jeton_api_libelle';

    /** La valeur de {@see PorteeDeJeton}, en texte : `lecture`, `contribution`, `validation`. */
    public const string ATTRIBUT_PORTEE = 'jeton_api_portee';

    public function __construct(
        public int $id,
        public string $libelle,
        public PorteeDeJeton $portee,
    ) {
    }

    public static function duJeton(JetonDApi $jeton): self
    {
        $id = $jeton->getId() ?? throw new \LogicException('Un jeton d\'API non enregistré n\'authentifie personne.');

        return new self($id, $jeton->getLibelle(), $jeton->getPortee());
    }

    /**
     * Le jeton d'API d'un jeton de sécurité, ou `null` si la requête n'en vient pas
     * (cookie de session, incarnation, console).
     */
    public static function depuisLeJetonDeSecurite(?TokenInterface $jetonDeSecurite): ?self
    {
        if (null === $jetonDeSecurite) {
            return null;
        }

        return self::depuisAttributs($jetonDeSecurite->getAttributes());
    }

    /**
     * @param array<mixed>|null $attributs ceux d'un `UserBadge` ou d'un jeton de sécurité
     */
    public static function depuisAttributs(?array $attributs): ?self
    {
        if (null === $attributs || !\array_key_exists(self::ATTRIBUT_ID, $attributs)) {
            return null;
        }

        $id = $attributs[self::ATTRIBUT_ID];
        $libelle = $attributs[self::ATTRIBUT_LIBELLE] ?? null;
        $portee = $attributs[self::ATTRIBUT_PORTEE] ?? null;
        $portee = \is_string($portee) ? PorteeDeJeton::tryFrom($portee) : null;

        if (!\is_int($id) || !\is_string($libelle) || null === $portee) {
            throw new \LogicException('Attributs de jeton d\'API incomplets : la requête ne peut être ni ouverte ni fermée en confiance.');
        }

        return new self($id, $libelle, $portee);
    }

    /** @return array{jeton_api_id: int, jeton_api_libelle: string, jeton_api_portee: string} */
    public function attributs(): array
    {
        return [
            self::ATTRIBUT_ID => $this->id,
            self::ATTRIBUT_LIBELLE => $this->libelle,
            self::ATTRIBUT_PORTEE => $this->portee->value,
        ];
    }
}
