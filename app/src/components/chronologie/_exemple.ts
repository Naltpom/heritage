/**
 * Le jeu d'essai de la chronologie — **dérivé de l'exemple du § 9.1 du
 * contrat**, et réservé aux tests.
 *
 * Aucun module de production ne l'importe : il sert aux tests, et il a servi à
 * voir l'écran pendant que le back s'écrivait en parallèle. Le préfixe `_` le
 * dit. S'il se retrouve un jour importé par une vue, c'est qu'on a substitué un
 * décor à la donnée réelle, et c'est exactement ce qu'il faut éviter.
 *
 * Ce qu'il porte, et pourquoi chaque ligne est là :
 *
 * - **Marne Dolomite Sureau**, `BET 1901 AND 1909` — le cas de contrôle du
 *   projet. Sa naissance est une fourchette de neuf années civiles, son décès
 *   est une année sans jour connu (`certain: false` avec `min === max`). Les
 *   deux extrémités doivent se hachurer.
 * - **Natron Romarin**, vivant : `fin: null`, `ouverte: true`. Rien n'invente sa
 *   date de fin.
 * - **Silice Bourrache**, deux ans de vie en plein dans la fourchette de
 *   Sureau : sans rangement en pistes, les deux barres se recouvriraient et se
 *   liraient comme une seule.
 * - **Pyrite Tilleul**, né en 1657 : il tient la borne basse de l'axe.
 * - Les seize bandes de 25 ans sont écrites **en toutes lettres**, telles que le
 *   serveur les calcule (`debut(k) = 1657 + 25k`, `fin(k) = debut(k) + 24`). Les
 *   engendrer par une boucle ici reviendrait à réimplémenter côté front le
 *   découpage que le § 9.1 lui interdit de refaire — y compris dans un test.
 *   La dernière, `2032–2056`, dépasse `bornes.max` : c'est correct et voulu.
 *
 * **`ligneDates` est ici en forme compacte** — `"1901-1909 – 1987"`,
 * `"n. 26.9.2012"` —, celle que le § 14.4 spécifie au caractère près pour la
 * barre de chronologie. L'exemple du § 9.1 montrait de la prose
 * (`"entre 1901 et 1909 – 1987"`), forme qui appartient à `texte`, dans le bloc
 * date d'une fiche. Le front n'assemble ni ne réécrit cette chaîne : il affiche
 * ce que le serveur envoie.
 *
 * **Une incohérence de l'exemple du contrat est conservée telle quelle** :
 * `branches` vaut `["pm", "mm"]` alors que la réponse porte une barre de branche
 * `tr` (Natron Romarin). C'est l'exemple du § 9.1, au caractère près. Le front
 * n'en fait pas une erreur — `disposer()` garde tout groupe qui porte une barre,
 * même absent du filtre, parce qu'une barre sans groupe disparaîtrait du dessin
 * sans que rien ne le signale.
 */

import type { VueChronologie } from '@/lib/api'

