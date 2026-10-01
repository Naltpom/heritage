<?php

declare(strict_types=1);

namespace App\Module\Proposal\Contract;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * CE QUE DÉFAIRE UN LIEN FERAIT À L'ARBRE — mesuré par `Tree`, écrit dans l'op.
 *
 * Le pendant, pour un lien, de {@see InventaireDeSuppression} pour une fiche, et pour
 * les mêmes trois usages :
 *
 * 1. **au dépôt**, il est écrit dans `prp_change_op.old_value` : c'est l'état de la
 *    famille que l'auteur avait sous les yeux en demandant ;
 * 2. **à la lecture d'administration**, il est remesuré et rendu en `valeurCourante`,
 *    avec la phrase de {@see enUneLigne()} en `libelleDiff` ;
 * 3. **à la validation**, les deux sont comparés. S'ils diffèrent, la demande est
 *    **caduque** : un enfant est arrivé dans l'union, une date de mariage a été posée, le
 *    lien a déjà été défait par quelqu'un d'autre — et valider ferait autre chose que ce
 *    que l'administrateur a lu.
 *
 * ## Pourquoi pas la seule `base_version` de la famille
 *
 * Elle est posée, et elle compte : une famille touchée depuis le dépôt fait rejouer la
 * demande sur la version courante au lieu de passer inaperçue. Mais le verrou porte sur
 * la **ligne** `tree_family`, et la moitié de ce qui change le sens d'un retrait n'y est
 * pas : un enfant rattaché vit dans `tree_child`, une note dans `tree_note`, un média dans
 * `med_media`. Une version inchangée ne dit donc pas que « retirer cet époux » laisse
 * toujours les mêmes enfants à un seul parent. La mesure, si.
 *
 * ## Les champs, et ce que chacun promet
 *
 * - `enfantsRestants` : les **autres** enfants de la famille après le retrait. Pour un
 *   conjoint, ce sont les enfants qui n'auront plus que l'autre époux pour parent — la
 *   conséquence que l'administrateur doit lire avant de cliquer ;
 * - `conjointRestant` : l'époux qui reste dans l'union quand un conjoint en sort ;
 * - `rattacheA` : quand un enfant ne perd qu'un parent (`parentId`), la famille où il
 *   restera l'enfant de l'autre — calculée par les règles de `etablirFiliation()` ;
 * - `familleSupprimee` : l'union ne porte plus rien après le retrait — ni enfant, ni
 *   date, ni note, citation, fait ou média, ni second époux — et disparaît. **Une famille
 *   qui porte la moindre donnée ne se supprime jamais** : on en retire la personne, rien
 *   de plus ;
 * - `blocage` : la raison pour laquelle ce retrait ne peut pas se faire, ou `null`. Un
 *   retrait bloqué est refusé au dépôt (`422`) ; un blocage apparu après le dépôt rend la
 *   mesure différente, donc la demande caduque.
 *
 * ## L'ordre des clés est un contrat
 *
 * Même règle, même raison que pour un bloc date ou un inventaire : la comparaison se fait
 * sur le tableau entier, et `JSONB` range les clés à sa façon. Les deux côtés repassent
 * par {@see depuisJson()} puis {@see versJson()}.
 */
final readonly class RetraitDeLien
{
    public function __construct(
        /** `enfant_de` ou `conjoint_de` — la valeur de `LinkKind`. */
        public string $nature,
        public int $personneId,
        public string $personneLibelle,
        public int $familleId,
        public string $familleLibelle,
        public ?int $parentId = null,
        public ?string $parentLibelle = null,
        public ?string $conjointRestant = null,
        public ?string $rattacheA = null,
        public int $enfantsRestants = 0,
        public bool $familleSupprimee = false,
        public ?string $blocage = null,
    ) {
    }

    public function estBloque(): bool
    {
        return null !== $this->blocage;
    }

    /**
     * La phrase lue par l'administrateur avant de cliquer, et gardée par l'historique
     * après — **la même**, pour que les deux se relisent ensemble six mois plus tard.
     */
    public function enUneLigne(): string
    {
        $phrase = match (true) {
            'conjoint_de' === $this->nature => \sprintf(
                'Lien retiré : %s ne fait plus partie de l’union « %s »',
                $this->personneLibelle,
                $this->familleLibelle,
            ),
            null !== $this->parentLibelle => \sprintf(
                'Lien retiré : %s n’est plus enfant de %s',
                $this->personneLibelle,
                $this->parentLibelle,
            ),
            default => \sprintf(
                'Lien retiré : %s n’est plus enfant de « %s »',
                $this->personneLibelle,
                $this->familleLibelle,
            ),
        };

        $suites = [];

        if (null !== $this->rattacheA) {
            $suites[] = \sprintf('reste enfant de « %s »', $this->rattacheA);
        }

        if ('conjoint_de' === $this->nature && $this->enfantsRestants > 0 && null !== $this->conjointRestant) {
            $suites[] = \sprintf(
                1 === $this->enfantsRestants ? 'son enfant n’y garde que %s pour parent' : 'ses %2$d enfants n’y gardent que %1$s pour parent',
                $this->conjointRestant,
                $this->enfantsRestants,
            );
        }

        if ($this->familleSupprimee) {
            $suites[] = 'l’union, qui ne porte plus rien, est supprimée';
        }

        return $phrase.([] === $suites ? '' : ' ; '.implode(' ; ', $suites)).'.';
    }

    /**
     * La forme stockée dans `old_value`, **clés dans cet ordre exact**.
     *
     * @return array<string, mixed>
     */
    public function versJson(): array
    {
        return [
            'nature' => $this->nature,
            'personneId' => $this->personneId,
            'personneLibelle' => $this->personneLibelle,
            'familleId' => $this->familleId,
            'familleLibelle' => $this->familleLibelle,
            'parentId' => $this->parentId,
            'parentLibelle' => $this->parentLibelle,
            'conjointRestant' => $this->conjointRestant,
            'rattacheA' => $this->rattacheA,
            'enfantsRestants' => $this->enfantsRestants,
            'familleSupprimee' => $this->familleSupprimee,
            'blocage' => $this->blocage,
        ];
    }

    /** @param array<string, mixed> $json */
    public static function depuisJson(array $json): self
    {
        return new self(
            self::chaine($json['nature'] ?? null) ?? '',
            self::entier($json['personneId'] ?? null),
            self::chaine($json['personneLibelle'] ?? null) ?? '',
            self::entier($json['familleId'] ?? null),
            self::chaine($json['familleLibelle'] ?? null) ?? '',
            \is_int($json['parentId'] ?? null) ? $json['parentId'] : null,
            self::chaine($json['parentLibelle'] ?? null),
            self::chaine($json['conjointRestant'] ?? null),
            self::chaine($json['rattacheA'] ?? null),
            self::entier($json['enfantsRestants'] ?? null),
            true === ($json['familleSupprimee'] ?? null),
            self::chaine($json['blocage'] ?? null),
        );
    }

    /** Le lien désigné, sans la mesure — ce que l'applicateur redonne à `Tree`. */
    public function lien(): LienARetirer
    {
        return LienARetirer::depuisJson($this->versJson());
    }

    private static function chaine(mixed $valeur): ?string
    {
        return \is_string($valeur) ? $valeur : null;
    }

    private static function entier(mixed $valeur): int
    {
        return \is_int($valeur) ? $valeur : 0;
    }
}
