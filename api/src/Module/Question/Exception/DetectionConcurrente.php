<?php

declare(strict_types=1);

namespace App\Module\Question\Exception;

/**
 * Deux requêtes ont détecté la même lacune pour le même compte **en même temps**, et
 * `uniq_qst_question_lacune` a tranché.
 *
 * ## Pourquoi on laisse ce cas arriver plutôt que de le prévenir
 *
 * La page **écrit en la lisant** : elle rejoue la détection et crée les questions
 * manquantes. Deux ouvertures simultanées — un double-clic, deux onglets — peuvent donc
 * insérer la même signature. L'index unique est précisément là pour ça, et il fait son
 * travail : **aucune question en double n'entre en base.**
 *
 * Prévenir le cas aurait demandé un verrou sur le compte pendant la détection. Le prix
 * en est connu : une page qui se bloque sur elle-même au double-clic, pour un incident
 * dont la réparation tient en un rechargement — la seconde tentative trouve les lignes
 * déjà créées et n'en crée aucune.
 *
 * ## Ce que l'appelant en fait
 *
 * Un **409 avec « réessayez »**, comme `verrou_concurrent` ailleurs dans ce produit. Pas
 * un 500 : rien n'est cassé, et rien n'est perdu.
 *
 * On ne rattrape **pas** l'erreur ici pour rejouer la lecture dans la foulée : après un
 * `flush()` en échec, Doctrine **ferme** l'`EntityManager`, et tout ce qu'on tenterait
 * ensuite avec lui lèverait à son tour — on transformerait un incident lisible en une
 * pile d'erreurs dont la cause première aurait disparu.
 */
final class DetectionConcurrente extends \RuntimeException
{
    public static function pourLeCompte(int $userId, ?\Throwable $precedente = null): self
    {
        return new self(
            \sprintf('Deux détections concurrentes pour le compte %d ; la seconde n\'a rien écrit.', $userId),
            0,
            $precedente,
        );
    }
}
