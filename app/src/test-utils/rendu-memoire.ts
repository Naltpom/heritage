/**
 * Un **rendu en mémoire** pour les tests : monter un écran, attendre ses
 * lectures, cliquer, et lire ce qu'il affiche — sans DOM ni dépendance de test.
 *
 * Les tests de rendu du dépôt passent par le SSR (`renderToString`), qui ne sait
 * ni attendre une lecture ni cliquer. Pour un geste qui n'existe qu'après une
 * lecture et ne se prouve qu'au clic, on monte le composant avec
 * `createRenderer` de Vue sur des nœuds de quelques lignes. Le fichier de test
 * doit s'appeler `*.client.test.ts` : `vite.config.ts` compile alors les `.vue`
 * pour le client (`testTransformMode`), faute de quoi ils n'ont qu'un
 * `ssrRender`, que rien ne peut monter.
 *
 * Ce n'est pas un DOM, et il ne prétend pas l'être : pas de CSS, pas de mise en
 * page, pas de `window`. Ce qu'on y vérifie, c'est ce que le gabarit **écrit**
 * — les nœuds, leurs attributs, leur texte — et ce que les clics **changent**.
 */
import { createRenderer, type Component } from 'vue'

export interface Noeud {
  type: 'element' | 'texte' | 'commentaire'
  tag: string
  texte: string
  attributs: Record<string, unknown>
  ecouteurs: Record<string, unknown>
  enfants: Noeud[]
  parent: Noeud | null
  open?: boolean
  showModal?: () => void
  close?: () => void
  focus?: () => void
  /** Ce que `v-model` pose sur un champ natif : ses écouteurs (`input`,
   *  `change`…) rejoignent ceux du gabarit, dans `ecouteurs`. */
  addEventListener?: (type: string, ecouteur: unknown) => void
  removeEventListener?: (type: string, ecouteur: unknown) => void
  /** Lu par `v-model` quand la valeur change sous le champ : la racine du rendu.
   *  Le test qui s'en sert déclare `Document` et `ShadowRoot` (`vi.stubGlobal`) :
   *  la racine n'étant ni l'un ni l'autre, le champ prend la nouvelle valeur. */
  getRootNode?: () => Noeud
  /** Ce que `v-show` écrit : `display: 'none'` quand l'élément est masqué. */
  style?: Record<string, string>
}

export function noeud(type: Noeud['type'], tag = '', texte = ''): Noeud {
  const n: Noeud = { type, tag, texte, attributs: {}, ecouteurs: {}, enfants: [], parent: null }
  if (type === 'element') {
    n.focus = () => undefined
    n.style = {}
    n.addEventListener = (type, ecouteur) => {
      const deja = n.ecouteurs[type]
      n.ecouteurs[type] = deja === undefined ? ecouteur : [...(Array.isArray(deja) ? deja : [deja]), ecouteur]
    }
    n.removeEventListener = (type, ecouteur) => {
      const deja = n.ecouteurs[type]
      if (deja === ecouteur) delete n.ecouteurs[type]
      else if (Array.isArray(deja)) n.ecouteurs[type] = deja.filter((e) => e !== ecouteur)
    }
    n.getRootNode = () => {
      let racine = n
      while (racine.parent !== null) racine = racine.parent
      return racine
    }
  }
  if (tag === 'dialog') {
    n.open = false
    n.showModal = () => {
      n.open = true
    }
    n.close = () => {
      n.open = false
    }
  }
  return n
}

function detacher(enfant: Noeud): void {
  const parent = enfant.parent
  if (parent === null) return
  parent.enfants.splice(parent.enfants.indexOf(enfant), 1)
  enfant.parent = null
}

