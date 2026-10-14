import { describe, expect, it } from 'vitest'

/**
 * L'ADR 0007 et sa révision du 2026-10-14, vérifiés sur `/arbre` en relecture — par un
 * test, puisque c'est le but d'une règle « vérifiable en relecture » : l'agent qui
 * touchera ces fichiers dans six mois ne lira peut-être pas l'ADR, mais il verra ce
 * test rougir.
 *
 * On lit le SOURCE des composants du canevas (le `?raw`, idiome de la maison :
 * `views/cadre.test.ts`). C'est grossier, et c'est exprès : un test qui appellerait une
 * fonction ne dirait rien d'un gabarit qui lie `opacity`.
 *
 * De l'écran lui-même (`ArbreGroupesView.vue`), on lit le canevas — du bloc
 * `ref="fenetre"` au volet de la fiche — et les styles. Le reste (le feuillet des
 * filtres, les volets) est du cadre, que Vue tient, et que l'ADR ne vise pas.
 */
const COMPOSANTS = import.meta.glob<string>('./*.vue', { query: '?raw', import: 'default', eager: true })
const VUE = Object.values(
  import.meta.glob<string>('../views/ArbreGroupesView.vue', { query: '?raw', import: 'default', eager: true }),
)[0]!
const ADR = Object.values(
  import.meta.glob<string>('../../../docs/adr/0007-rendu-svg-performance.md', { query: '?raw', import: 'default', eager: true }),
)[0]!

/** Le canevas de l'écran : de la fenêtre des gestes au volet de la fiche. */
const CANEVAS = VUE.slice(VUE.indexOf('ref="fenetre"'), VUE.indexOf('aria-label="Fiche de la personne choisie"'))
/** Les styles de l'écran, sans le fondu d'apparition d'un calque entier — la seule `opacity` admise. */
const STYLES = VUE.slice(VUE.indexOf('</template>\n\n<style'))
  .replace(/@keyframes apparition-calque\s*\{\s*from\s*\{\s*opacity:\s*0;\s*\}\s*\}/, '')

/** Le code, sans ses commentaires : ils ont le droit de nommer ce qu'ils interdisent. */
const sansCommentaires = (source: string): string =>
  source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/^\s*\/\/.*$/gm, '')

const SOURCES: Record<string, string> = Object.fromEntries(
  Object.entries({ ...COMPOSANTS, 'canevas de la vue': CANEVAS, 'styles de la vue': STYLES }).map(([f, s]) => [f, sansCommentaires(s)]),
)

