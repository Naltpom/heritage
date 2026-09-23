<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Core\Enum\SubjectKind;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Question\Contract\FicheEtudiee;
use App\Module\Question\Enum\NatureDeLacune;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  LES TROUS DE L'ARBRE, TROUVÉS PAR DES RÈGLES ÉCRITES — PAS PAR UN MODÈLE.
 * ═══════════════════════════════════════════════════════════════════════════════.
 *
 * C'est la moitié de l'ADR 0008 qui **ne se délègue pas** (§ 4). Le modèle de langage met
 * en français ce que cette classe a trouvé ; il n'ouvre aucune question et n'en ferme
 * aucune. Trois conséquences, et ce sont les raisons du découpage :
 *
 * - **la page fonctionne sans Gemini** — une lacune détectée a toujours une phrase ;
 * - **rien d'inventé n'entre dans l'arbre** — une question porte sur une colonne
 *   réellement vide, jamais sur une lacune hallucinée ;
 * - **la dépense suit les lacunes**, pas les chargements de page.
 *
 * Cette classe est **pure** : aucune dépendance, aucune base, aucun réglage injecté. Tout
 * entre par les paramètres. C'est ce qui permet de l'éprouver cas par cas, sans conteneur
 * et sans fixtures — et la détection est la pièce dont la justesse se juge sur des
 * exemples, pas sur une lecture.
 *
 * ── LA RÈGLE QUI COMPTE : LA MÉMOIRE VIVANTE ────────────────────────────────────
 *
 * Sans elle, l'arbre rendrait des centaines de questions justes et **inutiles** : « qui
 * était la mère de cet homme né en 1743 ? » est une lacune réelle, à laquelle personne ne
 * peut répondre. Une page qui s'ouvre là-dessus ne se rouvre pas.
 *
 * > **On ne pose de question que sur quelqu'un dont la famille peut avoir entendu
 * > parler de vive voix.**
 *
 * Deux cercles concentriques, et **pas un de plus** :
 *
 * 1. **la mémoire directe** — la personne est vivante, ou l'une de ses bornes de date
 *    (naissance ou décès) tombe après l'année plancher. C'est « quelqu'un a pu la
 *    connaître » ;
 * 2. **une seule passe de plus** — parent, enfant ou conjoint de quelqu'un du premier
 *    cercle. C'est « on m'en a parlé » : le grand-père mort avant ma naissance, dont mon
 *    père m'a parlé.
 *
 * **Une seule passe, en dernier, et elle ne propage rien.** C'est mot pour mot la
 * discipline des conjoints de l'ADR 0003, pour la même raison : itérer jusqu'à stabilité
 * remonterait l'arbre entier de proche en proche et annulerait la règle sans qu'on s'en
 * aperçoive — on relirait le code en croyant y lire un filtre, et il n'en resterait rien.
 *
 * L'année plancher se calcule depuis un **nombre d'années**, jamais depuis une année
 * écrite en dur : une constante `1936` est juste une fois et vieillit d'un an par an, en
 * silence.
 */
final class DetecteurDeLacunes
{
    /**
     * Balaie l'arbre et rend les lacunes, **dans l'ordre des fiches reçues**.
     *
     * L'ordre est stable par construction : il vient du port, qui trie par identifiant.
     * Un ordre instable ferait varier la page d'un chargement à l'autre sans qu'aucune
     * donnée n'ait changé — et l'on chercherait la cause dans la base.
     *
     * @param list<FicheEtudiee> $fiches            **toutes** les fiches, non filtrées
     * @param array<int, int>    $mediasParPersonne les personnes sans média en sont absentes
     * @param bool               $photosDemandables `false` désactive la nature
     *                                              `aucune_photo` — voir plus bas
     * @param int                $anneePlancher     la borne de la mémoire vivante
     *
     * @return list<Lacune>
     */
    public function detecter(
        array $fiches,
        array $mediasParPersonne,
        bool $photosDemandables,
        int $anneePlancher,
    ): array {
        $memoire = $this->memoireVivante($fiches, $anneePlancher);
        $lacunes = [];

        foreach ($fiches as $fiche) {
            if (!isset($memoire[$fiche->id])) {
                continue;
            }

            foreach ($this->naturesDe($fiche, $mediasParPersonne, $photosDemandables) as $nature) {
                $lacunes[] = new Lacune($nature, SubjectKind::Personne, $fiche->id, $fiche->nomAffiche);
            }
        }

        return $lacunes;
    }

