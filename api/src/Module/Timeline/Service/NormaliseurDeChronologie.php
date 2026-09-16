<?php

declare(strict_types=1);

namespace App\Module\Timeline\Service;

use App\Module\Core\Security\CercleDeVisibiliteInterface;
use App\Module\Core\ValueObject\UncertainDate;
use App\Module\Timeline\Contract\FaitDeLaChronologie;
use App\Module\Timeline\Contract\PersonneDeLaChronologie;
use App\Module\Timeline\Contract\PortChronologieArbre;

/**
 * La charge du § 9.1 : `bande`, `orientation`, `branches`, `showUncertainty`, `bornes`,
 * `bandes`, `barres`, `jalons`.
 *
 * **C'est ici, et nulle part ailleurs dans ce module, que le cercle de visibilité
 * s'applique.** « Le cercle de visibilité est appliqué dans la sérialisation, pas dans le
 * contrôleur » (§ 8 du dossier de mission). Le contrôleur charge, délègue et répond ; le
 * jour où il écrirait un `if ($estVisible)`, il existerait deux endroits qui décident de
 * la confidentialité — et le second serait celui qu'on oublierait de corriger.
 *
 * ## Les personnes vivantes hors cercle sont absentes de `barres`
 *
 * Décision du § 9.1, et elle est plus forte que celle du graphe : là-bas, un nœud hors
 * cercle reste présent avec sept clés ; ici la barre **disparaît**. Le motif est écrit :
 *
 * > Une barre *est* faite de dates, et une personne vivante hors cercle n'en expose
 * > aucune : elle n'aurait rien à dessiner.
 *
 * Il n'y a donc pas de « barre réduite » à écrire, et il ne faut pas en inventer une : un
 * segment sans début ni fin n'est pas une barre discrète, c'est un artefact. Conséquence
 * visible à assumer — « un membre voit moins de barres que de nœuds ».
 *
 * Un compte `en_attente` relève du régime `Regime::Aucun` : il n'a **pas** de cercle, donc
 * {@see CercleDeVisibiliteInterface::filtrerPersonnes()} ne lui rend que des personnes
 * décédées, et ses `jalons` ne portent par construction que des faits de personnes
 * décédées (§ 9.1, dernier paragraphe). Aucune branche n'est écrite ici pour ce régime :
 * il tombe du contrat du noyau, pas d'un `if`.
 *
 * ## Un seul appel au cercle, pas 274
 *
 * `filtrerPersonnes()` existe pour cette raison exacte, et son en-tête le dit : une boucle
 * sur `voitPersonne()` ferait 274 résolutions de cercle là où une seule suffit.
 *
 * ## Les bornes sont celles du lecteur
 *
 * Elles sont calculées sur les barres **retenues**, après cercle et après filtre de
 * branches. Un axe qui commencerait en 1644 alors que le lecteur ne voit rien avant 1900
 * lui apprendrait qu'il existe des fiches plus anciennes — c'est le genre de fuite par
 * déduction que le § 16.2 ferme.
 *
 * ## `bornes` ne vaut jamais `null` ; `bandes: []` porte la vacuité
 *
 * Deux clés, deux rôles, et c'est un contrat avec le front, qui type `bornes` **non
 * nullable** et déclenche son état vide sur `bandes: []`. Quand le lecteur ne voit aucune
 * barre — régime `Aucun` sur un arbre sans personne décédée, filtre de branches qui ne
 * retient rien, port non branché — `bornes` vaut `{année courante, année courante}` et
 * `bandes` est vide.
 *
 * Rendre `bornes: null` aurait fait mentir son type **uniquement** pour un compte en
 * attente, c'est-à-dire pas avant la production : le pire moment pour découvrir qu'une clé
 * non nullable était nullable.
 */
final class NormaliseurDeChronologie
{
    public function __construct(
        private readonly PortChronologieArbre $arbre,
        private readonly CercleDeVisibiliteInterface $cercle,
        private readonly CalculDesBandes $calculDesBandes,
        private readonly IntervalleDAnnees $intervalles,
    ) {
    }

