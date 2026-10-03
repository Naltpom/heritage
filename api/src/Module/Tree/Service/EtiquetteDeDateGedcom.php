<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Core\Enum\DateQualifier;
use App\Module\Core\ValueObject\ComposantesDeDate;
use App\Module\Core\ValueObject\UncertainDate;

/**
 * L'étiquette **compacte** d'une date, telle que le moteur de placement la mesure —
 * portage de `jolie()` et `annee()`, `docs/moteur-retrouve/graphe.py` 64-98.
 *
 * ## Ne pas confondre avec `UncertainDate::format()`
 *
 * Ce sont **deux objets différents qui portent le même nom de clé**, et les confondre
 * produit un nœud dont l'étiquette déborde de sa boîte (`docs/api-contract.md` § 941) :
 *
 * | | `UncertainDate::format()` | ici |
 * |---|---|---|
 * | destination | la **fiche** (§ 5.3 du contrat) | l'**étiquette du nœud** |
 * | `BET 1901 AND 1909` | « entre 1901 et 1909 » (20 car.) | `1901-1909` (9 car.) |
 * | `ABT 1934` | « vers 1934 » | `~1934` |
 * | `22 MAY 1949` | « 22 mai 1949 » | `22.5.1949` |
 *
 * La différence n'est pas cosmétique : **`w` est mesurée sur cette chaîne**. Servir la
 * prose de `format()` au moteur allonge chaque fiche et fait tomber le critère d'égalité
 * exacte de l'ADR 0005. C'est la raison pour laquelle le portage du moteur avait laissé
 * cette règle en suspens plutôt que de la deviner.
 *
 * Ni l'une ni l'autre n'aplatit jamais une fourchette en date ferme (§ 9.1) : `1901-1909`
 * reste un intervalle, simplement écrit court.
 */
final class EtiquetteDeDateGedcom
{
    /** Les mois du GEDCOM. Table en dur : ce sont les douze codes de la norme. */
    private const array MOIS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    /**
     * L'étiquette d'un bloc date.
     *
     * On part de la **chaîne GEDCOM verbatim** quand elle existe, parce que c'est elle
     * que `graphe.py` lisait : `annee()` y cherche un millésime **où qu'il soit**, ce qui
     * n'est pas toujours ce que disent les bornes analysées. `BEF 1920` en est le cas
     * d'école — il est stocké `min = null`, mais `jolie()` en tire quand même `<1920`.
     */
    public function etiquette(UncertainDate $date): string
    {
        $brut = $date->getRaw();

        if (null !== $brut && '' !== trim($brut)) {
            return $this->jolie($brut);
        }

        return $this->depuisLesBornes($date);
    }

    /**
     * La forme compacte des seules composantes permises — la naissance d'un vivant vue à
     * travers la réciprocité (ADR 0016) : « 1985 », « 3/1985 », « 12.3 », « mars ».
     *
     * **Jamais depuis `raw`**, qui porte toutes les composantes d'un coup : on part de la
     * date dégradée ({@see UncertainDate::reduite()}), qui n'en a plus.
     */
    public function etiquettePartielle(UncertainDate $date, ComposantesDeDate $permises): string
    {
        if ($permises->estComplete()) {
            return $this->etiquette($date);
        }

        if ($permises->annee) {
            $reduite = $date->reduite($permises);

            return null === $reduite ? '' : $this->depuisLesBornes($reduite);
        }

        $visibles = $date->composantesVisibles($permises);

        if (isset($visibles['mois'], $visibles['jour'])) {
            return $visibles['jour'].'.'.$visibles['mois'];
        }

        return isset($visibles['mois']) ? UncertainDate::nomDuMois($visibles['mois']) : '';
    }

