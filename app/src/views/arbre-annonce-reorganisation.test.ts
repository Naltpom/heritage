/**
 * **L'annonce est annoncée, pas subie — et ce n'est pas une boîte de dialogue.**
 *
 * ────────────────────────────────────────────────────────────────────
 * Le commanditaire a demandé deux choses en même temps, et elles se contredisent
 * si l'on n'y prend pas garde : que la réorganisation de l'arbre soit **dite**,
 * et qu'elle ne **bloque** pas — *« pas une boîte de dialogue, je suis sur
 * téléphone »*.
 *
 * Une modale satisferait la première et trahirait la seconde ; un bandeau qui
 * s'efface après trois secondes satisferait la seconde et trahirait la première.
 * Ces cas tiennent les deux à la fois, et ils le font sur le **texte rendu** —
 * même méthode que `arbre-recherche-mobile.test.ts`, pour la même raison : ce
 * dépôt ne monte pas ses composants, et une règle qui ne vit que dans un
 * `<template>` n'est éprouvée par rien.
 *
 * Les deux accusations que ces cas ont pour charge de trancher étaient écrites
 * avant eux :
 *   — « l'annonce est une boîte de dialogue déguisée » ;
 *   — « le bouton n'annonce pas ce qu'il va faire, ou l'annonce avec un nombre
 *      écrit en dur qui se périmera ».
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const lire = (chemin: string): string =>
  readFileSync(fileURLToPath(new URL(chemin, import.meta.url)), 'utf8')

/**
 * Le texte **sans les commentaires** : ces cas portent sur ce qui est *rendu*, et
 * un commentaire qui explique pourquoi la modale a été écartée doit pouvoir la
 * nommer sans faire rougir la garde qui l'interdit.
 */
const sansCommentaires = (source: string): string =>
  source
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')

const vue = sansCommentaires(lire('./ArbreView.vue'))
const gabarit = vue.slice(vue.indexOf('<template>'))

describe('l’annonce de la réorganisation', () => {
  it('N’EST PAS UNE BOÎTE DE DIALOGUE', () => {
    expect(gabarit).not.toMatch(/<dialog/)
    expect(gabarit).not.toMatch(/role="(dialog|alertdialog)"/)
    expect(gabarit).not.toMatch(/showModal|window\.alert|confirm\(/)
  })

  it('ne coupe pas la parole à un lecteur d’écran', () => {
    // `polite` attend la fin de ce qui est en cours ; `assertive` interromprait
    // la lecture d'une fiche pour annoncer un déplacement de cartes.
    expect(gabarit).toContain('aria-live="polite"')
    expect(gabarit).not.toMatch(/aria-live="assertive"|role="alert"/)
  })

  it('se signale comme un état, et pas comme une alerte', () => {
    const bandeau = gabarit.slice(gabarit.indexOf('annonceCourante !== null'))
    expect(bandeau.slice(0, 700)).toContain('role="status"')
  })

  it('NE DISPARAÎT PAS TOUT SEUL : aucune minuterie ne l’efface', () => {
    // Un bandeau qui s'efface n'est pas lu par qui regardait ailleurs, et
    // l'information est perdue au moment précis où elle compte.
    const script = sansCommentaires(lire('./ArbreView.vue')).slice(0, vue.indexOf('<template>'))
    expect(script).not.toMatch(/setTimeout\([^)]*annonceCourante/)
  })

  it('se ferme d’un geste, et le geste est nommé', () => {
    const bandeau = gabarit.slice(gabarit.indexOf('annonceCourante !== null'))
    expect(bandeau.slice(0, 1400)).toContain('annonceCourante = null')
    expect(bandeau.slice(0, 1400)).toContain('Masquer')
  })

  it('compose sa phrase HORS du gabarit', () => {
    // La phrase vient d'`insertionLocale.annonce()`, qui est éprouvée. Une
    // phrase assemblée dans le `<template>` ne le serait par rien, et son
    // accord au pluriel se découvrirait à l'écran.
    expect(gabarit).toContain('annonceCourante.texte')
    expect(gabarit).not.toMatch(/fiches ont changé de place/)
  })
})

describe('le bouton « réorganiser »', () => {
  it('existe, et il est sobre — un bouton, pas une bannière', () => {
    expect(gabarit).toContain('Réorganiser l’arbre')
    expect(gabarit).toContain('@click="reorganiser"')
  })

  it('DIT CE QU’IL VA FAIRE avant qu’on le presse', () => {
    expect(gabarit).toContain('phraseDuBoutonReorganiser')
  })

  it('n’écrit AUCUN nombre en dur : il les calcule', () => {
    const ligne = gabarit.slice(
      gabarit.indexOf('effetDeLaReorganisationCourante !== null'),
      gabarit.indexOf('effetDeLaReorganisationCourante !== null') + 900,
    )
    expect(ligne).not.toMatch(/Replacera \d/)
    expect(ligne).not.toMatch(/\d+ fiches sur \d+/)
  })

  it('ne s’affiche pas quand il n’a rien à faire', () => {
    expect(gabarit).toContain('v-if="effetDeLaReorganisationCourante !== null"')
  })
})

describe('la mémoire des places', () => {
  const script = vue.slice(0, vue.indexOf('<template>'))

  /**
   * Le reste de la règle — garder les places sur un changement de données, les
   * oublier sur un changement de mise en page — est éprouvé **en l'exécutant**,
   * dans `arbre-insertion-locale.test.ts`. Ne reste ici que ce qu'aucun appel ne
   * peut atteindre : le `<script setup>` est une fermeture.
   */
  it('ne rejoue pas le dépliage sur une insertion locale', () => {
    expect(script).toContain('animerLaProchaineArrivee')
    expect(script).toMatch(/if \(!animerLaProchaineArrivee\) return/)
  })
})
