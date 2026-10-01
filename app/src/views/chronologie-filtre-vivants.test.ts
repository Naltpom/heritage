/**
 * Le filtre « vivants uniquement » de la chronologie.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUI PEUT ÊTRE PROUVÉ ICI, ET CE QUI NE LE PEUT PAS.**
 *
 * Le projet n'a ni `jsdom`, ni `happy-dom`, ni `@vue/test-utils` — même
 * arbitrage qu'`anniversaires-rendu.test.ts`. Sous l'environnement `node` de
 * Vitest, `createSSRApp` + `renderToString` est le seul rendu disponible, et
 * `onMounted` ne s'exécute jamais. Cela partage le fichier en deux :
 *
 *  - le **prédicat** vit dans son propre module, `components/chronologie/vivants.ts`,
 *    et s'appelle directement. Il n'est pas rangé dans le `.vue` : un export
 *    nommé depuis un composant ne se résout que par le chemin `*.vue`, que seul
 *    `vue-tsc` sait suivre — `tsc` nu retombe sur la déclaration de complaisance
 *    d'`env.d.ts`, qui ne porte qu'un export par défaut, et le prédicat arrive
 *    en `any` avec toute sa cascade. Le module rend les types voyageurs ;
 *  - les deux morceaux d'interface qui portent le filtre — la case de la barre
 *    d'outils et la réserve — reçoivent tout par leurs propriétés : on les rend
 *    et on lit le balisage.
 *
 * Ce fichier ne prouve **rien de la peinture** : ni que la case est visible sur
 * un écran étroit, ni que la réserve ne recouvre pas le canevas. Cela se mesure
 * dans un navigateur.
 *
 * **Ce qu'aucun test d'ici ne peut atteindre**, et qu'il faut donc savoir :
 * le branchement lui-même — que la vue passe bien `vueAffichee` au canevas
 * plutôt que `vue`. `vivantsSeuls` est un état interne du `<script setup>`,
 * qu'aucun test sans navigateur ne peut allumer. Un vert ici ne dit donc pas
 * que l'écran filtre ; il dit que le prédicat est juste et que les deux
 * morceaux d'interface disent ce qu'il faut.
 *
 * Les noms de ce jeu d'essai sont inventés : ils viennent de `_exemple.ts`, qui
 * est dérivé de l'exemple du contrat. La base porte l'arbre réel du
 * commanditaire, et une suite de tests n'est pas un endroit où en recopier des
 * lignes.
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import {
  EXEMPLE_BORNES_PARTIELLES,
  EXEMPLE_CHRONOLOGIE,
  EXEMPLE_VIDE,
} from '@/components/chronologie/_exemple'
import { estVide } from '@/components/chronologie/disposition'
import { filtrerVivants } from '@/components/chronologie/vivants'
import BarreOutilsChronologie from '@/components/chronologie/BarreOutilsChronologie.vue'
import ReserveVivants from '@/components/chronologie/ReserveVivants.vue'

function rendre(composant: Component, proprietes: Record<string, unknown> = {}): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(composant, proprietes) }))
}

/** Les propriétés de la barre d'outils, hors celle qu'un cas veut changer. */
function outils(supplement: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    bande: 25,
    orientation: 'lignes',
    branches: ['pm', 'mm'],
    montrerIncertitude: true,
    vivantsSeuls: false,
    chargement: false,
    preferenceAJour: true,
    enregistrement: false,
    ...supplement,
  }
}

/* ==================================================================== */
/* Le témoin : le jeu d'essai porte-t-il les cas qui discriminent ?      */
/* ==================================================================== */

/**
 * **Ces trois cas font tout le pouvoir des tests qui suivent.**
 *
 * « Le filtre écarte une barre sans date de fin » ne prouve rien si le jeu
 * d'essai n'en contient aucune : l'assertion passerait sur une liste vide, en
 * vert, pour toujours. On vérifie donc d'abord que le piège existe — sans quoi
 * les cas suivants ne mesureraient que leur propre absence d'objet.
 */
