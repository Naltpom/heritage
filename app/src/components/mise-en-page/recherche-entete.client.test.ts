/**
 * La recherche de l'en-tête, montée et tapée (`test-utils/rendu-memoire.ts`).
 *
 * Ce que ces cas protègent :
 *  1. **le motif « combobox » de WAI-ARIA** : `role`, `aria-expanded`,
 *     `aria-controls` vers une liste qui existe toujours, `aria-activedescendant`
 *     vers l'option du curseur — rien de tout cela ne se voit à l'écran, et tout
 *     se perd sans bruit ;
 *  2. **une panne n'est pas un « personne »** : le défaut d'origine affichait
 *     « Personne ne correspond. » sur une coupure de réseau ;
 *  3. **« Voir tous les résultats »** n'apparaît que si la liste est tronquée, se
 *     rejoint au clavier et mène à la page `recherche`.
 *
 * Noms inventés.
 */
import { defineComponent, h } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ResultatDeRecherche } from '@/lib/api'
import { attendre, cliquer, demonterTout, monterEnMemoire, texte, tous, un, type Noeud } from '@/test-utils/rendu-memoire'

const api = vi.hoisted(() => ({ rechercherPersonnes: vi.fn() }))
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  rechercherPersonnes: api.rechercherPersonnes,
}))

const routeur = vi.hoisted(() => ({ push: vi.fn(() => Promise.resolve()) }))
vi.mock('vue-router', () => ({ useRouter: () => routeur }))

import { CODE_RESEAU, ErreurApi } from '@/lib/api/erreurs'
import RechercheEntete from './RechercheEntete.vue'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () => h('a', { href: '#', 'data-vers': JSON.stringify(props.to) }, slots['default']?.()),
})

function resultat(personId: number, displayName: string): ResultatDeRecherche {
  return { personId, displayName, sex: 'feminin', generation: 0, branch: 'pp', vivant: false, visibilite: 'complete' }
}

const huit = Array.from({ length: 8 }, (_, i) => resultat(i + 1, `Ambre Bourrache ${i + 1}`))

const champDe = (racine: Noeud): Noeud => {
  const champ = un(racine, (n) => n.tag === 'input')
  if (champ === null) throw new Error('Champ absent.')
  // Le champ se quitte (`blur`) quand on choisit ou qu'on ferme : le nœud en mémoire ne le sait pas.
  Object.assign(champ, { blur: () => undefined })
  return champ
}
const liste = (racine: Noeud): Noeud | null => un(racine, (n) => n.attributs['role'] === 'listbox')
const options = (racine: Noeud): Noeud[] => tous(racine, (n) => n.attributs['role'] === 'option')
/** Le panneau déroulant : le parent de la liste, que `v-show` masque. */
const deplie = (racine: Noeud): boolean => liste(racine)?.parent?.style?.['display'] !== 'none'

function appeler(champ: Noeud, evenement: string, charge: object = {}): void {
  const ecouteur = champ.ecouteurs[evenement]
  const fonctions = Array.isArray(ecouteur) ? ecouteur : [ecouteur]
  for (const f of fonctions) if (typeof f === 'function') f({ target: champ, ...charge })
}

/** Tape dans le champ, puis laisse passer le délai de frappe et la réponse simulée. */
async function taper(champ: Noeud, valeur: string, attendreLaReponse = true): Promise<void> {
  appeler(champ, 'focus')
  // `v-model` lit la valeur sur l'élément lui-même.
  Object.assign(champ, { value: valeur })
  appeler(champ, 'input')
  if (attendreLaReponse) await new Promise((fin) => setTimeout(fin, 180))
  await attendre()
}

async function touche(champ: Noeud, key: string): Promise<void> {
  appeler(champ, 'keydown', { key, preventDefault: () => undefined, stopPropagation: () => undefined })
  await attendre()
}

function monter(): Promise<Noeud> {
  return monterEnMemoire(RechercheEntete, { RouterLink: LienFactice })
}

beforeEach(() => {
  vi.stubGlobal('window', { addEventListener: () => undefined, removeEventListener: () => undefined })
  vi.stubGlobal('document', { getElementById: () => null })
  // Quand la valeur change sous un champ, `v-model` regarde si sa racine est un
  // document qui lui donne le focus : ici, ni document ni ombre.
  vi.stubGlobal('Document', class {})
  vi.stubGlobal('ShadowRoot', class {})
  api.rechercherPersonnes.mockReset()
  routeur.push.mockClear()
})
afterEach(() => {
  demonterTout()
  vi.unstubAllGlobals()
})

