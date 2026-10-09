/**
 * Le format des fiches de l'arbre : la préférence enregistrée, et la fenêtre
 * qui la propose sur téléphone — la décision et le pilote, sans navigateur.
 *
 * Même montage que `invitationPush.test.ts` : `localStorage` en `Map` (vitest
 * tourne sous `node`), remplacé au besoin par un stockage qui lève — la
 * navigation privée de Safari ; minuteries simulées.
 *
 * Ce qui compte : « Compact » tant que rien n'est choisi, et la page qui marche
 * sans stockage ; la fenêtre **seulement sur téléphone**, une fois l'arbre
 * dessiné, **jamais** en même temps qu'une autre fenêtre automatique, et plus
 * jamais une fois répondu — ni par elle, ni par le réglage des filtres.
 */
import { effectScope, nextTick, shallowRef, type EffectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  CLE_FORMAT_DES_CARTES,
  DELAI_INVITATION_FORMAT_MS,
  choisirFormat,
  doitProposerLeChoix,
  formatDejaChoisi,
  lireFormatEnregistre,
  piloterInvitationFormat,
  reinitialiserFormatDesCartes,
  useFormatDesCartes,
  type ContexteInvitationFormat,
} from './formatDesCartes'
import { doitCeder, liberer, occuper, uneAutreFenetreQue } from './fenetresAutomatiques'

/* ------------------------------------------------------------------ */
/* Montage                                                             */
/* ------------------------------------------------------------------ */

function stockage(): Map<string, string> {
  const m = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => m.get(k) ?? null,
    setItem: (k: string, v: string) => void m.set(k, v),
    removeItem: (k: string) => void m.delete(k),
  })
  return m
}

function stockageBloque(): void {
  const lever = (): never => {
    throw new Error('SecurityError')
  }
  vi.stubGlobal('localStorage', { getItem: lever, setItem: lever, removeItem: lever })
}

const pret = shallowRef(true)
const mobile = shallowRef(true)
let portee: EffectScope | null = null

/** Un chargement de l'écran de l'arbre : l'état du module repart du stockage. */
function charger(): ReturnType<typeof piloterInvitationFormat> {
  reinitialiserFormatDesCartes()
  return monter()
}

/** Le pilote seul, sans remettre le module à zéro (retour sur l'arbre). */
function monter(): ReturnType<typeof piloterInvitationFormat> {
  portee?.stop()
  portee = effectScope()
  const p = portee.run(() => piloterInvitationFormat(() => pret.value, () => mobile.value))
  if (!p) throw new Error('portée arrêtée')
  return p
}

/** L'écran de l'arbre démonté : la portée du pilote tombe. */
function quitterLArbre(): void {
  portee?.stop()
  portee = null
}

async function avancer(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await nextTick()
}

beforeEach(() => {
  vi.useFakeTimers()
  pret.value = true
  mobile.value = true
})

afterEach(() => {
  portee?.stop()
  portee = null
  for (const f of ['cle-d-acces', 'installation', 'notifications', 'format-des-cartes'] as const) liberer(f)
  vi.unstubAllGlobals()
  reinitialiserFormatDesCartes()
  vi.useRealTimers()
})

/* ------------------------------------------------------------------ */

describe('la préférence', () => {
  it('vaut « compact » tant que rien n’est enregistré', () => {
    stockage()
    reinitialiserFormatDesCartes()
    expect(useFormatDesCartes().format.value).toBe('compact')
    expect(lireFormatEnregistre()).toBeNull()
    expect(formatDejaChoisi()).toBe(false)
  })

  it('s’écrit et se relit au chargement suivant', () => {
    const m = stockage()
    reinitialiserFormatDesCartes()
    choisirFormat('portrait')
    expect(m.get(CLE_FORMAT_DES_CARTES)).toBe('portrait')
    expect(useFormatDesCartes().format.value).toBe('portrait')

    reinitialiserFormatDesCartes()
    expect(useFormatDesCartes().format.value).toBe('portrait')
    expect(formatDejaChoisi()).toBe(true)
  })

  it('une valeur inconnue est ramenée à « compact », et ne compte pas comme un choix', () => {
    const m = stockage()
    m.set(CLE_FORMAT_DES_CARTES, 'medaillon')
    reinitialiserFormatDesCartes()
    expect(useFormatDesCartes().format.value).toBe('compact')
    expect(formatDejaChoisi()).toBe(false)
  })

  it('stockage qui lève : la page marche, en « compact », et le choix vaut pour le chargement', () => {
    stockageBloque()
    reinitialiserFormatDesCartes()
    const { format } = useFormatDesCartes()
    expect(format.value).toBe('compact')
    expect(() => choisirFormat('portrait')).not.toThrow()
    expect(format.value).toBe('portrait')
    expect(formatDejaChoisi()).toBe(true)
  })
})

