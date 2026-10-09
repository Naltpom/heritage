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

/** La balise ouvrante qui contient `marque`, attributs compris. */
function baliseAvec(texte: string, marque: string): string {
  const position = texte.indexOf(marque)
  if (position < 0) return ''
  const debut = texte.lastIndexOf('<', position)
  return texte.slice(debut, texte.indexOf('>', position) + 1)
}

describe('le bouton « Ajouter une personne »', () => {
  it('n’existe que pour qui peut proposer : rattaché, hors de la vue empruntée', async () => {
    const texte = await source()
    const bouton = baliseAvec(texte, 'data-ajouter-une-personne')

    expect(bouton).toContain('v-if="peutProposer"')
    expect(texte).toContain('const peutProposer = computed(() => rattache.value && vueEmpruntee.value === null)')
  })

  it('est caché sous `lg`, désigne le volet et dit s’il est ouvert', async () => {
    const bouton = baliseAvec(await source(), 'data-ajouter-une-personne')

    expect(bouton).toMatch(/class="[^"]*\bmax-lg:hidden\b/)
    expect(bouton).toContain('aria-controls="volet-proposition"')
    expect(bouton).toContain(':aria-expanded="propositionVisible"')
    expect(bouton).toContain('@click="basculerProposition"')
  })

  it('vient avant « Me trouver », dans les actions de la barre', async () => {
    const texte = await source()
    const actions = texte.slice(texte.indexOf('<template #actions>'), texte.indexOf('</BarreEcran>'))

    expect(actions.indexOf('data-ajouter-une-personne')).toBeGreaterThan(0)
    expect(actions.indexOf('data-ajouter-une-personne')).toBeLessThan(actions.indexOf('Me trouver'))
  })
})

describe('le volet « Ajouter une personne »', () => {
  it('est monté au premier clic puis masqué, avant le canevas qu’il pousse', async () => {
    const texte = await source()
    const volet = baliseAvec(texte, '<VoletProposition')

    expect(volet).toContain('v-if="propositionMontee"')
    expect(volet).toContain('v-show="propositionVisible"')
    expect(texte.indexOf('<VoletProposition')).toBeLessThan(texte.indexOf('ref="fenetre"'))
  })

  it('a une coquille marquée comme lui pendant son chargement', async () => {
    // Les raccourcis se taisent aussi dans la coquille : elle porte la même marque.
    expect(await source()).toContain("'data-volet-proposition': ''")
  })

  it('recule le cadrage dans le même tic qu’il paraît, et le volet de droite lui cède la place', async () => {
    const texte = await source()

    expect(texte).toContain('cadrageApresVolet(cadrage.value, visible, pret)')
    expect(texte).toContain('voletDroitVisible({')
    expect(texte).toContain('v-if="voletDroitOuvert && ficheChoisie && disposition"')
  })

  it('se relit après un dépôt sans écran de chargement, et sans recadrer tout l’arbre', async () => {
    const corps = corpsDe(await source(), 'rafraichir')

    expect(corps).toContain('obtenirLeGraphe(')
    expect(corps).toContain("garderLeRepere(() => {")
    expect(corps).toContain("'garder')")
    expect(corps).not.toContain('chargement.value = true')
    expect(corps).not.toContain('lancerRevelation')
    expect(corps).not.toContain('ajusterLargeur')
  })
})

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
