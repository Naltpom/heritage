/**
 * Le chargement des polices de l'affiche **dans le navigateur** : une fois par session, à la
 * première ouverture de la modale — jamais au démarrage de l'application (≈ 560 Ko).
 *
 * Les mêmes octets servent trois fois :
 *
 * 1. **la mesure** (`installerPolices`) : le placement lit les avances des glyphes ;
 * 2. **l'aperçu** : chaque police est déclarée au document par `FontFace`, sous sa clé
 *    (`SS-Txt-Reg`…), le nom de famille qu'écrit le SVG. On passe les octets, pas une URL :
 *    rien n'est retéléchargé, et la CSP (`font-src 'self'`) n'a rien à autoriser de plus —
 *    une `@font-face` en `data:` dans la page, elle, serait refusée ;
 * 3. **les fichiers produits** : le SVG téléchargé et le PNG les embarquent en base64.
 *
 * Les fichiers sont des actifs de Vite (nom haché, cache d'un an sous `/assets/`).
 */
import { CLES_DE_POLICE, installerPolices, MetriquesDePolice } from './polices'

const URLS = import.meta.glob<string>('./polices/*.ttf', { query: '?url', import: 'default', eager: true })

export interface PolicesChargees {
  /** Les octets de chaque police, par clé. */
  octets: ReadonlyMap<string, ArrayBuffer>
  /** Les mêmes en base64, calculés à la première demande (le SVG et le PNG). */
  base64(): ReadonlyMap<string, string>
}

let chargement: Promise<PolicesChargees> | null = null

function enBase64(octets: ArrayBuffer): string {
  const u = new Uint8Array(octets)
  let binaire = ''
  for (let i = 0; i < u.length; i += 0x8000) binaire += String.fromCharCode(...u.subarray(i, i + 0x8000))
  return btoa(binaire)
}

/** Déclare les polices à un document — celui de la page, ou celui du cadre d'impression. */
export async function declarerLesPolices(fenetre: Window & typeof globalThis, octets: ReadonlyMap<string, ArrayBuffer>): Promise<void> {
  const document = fenetre.document
  await Promise.all(
    [...octets].map(async ([cle, donnees]) => {
      if ([...document.fonts].some((f) => f.family === cle || f.family === `"${cle}"`)) return
      // Une copie : selon les navigateurs, la police peut s'approprier le tampon.
      const police = new fenetre.FontFace(cle, donnees.slice(0))
      await police.load()
      document.fonts.add(police)
    }),
  )
}

export function chargerLesPolices(): Promise<PolicesChargees> {
  chargement ??= (async () => {
    const octets = new Map<string, ArrayBuffer>()
    await Promise.all(
      CLES_DE_POLICE.map(async (cle) => {
        const url = URLS[`./polices/${cle}.ttf`]
        if (!url) throw new Error(`Police introuvable : ${cle}.`)
        const reponse = await fetch(url)
        if (!reponse.ok) throw new Error(`La police ${cle} n’a pas pu être chargée (${reponse.status}).`)
        octets.set(cle, await reponse.arrayBuffer())
      }),
    )
    installerPolices(new Map([...octets].map(([cle, b]) => [cle, MetriquesDePolice.depuis(b)])))
    await declarerLesPolices(window, octets)
    let memo: Map<string, string> | null = null
    return {
      octets,
      base64: () => (memo ??= new Map([...octets].map(([cle, b]) => [cle, enBase64(b)]))),
    }
  })().catch((e: unknown) => {
    // Un échec ne doit pas condamner les ouvertures suivantes : on réessaiera.
    chargement = null
    throw e
  })
  return chargement
}