describe('le motif combobox', () => {
  it('porte rôle, liste contrôlée et autocomplétion dès l’ouverture, liste repliée', async () => {
    const racine = await monter()
    const champ = champDe(racine)

    expect(champ.attributs['role']).toBe('combobox')
    expect(champ.attributs['aria-autocomplete']).toBe('list')
    expect(champ.attributs['aria-expanded']).toBe(false)
    // La liste existe avant toute saisie : `aria-controls` ne désigne jamais le vide.
    expect(champ.attributs['aria-controls']).toBe('recherche-entete-liste')
    expect(liste(racine)?.attributs['id']).toBe('recherche-entete-liste')
    expect(champ.attributs['aria-activedescendant']).toBeUndefined()
    expect(deplie(racine)).toBe(false)
  })

  it('déplie la liste et désigne l’option du curseur, que ↓ déplace', async () => {
    api.rechercherPersonnes.mockResolvedValue({ elements: huit.slice(0, 3), tronque: false })
    const racine = await monter()
    const champ = champDe(racine)

    await taper(champ, 'bo')

    expect(champ.attributs['aria-expanded']).toBe(true)
    expect(deplie(racine)).toBe(true)
    expect(options(racine).map((o) => o.attributs['id'])).toEqual(['resultat-entete-0', 'resultat-entete-1', 'resultat-entete-2'])
    expect(champ.attributs['aria-activedescendant']).toBe('resultat-entete-0')
    expect(options(racine)[0]?.attributs['aria-selected']).toBe(true)

    await touche(champ, 'ArrowDown')
    expect(champ.attributs['aria-activedescendant']).toBe('resultat-entete-1')
    expect(options(racine)[1]?.attributs['aria-selected']).toBe(true)

    await touche(champ, 'ArrowUp')
    await touche(champ, 'ArrowUp')
    // ↑ depuis la première fait le tour.
    expect(champ.attributs['aria-activedescendant']).toBe('resultat-entete-2')
    // Trois personnes, pas de troncature : pas de lien vers la page.
    expect(un(racine, (n) => 'data-voir-tous' in n.attributs)).toBeNull()
  })

  it('ouvre la fiche à l’Entrée, sans arbre pour la prendre, et se replie', async () => {
    api.rechercherPersonnes.mockResolvedValue({ elements: huit.slice(0, 2), tronque: false })
    const racine = await monter()
    const champ = champDe(racine)
    await taper(champ, 'bo')

    await touche(champ, 'ArrowDown')
    await touche(champ, 'Enter')

    expect(routeur.push).toHaveBeenCalledWith({ name: 'personne.detail', params: { id: 2 } })
    expect(champ.attributs['aria-expanded']).toBe(false)
  })

  it('se replie à Échap', async () => {
    api.rechercherPersonnes.mockResolvedValue({ elements: huit.slice(0, 2), tronque: false })
    const racine = await monter()
    const champ = champDe(racine)
    await taper(champ, 'bo')

    await touche(champ, 'Escape')

    expect(champ.attributs['aria-expanded']).toBe(false)
    expect(champ.attributs['aria-activedescendant']).toBeUndefined()
  })
})

describe('les états', () => {
  it('dit « Recherche… » avant la réponse, jamais « Personne ne correspond. »', async () => {
    api.rechercherPersonnes.mockReturnValue(new Promise(() => undefined))
    const racine = await monter()

    await taper(champDe(racine), 'bo', false)

    expect(texte(racine)).toContain('Recherche…')
    expect(texte(racine)).not.toContain('Personne ne correspond.')
  })

  it('dit « Personne ne correspond. » après une réponse vide', async () => {
    api.rechercherPersonnes.mockResolvedValue({ elements: [], tronque: false })
    const racine = await monter()

    await taper(champDe(racine), 'zz')

    expect(texte(racine)).toContain('Personne ne correspond.')
    expect(options(racine)).toEqual([])
  })

  it('dit une panne comme une panne, et « Réessayer » relance', async () => {
    api.rechercherPersonnes.mockRejectedValueOnce(new ErreurApi(0, CODE_RESEAU, "Le serveur n'a pas répondu."))
    const racine = await monter()
    const champ = champDe(racine)

    await taper(champ, 'bo')

    expect(texte(racine)).toContain("Le serveur n'a pas répondu. Vérifiez votre connexion.")
    expect(texte(racine)).not.toContain('Personne ne correspond.')
    const reessayer = un(racine, (n) => n.tag === 'button' && texte(n).trim() === 'Réessayer')
    expect(reessayer).not.toBeNull()

    api.rechercherPersonnes.mockResolvedValueOnce({ elements: huit.slice(0, 1), tronque: false })
    await cliquer(reessayer)

    expect(api.rechercherPersonnes).toHaveBeenCalledTimes(2)
    expect(options(racine)).toHaveLength(1)
  })
})

describe('« Voir tous les résultats »', () => {
  it('apparaît sur une liste tronquée, se rejoint au clavier et ouvre la page', async () => {
    api.rechercherPersonnes.mockResolvedValue({ elements: huit, tronque: true })
    const racine = await monter()
    const champ = champDe(racine)
    await taper(champ, ' bo ')

    // Huit demandés sans l'arbre pour écouter.
    expect(api.rechercherPersonnes.mock.calls[0]?.slice(0, 2)).toEqual(['bo', 8])
    const tousLesResultats = un(racine, (n) => 'data-voir-tous' in n.attributs)
    expect(tousLesResultats?.attributs['role']).toBe('option')
    // Un vrai lien, vers la page et la saisie.
    const lien = un(tousLesResultats!, (n) => n.tag === 'a')
    expect(JSON.parse(String(lien?.attributs['data-vers']))).toEqual({ name: 'recherche', query: { q: 'bo' } })

    await touche(champ, 'ArrowUp')
    expect(champ.attributs['aria-activedescendant']).toBe('resultat-entete-tous')

    await touche(champ, 'Enter')
    expect(routeur.push).toHaveBeenCalledWith({ name: 'recherche', query: { q: 'bo' } })
  })
})