describe('/arbre respecte l’ADR 0007', () => {
  it('lit bien le canevas et ses composants', () => {
    expect(Object.keys(COMPOSANTS)).toEqual(
      expect.arrayContaining(['./TracesGroupes.vue', './FichesGroupes.vue', './CalqueLibelles.vue', './BandeauFamilles.vue']),
    )
    expect(CANEVAS.length).toBeGreaterThan(1000)
    expect(CANEVAS).toContain('<TracesGroupes')
    expect(STYLES).toContain('<style')
  })

  it('renvoie à l’ADR en tête du canevas, les règles résumées', () => {
    for (const source of [VUE, COMPOSANTS['./TracesGroupes.vue']!]) {
      const tete = source.slice(0, 4000)
      expect(tete).toContain('ADR 0007')
      expect(tete).toContain('viewBox')
      expect(tete).toMatch(/opacity/)
    }
  })

  it('ne pose `opacity` nulle part : `fill-opacity`, `stroke-opacity` ou une couleur transparente (règle 2)', () => {
    for (const [fichier, source] of Object.entries(SOURCES)) {
      // `opacity:` en CSS ou dans un objet de style, `:opacity=` lié, `opacity="…"` en attribut.
      expect(source, fichier).not.toMatch(/(?<![-\w])opacity\s*:/)
      expect(source, fichier).not.toMatch(/[\s:]opacity\s*=/)
      // Les utilitaires Tailwind `opacity-75`… ne sont pas mieux.
      expect(source, fichier).not.toMatch(/(?<![-\w])opacity-\d/)
    }
  })

  it('n’admet que le fondu d’un calque entier, sur son seul conteneur, une fois par chargement', () => {
    expect(VUE).toMatch(/@keyframes apparition-calque\s*\{\s*from\s*\{\s*opacity:\s*0;/)
    // Deux conteneurs le portent : les boîtes et la couche des textes. Aucune fiche.
    expect(CANEVAS.match(/calque-apparait/g)).toHaveLength(2)
    expect(COMPOSANTS['./FichesGroupes.vue']).toContain("revelation ? 'calque-apparait' : ''")
    expect(COMPOSANTS['./CalqueLibelles.vue']).not.toContain('animation')
  })

  it('ne zoome pas le `<svg>` par une transformation : son `viewBox` (règle 1)', () => {
    for (const [fichier, source] of Object.entries(SOURCES)) {
      expect(source, fichier).not.toMatch(/<g[^>]*transform/)
      expect(source, fichier).not.toMatch(/:transform=/)
      expect(source, fichier).not.toMatch(/setAttribute\('transform'/)
    }
    expect(COMPOSANTS['./TracesGroupes.vue']).toContain("setAttribute('viewBox'")
    expect(COMPOSANTS['./TracesGroupes.vue']).toContain('preserveAspectRatio="none"')
  })

  it('ne rend aucun trait, rectangle, zone de clic ni boîte par `v-for` (règle 3)', () => {
    // Le `<svg>` des traits est vide dans le gabarit : ses chemins sont construits à la main.
    expect(COMPOSANTS['./TracesGroupes.vue']).toMatch(/<svg[^>]*\/>/)
    expect(SOURCES['./TracesGroupes.vue']).not.toContain('v-for')
    expect(SOURCES['./FichesGroupes.vue']).not.toContain('v-for')
    expect(CANEVAS).not.toMatch(/v-for="[^"]*(disposition\.(fiches|traces)|rectangles)/)
    expect(SOURCES['canevas de la vue']).not.toContain('<svg')
    expect(VUE).not.toContain('FichesMonde')
  })

  it('un seul écouteur délégué pour toutes les boîtes, et chacune reste un bouton au clavier', () => {
    const fiches = COMPOSANTS['./FichesGroupes.vue']!
    expect(fiches).toContain('@click="surClic"')
    expect(fiches).toContain('@keydown="surTouche"')
    expect(fiches).not.toContain('addEventListener')
    expect(fiches).toContain("setAttribute('role', 'button')")
    expect(fiches).toContain('tabIndex = 0')
    expect(fiches).toContain("setAttribute('aria-label'")
  })

  it('n’écrit le cadrage dans aucun gabarit : il passe par le DOM, pas par un rendu (règle 3)', () => {
    // L'ancien `:style="{ transform: transformationMonde }"` rendait l'écran à chaque image.
    expect(CANEVAS).not.toMatch(/:style="[^"]*transform/)
    expect(VUE).not.toContain('transformationTraits')
    expect(VUE).not.toMatch(/const cadrage = ref</)
    expect(VUE).not.toContain('requestAnimationFrame')
    expect(VUE).toContain('useCadrage(')
  })

  it('ne refait pas le filtre par fiche et par image : un ensemble, calculé sur les filtres', () => {
    expect(VUE).not.toMatch(/function passe\(/)
    expect(VUE).toContain('const passent = computed')
  })

  it('l’ADR dit ce que le code fait : la révision datée pour `/arbre`', () => {
    expect(ADR).toContain('Révision du 2026-10-14')
    expect(ADR).toContain('ArbreGroupesView.vue')
    expect(ADR).toContain('arbre2/rendu-adr0007.test.ts')
  })
})

describe('le chargement de /arbre', () => {
  it('se relit quand le lecteur change, au retour sur l’onglet, et abandonne ses requêtes au démontage', () => {
    expect(VUE).toContain('useRelectureDeLArbre({')
    expect(VUE).toContain('identite: () => identiteDuLecteur(session.value)')
    expect(VUE).toContain('nouveauLecteur: changerDeLecteur')
    expect(VUE).toContain('chargerGraphe(signal)')
  })

  it('garde ses dispositions sous la clé de son moteur, pas sous celles de `/arbre-2`', () => {
    expect(VUE).toContain("moteur: 'groupes'")
    expect(VUE).toContain('memoireDeLArbre.garderLaDisposition(')
  })

  it('dit ses erreurs par `analyserErreur`, et n’en avale aucune', () => {
    expect(VUE).toContain('erreur.value = analyserErreur(e).message')
    expect(VUE).not.toMatch(/e instanceof Error \? e\.message/)
    expect(VUE).not.toContain('.catch(() => {})')
    expect(VUE).toContain("return jeton === jetonRafraichir ? 'echec' : null")
  })
})