describe('le jeu d’essai porte les cas qui séparent les prédicats', () => {
  it('contient au moins une barre vivante, sinon il n’y a rien à retenir', () => {
    expect(EXEMPLE_CHRONOLOGIE.barres.some((b) => b.vivant)).toBe(true)
    expect(EXEMPLE_BORNES_PARTIELLES.barres.some((b) => b.vivant)).toBe(true)
  })

  it('contient au moins une barre décédée, sinon il n’y a rien à écarter', () => {
    expect(EXEMPLE_CHRONOLOGIE.barres.some((b) => !b.vivant)).toBe(true)
  })

  it('contient une barre décédée SANS date de fin — le piège des 53 barres', () => {
    // `fin: null` avec `ouverte: false` : une personne décédée dont on ignore la
    // date du décès. La charge réelle en porte 53 sur 235. Un filtre écrit sur
    // `fin === null` ou sur `!ouverte` les classerait vivantes — et rendrait un
    // résultat plausible, jamais une erreur.
    const piege = EXEMPLE_BORNES_PARTIELLES.barres.filter(
      (b) => b.fin === null && !b.ouverte && !b.vivant,
    )
    expect(piege.length).toBeGreaterThan(0)
  })
})

/* ==================================================================== */
/* Le prédicat                                                          */
/* ==================================================================== */

