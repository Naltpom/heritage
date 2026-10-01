<?php

declare(strict_types=1);

namespace App\Module\Assistant\Service;

use App\Module\Assistant\Contract\ArbrePourLAssistant;
use App\Module\Assistant\Contract\PersonneDeLArbre;
use App\Module\Core\Security\CercleDeVisibiliteInterface;

/**
 * Les fiches qu'une réponse cite : `[Marie Becquelin](personne:12)` dans le texte, et la
 * liste des identifiants à côté.
 *
 * ── Pourquoi une mention dans le texte, et pas seulement une liste ──────────────
 *
 * « Marie est la fille de Jean et de Rose » : une liste de trois fiches sous la réponse
 * ne dit pas laquelle est Rose. Le lien doit être **dans** la phrase, à l'endroit du nom.
 * La forme est celle d'un lien Markdown, que le modèle écrit naturellement ; le front la
 * découpe en texte et en liens (`components/proposition/assistant/questions.ts`), sans
 * jamais interpréter le texte comme du HTML.
 *
 * ── Ce que le serveur vérifie, à l'écriture comme à chaque lecture ──────────────
 *
 * - **Une mention vers une fiche qui n'existe pas** perd son lien et garde son texte :
 *   un numéro inventé ne doit pas mener à la fiche de quelqu'un d'autre.
 * - **Une mention vers une personne que le lecteur ne voit pas en entier** garde son lien
 *   — la fiche réduite est visible de tous, comme le nœud du graphe — mais son libellé
 *   devient le masque. Le modèle n'a pas reçu ce nom ({@see OutilsDeConsultation}) ; le
 *   contrôle tient pour le jour où un cercle **rétrécit** : une réponse gardée d'hier
 *   peut citer quelqu'un qu'on ne voit plus aujourd'hui (ADR 0008, § 3 — « le second
 *   contrôle est celui qui compte »).
 * - **Les identifiants** sont ceux que le modèle déclare et ceux qu'il mentionne, réduits
 *   aux fiches qui existent, trente au plus.
 */
final readonly class MentionsDeFiches
{
    /** `[libellé](personne:12)` — un libellé d'une ligne, un identifiant entier. */
    public const string MOTIF = '/\[([^\[\]\n]{1,160})\]\(personne:(\d{1,9})\)/u';

    /** Au-delà, la réponse est une énumération : le texte la porte, pas la liste. */
    public const int PERSONNES_MAXIMALES = 30;

    /** Une réponse plus longue est coupée : c'est un paragraphe qu'on attend, pas un dossier. */
    public const int TEXTE_MAXIMAL = 6000;

    public function __construct(
        private ArbrePourLAssistant $arbre,
        private VueDuLecteur $vue,
    ) {
    }

    /**
     * Le texte, mentions vérifiées, et les fiches citées qui existent.
     *
     * @param array<mixed> $declares les identifiants que le modèle annonce
     *
     * @return array{texte: string, personIds: list<int>}
     */
    public function nettoyer(string $texte, array $declares): array
    {
        /** @var array<int, PersonneDeLArbre|null> $connues */
        $connues = [];
        $trouver = function (int $id) use (&$connues): ?PersonneDeLArbre {
            if (!\array_key_exists($id, $connues)) {
                $connues[$id] = $this->arbre->personne($id);
            }

            return $connues[$id];
        };

        $texte = trim($texte);

        if (mb_strlen($texte) > self::TEXTE_MAXIMAL) {
            $texte = rtrim(mb_substr($texte, 0, self::TEXTE_MAXIMAL - 1)).'…';
        }

        $mentionnes = [];
        $texte = (string) preg_replace_callback(self::MOTIF, function (array $m) use ($trouver, &$mentionnes): string {
            $id = (int) $m[2];
            $personne = $trouver($id);

            if (null === $personne) {
                return $m[1];
            }

            $mentionnes[] = $id;

            return $this->vue->voitEnEntier($personne)
                ? $m[0]
                : \sprintf('[%s](personne:%d)', CercleDeVisibiliteInterface::NOM_MASQUE, $id);
        }, $texte);

        $ids = [];
        foreach ([...$mentionnes, ...$declares] as $brut) {
            // Gemini rend les entiers JSON tantôt `12`, tantôt `12.0`.
            $id = \is_float($brut) || (\is_string($brut) && ctype_digit($brut)) ? (int) $brut : $brut;

            if (\is_int($id) && !isset($ids[$id]) && null !== $trouver($id)) {
                $ids[$id] = true;
            }
        }

        return [
            'texte' => $texte,
            'personIds' => \array_slice(array_keys($ids), 0, self::PERSONNES_MAXIMALES),
        ];
    }

    /**
     * Les fiches citées, telles que le lecteur a le droit de les voir **maintenant**
     * (`PersonneCitee` du front) — une fiche supprimée depuis disparaît de la liste.
     *
     * @param list<int> $ids
     *
     * @return list<array{personId: int, displayName: string, visibilite: string, ligneDates?: string}>
     */
    public function personnes(array $ids): array
    {
        $personnes = [];

        foreach ($ids as $id) {
            $personne = $this->arbre->personne($id);

            if (null !== $personne) {
                $personnes[] = $this->vue->personneCitee($personne);
            }
        }

        return $personnes;
    }
}