    /**
     * Les lacunes d'**une** fiche.
     *
     * @param array<int, int> $mediasParPersonne
     *
     * @return list<NatureDeLacune>
     */
    private function naturesDe(FicheEtudiee $fiche, array $mediasParPersonne, bool $photosDemandables): array
    {
        $natures = [];

        if ($photosDemandables && 0 === ($mediasParPersonne[$fiche->id] ?? 0)) {
            $natures[] = NatureDeLacune::AucunePhoto;
        }

        if ('' === trim($fiche->prenoms)) {
            $natures[] = NatureDeLacune::PrenomInconnu;
        }

        if ('' === trim($fiche->nom)) {
            $natures[] = NatureDeLacune::NomInconnu;
        }

        if (null === $fiche->mereId) {
            $natures[] = NatureDeLacune::MereInconnue;
        }

        if (null === $fiche->pereId) {
            $natures[] = NatureDeLacune::PereInconnu;
        }

        // ── Les dates : on ne demande jamais le LIEU d'un événement qu'on ne sait pas
        // DATER. Poser les deux questions sur la même fiche en produirait une seconde,
        // absurde pour qui la lit — et un membre qui lit une question absurde cesse de
        // lire les suivantes.
        if (!$this->estDatee($fiche->naissance)) {
            $natures[] = NatureDeLacune::DateDeNaissanceInconnue;
        } elseif (!$this->estSituee($fiche->naissance)) {
            $natures[] = NatureDeLacune::LieuDeNaissanceInconnu;
        }

        // Une personne vivante n'a pas de décès à documenter, et le lui demander serait
        // le genre de maladresse qu'aucune excuse ne rattrape. `vivante` vient de
        // `ReglePersonneVivante`, jamais de `tree_person.deceased` : « un décès inconnu
        // vaut vivant » ferme ici aussi.
        if (!$fiche->vivante) {
            if (!$this->estDatee($fiche->deces)) {
                $natures[] = NatureDeLacune::DateDeDecesInconnue;
            } elseif (!$this->estSituee($fiche->deces)) {
                $natures[] = NatureDeLacune::LieuDeDecesInconnu;
            }
        }

        return $natures;
    }

    /**
     * Un bloc date porte-t-il **une borne** ?
     *
     * `min` **ou** `max`, et pas `raw` : une chaîne GEDCOM qu'aucune forme connue n'a su
     * analyser (§ 0.5 du modèle) est une provenance, pas une date. La demander de nouveau
     * est exactement ce qu'il faut faire — c'est même le cas le plus utile du lot, celui
     * où l'import a gardé quelque chose que personne n'a pu lire.
     *
     * On ne regarde pas non plus `qualifier` : `entre 1901 et 1909` est une date, et
     * redemander une fourchette déjà établie ferait douter de ce qui est écrit.
     */
    private function estDatee(UncertainDate $date): bool
    {
        return null !== $date->getMin() || null !== $date->getMax();
    }

    private function estSituee(UncertainDate $date): bool
    {
        return '' !== trim($date->getPlace() ?? '');
    }

    /**
     * Qui peut encore faire l'objet d'une question — voir l'en-tête pour le mécanisme.
     *
     * @param list<FicheEtudiee> $fiches
     *
     * @return array<int, true>
     */
    private function memoireVivante(array $fiches, int $anneePlancher): array
    {
        $directe = [];

        foreach ($fiches as $fiche) {
            if ($this->dansLaMemoireDirecte($fiche, $anneePlancher)) {
                $directe[$fiche->id] = true;
            }
        }

        // ── UNE SEULE PASSE. JAMAIS UN POINT FIXE. ──────────────────────────────────
        // On part de `$directe` — figé — et l'on écrit dans `$etendue`. Boucler sur
        // `$etendue` en la modifiant remonterait l'arbre de proche en proche jusqu'à la
        // racine, et le filtre ne filtrerait plus rien. C'est la discipline des conjoints
        // de l'ADR 0003, et elle se casse exactement de la même façon.
        $etendue = $directe;

        foreach ($fiches as $fiche) {
            if (isset($etendue[$fiche->id])) {
                continue;
            }

            foreach ([$fiche->pereId, $fiche->mereId] as $parent) {
                if (null !== $parent && isset($directe[$parent])) {
                    $etendue[$fiche->id] = true;
                    continue 2;
                }
            }

            foreach ([...$fiche->enfantIds, ...$fiche->conjointIds] as $proche) {
                if (isset($directe[$proche])) {
                    $etendue[$fiche->id] = true;
                    continue 2;
                }
            }
        }

        return $etendue;
    }

    private function dansLaMemoireDirecte(FicheEtudiee $fiche, int $anneePlancher): bool
    {
        if ($fiche->vivante) {
            return true;
        }

        foreach ([$fiche->deces, $fiche->naissance] as $bloc) {
            foreach ([$bloc->getMax(), $bloc->getMin()] as $borne) {
                if (null !== $borne && (int) $borne->format('Y') >= $anneePlancher) {
                    return true;
                }
            }
        }

        return false;
    }
}