describe('filtrerVivants', () => {
  it('éteint, rend la vue elle-même — la MÊME, pas une copie', () => {
    // Le canevas surveille `vue` par identité et se reconstruit à chaque nouvel
    // objet. Rendre `{ ...vue }` ferait repeindre le dessin à chaque changement
    // de réglage sans que rien n'ait bougé, et le cadrage du lecteur sauterait.
    expect(filtrerVivants(EXEMPLE_CHRONOLOGIE, false)).toBe(EXEMPLE_CHRONOLOGIE)
    expect(filtrerVivants(EXEMPLE_BORNES_PARTIELLES, false)).toBe(EXEMPLE_BORNES_PARTIELLES)
  })

  it('allumé, ne retient que les barres dont `vivant` est vrai', () => {
    const filtree = filtrerVivants(EXEMPLE_CHRONOLOGIE, true)
    expect(filtree.barres.every((b) => b.vivant)).toBe(true)
    expect(filtree.barres.map((b) => b.displayName)).toEqual(['Natron Romarin'])
  })

  it('retient exactement les vivants du jeu d’essai, sans en perdre un', () => {
    // L'égalité des deux côtés : ni une barre vivante de trop, ni une de moins.
    const attendus = EXEMPLE_CHRONOLOGIE.barres.filter((b) => b.vivant).map((b) => b.personId)
    const obtenus = filtrerVivants(EXEMPLE_CHRONOLOGIE, true).barres.map((b) => b.personId)
    expect(obtenus).toEqual(attendus)
  })

  it('écarte une personne décédée dont la date de décès est inconnue', () => {
    // Le piège du témoin ci-dessus, vérifié par l'effet : `fin: null` ne doit
    // jamais suffire à faire passer une barre pour vivante.
    const filtree = filtrerVivants(EXEMPLE_BORNES_PARTIELLES, true)
    const noms = filtree.barres.map((b) => b.displayName)
    expect(noms).not.toContain('Turquoise Marjolaine')
    expect(noms).not.toContain('Sans aucune date')
    expect(noms).toEqual(['Natron Romarin'])
  })

  it('n’est pas le prédicat « sans date de fin » — les deux comptes diffèrent', () => {
    // Si un jour quelqu'un réécrit le filtre sur `fin === null`, ce cas rougit :
    // les deux ensembles n'ont pas la même taille sur ce jeu d'essai.
    const parVivant = filtrerVivants(EXEMPLE_BORNES_PARTIELLES, true).barres.length
    const parFinNulle = EXEMPLE_BORNES_PARTIELLES.barres.filter((b) => b.fin === null).length
    expect(parVivant).not.toBe(parFinNulle)
  })

  it('ne touche ni aux bandes, ni aux bornes, ni aux branches', () => {
    // L'axe vient du serveur. Le filtre cache des barres ; il ne redécoupe pas
    // le temps, sinon l'axe changerait de graduation à chaque geste.
    const filtree = filtrerVivants(EXEMPLE_CHRONOLOGIE, true)
    expect(filtree.bandes).toBe(EXEMPLE_CHRONOLOGIE.bandes)
    expect(filtree.bornes).toBe(EXEMPLE_CHRONOLOGIE.bornes)
    expect(filtree.branches).toBe(EXEMPLE_CHRONOLOGIE.branches)
    expect(filtree.bande).toBe(EXEMPLE_CHRONOLOGIE.bande)
  })

  it('ne modifie pas la vue reçue', () => {
    // `vue` reste ce que le serveur a envoyé : c'est sur elle que se lit ce que
    // le compte a le droit de voir, et une mutation en place la corromprait
    // pour tout le reste de l'écran.
    const avant = EXEMPLE_CHRONOLOGIE.barres.length
    filtrerVivants(EXEMPLE_CHRONOLOGIE, true)
    expect(EXEMPLE_CHRONOLOGIE.barres.length).toBe(avant)
  })

  it('emporte les jalons des personnes écartées', () => {
    // Le seul jalon du jeu d'essai appartient à une personne décédée : il doit
    // partir avec elle. Le laisser rendrait l'objet incohérent — des `jalons`
    // qui désignent des personnes absentes de `barres`.
    expect(EXEMPLE_CHRONOLOGIE.jalons.length).toBeGreaterThan(0)
    const filtree = filtrerVivants(EXEMPLE_CHRONOLOGIE, true)
    const visibles = new Set(filtree.barres.map((b) => b.personId))
    expect(filtree.jalons.every((j) => visibles.has(j.personId))).toBe(true)
    expect(filtree.jalons).toEqual([])
  })

  it('sur une vue déjà vide, ne fabrique rien', () => {
    const filtree = filtrerVivants(EXEMPLE_VIDE, true)
    expect(filtree.barres).toEqual([])
    expect(filtree.jalons).toEqual([])
  })

  it('une vue où personne n’est vivant donne l’état vide explicite', () => {
    // C'est ce qui fait apparaître le panneau « Aucune barre à afficher » plutôt
    // qu'un canevas blanc, qu'on prendrait pour une panne.
    const tousDecedes = {
      ...EXEMPLE_CHRONOLOGIE,
      barres: EXEMPLE_CHRONOLOGIE.barres.filter((b) => !b.vivant),
    }
    expect(estVide(tousDecedes)).toBe(false)
    expect(estVide(filtrerVivants(tousDecedes, true))).toBe(true)
  })
})

/* ==================================================================== */
/* La case de la barre d'outils                                         */
/* ==================================================================== */

/**
 * La balise `<input>` de notre case, isolée des autres.
 *
 * Deux cases se suivent sur cette barre — l'incertitude et celle-ci —, et le
 * rendu serveur écrit les attributs **dans l'ordre du gabarit** : découper la
 * chaîne à partir du `title=` laisserait le `checked` qui le précède du mauvais
 * côté de la coupe, et le test lirait « décochée » quoi qu'il arrive.
 *
 * Absente, on **lève** plutôt que de rendre une chaîne vide : un `expect` sur
 * du vide passerait pour tous les cas « pas cochée », et la disparition de la
 * case se lirait comme un succès.
 */
function caseVivants(html: string): string {
  const balise = (html.match(/<input\b[^>]*>/g) ?? []).find((t) =>
    t.includes('N’afficher que les personnes vivantes'),
  )
  if (balise === undefined) throw new Error('La case « Vivants uniquement » est introuvable.')
  return balise
}

