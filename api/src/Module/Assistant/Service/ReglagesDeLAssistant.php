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
     * Quatre-vingt-dix secondes pour toute l'analyse, tous tours compris.
     *
     * Le membre attend devant un écran qui le lui dit (« 20 à 60 s »). Au-delà, il a
     * rechargé la page, et la réponse ne servirait plus personne.
     */
    public const float DELAI_GLOBAL_SECONDES = 90.0;

    public function __construct(
        #[Autowire(env: 'default::HERITAGE_GEMINI_MODELE_ASSISTANT')]
        private ?string $modele = null,
    ) {
    }

    public function modele(): string
    {
        $modele = trim($this->modele ?? '');

        return '' === $modele ? self::MODELE_DEFAUT : $modele;
    }
}
