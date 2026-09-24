<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LES DEMANDES COMPLÈTES RESTENT CÔTÉ SERVEUR — c'est ce qui permet de ne rien
 *  masquer de travers.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * L'assistant peut proposer une modification sur une personne que le membre ne voit
 * qu'en restreint (décision du commanditaire). Le corps complet porte alors un `oldValue`
 * et un `baseVersion` lus en base — des données que le navigateur **ne doit pas
 * recevoir**. On ne peut donc pas faire reposter le corps par le front, comme le fait le
 * formulaire : on le **garde ici**, sous un identifiant opaque, et c'est le serveur qui
 * dépose au clic du membre.
 *
 * ── Ce qui est gardé, et ce qui ne l'est pas ────────────────────────────────────
 *
 * Gardés : les corps vérifiés, leur titre et leur justification, les clés déjà déposées.
 * **Pas** les documents, pas le texte du membre, pas la réponse du modèle : l'analyse ne
 * stocke aucun octet de ce que le membre a joint (contrat de l'assistant).
 *
 * ── Pourquoi le cache, et pourquoi deux heures ──────────────────────────────────
 *
 * Une analyse est un état **transitoire** : elle vaut le temps d'une relecture. Une
 * table la ferait survivre, avec des `oldValue` de vivants hors cercle, bien après que
 * personne n'en ait l'usage — et il faudrait une tâche pour la purger. Le cache se périme
 * seul. Deux heures laissent le temps de relire vingt demandes, et pas celui de déposer
 * demain une demande dont le `baseVersion` a vieilli d'une journée.
 *
 * ── Pourquoi l'utilisateur est dans la clé ──────────────────────────────────────
 *
 * Un identifiant d'analyse deviné, ou recopié depuis l'écran d'un autre, ne donne rien :
 * la clé du cache porte le compte, et une analyse d'autrui n'est **pas trouvée** — `404`,
 * pas `403`, pour ne pas confirmer qu'elle existe.
 */
final readonly class MemoireDesAnalyses
{
    public const int DUREE_SECONDES = 7200;

    /** 128 bits d'aléa en hexadécimal : opaque, et impossible à énumérer. */
    public const string FORME_DE_L_IDENTIFIANT = '/^[a-f0-9]{32}$/';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, array{corps: array<string, mixed>, titre: string, justification: string}> $demandes par clé (`d1`, `d2`…)
     *
     * @return string l'identifiant de l'analyse
     */
    public function garder(int $userId, array $demandes): string
    {
        $identifiant = bin2hex(random_bytes(16));

        $this->ecrire($userId, $identifiant, ['demandes' => $demandes, 'deposees' => []]);

        return $identifiant;
    }

    /**
     * @return array{demandes: array<string, array{corps: array<string, mixed>, titre: string, justification: string}>, deposees: array<string, int|null>}|null
     */
    public function lire(int $userId, string $identifiant): ?array
    {
        if (1 !== preg_match(self::FORME_DE_L_IDENTIFIANT, $identifiant)) {
            return null;
        }

        $element = $this->cache->getItem(self::cle($userId, $identifiant));
        $valeur = $element->isHit() ? $element->get() : null;

        if (!\is_array($valeur) || !\is_array($valeur['demandes'] ?? null) || !\is_array($valeur['deposees'] ?? null)) {
            return null;
        }

        /** @var array{demandes: array<string, array{corps: array<string, mixed>, titre: string, justification: string}>, deposees: array<string, int|null>} $valeur */
        return $valeur;
    }

    /**
     * Note qu'une clé est déposée (ou en cours de dépôt : `null`), ou l'oublie
     * (`$deposee = false`) si le dépôt a échoué.
     *
     * La durée de vie est **réarmée** : elle court depuis la dernière action, pas depuis
     * l'analyse — un membre en train de déposer ne doit pas perdre la fin de sa liste.
     */
    public function noterLeDepot(int $userId, string $identifiant, string $cle, int|false|null $demandeId): void
    {
        $analyse = $this->lire($userId, $identifiant);

        if (null === $analyse) {
            return;
        }

        if (false === $demandeId) {
            unset($analyse['deposees'][$cle]);
        } else {
            $analyse['deposees'][$cle] = $demandeId;
        }

        $this->ecrire($userId, $identifiant, $analyse);
    }

    /** @param array<string, mixed> $valeur */
    private function ecrire(int $userId, string $identifiant, array $valeur): void
    {
        $element = $this->cache->getItem(self::cle($userId, $identifiant));
        $element->set($valeur);
        $element->expiresAfter(self::DUREE_SECONDES);

        $this->cache->save($element);
    }

    private static function cle(int $userId, string $identifiant): string
    {
        return \sprintf('assistant.analyse.%d.%s', $userId, $identifiant);
    }
}