    /** @return array<string, mixed> la charge complète du § 9.1 */
    public function vue(RequeteDeChronologie $requete, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): array
    {
        $arbre = $this->arbre->vue();

        $visibles = array_flip($this->cercle->filtrerPersonnes(
            array_map(static fn (PersonneDeLaChronologie $p): int => $p->id, $arbre->personnes),
        ));

        $barres = [];
        $annees = [];
        $ouverte = false;

        foreach ($arbre->personnes as $personne) {
            if (!isset($visibles[$personne->id]) || !$requete->afficheLaBranche($personne->branch)) {
                continue;
            }

            $barre = $this->barre($personne);

            if (null === $barre) {
                continue;
            }

            $barres[] = $barre;
            $ouverte = $ouverte || true === $barre['ouverte'];

            /** @var array{min: int|null, max: int|null, certain: bool}|null $debut */
            $debut = $barre['debut'];
            /** @var array{min: int|null, max: int|null, certain: bool}|null $fin */
            $fin = $barre['fin'];

            $annees = [...$annees, ...IntervalleDAnnees::anneesDe($debut), ...IntervalleDAnnees::anneesDe($fin)];
        }

        $bornes = Bornes::surLesAnnees($annees, $ouverte, (int) $maintenant->format('Y'));
        $dessinees = array_column($barres, 'personId');

        return [
            'bande' => $requete->bande,
            'orientation' => $requete->orientation->value,
            'branches' => array_map(static fn ($b): string => $b->value, $requete->branches),
            'showUncertainty' => $requete->showUncertainty,
            // `bornes` est **non nullable**, toujours : quand rien n'est visible elle vaut
            // {année courante, année courante}. C'est `bandes: []` qui est l'unique signal
            // de vacuité, et le front est typé dessus. Un `bornes: null` ne mentirait que
            // pour un compte en attente — c'est-à-dire pas avant la production.
            'bornes' => ['min' => $bornes->min, 'max' => $bornes->max],
            'bandes' => [] === $barres ? [] : array_map(
                static fn (Bande $bande): array => $bande->enJson(),
                $this->calculDesBandes->bandes($bornes, $requete->bande),
            ),
            'barres' => $barres,
            'jalons' => $this->jalons($arbre->faits, array_flip($dessinees)),
        ];
    }

    /**
     * Une barre, ou `null` quand il n'y a rien à dessiner.
     *
     * Trois raisons de rendre `null`, et aucune n'est un cas d'erreur :
     *
     * 1. **pas de branche** — `branch` est une clé obligatoire de la barre au § 9.1, et
     *    c'est aussi la colonne où le front la range. Les 274 personnes importées en ont
     *    une (`pm` 134, `mm` 64, `mp` 51, `pp` 20, `tr` 5) ; le cas ne se produit pas sur
     *    la vraie base, et on ne devine pas une branche pour autant ;
     * 2. **pas de génération** — même raison, même clé obligatoire ;
     * 3. **aucune année exploitable** — ni naissance ni décès ne pose de borne sur l'axe.
     *    C'est le même raisonnement que le § 9.1 tient pour les vivants hors cercle : *une
     *    barre est faite de dates*, et sans aucune date il n'y a pas de segment, seulement
     *    un point flottant que le front devrait poser quelque part.
     *
     * @return array<string, mixed>|null
     */
    private function barre(PersonneDeLaChronologie $personne): ?array
    {
        if (null === $personne->branch || null === $personne->generation) {
            return null;
        }

        $debut = $this->intervalles->pour($personne->birth);

        // Une personne vivante n'expose **jamais** de fin : « l'API n'invente pas une date
        // de fin » (§ 9.1), et la barre s'arrête à l'année courante côté rendu. Écrit sous
        // cette forme, le cas « vivante mais porteuse d'une date de décès » — qui ne
        // devrait pas exister, et qu'un import approximatif produirait — ne peut pas fuir.
        $ouverte = $personne->vivant;
        $fin = $ouverte ? null : $this->intervalles->pour($personne->death);

        if (null === $debut && null === $fin) {
            return null;
        }

        return [
            'personId' => $personne->id,
            'displayName' => $personne->displayName,
            'branch' => $personne->branch->value,
            'generation' => $personne->generation,
            'sex' => $personne->sex->value,
            'vivant' => $personne->vivant,
            'debut' => $debut,
            'fin' => $fin,
            'ouverte' => $ouverte,
            'ligneDates' => $this->ligneDates($personne),
        ];
    }

