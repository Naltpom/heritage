<?php

declare(strict_types=1);

namespace App\Module\Question\Enum;

/**
 * Qui a écrit la phrase — pas qui a trouvé la lacune.
 *
 * La distinction est tout l'ADR 0008 (§ 4) : **la détection est à nous, la
 * formulation peut être déléguée**. Un `Gemini` ici ne veut pas dire qu'un modèle a
 * décidé qu'il manquait quelque chose ; il veut dire qu'il a mis en français une
 * lacune que le code avait trouvée.
 *
 * ── Pourquoi c'est une colonne et pas une ligne de journal ──────────────────────
 *
 * Parce que « le repli a-t-il servi ? » est une question qu'on se posera, et qu'une
 * réponse qui suppose de relire des journaux est une réponse qu'on n'obtient pas. Une
 * panne du fournisseur ne se manifeste par **aucun symptôme visible** — la page
 * continue de servir des questions, simplement plus raides. C'est précisément le genre
 * de défaillance qui dure des semaines : rien ne tombe, rien ne rougit, et
 * `SELECT redacteur, count(*) FROM qst_question GROUP BY 1` est le seul endroit où
 * elle se lit.
 *
 * Elle sert aussi le produit : la page dit à qui lit qu'un modèle a écrit la question
 * (ADR 0008, § Conséquences). Un membre qui apprend par hasard que ses données
 * familiales sont passées chez un tiers le vit autrement que celui à qui on l'a dit.
 */
enum RedacteurDeQuestion: string
{
    /**
     * Le gabarit de {@see NatureDeLacune::gabarit()}, rempli avec le nom de la
     * personne, et **aucun appel sortant**.
     *
     * C'est l'état normal d'un déploiement sans `HERITAGE_GEMINI_CLE_API`, et celui de
     * l'intégration continue. Ce n'est pas une panne, et cela ne déclenche aucune
     * alerte : un rouge permanent sur un état normal détruit l'alarme là où elle sert.
     */
    case Regles = 'regles';

    /** Le modèle a reformulé, par le palier payant / Vertex (ADR 0008, § 5). */
    case Gemini = 'gemini';

    public function label(): string
    {
        return match ($this) {
            self::Regles => 'Formulation automatique',
            self::Gemini => 'Formulation par un modèle de langage',
        };
    }
}
