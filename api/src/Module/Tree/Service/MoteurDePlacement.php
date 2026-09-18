<?php

declare(strict_types=1);

namespace App\Module\Tree\Service;

use App\Module\Tree\ValueObject\JeuDePlacement;
use App\Module\Tree\ValueObject\Placement;

/**
 * Le moteur de placement de l'arbre — portage de `docs/moteur-retrouve/graphe.py`,
 * lignes 391 à 612 (ADR 0005).
 *
 * **C'est un portage, pas une inspiration.** Le script d'origine est déterministe et on
 * possède son code : la sortie de ce service doit reproduire `graphe.json` au centième
 * près sur les 377 nœuds. Cette preuve tient toujours, et **rien ici ne doit la casser**.
 *
 * ── Mais ce n'est plus ce qui est servi ───────────────────────────────────
 *
 * Ce moteur est désormais la **première moitié** du calcul. Il donne les générations et
 * la structure d'ascendance — le lecteur au centre, son père à gauche, sa mère à
 * droite, les fratries recentrées sous leur couple parental —, et c'est la partie qui
 * fonctionne. {@see AffinageDuPlacement} refait ensuite l'ordre des rangées et toutes
 * les abscisses, sous des règles arrêtées avec le commanditaire.
 *
 * **Pourquoi il a fallu en passer par là.** Une preuve d'identité interdit par
 * construction toute amélioration : « toute *amélioration* casse cette preuve », disait
 * cette ligne, et c'est exactement ce qui bloquait pendant que 28 couples sur 100
 * étaient séparés sur l'arbre réel. La preuve d'identité est donc conservée **pour ce
 * qu'elle prouve** — que le portage est fidèle —, et une preuve de **propriétés** a
 * pris sa place sur ce qui sort : {@see \App\Tests\Tree\PlacementLisibleTest}.
 *
 * Si une règle de placement doit changer, elle se change donc dans l'affinage, où le
 * contrat est une liste d'invariants et une mesure qui ne remonte jamais — pas ici,
 * où le contrat est une égalité.
 *
 * **Service sans état, sans accès à la base**, c'est une contrainte de l'ADR 0005 et pas
 * un commentaire de style : c'est elle qui rend le moteur testable contre le fichier de
 * référence. Il ne lit ni Doctrine, ni cache, ni réglage ; il ne persiste ni `branch`,
 * ni `generation`, ni `rank`, qu'il **reçoit** en entrée.
 *
 * L'état de travail (mémoïsations, positions intermédiaires) vit dans un
 * {@see CalculDePlacement} créé par appel, ce qui permet à ce service d'être partagé
 * par le conteneur sans qu'un calcul n'en contamine un autre.
 *
 * L'algorithme en sept temps :
 *  1. `y = génération × 150`, imposé — aucune liberté verticale ;
 *  2. largeur de fiche mesurée sur le texte ;
 *  3. récursion d'ascendance depuis l'ego : `largBloc` de bas en haut, `poseBloc` de
 *     haut en bas, qui recentre la fratrie sous le barycentre du couple parental ;
 *  4. fratrie triée par année de naissance, conjoints accolés ;
 *  5. non-ascendants : 12 passes de point fixe sur le barycentre des parents placés ;
 *  6. décrochage des chevauchements rangée par rangée, **sans jamais réordonner** ;
 *  7. nœuds-famille en losange, recentrage sur l'ego, boîte englobante.
 */
final class MoteurDePlacement
{
    /** Largeur moyenne d'un caractère du nom, en pixels. */
    public const float LARG_CAR = 6.6;

    /** Idem pour la ligne de dates, écrite plus petit. */
    public const float LARG_CAR_DATES = 5.8;

    /** Marge intérieure horizontale d'une fiche, comptée des deux côtés. */
    public const int PAD = 14;

    /** Aucune fiche n'est plus étroite, même pour un nom d'une lettre. */
    public const int LARGEUR_MINIMALE = 104;

    public const int H_FICHE = 40;

    /** L'écart vertical entre deux générations. */
    public const int H_RANG = 150;

    /** Entre deux fiches d'une même fratrie. */
    public const int ESPACE = 20;

    /** Entre le bloc paternel et le bloc maternel. */
    public const int GAP_BLOC = 70;

    /** Le nœud de jonction familial : un losange, **pas** un petit cercle (§ 7.1). */
    public const int LOSANGE_L = 22;
    public const int LOSANGE_H = 14;

    /** Le losange se pose sous la rangée de ses époux. */
    public const int DESCENTE_NOEUD_FAMILLE = 26;

    /**
     * Le point fixe de la phase 2 s'arrête de lui-même dès qu'il ne reste personne à
     * placer ; 12 est la borne du script d'origine, calée sur les 12 générations de
     * l'arbre. On la conserve telle quelle : la changer changerait la sortie sur un
     * arbre plus profond.
     */
    public const int PASSES_POINT_FIXE = 12;

    /** La marge de la boîte englobante, qui alimente le `viewBox` du viewer. */
    public const int MARGE_BOITE = 60;

    public function calcule(JeuDePlacement $jeu): Placement
    {
        return (new CalculDePlacement($jeu))->execute();
    }
}
