<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

use App\Module\Proposal\Enum\LinkKind;

/**
 * Le lien qu'une demande `retrait_lien` désigne — **ce qu'on veut défaire, pas comment**.
 *
 * Trois identifiants et une nature, rien d'autre : c'est `Tree` qui sait ce que défaire
 * ce lien emporte (une famille vidée, un enfant qui garde son autre parent, un couple qui
 * entrerait en collision avec une famille déjà là). La demande ne transporte donc pas
 * d'effet, elle le fait **mesurer** — voir {@see RetraitDeLien}.
 *
 * ── Les deux natures, lues comme `LinkKind` les lit déjà ────────────────────────────
 *
 * | `nature` | `personneId` | `familleId` | `parentId` |
 * |---|---|---|---|
 * | `enfant_de` | l'enfant | sa famille de parents | facultatif : **le** parent qu'il perd |
 * | `conjoint_de` | l'époux qui quitte l'union | l'union | — |
 *
 * `parent_de` n'est pas une troisième nature : « X n'est plus le parent de C » se dit
 * `enfant_de` avec `personneId = C` et `parentId = X`. Deux écritures pour la même arête
 * feraient deux chemins à tenir cohérents, et c'est précisément ce que `LinkKind` évite
 * déjà à l'ajout en lisant toujours « du sujet vers l'autre ».
 *
 * **`parentId` change ce que le retrait veut dire.** Sans lui, l'enfant quitte la famille
 * et perd **les deux** parents du couple — « il n'est pas leur enfant ». Avec lui, il ne
 * perd que celui-là, et reste l'enfant de l'autre, rattaché par
 * {@see \App\Module\Proposal\Service\Port\PortArbre::etablirFiliation()} — le même
 * chemin qu'un ajout, donc le même invariant : pas de familles parentales doublées.
 */
final readonly class LienARetirer
{
    public function __construct(
        public LinkKind $nature,
        public int $personneId,
        public int $familleId,
        public ?int $parentId = null,
    ) {
        if (LinkKind::ParentDe === $nature) {
            throw new \InvalidArgumentException('Un retrait « parent_de » s’écrit « enfant_de » avec parentId.');
        }

        if (null !== $parentId && LinkKind::EnfantDe !== $nature) {
            throw new \InvalidArgumentException('Seul un retrait « enfant_de » nomme un parent.');
        }
    }

    /**
     * Le lien, relu depuis l'`old_value` d'un op — la seule chose que l'applicateur a
     * entre les mains.
     *
     * @param array<string, mixed> $json la forme de {@see RetraitDeLien::versJson()}
     */
    public static function depuisJson(array $json): self
    {
        $parent = $json['parentId'] ?? null;

        return new self(
            LinkKind::from(\is_string($json['nature'] ?? null) ? $json['nature'] : ''),
            \is_int($json['personneId'] ?? null) ? $json['personneId'] : 0,
            \is_int($json['familleId'] ?? null) ? $json['familleId'] : 0,
            \is_int($parent) ? $parent : null,
        );
    }
}
