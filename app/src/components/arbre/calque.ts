/**
 * Ce que le calque du graphe **n'a pas pu montrer**, mis en mots.
 *
 * ── Le défaut que ce fichier existe pour empêcher ────────────────────────────
 *
 * Mesuré sur un cas réel : cinq demandes portant une branche de vingt-deux
 * personnes ont rendu un calque annonçant `actif: true` et
 * `demandes: [1,2,3,4,5]` — avec **cinq nœuds placés et dix-sept absents**. Le
 * bandeau de l'arbre disait « votre arbre affiche 5 demandes en attente de
 * validation », ce qui était vrai et trompeur : il laissait croire que la
 * branche affichée était la branche déposée.
 *
 * > **`calque.actif` dit qu'il y a quelque chose en attente. Il ne dit pas que
 * > tout est là.**
 *
 * ── Pourquoi l'omission est la bonne réponse, et le silence la mauvaise ──────
 *
 * Le placement partagé ne contient que l'arbre **validé**. Une fiche rattachée à
 * une autre fiche de la même demande n'a nulle part où s'accrocher, et la poser
 * faute de mieux la mettrait à `x = 0` — sur l'ego. Dix-sept fiches empilées là
 * seraient pires qu'absentes. Le serveur a donc raison de les omettre ; ce qui
 * manquait, c'est de le dire.
 *
 * ── Trois noms, puis un compte ───────────────────────────────────────────────
 *
 * Nommer les dix-sept ferait un bandeau plus haut que l'arbre. Nommer zéro
 * laisserait l'utilisateur sans geste : il saurait qu'il manque quelque chose
 * sans savoir quoi. Trois noms cliquables et un compte pour le reste donnent les
 * deux — laquelle manque, et combien.
 */

import type { NoeudNonPlace } from '@/lib/api'

/** Combien de fiches absentes sont nommées avant qu'on se mette à compter. */
export const NOMMES_AU_PLUS = 3

export interface AvertissementDuCalque {
  /** « 3 fiches proposées ne peuvent pas être placées dans l’arbre : » */
  phrase: string
  /** Les premières, nommées — chacune mène à sa demande. */
  nommes: NoeudNonPlace[]
  /** Combien restent au-delà des nommées. `0` quand elles tiennent toutes. */
  enPlus: number
}

/**
 * L'avertissement à afficher, ou `null` quand il n'y a rien à dire.
 *
 * `null` et non un objet vide : un bandeau qui s'affiche toujours, vide la
 * plupart du temps, cesse d'être lu — et c'est la lecture qui est tout l'objet.
 */
export function avertissementDuCalque(nonPlaces: NoeudNonPlace[]): AvertissementDuCalque | null {
  if (nonPlaces.length === 0) {
    return null
  }

  const nommes = nonPlaces.slice(0, NOMMES_AU_PLUS)

  return {
    // L'accord est composé ici et non à coups de `<template v-if>` imbriqués :
    // un pluriel éclaté sur six balises est illisible et se casse au premier
    // ajout.
    phrase:
      nonPlaces.length === 1
        ? 'Une fiche proposée ne peut pas être placée dans l’arbre :'
        : `${nonPlaces.length} fiches proposées ne peuvent pas être placées dans l’arbre :`,
    nommes,
    enPlus: nonPlaces.length - nommes.length,
  }
}

/**
 * Ce qui suit le nom de rang `rang` dans l'énumération.
 *
 * Une virgule entre deux noms, un point après le dernier — **sauf** s'il reste
 * des absences non nommées, auquel cas la phrase continue par « et N autres. »
 * et une virgule avant le « et » serait fautive.
 *
 * Le point final n'est pas une coquetterie : sans lui, la dernière fiche nommée
 * se colle à la phrase qui suit — « Cèpe Chanterelle Elles se rattachent à… ».
 * Le cas ne se voit **que** lorsque les absences tiennent toutes dans les noms,
 * c'est-à-dire précisément le cas le plus fréquent.
 *
 * C'est ici et non dans le gabarit parce que le bandeau est une rangée `flex` à
 * gouttière : une virgule posée comme élément voisin recevrait la gouttière
 * comme les autres, et s'écrirait « Morille Chanterelle , Cèpe Chanterelle ».
 * La ponctuation appartient au nom, pas à la rangée.
 */
export function ponctuationApres(avertissement: AvertissementDuCalque, rang: number): string {
  if (rang < avertissement.nommes.length - 1) {
    return ','
  }

  return avertissement.enPlus > 0 ? '' : '.'
}

/**
 * L'écart entre ce que le calque a reçu et ce qu'il a posé, **tel que le serveur
 * le mesure** — jamais recalculé ici.
 *
 * Le serveur compte les deux sur la même sortie, si bien que
 * `proposes === places + nonPlaces.length` par construction. Le front ne
 * redérive donc pas le compte : il l'affiche. Une seconde arithmétique côté
 * client serait un second endroit où le chiffre peut être faux, et le jour où
 * les deux divergeraient, c'est l'écran qui aurait tort sans que rien ne le
 * signale.
 */
export function calqueEstComplet(calque: { proposes: number; places: number }): boolean {
  return calque.proposes === calque.places
}
