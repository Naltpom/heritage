<?php

declare(strict_types=1);

namespace App\Module\Question\Service;

use App\Module\Core\Service\SettingService;

/**
 * Les deux réglages de la page « Questions », adossés à `core_setting`.
 *
 * C'est le seul point de ce module qui connaisse `Core\Service\SettingService` — un appel
 * de service, pas une relation Doctrine : la frontière du § 0.4 interdit les secondes,
 * pas les premiers. Même geste que `Tree\Service\ReglagesDeLArbreParSettings`.
 *
 * ## Pourquoi des réglages et pas deux constantes
 *
 * C'est l'argument de l'ADR 0003 sur `cercle.generations`, et il vaut mot pour mot ici :
 * *« un chiffre qu'on découvre faux et qu'on ne peut changer qu'en redéployant sera
 * changé en dur dans le code par le premier qui passe, sans trace et sans discussion. »*
 *
 * Les deux valeurs ci-dessous sont des **intuitions**, pas des mesures. Elles seront
 * confrontées à la réalité au premier membre qui ouvrira la page — soit une page vide,
 * soit un mur de questions. En réglage d'administration, le changement est daté, tracé au
 * journal d'audit, réversible.
 *
 * Aucune migration ne les sème : {@see SettingService::entier()} prend un défaut, et une
 * clé absente de `core_setting` vaut le défaut. Une ligne semée aurait figé la valeur du
 * jour de l'installation, ce qui est l'inverse du but.
 */
final readonly class ReglagesDesQuestions
{
    public const string CLE_MEMOIRE_VIVANTE = 'questions.memoire_vivante_annees';
    public const string CLE_MAXIMUM_PAR_PAGE = 'questions.maximum_par_page';
    public const string CLE_RELANCE_JOURS = 'questions.relance_jours';

    /**
     * **Un nombre d'années, jamais une année.**.
     *
     * Une constante `1936` serait juste une fois et vieillirait d'un an par an, en
     * silence — la page se mettrait à poser des questions sur des gens dont plus personne
     * n'a entendu parler, et rien n'indiquerait pourquoi.
     *
     * 90 ans : en 2026, cela ramène à 1936. Quelqu'un mort en 1936 a pu être connu d'une
     * personne née vers 1925, qui aurait un siècle aujourd'hui — c'est la limite haute de
     * la mémoire **directe**. La mémoire **rapportée** (« mon père m'a parlé de son
     * père ») est couverte par la passe supplémentaire du détecteur, et non par ce
     * chiffre.
     */
    public const int MEMOIRE_VIVANTE_DEFAUT = 90;

    /**
     * Combien de questions la page sert d'un coup.
     *
     * Une liste sans fin n'est pas une liste : sur les 274 fiches de l'arbre réel, la
     * détection peut rendre plusieurs centaines de lacunes légitimes. Vingt questions,
     * c'est une page qu'on finit ; deux cents, c'est une page qu'on ferme.
     *
     * Le reste n'est pas perdu : rien n'est supprimé, et la détection étant rejouée à
     * chaque ouverture, les questions suivantes remontent à mesure qu'on traite les
     * premières.
     */
    public const int MAXIMUM_PAR_PAGE_DEFAUT = 20;

    /** Le premier délai de relance d'un « je vais me renseigner ». */
    public const int RELANCE_JOURS_DEFAUT = 30;

    /** Le plafond du doublement — voir {@see prochaineRelance()}. */
    public const int RELANCE_PLAFOND_JOURS = 365;

    public function __construct(private SettingService $reglages)
    {
    }

    /**
     * L'année avant laquelle on ne pose plus de question.
     *
     * `$maintenant` est un paramètre et non un `new \DateTimeImmutable()` interne : deux
     * appels de la même requête doivent rendre la même année, y compris le 31 décembre à
     * minuit, et un test doit pouvoir fixer la date sans toucher à l'horloge.
     */
    public function anneePlancher(\DateTimeImmutable $maintenant): int
    {
        $annees = $this->reglages->entier(self::CLE_MEMOIRE_VIVANTE, self::MEMOIRE_VIVANTE_DEFAUT);

        // Une valeur négative ou nulle rendrait une année dans le futur, donc une page
        // vide — et une page vide se diagnostique mal : elle ressemble à « rien à
        // demander ». On borne plutôt que de laisser un réglage produire ce silence.
        return (int) $maintenant->format('Y') - max(1, $annees);
    }

    public function maximumParPage(): int
    {
        return max(1, $this->reglages->entier(self::CLE_MAXIMUM_PAR_PAGE, self::MAXIMUM_PAR_PAGE_DEFAUT));
    }

    /**
     * Dans combien de temps reposer une question repoussée — **et le délai DOUBLE à
     * chaque report**.
     *
     * C'est la réponse douce au problème que pose `EtatDeQuestion::Differee` : « je vais
     * me renseigner » doit revenir, sinon la promesse est perdue ; mais une promesse
     * qu'on rappelle au même rythme indéfiniment est un harcèlement poli, au terme duquel
     * le membre répond « je ne sais pas » pour s'en débarrasser — ce qui **détruit**
     * l'information au lieu de la recueillir.
     *
     * Doubler plutôt que fermer au bout de trois : fermer ferait décider à la place du
     * membre, et le troisième rappel est précisément celui qui tombe le jour où il a
     * enfin vu sa tante. La question ne disparaît jamais d'elle-même ; elle se fait juste
     * de plus en plus discrète.
     *
     * Le plafond à un an existe pour que le doublement ne rende pas la question
     * inatteignable au bout de huit reports — `30 × 2^8`, c'est vingt ans.
     */
    public function prochaineRelance(\DateTimeImmutable $maintenant, int $reportsDejaFaits): \DateTimeImmutable
    {
        $base = max(1, $this->reglages->entier(self::CLE_RELANCE_JOURS, self::RELANCE_JOURS_DEFAUT));
        $jours = min(self::RELANCE_PLAFOND_JOURS, $base * (2 ** max(0, $reportsDejaFaits)));

        return $maintenant->modify(sprintf('+%d days', $jours));
    }
}