const { createApp } = createRenderer<Noeud, Noeud>({
  createElement: (tag) => noeud('element', tag),
  createText: (texte) => noeud('texte', '', texte),
  createComment: (texte) => noeud('commentaire', '', texte),
  setText: (n, texte) => {
    n.texte = texte
  },
  setElementText: (n, texte) => {
    n.enfants.forEach((enfant) => (enfant.parent = null))
    n.enfants = []
    if (texte !== '') {
      const t = noeud('texte', '', texte)
      t.parent = n
      n.enfants.push(t)
    }
  },
  insert: (enfant, parent, ancre) => {
    detacher(enfant)
    const rang = ancre ? parent.enfants.indexOf(ancre) : -1
    if (rang < 0) parent.enfants.push(enfant)
    else parent.enfants.splice(rang, 0, enfant)
    enfant.parent = parent
  },
  remove: detacher,
  parentNode: (n) => n.parent,
  nextSibling: (n) => (n.parent === null ? null : (n.parent.enfants[n.parent.enfants.indexOf(n) + 1] ?? null)),
  patchProp: (n, cle, _avant, apres) => {
    if (/^on[A-Z]/.test(cle)) {
      const nom = cle.slice(2).toLowerCase()
      if (apres) n.ecouteurs[nom] = apres
      else delete n.ecouteurs[nom]
      return
    }
    // Comme le DOM de Vue 3 : `false` reste une valeur (`aria-expanded="false"`) ;
    // seuls `null` et `undefined` retirent l'attribut.
    if (apres === null || apres === undefined) delete n.attributs[cle]
    else n.attributs[cle] = apres
  },
})

export function versHtml(n: Noeud): string {
  if (n.type === 'texte') return n.texte
  if (n.type === 'commentaire') return ''
  const attributs = Object.entries(n.attributs)
    .filter(([, valeur]) => valeur !== false)
    .map(([cle, valeur]) => (valeur === true ? ` ${cle}` : ` ${cle}="${typeof valeur === 'object' ? JSON.stringify(valeur) : String(valeur)}"`))
    .join('')
  return `<${n.tag}${attributs}>${n.enfants.map(versHtml).join('')}</${n.tag}>`
}

export function texte(n: Noeud): string {
  return n.type === 'texte' ? n.texte : n.enfants.map(texte).join('')
}

export function tous(racine: Noeud, critere: (n: Noeud) => boolean): Noeud[] {
  const trouves: Noeud[] = []
  const parcourir = (n: Noeud): void => {
    if (n.type === 'element' && critere(n)) trouves.push(n)
    n.enfants.forEach(parcourir)
  }
  parcourir(racine)
  return trouves
}

export function un(racine: Noeud, critere: (n: Noeud) => boolean): Noeud | null {
  return tous(racine, critere)[0] ?? null
}

export const parAttribut = (attribut: string) => (n: Noeud) => attribut in n.attributs
export const boutonNomme = (libelle: string) => (n: Noeud) => n.tag === 'button' && texte(n).trim() === libelle

/** Laisse passer les lectures simulées et les rendus qu'elles déclenchent. */
export async function attendre(): Promise<void> {
  for (let i = 0; i < 6; i++) await new Promise((fin) => setTimeout(fin, 0))
}

/** Un clic de navigateur : rien sur un bouton éteint. */
export async function cliquer(n: Noeud | null): Promise<void> {
  if (n === null) throw new Error('Rien à cliquer.')
  if (n.attributs['disabled']) return
  const ecouteur = n.ecouteurs['click']
  const fonctions = Array.isArray(ecouteur) ? ecouteur : [ecouteur]
  for (const f of fonctions) if (typeof f === 'function') f({ target: n, preventDefault: () => undefined })
  await attendre()
}


/** Monte `composant` dans une racine en mémoire et attend ses premières lectures. */
export async function monterEnMemoire(
  composant: unknown,
  composantsGlobaux: Record<string, Component> = {},
): Promise<Noeud> {
  const racine = noeud('element', 'div')
  const app = createApp(composant as Component)
  for (const [nom, global] of Object.entries(composantsGlobaux)) app.component(nom, global)
  app.mount(racine)
  await attendre()
  return racine
}