describe('doitProposerLeChoix', () => {
  const OUI: ContexteInvitationFormat = { mobile: true, dejaChoisi: false, pret: true, autreFenetre: false }

  it('oui sur téléphone, arbre dessiné, rien de choisi, la place libre', () => {
    expect(doitProposerLeChoix(OUI)).toBe(true)
  })

  it.each([
    ['sur bureau', { mobile: false }],
    ['déjà choisi', { dejaChoisi: true }],
    ['arbre pas encore dessiné', { pret: false }],
    ['une autre fenêtre en route', { autreFenetre: true }],
  ] as const)('non %s', (_cas, ecart) => {
    expect(doitProposerLeChoix({ ...OUI, ...ecart })).toBe(false)
  })
})

describe('le pilote', () => {
  it('ouvre sur téléphone après le délai, et pas avant', async () => {
    stockage()
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS - 100)
    expect(p.ouverte.value).toBe(false)
    await avancer(200)
    expect(p.ouverte.value).toBe(true)
    // Ouverte, elle tient sa place, et le signale : même l'installation, qui
    // la précède, attendra qu'elle soit fermée.
    expect(uneAutreFenetreQue('notifications')).toBe(true)
    expect(doitCeder('installation')).toBe(true)
    p.garder()
    expect(doitCeder('installation')).toBe(false)
  })

  it('jamais sur bureau', async () => {
    stockage()
    mobile.value = false
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS * 10)
    expect(p.ouverte.value).toBe(false)
  })

  it('jamais si un choix est déjà enregistré', async () => {
    stockage().set(CLE_FORMAT_DES_CARTES, 'compact')
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS * 10)
    expect(p.ouverte.value).toBe(false)
  })

  it('attend que l’arbre soit dessiné', async () => {
    stockage()
    pret.value = false
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS * 5)
    expect(p.ouverte.value).toBe(false)
    pret.value = true
    await avancer(DELAI_INVITATION_FORMAT_MS + 100)
    expect(p.ouverte.value).toBe(true)
  })

  it.each(['cle-d-acces', 'installation', 'notifications'] as const)(
    'attend tant que « %s » tient la place, et ouvre après sa libération',
    async (autre) => {
      stockage()
      occuper(autre)
      const p = charger()
      await avancer(DELAI_INVITATION_FORMAT_MS * 5)
      expect(p.ouverte.value).toBe(false)
      liberer(autre)
      await avancer(DELAI_INVITATION_FORMAT_MS + 100)
      expect(p.ouverte.value).toBe(true)
    },
  )

  it('une autre fenêtre qui arrive pendant l’attente l’annule', async () => {
    stockage()
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS - 500)
    occuper('notifications')
    await avancer(DELAI_INVITATION_FORMAT_MS)
    expect(p.ouverte.value).toBe(false)
  })

  it('« Portrait » touché : enregistré, fenêtre fermée, place rendue', async () => {
    const m = stockage()
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS + 100)
    p.choisir('portrait')
    expect(p.ouverte.value).toBe(false)
    expect(m.get(CLE_FORMAT_DES_CARTES)).toBe('portrait')
    expect(useFormatDesCartes().format.value).toBe('portrait')
    expect(uneAutreFenetreQue('notifications')).toBe(false)
  })

  it('« Garder l’actuel » (Échap, voile) : « compact » enregistré, elle ne revient plus', async () => {
    const m = stockage()
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS + 100)
    p.garder()
    expect(p.ouverte.value).toBe(false)
    expect(m.get(CLE_FORMAT_DES_CARTES)).toBe('compact')

    const suivant = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS * 5)
    expect(suivant.ouverte.value).toBe(false)
  })

  it('stockage bloqué : proposée une seule fois par chargement', async () => {
    stockageBloque()
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS + 100)
    expect(p.ouverte.value).toBe(true)
    p.garder()

    // Retour sur l'arbre, même chargement : rien.
    const retour = monter()
    await avancer(DELAI_INVITATION_FORMAT_MS * 5)
    expect(retour.ouverte.value).toBe(false)
  })

  it('quitter l’arbre pendant l’attente l’annule ; ouverte, rend la place', async () => {
    stockage()
    charger()
    await avancer(DELAI_INVITATION_FORMAT_MS - 500)
    quitterLArbre()
    await avancer(DELAI_INVITATION_FORMAT_MS)
    expect(uneAutreFenetreQue('notifications')).toBe(false)

    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS + 100)
    expect(p.ouverte.value).toBe(true)
    quitterLArbre()
    expect(uneAutreFenetreQue('notifications')).toBe(false)
  })

  it('un choix dans le réglage des filtres pendant l’attente annule l’ouverture', async () => {
    stockage()
    const p = charger()
    await avancer(DELAI_INVITATION_FORMAT_MS - 500)
    choisirFormat('portrait')
    await avancer(DELAI_INVITATION_FORMAT_MS * 5)
    expect(p.ouverte.value).toBe(false)
    expect(useFormatDesCartes().format.value).toBe('portrait')
  })
})
