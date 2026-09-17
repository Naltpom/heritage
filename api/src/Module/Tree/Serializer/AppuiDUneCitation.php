<?php

declare(strict_types=1);

namespace App\Module\Tree\Serializer;

use App\Module\Tree\Enum\CitationQuality;
use App\Module\Tree\Enum\SourceKind;

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * SUR QUOI UNE CITATION REPOSE — les trois niveaux, décidés au serveur.
 *
 * ## Ce que le commanditaire a demandé
 *
 * Voir d'un coup d'œil, sur ses 483 citations, lesquelles sont tenues par un acte. Il a
 * validé **trois niveaux** et non deux, et la nuance est la sienne :
 *
 * | niveau | ce qui le tient | ce que ça vaut |
 * |---|---|---|
 * | `acte` | acte d'état civil, acte notarié, registre | quelqu'un a lu l'original |
 * | `index` | table décennale, relevé d'association, recensement, fichier INSEE | quelqu'un avait le registre sous les yeux, mais a recopié |
 * | `arbre` | Geneanet, Filae, MyHeritage, site familial | quelqu'un a recopié un autre arbre |
 *
 * **La différence entre les deux premiers compte**, et elle a été payée deux fois le même
 * jour sur ce dossier : un index se trompe, mais il a été dressé par quelqu'un qui tenait
 * le registre. Les fondre ferait disparaître exactement l'information qui dit s'il faut
 * aller vérifier.
 *
 * Le troisième est celui qui motive ce lot : « Ils recopient d'autres arbres en ligne.
 * Utilise-les comme piste vers un acte, jamais comme source. Deux arbres qui concordent
 * peuvent descendre du même original erroné. » Environ **235 des 483 citations** de l'arbre
 * sont dans ce cas, soit deux sur cinq.
 *
 * ## Deux colonnes disent la même chose, et aucune ne suffit seule
 *
 * - `tree_source.source_kind` qualifie le **fonds** : ce registre est-il un acte, un
 *   recensement, un arbre d'un autre utilisateur ? C'est une propriété du document.
 * - `tree_citation.quay` qualifie **cet usage-ci** : ai-je vérifié, sur ce fait précis ?
 *   C'est une propriété de la lecture, et le `QUAY` du GEDCOM.
 *
 * Les deux peuvent diverger, et c'est légitime dans un sens seulement. Citer un acte sans
 * l'avoir vérifié se dit `quay = 0` sur une source `acte_etat_civil` : la fiche repose
 * alors sur une recopie, quoi qu'en dise le fonds. **L'inverse n'a pas de sens** : un arbre
 * en ligne coté « acte ou registre » est une contradiction, et ce serait le genre
 * d'affirmation qu'on ne rattrape jamais.
 *
 * Le niveau est donc le **plus bas des deux**, et c'est la seule composition sûre : une
 * fiche ne doit jamais paraître mieux tenue qu'elle ne l'est. Le cas se produit — les
 * citations d'arbres tiers de ce fonds sont cotées `2` par l'import, qui a recopié un
 * `QUAY` posé par le logiciel d'origine.
 *
 * ## Pourquoi c'est ici et pas dans le front
 *
 * Parce que « le libellé vient du serveur : on l'affiche, on ne le recompose pas » (§ 5.3),
 * et parce que trois écrans le montrent — la fiche, le diff d'une demande de citation, et
 * la file d'administration. Une règle recopiée trois fois diverge deux fois.
 */
final class AppuiDUneCitation
{
    /**
     * Les fonds qui **ne peuvent pas** tenir mieux qu'une recopie, quel que soit le `QUAY`.
     *
     * `Autre` n'y est pas, et c'est un choix prudent dans le sens qui coûte : il couvre
     * aussi bien un site familial qu'un acte mal classé à l'import. Le classer d'office en
     * `arbre` marquerait comme recopie des fiches qui tiennent. C'est
     * {@see \App\Module\Tree\Command\ReclasserLesArbresTiersCommand} qui range les sites
     * familiaux là où ils doivent être, sur preuve de leur adresse, plutôt que cette liste
     * qui ne peut que deviner.
     *
     * @var list<SourceKind>
     */
    private const array FONDS_RECOPIES = [SourceKind::ArbreTiers];

    /**
     * Les fonds qui tiennent au mieux un index : quelqu'un avait le registre, et a recopié.
     *
     * @var list<SourceKind>
     */
    private const array FONDS_INDIRECTS = [SourceKind::Recensement, SourceKind::Temoignage];

    /** Ce qu'une citation vaut, en une valeur stable que le front n'interprète pas. */
    public function niveau(SourceKind $fonds, ?CitationQuality $quay): string
    {
        $parLeFonds = match (true) {
            \in_array($fonds, self::FONDS_RECOPIES, true) => 'arbre',
            \in_array($fonds, self::FONDS_INDIRECTS, true) => 'index',
            default => 'acte',
        };

        // `null` vaut `arbre` et non `acte` : une citation importée sans `QUAY` n'a jamais
        // été évaluée, et le défaut fermé vaut ici comme ailleurs. Le contraire ferait
        // passer pour tenu ce dont personne n'a rien dit.
        $parLUsage = match ($quay) {
            null, CitationQuality::RecopieNonVerifiee => 'arbre',
            CitationQuality::IndexOuDeduction => 'index',
            CitationQuality::ActeOuRegistre, CitationQuality::PreuveDirecte => 'acte',
        };

        return $this->leMoinsBon($parLeFonds, $parLUsage);
    }

    /** Le libellé français du niveau, affiché tel quel. */
    public function libelle(string $niveau): string
    {
        return match ($niveau) {
            'acte' => 'tenu par un acte',
            'index' => 'tenu par un index ou un relevé',
            default => 'tenu par un arbre en ligne',
        };
    }

    /**
     * Cette citation repose-t-elle sur un arbre en ligne ?
     *
     * C'est la question du commanditaire, et elle mérite sa propre clé plutôt qu'une
     * comparaison de chaîne côté front : le jour où un quatrième niveau apparaîtra, un
     * `niveau === 'arbre'` écrit dans un gabarit deviendra faux sans que rien ne tombe.
     */
    public function estUnArbreEnLigne(SourceKind $fonds, ?CitationQuality $quay): bool
    {
        return 'arbre' === $this->niveau($fonds, $quay);
    }

    /**
     * Le plus bas des deux niveaux — **jamais le plus haut, jamais une moyenne**.
     *
     * Une fiche ne doit pas paraître mieux tenue qu'elle ne l'est. C'est la même règle que
     * le défaut fermé du cercle de visibilité : quand deux réponses divergent, on retient
     * celle qui montre le moins.
     */
    private function leMoinsBon(string $a, string $b): string
    {
        $rang = ['arbre' => 0, 'index' => 1, 'acte' => 2];

        return ($rang[$a] ?? 0) <= ($rang[$b] ?? 0) ? $a : $b;
    }
}
