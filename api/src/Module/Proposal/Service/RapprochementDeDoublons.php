<?php

declare(strict_types=1);

namespace App\Module\Proposal\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\Enum\Sex;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * Deux personnes sont-elles **probablement la même** ? — la règle du rapprochement
 * d'avant dépôt (§ 6.3 ter du contrat d'API).
 *
 * ── Ce qu'elle reprend de la clé de l'administrateur ─────────────────────────────
 *
 * La file d'administration rapproche deux demandes par **nom + année de naissance**
 * ({@see CleDeDoublon}, ADR 0004). Cette règle-ci en garde la normalisation, au caractère
 * près — même patronyme replié, même **premier** prénom —, et le parti pris qui l'écrit :
 * pas de phonétique, pas de distance d'édition. « Mieux vaut manquer un rapprochement que
 * le rendre inutile » : un avertissement qui crie au doublon à chaque saisie apprend au
 * membre à cliquer « C'est bien une nouvelle personne » sans lire.
 *
 * ── Ce qu'elle ajoute, et pourquoi la clé ne suffisait pas ───────────────────────
 *
 * La clé est une **égalité** : `sureau|marne|1901`. Elle ne voit ni la fourchette
 * (« entre 1901 et 1909 » contre « 1905 »), ni le nom d'usage (une épouse saisie sous le
 * nom de son mari), ni une date inconnue d'un seul côté — trois cas courants dès qu'on
 * compare une saisie à l'arbre, que l'ADR 0004 nomme d'ailleurs : « avec la tolérance
 * qu'impose une fourchette de dates ». D'où :
 *
 * - **les dates se comparent à l'année, en recouvrement** : deux dates connues qui ne
 *   peuvent pas désigner la même année écartent le rapprochement ; une date « vers »
 *   s'élargit de {@see self::TOLERANCE_ENVIRON} ans de chaque côté ; une date inconnue
 *   ne prouve rien, ni pour ni contre ;
 * - **le nom se compare au patronyme et au nom d'usage**, des deux côtés ;
 * - **un sexe connu des deux côtés et différent** écarte ;
 * - **un proche commun** (même parent, conjoint ou enfant dans l'arbre) compte autant
 *   qu'une date : c'est le signe le plus sûr qu'on décrit deux fois la même personne.
 *
 * Il faut le nom **et** le prénom, ou l'un des deux plus un appui (une naissance qui
 * recouvre, un proche commun) — l'autre étant absent, jamais différent.
 *
 * Le score ne sert qu'à **ordonner** : la meilleure correspondance d'abord. Il n'est pas
 * un seuil, et le front ne l'affiche pas.
 */