    /**
     * `ligneDates` — la forme **compacte** du § 14.4, assemblée.
     *
     * `"1901-1909 – 1987"` pour Marne Dolomite Sureau, `"n. 26.9.2012"` pour une personne
     * vivante. **Ce n'est pas la prose** : `"entre 1901 et 1909"` est la valeur de la clé
     * `texte`, à l'intérieur d'un bloc date, et elle n'a pas cours ici.
     *
     * ## Trois formes, trois clés — et pourquoi celle-ci est la bonne
     *
     * Le § 14.4 les sépare définitivement, après qu'une seule clé eut désigné les trois :
     *
     * | forme | clé | où |
     * |---|---|---|
     * | prose | `texte`, dans le bloc date | fiches, calque |
     * | **compacte** | **`ligneDates`** | fiche, recherche, brouillon, **la barre d'ici** |
     * | étiquette mesurée | `etiquetteDates` | nœud du graphe, et nulle part ailleurs |
     *
     * ## La différence avec `etiquetteDates`, qu'il ne faut pas « unifier »
     *
     * `etiquetteDates` court-circuite sur `vivant` et rend la constante `vivant·e` ;
     * **`ligneDates` ne court-circuite jamais**. Le motif de ce court-circuit ne se
     * transpose pas, et c'est précisément pourquoi il faut deux clés :
     *
     * - `etiquetteDates` est **mesurée** — `w` est calculée dessus, à l'étage 1 du cache de
     *   placement, **partagé et sans filtre de confidentialité** (ADR 0005). Si elle portait
     *   les vraies dates d'une personne vivante, la largeur de sa fiche les encoderait pour
     *   tous les lecteurs, cercle ou non ;
     * - `ligneDates` est filtrée **en aval** : une personne vivante hors cercle est absente
     *   de `barres` (§ 9.1). Celles qui restent exposent légitimement leurs dates, et leur
     *   servir `vivant·e` masquerait ce que le cercle vient d'autoriser — en rendant la
     *   chronologie illisible sur les vivants du cercle, qui sont le peu de vivants qu'on a.
     *
     * **Conséquence à ne pas « corriger » en la voyant** : la même personne porte deux
     * chaînes différentes dans la même réponse. Natron Romarin est `vivant·e` en
     * `etiquetteDates` sur son nœud et `n. 26.9.2012` en `ligneDates` sur sa barre. C'est
     * un invariant, pas une incohérence — et l'unifier le casserait sans qu'aucun test
     * d'affichage ne rougisse.
     *
     * ## La grammaire n'est pas écrite ici
     *
     * Les deux étiquettes arrivent **déjà composées** par `Tree`, qui possède `jolie()` de
     * `graphe.py` sous le nom `EtiquetteDeDateGedcom`. Cette méthode n'en fait que
     * l'**assemblage**, qui est la seule part appartenant à l'appelant. Le demi-cadratin est
     * entouré d'espaces — à ne pas confondre avec celui de {@see Bande::libelle()}, qui est
     * sans espaces.
     *
     * Le repli est `—` et non la chaîne vide : c'est ce qu'écrit `ligne_dates()` de
     * `graphe.py`, et une barre sans aucune date reste une barre à étiqueter.
     */
    private function ligneDates(PersonneDeLaChronologie $personne): string
    {
        $naissance = $personne->etiquetteNaissance;

        // Une personne calculée vivante n'expose pas de fin — `fin` vaut déjà `null` sur sa
        // barre. Laisser passer une date de décès ici contredirait cette clé dans le même
        // objet, pour une donnée qui ne devrait pas exister (§ 9.1). Ce n'est pas le
        // court-circuit `vivant·e` d'`etiquetteDates` : on ne substitue aucune constante,
        // on s'abstient d'afficher une fin que la barre elle-même ne porte pas.
        $deces = $personne->vivant ? '' : $personne->etiquetteDeces;

        if ('' !== $naissance && '' !== $deces) {
            return $naissance.' – '.$deces;
        }

        if ('' !== $naissance) {
            return 'n. '.$naissance;
        }

        if ('' !== $deces) {
            return '† '.$deces;
        }

        return '—';
    }

