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

    // Le cadrage vit hors de Vue (`arbre2/useCadrage.ts`) : `decaler` l'écrit dans le DOM
    // tout de suite, et décale aussi l'image d'un glisser qui attend encore.
    expect(texte).toContain('cadrage.decaler((c) => cadrageApresVolet(c, visible, pret))')
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

describe('les ressemblances du volet (« Est-ce déjà… ? »)', () => {
  it('« Voir dans l’arbre » passe par `montrerPersonne` et `montrerNoeud`, qui ne quittent pas `/arbre`', async () => {
    const texte = await source()
    const volet = baliseAvec(texte, '<VoletProposition')

    expect(volet).toContain('@voir-personne="voirLaPersonneDuVolet"')
    expect(volet).toContain('@voir-proposition="voirLaPropositionDuVolet"')
    expect(volet).toContain('@en-evidence="mettreEnEvidence"')
    expect(corpsDe(texte, 'voirLaPersonneDuVolet')).toContain('repondre(montrerPersonne(personId))')
    expect(corpsDe(texte, 'voirLaPropositionDuVolet')).toContain('repondre(montrerNoeud(noeudId))')

    const corps = corpsDe(texte, 'montrerNoeud')
    expect(corps).not.toBe('')
    expect(corps).not.toContain('routeur.')
    expect(corps).toContain('disposition.value?.parId.get(noeudId)')
    expect(corps).toContain('return false')
  })

  it('les cercle en pointillé, et seulement tant que le volet est à l’écran', async () => {
    const texte = await source()
    const debut = texte.indexOf('const anneauxRessemblances = computed(')
    const calcul = texte.slice(debut, texte.indexOf('\n})', debut))

    expect(debut).toBeGreaterThan(0)
    expect(calcul).toContain('!propositionVisible.value')
    expect(calcul).toContain('dansLaFenetre(')
    const anneau = baliseAvec(texte, 'v-for="a in anneauxRessemblances"')
    expect(anneau).toContain('border-dashed')
    expect(anneau).toContain('pointer-events-none')
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

describe('la bascule « En attente de validation » (demande du 22/10/2026)', () => {
  it('est allumée par défaut, retenue comme les autres, et dans « Qui apparaît »', async () => {
    const texte = await source()

    expect(texte).toContain('const avecEnAttente = ref(lirePreference(CLE_EN_ATTENTE, true))')
    expect(corpsDe(texte, 'basculerEnAttente')).toContain('ecrirePreference(CLE_EN_ATTENTE, avec)')
    const bascule = baliseAvec(texte, 'data-filtre-en-attente')
    expect(bascule).toContain('<InterrupteurFiltre')
    expect(bascule).toContain(':actif="avecEnAttente"')
    expect(bascule).toContain('@basculer="basculerEnAttente(!avecEnAttente)"')
    const section = texte.slice(texte.indexOf('aria-labelledby="filtres-qui"'), texte.indexOf('aria-labelledby="filtres-affichage"'))
    expect(section).toContain('data-filtre-en-attente')
  })

  it('retire le calque avant tout le reste de la disposition, et nomme la disposition gardée', async () => {
    const texte = await source()
    const disposer = corpsDe(texte, 'disposer')

    expect(disposer).toContain('sansDemandesEnAttente(recu)')
    expect(disposer.indexOf('sansDemandesEnAttente(recu)')).toBeLessThan(disposer.indexOf('sansFamillesParAlliance('))
    expect(corpsDe(texte, 'cleDeLaDisposition')).toContain('avecEnAttente: avecEnAttente.value')
  })

  it('se rallume pour montrer une proposition : déposée à l’instant, ou « Voir dans l’arbre »', async () => {
    const texte = await source()

    expect(corpsDe(texte, 'surDepot')).toContain('basculerEnAttente(true)')
    expect(corpsDe(texte, 'montrerNoeud')).toContain('basculerEnAttente(true)')
  })
})