final readonly class RapprochementDeDoublons
{
    /** « vers 1889 » : un relevé sur deux se trompe d'un an ou deux, rarement plus. */
    public const int TOLERANCE_ENVIRON = 2;

    public function __construct(private CleDeDoublon $cle = new CleDeDoublon())
    {
    }

    public function comparer(IdentiteComparee $saisie, IdentiteComparee $existante): ?Rapprochement
    {
        if (Sex::Inconnu !== $saisie->sex && Sex::Inconnu !== $existante->sex && $saisie->sex !== $existante->sex) {
            return null;
        }

        $naissance = $this->recouvrement($saisie->naissance, $existante->naissance);
        $deces = $this->recouvrement($saisie->deces, $existante->deces);

        // Deux dates connues qui ne peuvent pas désigner la même année : ce n'est pas la
        // même personne — c'est le cas du père et du fils homonymes, le plus courant.
        if (false === $naissance || false === $deces) {
            return null;
        }

        $nomsSaisis = $this->noms($saisie);
        $nomsExistants = $this->noms($existante);
        $nomEgal = [] !== array_intersect($nomsSaisis, $nomsExistants);
        $nomAbsent = [] === $nomsSaisis || [] === $nomsExistants;

        $prenomSaisi = $this->cle->prenom($saisie->givenNames);
        $prenomExistant = $this->cle->prenom($existante->givenNames);
        $prenomEgal = '' !== $prenomSaisi && $prenomSaisi === $prenomExistant;
        $prenomAbsent = '' === $prenomSaisi || '' === $prenomExistant;

        // Un nom **différent** écarte ; un nom **absent** d'un côté ne prouve rien.
        if ((!$nomEgal && !$nomAbsent) || (!$prenomEgal && !$prenomAbsent) || (!$nomEgal && !$prenomEgal)) {
            return null;
        }

        $communs = [
            'parents' => array_intersect($saisie->parents, $existante->parents),
            'conjoints' => array_intersect($saisie->conjoints, $existante->conjoints),
            'enfants' => array_intersect($saisie->enfants, $existante->enfants),
        ];
        $prochesCommuns = array_filter($communs, static fn (array $ids): bool => [] !== $ids);

        // Le nom seul ou le prénom seul ne suffit pas : il faut un appui.
        if (!($nomEgal && $prenomEgal) && true !== $naissance && [] === $prochesCommuns) {
            return null;
        }

        $score = 0;
        $criteres = [];

        if ($nomEgal) {
            $score += 3;
            $criteres[] = 'nom';
        }
        if ($prenomEgal) {
            $score += 3;
            $criteres[] = 'prenom';
        }
        if (true === $naissance) {
            $score += 2;
            $criteres[] = 'naissance';
        }
        if (true === $deces) {
            ++$score;
            $criteres[] = 'deces';
        }
        if (Sex::Inconnu !== $saisie->sex && $saisie->sex === $existante->sex) {
            ++$score;
            $criteres[] = 'sexe';
        }
        foreach ($prochesCommuns as $role => $ids) {
            $score += 3 * \count($ids);
            $criteres[] = $role;
        }

        return new Rapprochement($score, $criteres);
    }

    /**
     * Les noms d'une personne, **normalisés** : patronyme et nom d'usage.
     *
     * @return list<string>
     */
    private function noms(IdentiteComparee $identite): array
    {
        $noms = [];

        foreach ([$identite->surname, $identite->marriedName] as $nom) {
            $normalise = $this->cle->nom($nom);

            if ('' !== $normalise) {
                $noms[] = $normalise;
            }
        }

        return array_values(array_unique($noms));
    }

    /**
     * `true` : les deux dates sont connues et peuvent désigner la même année.
     * `false` : connues toutes deux, elles ne le peuvent pas.
     * `null` : l'une au moins est inconnue — rien n'est prouvé.
     */
    private function recouvrement(UncertainDate $a, UncertainDate $b): ?bool
    {
        $bornesA = $this->bornes($a);
        $bornesB = $this->bornes($b);

        if (null === $bornesA || null === $bornesB) {
            return null;
        }

        [$minA, $maxA] = $bornesA;
        [$minB, $maxB] = $bornesB;

        // Une borne nulle est ouverte de ce côté (`BEF`, `AFT`) : infinie.
        return (null === $minA || null === $maxB || $minA <= $maxB)
            && (null === $minB || null === $maxA || $minB <= $maxA);
    }

    /**
     * Les bornes **en années** d'une date, élargies pour un « vers » ; `null` si la date
     * n'a aucune borne — elle ne recouvrirait alors rien, ou tout.
     *
     * @return array{int|null, int|null}|null
     */
    private function bornes(UncertainDate $date): ?array
    {
        $min = $date->getMin();
        $max = $date->getMax();

        if (!$date->isKnown() || (null === $min && null === $max)) {
            return null;
        }

        $tolerance = DateQualifier::Environ === $date->getQualifier() ? self::TOLERANCE_ENVIRON : 0;

        return [
            null === $min ? null : (int) $min->format('Y') - $tolerance,
            null === $max ? null : (int) $max->format('Y') + $tolerance,
        ];
    }
}