    /**
     * `graphe.py` 77-98. `'22 MAY 1949'` → `'22.5.1949'` ;
     * `'BET 1941 AND 1946'` → `'1941-1946'`.
     *
     * L'ordre des cas est celui du script et il compte : `BET … AND …` doit être testé
     * avant tout le reste, sans quoi `annee()` n'y verrait que le premier millésime.
     */
    public function jolie(string $date): string
    {
        $d = trim($date);

        if ('' === $d) {
            return '';
        }

        if (1 === preg_match('/^BET (.+) AND (.+)$/', $d, $m)) {
            return $this->millesimeOuPoint($m[1]).'-'.$this->millesimeOuPoint($m[2]);
        }

        if (1 === preg_match('/^(?:ABT|EST|CAL) (.+)$/', $d, $m)) {
            return '~'.$this->millesimeOuPoint($m[1]);
        }

        if (str_starts_with($d, 'BEF ')) {
            return '<'.$this->millesimeOuPoint($d);
        }

        if (str_starts_with($d, 'AFT ')) {
            return '>'.$this->millesimeOuPoint($d);
        }

        if (1 === preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/', $d, $m) && isset(self::MOIS[$m[2]])) {
            return $m[1].'.'.self::MOIS[$m[2]].'.'.$m[3];
        }

        if (1 === preg_match('/^([A-Z]{3}) (\d{4})$/', $d, $m) && isset(self::MOIS[$m[1]])) {
            return self::MOIS[$m[1]].'/'.$m[2];
        }

        // `str(annee(d) or d)` : le millésime s'il y en a un, sinon la chaîne telle quelle.
        $annee = $this->annee($d);

        return null === $annee ? $d : (string) $annee;
    }

    /**
     * `graphe.py` 64-67 : la borne basse d'une date, c'est-à-dire **le premier millésime
     * rencontré** dans la chaîne, où qu'il soit.
     *
     * C'est cette valeur qui ordonne les fratries. Elle n'est pas interchangeable avec
     * `birth_min` : `BEF 1920` n'a pas de borne basse mais rend bien 1920 ici.
     */
    public function annee(?string $date): ?int
    {
        if (null === $date || 1 !== preg_match('/\b(\d{4})\b/', $date, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /** `graphe.py` 70-74 : le **dernier** millésime. « BET 1957 AND 1980 » donne 1980. */
    public function anneeMax(?string $date): ?int
    {
        if (null === $date || 0 === preg_match_all('/\b(\d{4})\b/', $date, $m)) {
            return null;
        }

        $millesimes = $m[1];

        return (int) $millesimes[\count($millesimes) - 1];
    }

    /** `str(annee(x) or "?")` — `0` est faux en Python autant que `None`. */
    private function millesimeOuPoint(string $fragment): string
    {
        $annee = $this->annee($fragment);

        return null === $annee || 0 === $annee ? '?' : (string) $annee;
    }

    /**
     * Le repli pour une personne **créée dans l'application** et non importée : elle n'a
     * pas de chaîne GEDCOM, seulement un qualificatif et des bornes.
     *
     * Cette branche ne s'exécute sur aucune des 274 personnes de référence — toutes
     * viennent du GEDCOM et portent leur `raw`. Elle ne peut donc pas influer sur le
     * critère d'égalité exacte, et c'est **le seul endroit de ce fichier qui est une
     * décision et non une traduction**. Elle vise la même forme compacte, pour qu'une
     * fiche ajoutée à la main ne détonne pas dans sa fratrie.
     */
    private function depuisLesBornes(UncertainDate $date): string
    {
        $min = $date->getMin();
        $max = $date->getMax();

        return match ($date->getQualifier()) {
            DateQualifier::Inconnue => '',
            DateQualifier::Entre => null !== $min && null !== $max
                ? $min->format('Y').'-'.$max->format('Y')
                : ($min ?? $max)?->format('Y') ?? '',
            DateQualifier::Environ => '~'.(($min ?? $max)?->format('Y') ?? '?'),
            // `BEF 1920` est stocké `max = 1919-12-31` : on rend la borne exclue.
            DateQualifier::Avant => '<'.($max?->modify('+1 day')->format('Y') ?? '?'),
            DateQualifier::Apres => '>'.($min?->modify('-1 day')->format('Y') ?? '?'),
            DateQualifier::Exacte => null !== ($j = $min ?? $max)
                ? $j->format('j').'.'.$j->format('n').'.'.$j->format('Y')
                : '',
            DateQualifier::Mois => null !== ($mo = $min ?? $max)
                ? $mo->format('n').'/'.$mo->format('Y')
                : '',
            DateQualifier::Annee, DateQualifier::Depuis, DateQualifier::JusquA => ($min ?? $max)?->format('Y') ?? '',
        };
    }
}