export const EXEMPLE_CHRONOLOGIE: VueChronologie = {
  bande: 25,
  orientation: 'colonnes',
  branches: ['pm', 'mm'],
  showUncertainty: true,
  bornes: { min: 1657, max: 2037 },
  bandes: [
    { debut: 1657, fin: 1681, libelle: '1657–1681' },
    { debut: 1682, fin: 1706, libelle: '1682–1706' },
    { debut: 1707, fin: 1731, libelle: '1707–1731' },
    { debut: 1732, fin: 1756, libelle: '1732–1756' },
    { debut: 1757, fin: 1781, libelle: '1757–1781' },
    { debut: 1782, fin: 1806, libelle: '1782–1806' },
    { debut: 1807, fin: 1831, libelle: '1807–1831' },
    { debut: 1832, fin: 1856, libelle: '1832–1856' },
    { debut: 1857, fin: 1881, libelle: '1857–1881' },
    { debut: 1882, fin: 1906, libelle: '1882–1906' },
    { debut: 1907, fin: 1931, libelle: '1907–1931' },
    { debut: 1932, fin: 1956, libelle: '1932–1956' },
    { debut: 1957, fin: 1981, libelle: '1957–1981' },
    { debut: 1982, fin: 2006, libelle: '1982–2006' },
    { debut: 2007, fin: 2031, libelle: '2007–2031' },
    { debut: 2032, fin: 2056, libelle: '2032–2056' },
  ],
  barres: [
    {
      personId: 17,
      displayName: 'Marne Dolomite Sureau',
      branch: 'pm',
      generation: -3,
      sex: 'feminin',
      vivant: false,
      debut: { min: 1901, max: 1909, certain: false },
      fin: { min: 1987, max: 1987, certain: false },
      ouverte: false,
      ligneDates: '1901-1909 – 1987',
    },
    {
      personId: 1,
      displayName: 'Natron Romarin',
      branch: 'tr',
      generation: 0,
      sex: 'masculin',
      vivant: true,
      debut: { min: 2012, max: 2012, certain: true },
      fin: null,
      ouverte: true,
      ligneDates: 'n. 26.9.2012',
    },
    {
      personId: 44,
      displayName: 'Jaspe Bourrache',
      branch: 'pm',
      generation: -4,
      sex: 'masculin',
      vivant: false,
      debut: { min: 1873, max: 1873, certain: false },
      fin: { min: 1942, max: 1942, certain: true },
      ouverte: false,
      ligneDates: '1873 – 16.8.1942',
    },
    {
      personId: 58,
      displayName: 'Silice Bourrache',
      branch: 'pm',
      generation: -3,
      sex: 'feminin',
      vivant: false,
      debut: { min: 1904, max: 1904, certain: true },
      fin: { min: 1905, max: 1905, certain: true },
      ouverte: false,
      ligneDates: '7.6.1904 – 23.5.1905',
    },
    {
      personId: 61,
      displayName: 'Galène Tilleul',
      branch: 'mm',
      generation: -3,
      sex: 'feminin',
      vivant: false,
      debut: { min: 1912, max: 1912, certain: true },
      fin: { min: 1999, max: 1999, certain: false },
      ouverte: false,
      ligneDates: '29.3.1912 – 1999',
    },
    {
      personId: 72,
      displayName: 'Pyrite Tilleul',
      branch: 'mm',
      generation: -8,
      sex: 'masculin',
      vivant: false,
      debut: { min: 1657, max: 1657, certain: false },
      fin: { min: 1713, max: 1721, certain: false },
      ouverte: false,
      ligneDates: '1657 – 1713-1721',
    },
  ],
  jalons: [
    {
      type: 'recensement',
      annee: 1957,
      libelle: 'Recensement, Saint-Ronce-de-Verçay',
      personId: 17,
    },
  ],
}

/**
 * **Les trois formes de bornes que la charge réelle porte, et que le § 9.1 ne
 * décrit pas.**
 *
 * Relevées sur `GET /api/chronologie` de la pile vivante, 235 barres :
 *
 * | Forme | Nombre | Exemple réel |
 * |---|---|---|
 * | `debut` et `fin` datés | 171 | la plupart |
 * | `fin: null` + `ouverte: true` (vivante) | 11 | Natron Romarin |
 * | `fin: null` + `ouverte: false` (décès non daté) | **53** | Turquoise Marjolaine |
 * | `debut: null` (décès seul) | **11** | Béryl Lavande |
 * | une borne `min` ou `max` à `null` (`BEF`) | **6** | Silice-Opaline Glycine |
 *
 * Les deux formes du milieu contredisent l'équivalence `fin: null` ⟺
 * `ouverte: true` du § 9.1 ; la dernière suit le § 14.1 (« `null` = non bornée à
 * gauche ») que l'exemple du § 9.1 n'illustre jamais. Elles sont ici pour que le rendu soit vérifié dessus : sans elles,
 * les types du front seraient restés faux et l'écran aurait planté sur
 * soixante-quatre barres — c'est-à-dire en production, pas en test.
 */