    /**
     * Les jalons — les `tree_fact` datés des personnes **effectivement dessinées**.
     *
     * Le filtre porte sur les personnes retenues dans `barres`, et non sur le seul cercle.
     * Deux raisons, dans cet ordre :
     *
     * 1. **confidentialité** — un fait porte une date et un lieu, donc des données
     *    protégées au sens du § 11.1 ; il suit la personne, sans exception ;
     * 2. **cohérence** — `personId` est la clé par laquelle le front rattache un jalon à
     *    sa barre. Un jalon dont la barre a été écartée (branche non retenue, aucune année
     *    exploitable) désignerait une barre absente, et révélerait au passage qu'une
     *    personne existe dans une branche que le lecteur a justement décochée.
     *
     * @param list<FaitDeLaChronologie> $faits
     * @param array<int, int>           $dessinees ensemble des `personId` retenus, en clés
     *
     * @return list<array<string, mixed>>
     */
    private function jalons(array $faits, array $dessinees): array
    {
        $jalons = [];

        foreach ($faits as $fait) {
            if (!isset($dessinees[$fait->personId])) {
                continue;
            }

            $annee = self::anneeDuJalon($fait->happened);

            if (null === $annee) {
                continue;
            }

            $jalons[] = [
                'type' => $fait->type,
                'annee' => $annee,
                'libelle' => self::libelleDuJalon($fait),
                'personId' => $fait->personId,
            ];
        }

        return $jalons;
    }

    /**
     * **Le seul endroit du module où une date devient un point**, et c'est le contrat qui
     * l'impose : `"annee": 1946` au § 9.1, un entier scalaire.
     *
     * Un jalon *est* un point — un recensement a eu lieu une année, pas pendant huit ans —
     * là où une barre est un segment. Ce n'est donc pas l'aplatissement que le § 4 du
     * dossier interdit : on ne réduit pas un intervalle connu, on lit une date ponctuelle.
     * Le calcul est néanmoins écrit ici, en clair, plutôt que caché dans un accesseur
     * d'{@see UncertainDate} — c'est exactement la consigne de l'en-tête de cet objet.
     *
     * La borne basse d'abord : pour une date bornée à droite seulement (`BEF 1920`), c'est
     * `max` qui porte l'information.
     */
    private static function anneeDuJalon(UncertainDate $date): ?int
    {
        $borne = $date->getMin() ?? $date->getMax();

        return null === $borne ? null : (int) $borne->format('Y');
    }

    /**
     * `"Recensement, Saule"` — la partie descriptive, puis le lieu.
     *
     * La partie descriptive est la **valeur textuelle** du fait quand il en porte une
     * (« Tisserand » pour une profession), et à défaut le libellé de son type. C'est ce
     * qui reproduit l'exemple du § 9.1 : un recensement n'a pas de valeur textuelle, donc
     * `Recensement`, puis son lieu.
     */
    private static function libelleDuJalon(FaitDeLaChronologie $fait): string
    {
        $morceaux = array_filter([
            null !== $fait->label && '' !== trim($fait->label) ? trim($fait->label) : $fait->typeLabel,
            $fait->happened->getPlace(),
        ], static fn (?string $m): bool => null !== $m && '' !== trim($m));

        return implode(', ', array_map(trim(...), $morceaux));
    }
}
