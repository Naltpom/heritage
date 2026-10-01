<?php

declare(strict_types=1);

namespace App\Tests\Assistant\Double;

use App\Module\Assistant\Service\TransportDuModele;

/**
 * Le modèle Gemini, **scripté** : il rejoue, dans l'ordre, les réponses qu'un test lui a
 * données, et garde les requêtes reçues pour qu'on les inspecte.
 *
 * Son état est **statique**, pour la raison qu'expose `Tests\Proposal\Double\ArbreEnMemoire` :
 * le client HTTP de test rebâtit le noyau à chaque requête, et le script doit survivre à
 * ce redémarrage. `oublierTout()` le remet à zéro, dans le `setUp()` des tests.
 */
final class ModeleScripte implements TransportDuModele
{
    /** @var list<array<string, mixed>|\Throwable|(\Closure(): array<string, mixed>)> */
    private static array $reponses = [];

    /** @var list<array{modele: string, charge: array<string, mixed>}> */
    private static array $requetes = [];

    public static function oublierTout(): void
    {
        self::$reponses = [];
        self::$requetes = [];
    }

    /**
     * Une réponse peut être une **fermeture** : elle est appelée au moment où le modèle
     * « répond », et rend la réponse. C'est le moyen de faire arriver quelque chose
     * **pendant** l'analyse — un refus par une autre requête, par exemple.
     *
     * @param array<string, mixed>|\Throwable|(\Closure(): array<string, mixed>) ...$reponses
     */
    public static function repondra(array|\Throwable|\Closure ...$reponses): void
    {
        foreach ($reponses as $reponse) {
            self::$reponses[] = $reponse;
        }
    }

    /** @return list<array{modele: string, charge: array<string, mixed>}> */
    public static function requetes(): array
    {
        return self::$requetes;
    }

    public function generer(string $modele, array $charge, float $delai): array
    {
        self::$requetes[] = ['modele' => $modele, 'charge' => $charge];

        $reponse = array_shift(self::$reponses)
            ?? throw new \LogicException('Le script du modèle est épuisé : le test attendait moins d’appels.');

        if ($reponse instanceof \Throwable) {
            throw $reponse;
        }

        if ($reponse instanceof \Closure) {
            return $reponse();
        }

        return $reponse;
    }

    /**
     * Une réponse Gemini qui appelle un outil.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function appel(string $nom, array $arguments): array
    {
        return ['candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $nom, 'args' => $arguments]]]],
            'finishReason' => 'STOP',
        ]]];
    }
}
