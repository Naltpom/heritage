<?php

declare(strict_types=1);

namespace App\Module\Core\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'adresse publique de l'application — **configurée, jamais déduite de la requête**.
 *
 * ## La vulnérabilité que cette classe supprime
 *
 * Le lien d'invitation était bâti sur `Request::getSchemeAndHttpHost()`. Or l'en-tête
 * `Host` et les `X-Forwarded-*` **viennent du client**, et l'API leur fait confiance
 * (`trusted_proxies: private_ranges`) sans qu'aucun `trusted_hosts` ne les borne.
 *
 * Conséquence, et c'est la première demande du commanditaire retournée contre ses
 * destinataires : une requête portant un `Host` falsifié fait fabriquer **par nous** un
 * lien vers un domaine attaquant, et ce lien part **dans un courriel authentique, depuis
 * notre adresse, à une personne réelle de la famille**. Elle n'a aucun moyen de le
 * distinguer d'un vrai — c'est précisément ce qu'un lien d'invitation est censé
 * garantir.
 *
 * ## Pourquoi configurer plutôt que valider
 *
 * On aurait pu valider le `Host` reçu contre une liste blanche. Configurer l'adresse est
 * **plus simple et plus sûr** : il n'y a plus rien à valider, la valeur ne vient plus de
 * l'extérieur, et la falsification devient impossible **par construction** plutôt que
 * repoussée par un contrôle qu'on peut oublier d'appliquer au prochain lien. Le VPS a
 * de toute façon besoin de connaître son adresse publique : c'est un réglage qui devait
 * exister.
 *
 * `framework.trusted_hosts` reste utile — mais comme **défense en profondeur**, pas
 * comme correctif. Voir `config/packages/framework.yaml`.
 *
 * ## Pas de repli sur la requête, et c'est délibéré
 *
 * Si la variable manque, cette classe **lève**. Un repli sur le `Host` de la requête
 * rouvrirait exactement le trou qu'on ferme, et il le rouvrirait **silencieusement** —
 * au moment précis où la configuration est absente, c'est-à-dire quand personne ne
 * regarde. `api/.env` porte donc une valeur de développement par défaut, de sorte que le
 * cas « non configuré » ne se produise qu'en production mal déployée, où l'on préfère
 * une erreur franche à un courriel d'hameçonnage signé par nous.
 */
final class UrlPublique
{
    public function __construct(
        #[Autowire(env: 'default::HERITAGE_URL_PUBLIQUE')]
        private readonly ?string $base = null,
    ) {
    }

    /**
     * La base, sans barre oblique finale : `https://heritage.example.net`.
     *
     * @throws \LogicException si `HERITAGE_URL_PUBLIQUE` est absente
     */
    public function base(): string
    {
        $base = trim((string) $this->base);

        if ('' === $base) {
            throw new \LogicException('HERITAGE_URL_PUBLIQUE n’est pas configurée : impossible de bâtir une URL publique. La déduire de la requête laisserait un client choisir le domaine de nos liens d’invitation.');
        }

        return rtrim($base, '/');
    }

    /**
     * Une URL absolue vers un chemin **du front**, pas de l'API.
     *
     * `/inscription/{jeton}` est une route de la SPA : elle la lit et appelle ensuite
     * `POST /api/inscription/verifier`. C'est pour cela que l'adresse publique est celle
     * du front, et non celle de l'API — les deux partagent l'origine (§ 1.1 du contrat),
     * mais c'est le front qu'on donne à cliquer.
     */
    public function versLeFront(string $chemin): string
    {
        return $this->base().'/'.ltrim($chemin, '/');
    }

    /** L'hôte seul, pour composer une liste blanche ou un journal. */
    public function hote(): string
    {
        return (string) parse_url($this->base(), \PHP_URL_HOST);
    }
}