export const EXEMPLE_BORNES_PARTIELLES: VueChronologie = {
  bande: 25,
  orientation: 'lignes',
  branches: ['pm'],
  showUncertainty: true,
  bornes: { min: 1655, max: 2037 },
  bandes: [
    { debut: 1655, fin: 1679, libelle: '1655–1679' },
    { debut: 1680, fin: 1704, libelle: '1680–1704' },
    { debut: 1705, fin: 1729, libelle: '1705–1729' },
    { debut: 1730, fin: 1754, libelle: '1730–1754' },
    { debut: 1755, fin: 1779, libelle: '1755–1779' },
    { debut: 1780, fin: 1804, libelle: '1780–1804' },
    { debut: 1805, fin: 1829, libelle: '1805–1829' },
    { debut: 1830, fin: 1854, libelle: '1830–1854' },
    { debut: 1855, fin: 1879, libelle: '1855–1879' },
    { debut: 1880, fin: 1904, libelle: '1880–1904' },
    { debut: 1905, fin: 1929, libelle: '1905–1929' },
    { debut: 1930, fin: 1954, libelle: '1930–1954' },
    { debut: 1955, fin: 1979, libelle: '1955–1979' },
    { debut: 1980, fin: 2004, libelle: '1980–2004' },
    { debut: 2005, fin: 2029, libelle: '2005–2029' },
    { debut: 2030, fin: 2054, libelle: '2030–2054' },
  ],
  barres: [
    {
      // Décédée, décès **non daté** : `fin: null` et `ouverte: false`.
      personId: 53,
      displayName: 'Turquoise Marjolaine',
      branch: 'pm',
      generation: -7,
      sex: 'feminin',
      vivant: false,
      debut: { min: 1791, max: 1791, certain: false },
      fin: null,
      ouverte: false,
      ligneDates: 'n. 1791',
    },
    {
      // Décès seul : `debut: null`.
      personId: 36,
      displayName: 'Béryl Lavande',
      branch: 'pm',
      generation: -3,
      sex: 'masculin',
      vivant: false,
      debut: null,
      fin: { min: 1995, max: 1995, certain: false },
      ouverte: false,
      ligneDates: '† 1995',
    },
    {
      // Vivante : `fin: null` et `ouverte: true`.
      personId: 1,
      displayName: 'Natron Romarin',
      branch: 'pm',
      generation: 0,
      sex: 'masculin',
      vivant: true,
      debut: { min: 2009, max: 2009, certain: true },
      fin: null,
      ouverte: true,
      ligneDates: 'n. 26.9.2009',
    },
    {
      // **Borne basse nulle** : `BEF 1899`, « décédée avant 1899 », naissance
      // inconnue. Six barres de la charge réelle portent une borne `null`, que
      // le § 14.1 documente (« `null` = non bornée à gauche ») et que l'exemple
      // du § 9.1 ne montre jamais. Sans ce cas dans le jeu d'essai, le front
      // dessinait une barre partant 1 644 ans avant l'origine de l'axe.
      personId: 88,
      displayName: 'Silice-Opaline Glycine',
      branch: 'pm',
      generation: -6,
      sex: 'feminin',
      vivant: false,
      debut: null,
      fin: { min: null, max: 1898, certain: false },
      ouverte: false,
      ligneDates: '† <1899',
    },
    {
      // Naissance connue, décès **avant** une date : l'incertitude de fin court
      // sur toute la barre, et c'est exact — elle est morte entre sa naissance
      // et 1931.
      personId: 91,
      displayName: 'Marne Galène Ciboule',
      branch: 'pm',
      generation: -5,
      sex: 'feminin',
      vivant: false,
      debut: { min: 1878, max: 1879, certain: false },
      fin: { min: null, max: 1931, certain: false },
      ouverte: false,
      ligneDates: '1878-1879 – <1931',
    },
    {
      // Aucune borne : l'API ne devrait pas l'envoyer (§ 9.1). Le rendu ne doit
      // ni planter ni la poser n'importe où.
      personId: 999,
      displayName: 'Sans aucune date',
      branch: 'pm',
      generation: -5,
      sex: 'inconnu',
      vivant: false,
      debut: null,
      fin: null,
      ouverte: false,
      ligneDates: '—',
    },
  ],
  jalons: [],
}

/** Une réponse sans aucune bande ni barre : l'état vide, que l'écran doit dire
 *  explicitement au lieu d'afficher un canevas blanc. */
export const EXEMPLE_VIDE: VueChronologie = {
  bande: 10,
  orientation: 'lignes',
  branches: ['pp', 'pm', 'mp', 'mm', 'tr'],
  showUncertainty: true,
  bornes: { min: 0, max: 0 },
  bandes: [],
  barres: [],
  jalons: [],
}
