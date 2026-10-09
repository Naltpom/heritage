import { describe, expect, it } from 'vitest'

/**
 * `/arbre` (`ArbreGroupesView.vue`) lu **comme du texte**.
 *
 * L'écran ne se monte pas en test : il lui faut un canevas mesuré, un graphe, un
 * `ResizeObserver`. Ce qu'on tient ici, ce sont des décisions écrites dans sa
 * source, que la logique pure (`components/arbre/voletProposition.ts`) ne voit
 * pas — le même procédé que `cadre.test.ts`.
 */
async function source(): Promise<string> {
  const module = (await import('./ArbreGroupesView.vue?raw')) as { default: string }
  return module.default
}

/** Le corps d'une fonction de l'écran, de sa signature à la suivante. */
function corpsDe(texte: string, nom: string): string {
  const debut = texte.indexOf(`function ${nom}(`)
  if (debut < 0) return ''
  const suite = texte.indexOf('\nfunction ', debut + 1)
  return texte.slice(debut, suite < 0 ? undefined : suite)
}

describe('les raccourcis clavier de l’arbre', () => {
  it('passent par `toucheIgnoree` : champs, listes déroulantes, volet', async () => {
    const corps = corpsDe(await source(), 'surToucheGlobale')

    expect(corps).toContain('toucheIgnoree(')
    // L'ancienne liste en dur oubliait `SELECT` : elle ne doit pas revenir à côté.
    expect(corps).not.toContain("tagName === 'INPUT'")
  })
})

describe('montrer une personne sans quitter l’arbre', () => {
  it('`montrerPersonne` ne navigue jamais', async () => {
    const corps = corpsDe(await source(), 'montrerPersonne')

    expect(corps).not.toBe('')
    expect(corps).not.toContain('routeur.')
    expect(corps).toContain('return false')
  })

  it('`choisirProche` garde son repli vers la page de la fiche', async () => {
    const corps = corpsDe(await source(), 'choisirProche')

    expect(corps).toContain('montrerPersonne(personId)')
    expect(corps).toContain("name: 'personne.detail'")
  })
})

describe('le volet de la fiche sur un nœud en attente', () => {
  it('dit « Proposée, en attente de validation » et mène à la demande', async () => {
    const texte = await source()
    const debut = texte.indexOf('data-noeud-propose')
    const bloc = texte.slice(debut, texte.indexOf('</section>', debut))

    expect(debut).toBeGreaterThan(0)
    expect(bloc).toContain('Proposée, en attente de validation')
    expect(bloc).toContain("name: 'demande.detail'")
    // Avant « Lecture de la fiche… », qui restait affiché sans fin sur ce nœud.
    expect(debut).toBeLessThan(texte.indexOf('Lecture de la fiche…'))
  })
})