describe('BarreOutilsChronologie — la case « Vivants uniquement »', () => {
  it('porte le libellé du filtre', async () => {
    expect(await rendre(BarreOutilsChronologie, outils())).toContain('Vivants uniquement')
  })

  it('n’est pas cochée quand le filtre est éteint', async () => {
    const html = await rendre(BarreOutilsChronologie, outils({ vivantsSeuls: false }))
    expect(caseVivants(html)).not.toContain('checked')
  })

  it('est cochée quand le filtre est allumé', async () => {
    const html = await rendre(BarreOutilsChronologie, outils({ vivantsSeuls: true }))
    expect(caseVivants(html)).toContain('checked')
  })

  it('l’état de la case ne suit pas celui de l’incertitude', async () => {
    // Les deux cases se ressemblent ; les brancher sur la même propriété par
    // copier-coller donnerait un écran où cocher l'une coche l'autre.
    const html = await rendre(
      BarreOutilsChronologie,
      outils({ montrerIncertitude: true, vivantsSeuls: false }),
    )
    expect(html).toContain('Hachurer les dates incertaines')
    expect(caseVivants(html)).not.toContain('checked')
  })

  it('dit au lecteur que ce réglage n’est pas enregistré', async () => {
    // Il est posé à côté de quatre réglages qui, eux, s'enregistrent : sans
    // cette mention, on le croit retenu et on le retrouve éteint au retour.
    expect(await rendre(BarreOutilsChronologie, outils())).toContain('n’est pas enregistré')
  })

  it('le bouton « Enregistrer » n’annonce toujours que les quatre champs du contrat', async () => {
    // `PreferenceChronologie` a quatre champs et le `PUT` est un remplacement
    // complet. Si quelqu'un ajoute le filtre à cette énumération, c'est qu'il
    // croit l'enregistrer — et le serveur, lui, n'en a pas le champ.
    const html = await rendre(BarreOutilsChronologie, outils({ preferenceAJour: false }))
    expect(html).toContain('Retenir la bande, le sens, les branches et l’incertitude')
    expect(html).not.toContain('Retenir la bande, le sens, les branches, l’incertitude et')
  })
})

/* ==================================================================== */
/* La réserve                                                           */
/* ==================================================================== */

describe('ReserveVivants', () => {
  it('dit que les fiches confidentielles ne peuvent pas s’afficher', async () => {
    // La réserve exigée à l'écran : sans elle, deux comptes qui comparent leurs
    // écrans concluent au bogue. Depuis le 2026-10-01, un membre voit tous les
    // vivants : seule une fiche confidentielle manque (ADR 0003).
    const html = await rendre(ReserveVivants)
    expect(html).toContain('fiche confidentielle')
    expect(html).toContain('aucune barre')
    expect(html).not.toContain('cercle de visibilité')
  })

  it('dit que deux comptes ne verront pas forcément les mêmes personnes', async () => {
    expect(await rendre(ReserveVivants)).toContain('ne verra donc pas forcément les mêmes personnes')
  })

  it('dit que « vivant » est calculé, pas relevé', async () => {
    // La seconde réserve : trois fiches de la base portent un état de décès faux
    // et connu. Elles tombent du mauvais côté du filtre, et c'est la fiche qui
    // est muette, pas le filtre qui se trompe.
    const html = await rendre(ReserveVivants)
    expect(html).toContain('calculé')
    expect(html).toContain('le décès n’a jamais été renseigné')
  })

  it('n’affiche AUCUN décompte', async () => {
    // Un compteur de barres — affichées ou masquées — trahirait par soustraction
    // le nombre de personnes que le cercle protège. C'est la raison pour
    // laquelle `Disposition.ignorees` est calculé sans jamais être montré.
    const html = await rendre(ReserveVivants)
    const texte = html.replace(/<[^>]*>/g, '')
    expect(texte).not.toMatch(/\d/)
  })
})
