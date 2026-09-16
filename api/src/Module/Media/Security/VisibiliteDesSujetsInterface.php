<?php

declare(strict_types=1);

namespace App\Module\Media\Security;

use App\Module\Core\Enum\Regime;
use App\Module\Media\Enum\MediaSubject;

/**
 * Le port **local** par lequel `Media` interroge le cercle de visibilité.
 *
 * ## Ce port ne définit rien — il traduit
 *
 * Point tranché explicitement, parce que les deux lectures possibles ne se valent pas :
 * **cette interface ne porte aucune définition de « visible »**. Elle ne connaît ni la
 * formule du § 6.2 du dossier, ni le nombre de générations remontées, ni la distinction
 * vivant / décédé, ni le régime du compte en attente. Elle pose ses questions à qui sait
 * y répondre, et obéit. Il n'existe donc **pas** de seconde définition de la
 * confidentialité dans le produit, et il ne doit jamais en apparaître une ici : une règle
 * de confidentialité ne se trompe qu'une fois pour fuir.
 *
 * Ce qu'elle ajoute au contrat du noyau est un **vocabulaire**, pas une règle. Le noyau
 * ne connaît que des identifiants de personnes — `Core\Security\CercleDeVisibiliteInterface`
 * le dit en toutes lettres et explique pourquoi : un type-hint vers une entité de `Tree`
 * inverserait la dépendance du noyau. Ce module, lui, raisonne en *sujets de média* :
 * une personne, une union, une citation. La traduction « une union suit ses époux »,
 * « une citation suit la fiche qu'elle étaye » appartient à qui possède ces lignes, et
 * elle a lieu dans **un seul** fichier : {@see VisibiliteParLeCercle}, le seul de `Media`
 * à connaître le contrat du noyau.
 *
 * ## Le lecteur n'est pas un paramètre
 *
 * Aucune méthode ne le prend, et c'est repris du noyau sans discuter : l'implémentation
 * résout le lecteur elle-même, session comprise. Le motif est la bascule « voir comme un
 * membre » (§ 10.4 du contrat d'API) — un lecteur passé par chaque appelant est un
 * lecteur qu'un appelant finira par construire sans la bascule, et une bascule qu'on
 * oublie de rejouer sur un appel ne vérifie plus rien.
 *
 * Corollaire qu'aucune implémentation n'a le droit d'assouplir, et que ce module a dû
 * corriger chez lui : **il n'existe pas de mode « sans filtre »**. Un administrateur
 * passe par un cercle complet, jamais par un `if ($estAdmin) { return true; }`.
 *
 * ## Les refus ne se distinguent pas
 *
 * « N'existe pas » et « pas le droit » rendent la même chose, et l'appelant rend `404`
 * dans les deux cas (§ 15.3 du contrat) — sans quoi la réponse dirait à un lecteur hors
 * cercle quelles personnes existent.
 */
interface VisibiliteDesSujetsInterface
{
    /**
     * Le régime de lecture du lecteur courant.
     *
     * Lu ici plutôt que recalculé : `Core\Security\RegimeVoter::regimeDe()` est l'unique
     * correspondance statut → régime du projet, et une seconde, même triviale, finirait
     * par ne plus dire la même chose.
     */
    public function regimeDuLecteur(): Regime;

    /**
     * Le sujet existe-t-il, et sous quel libellé ?
     *
     * Appelée **avant écriture** pour valider la référence inter-module et en rapporter
     * le libellé à dénormaliser (mesure 1 de la compensation de l'ADR 0002). Rend `null`
     * si le sujet n'existe pas — ou si le lecteur n'a rien à y faire, ce qui est
     * indistinguable par construction.
     */
    public function decrire(MediaSubject $kind, int $id): ?SujetMedia;

    /**
     * Le lecteur courant a-t-il le droit de voir ce sujet ?
     *
     * Appelée à **chaque** lecture : liste, détail, octets du fichier, octets de la
     * vignette. Une seule réponse gouverne les quatre, sinon la vignette finira par
     * suivre une règle que la fiche n'applique plus — et la vignette d'un portrait en dit
     * autant que le portrait.
     */
    public function estVisible(MediaSubject $kind, int $id): bool;

    /**
     * La même question pour plusieurs sujets d'un coup.
     *
     * Elle existe pour une raison mesurable, et c'est la même que celle du contrat du
     * noyau : le filtre « Avec image » de la barre d'outils du graphe pose la question
     * pour les 274 personnes de l'arbre **à chaque affichage**. Une boucle sur
     * {@see estVisible()} y ferait 274 résolutions de cercle là où une seule suffit.
     *
     * @param list<int> $ids
     *
     * @return list<int> ceux que le lecteur voit, dans l'ordre reçu, doublons retirés
     */
    public function filtrerVisibles(MediaSubject $kind, array $ids): array;
}
