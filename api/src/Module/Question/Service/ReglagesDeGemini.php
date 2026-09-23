<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Les trois variables d'environnement de l'appel au modèle (ADR 0008 § 6).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 *  CETTE CLASSE NE RÉVÈLE JAMAIS LA CLÉ, ET C'EST SA SECONDE FONCTION.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * {@see configure()} rend un booléen, jamais la valeur. La clé elle-même ne sort que
 * par {@see cleApi()}, appelée en un seul endroit — l'en-tête de la requête HTTP. Elle
 * n'entre dans aucun message d'exception, aucun journal, aucune trace : une clé qui
 * passe une fois dans un `\Throwable` finit dans un rapport d'erreur, et une clé qui a
 * fini dans un rapport d'erreur se révoque.
 *
 * ## Clé vide est un ÉTAT NORMAL, pas une panne
 *
 * C'est celui d'un déploiement qui n'a pas souscrit, et celui de l'intégration continue.
 * Il ne lève rien, ne journalise rien, n'alerte sur rien : le repli rédige, et la page
 * sert les mêmes questions, moins bien tournées. Un rouge permanent sur un état normal
 * détruit l'alarme là où elle sert.
 *
 * Ce qui se lit, quand on veut savoir, est une requête :
 * `SELECT redacteur, count(*) FROM qst_question GROUP BY 1`.
 *
 * ## Pourquoi le point d'accès est une variable
 *
 * Parce que **Vertex AI n'a pas la même URL que l'API publique** : la sienne porte le
 * projet Google Cloud et la région de traitement, qui sont propres à l'installation. Un
 * point d'accès écrit en dur aurait interdit Vertex — c'est-à-dire justement le palier
 * que l'ADR 0008 préfère, pour sa région de traitement explicite et sa journalisation
 * des accès.
 *
 * ## Pourquoi le modèle en est une aussi
 *
 * Les identifiants de modèle sont retirés du service par le fournisseur, à son rythme.
 * Un identifiant en dur transforme cette date en panne de production réparable seulement
 * par un déploiement. En variable, la réparation est une ligne dans un `.env`.
 */
final readonly class ReglagesDeGemini
{
    /**
     * Le modèle par défaut si `HERITAGE_GEMINI_MODELE` est vide.
     *
     * Le plus petit de la famille, et c'est un choix : la tâche est de **reformuler une
     * phrase courte** à partir d'un contexte fourni, pas de raisonner. Un modèle plus
     * gros coûterait davantage pour écrire la même question.
     */
    public const string MODELE_DEFAUT = 'gemini-2.5-flash';

    /** Le point d'accès public. Vertex AI a le sien, d'où la variable. */
    public const string POINT_DACCES_DEFAUT = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        #[Autowire(env: 'default::HERITAGE_GEMINI_CLE_API')]
        private ?string $cleApi = null,
        #[Autowire(env: 'default::HERITAGE_GEMINI_MODELE')]
        private ?string $modele = null,
        #[Autowire(env: 'default::HERITAGE_GEMINI_POINT_DACCES')]
        private ?string $pointDacces = null,
    ) {
    }

    /**
     * Un appel sortant est-il possible ?
     *
     * **Un booléen, jamais la valeur.** C'est ce que tout le reste du module interroge,
     * et c'est ce qui fait qu'aucun autre fichier n'a la clé sous la main.
     */
    public function configure(): bool
    {
        return '' !== trim($this->cleApi ?? '');
    }

    /**
     * La clé, pour le **seul** appelant qui en a besoin : l'en-tête de la requête.
     *
     * @throws \LogicException si elle est absente — appeler cette méthode sans avoir
     *                         vérifié {@see configure()} est un défaut de programmation,
     *                         pas un état du déploiement
     */
    public function cleApi(): string
    {
        $cle = trim($this->cleApi ?? '');

        if ('' === $cle) {
            // Le message ne contient rien de la valeur, pour la raison évidente, et
            // nomme la variable pour celle qui l'est moins : c'est la seule chose utile
            // à qui lit la trace.
            throw new \LogicException('HERITAGE_GEMINI_CLE_API est absente : vérifier configure() avant d\'appeler.');
        }

        return $cle;
    }

    public function modele(): string
    {
        $modele = trim($this->modele ?? '');

        return '' === $modele ? self::MODELE_DEFAUT : $modele;
    }

    /** Sans barre oblique finale, pour que la concaténation qui suit ne double pas. */
    public function pointDacces(): string
    {
        $base = trim($this->pointDacces ?? '');

        return rtrim('' === $base ? self::POINT_DACCES_DEFAUT : $base, '/');
    }
}
