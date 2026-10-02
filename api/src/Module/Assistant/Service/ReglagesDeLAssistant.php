<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le modèle de l'assistant, et les bornes de son travail.
 *
 * La clé et le point d'accès ne sont **pas** ici : ils sont prêtés par `Question`
 * (`Contract\AccesAuModele`), pour qu'il n'existe qu'un endroit où la clé est lue.
 *
 * ## Pourquoi un modèle distinct de celui des questions
 *
 * Les questions reformulent une phrase courte : le plus petit modèle suffit. L'assistant
 * **lit des actes manuscrits**, recoupe des dates, enchaîne des recherches dans l'arbre
 * et décide entre « c'est la fiche 12 » et « c'est une personne nouvelle ». C'est le
 * travail d'un modèle de raisonnement — `gemini-pro-latest` par défaut (l'alias qui suit
 * le dernier Pro servi : `gemini-2.5-pro` répond `404 … no longer available to new users`
 * aux clés récentes, constaté le 24 septembre 2026). En variable, parce
 * que les identifiants de modèle sont retirés par le fournisseur à son rythme, et qu'une
 * réparation doit tenir en une ligne de `.env`.
 */
final readonly class ReglagesDeLAssistant
{
    public const string MODELE_DEFAUT = 'gemini-pro-latest';

    /**
     * Au plus huit allers-retours avec le modèle.
     *
     * Une analyse ordinaire en fait trois ou quatre : chercher, lire une ou deux fiches,
     * proposer. Au-delà de huit, le modèle tourne en rond — et chaque tour renvoie les
     * documents entiers, donc coûte.
     */
    public const int TOURS_MAXIMAUX = 8;

    /**
     * Cinquante-cinq secondes pour toute l'analyse, tous tours compris.
     *
     * Le membre attend devant un écran qui le lui dit (« 20 à 60 s »). La borne était de
     * 90 s, mais deux plafonds plus bas la coupaient avant elle, sans message :
     * `max_execution_time` de PHP (30 s, valeur compilée — d'où l'« Erreur interne » du
     * 02/10/2026, incident 0d101c6bd09ac5dc, `MaxExecutionTimeError` dans l'appel au
     * modèle) et le `proxy_read_timeout` de 60 s du nginx de l'hôte. 55 s tient sous le
     * second ; le premier est levé par {@see self::accorderLeTemps()}.
     */
    public const float DELAI_GLOBAL_SECONDES = 55.0;

    /** Ce que PHP accorde à une requête qui interroge le modèle : la borne, plus une marge. */
    public const int DUREE_PHP_SECONDES = 70;

    /**
     * Relève `max_execution_time` pour la requête en cours, avant le premier appel au
     * modèle. Sans cela, PHP tue la requête à 30 s au milieu d'un appel HTTP, et le membre
     * reçoit une `500` au lieu du message « la recherche a pris trop de temps ».
     */
    public static function accorderLeTemps(): void
    {
        // Relever, jamais abaisser : `0` (illimité, la valeur de la ligne de commande et
        // donc des tests) ou une valeur déjà plus haute restent tels quels — sinon la
        // suite de tests, qui tourne dans un seul processus, mourrait à 70 s.
        $actuel = (int) \ini_get('max_execution_time');

        if (0 === $actuel || $actuel >= self::DUREE_PHP_SECONDES) {
            return;
        }

        set_time_limit(self::DUREE_PHP_SECONDES);
    }

    /**
     * Le modèle de la **transcription** des messages vocaux (ADR 0013, addendum du
     * 2 octobre 2026, soir) — `gemini-2.5-flash`, le modèle léger des questions.
     *
     * Écouter et recopier n'est pas raisonner : un modèle Flash le fait aussi bien, plus
     * vite, et l'audio y est facturé environ trois fois moins qu'en Pro. En variable pour
     * la même raison que l'autre : les identifiants se retirent au rythme du fournisseur.
     */
    public const string MODELE_TRANSCRIPTION_DEFAUT = 'gemini-2.5-flash';

    /**
     * Soixante secondes pour une transcription. Cinq minutes de voix, c'est environ
     * 10 000 jetons d'entrée et 1 500 de sortie : un modèle Flash rend cela en dix à
     * trente secondes. Au-delà, il ne rendra plus rien d'utile, et l'écran attend.
     */
    public const float DELAI_TRANSCRIPTION_SECONDES = 60.0;

    public function __construct(
        #[Autowire(env: 'default::HERITAGE_GEMINI_MODELE_ASSISTANT')]
        private ?string $modele = null,
        #[Autowire(env: 'default::HERITAGE_GEMINI_MODELE_TRANSCRIPTION')]
        private ?string $modeleDeTranscription = null,
    ) {
    }

    public function modele(): string
    {
        $modele = trim($this->modele ?? '');

        return '' === $modele ? self::MODELE_DEFAUT : $modele;
    }

    public function modeleDeTranscription(): string
    {
        $modele = trim($this->modeleDeTranscription ?? '');

        return '' === $modele ? self::MODELE_TRANSCRIPTION_DEFAUT : $modele;
    }
}
