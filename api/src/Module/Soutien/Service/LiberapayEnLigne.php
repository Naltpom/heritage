<?php

declare(strict_types=1);

namespace App\Module\Soutien\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * `GET https://liberapay.com/<pseudo>/public.json` — le profil public du compte de soutien.
 *
 * ## Ce qui sort
 *
 * **Le pseudo du compte, et rien d'autre.** Aucune donnée de la famille, aucun identifiant
 * de lecteur : Liberapay voit qu'une instance de Heritage lit un profil qui est de toute
 * façon public. Pas de clé, pas de compte développeur.
 *
 * ## Ce qu'on lit
 *
 * ```json
 * { "receiving": { "amount": "32.50", "currency": "EUR" }, "npatrons": 4, … }
 * ```
 *
 * `receiving` est un montant **par semaine**. Il manque, ou vaut `null`, quand le
 * propriétaire a choisi de masquer ce qu'il reçoit : on le lit alors comme une panne, et la
 * page dit qu'aucun relevé n'est disponible — c'est à l'administrateur de rendre le
 * montant public dans ses réglages Liberapay.
 *
 * ## Le délai
 *
 * Quatre secondes, connexion comprise : la lecture se fait au premier affichage de la page
 * dans la journée, et c'est un membre qui attend derrière. Pas de réessai — l'appelant
 * retient l'échec et ne repique pas avant une heure.
 */
final class LiberapayEnLigne implements SourceLiberapay
{
    public const string URL = 'https://liberapay.com/%s/public.json';

    private const float DELAI_SECONDES = 4.0;

    public function __construct(
        private readonly HttpClientInterface $http,
    ) {
    }

    public function profil(string $pseudo): ProfilLiberapay
    {
        try {
            $reponse = $this->http->request('GET', \sprintf(self::URL, rawurlencode($pseudo)), [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::DELAI_SECONDES,
                'max_duration' => self::DELAI_SECONDES,
            ]);

            // `toArray()` lève sur un 3xx/4xx/5xx comme sur un corps qui n'est pas du JSON :
            // un pseudo inconnu (`404`) est une panne comme une autre, vue de la page.
            $corps = $reponse->toArray();
        } catch (ExceptionInterface $e) {
            throw new LiberapayIndisponible('Liberapay ne répond pas : '.$e->getMessage(), 0, $e);
        }

        $recu = $corps['receiving'] ?? null;
        if (!\is_array($recu) || !isset($recu['amount'], $recu['currency'])
            || !\is_string($recu['amount']) || !\is_string($recu['currency'])) {
            throw new LiberapayIndisponible('Le profil Liberapay ne publie pas ce qu’il reçoit.');
        }

        $centimes = self::enCentimes($recu['amount']);
        if (null === $centimes || 1 !== preg_match('/^[A-Z]{3}$/', $recu['currency'])) {
            throw new LiberapayIndisponible('Montant Liberapay illisible : '.$recu['amount'].' '.$recu['currency']);
        }

        $donateurs = $corps['npatrons'] ?? 0;

        return new ProfilLiberapay($centimes, $recu['currency'], \is_int($donateurs) ? max(0, $donateurs) : 0);
    }

    /** `"829.48"` → `82948`. Par la chaîne, jamais par un `float`. */
    private static function enCentimes(string $montant): ?int
    {
        if (1 !== preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $montant, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
