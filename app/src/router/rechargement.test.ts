import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement : on monte ici un
// routeur en mémoire, et le module du rattrapage seul.
import {
  CLE_GARDE,
  DELAI_GARDE_MS,
  MESSAGE_APRES_RECHARGEMENT,
  MESSAGE_HORS_CONNEXION,
  deciderApresEchec,
  estEchecDeChargement,
  installerRattrapageChargement,
  lireGarde,
  rechargerVers,
  type Environnement,
  type Stockage,
} from './rechargement'

/**
 * Ce que ces tests protègent : **après une mise en production, un clic qui
 * mène à un écran paresseux disparu recharge la page une fois, et une seule.**
 *
 * Trois choses se cassent en silence ici :
 *
 *  1. **Le rattrapage n'est plus branché** — un `onError` retiré, un
 *     `vite:preloadError` qu'on n'écoute plus. Le symptôme est celui d'avant :
 *     le clic ne fait rien, sans un mot.
 *  2. **La garde ne tient plus** — et la page rechargée, qui échoue encore
 *     (serveur à moitié déployé), se recharge à l'infini.
 *  3. **La garde n'est plus levée** — et la mise en production suivante, des
 *     heures plus tard dans le même onglet, n'a plus droit à son rechargement.
 */

/* ------------------------------------------------------------------ */
/* Doublures                                                           */
/* ------------------------------------------------------------------ */

function stockageEnMemoire(): Stockage & { contenu: Map<string, string> } {
  const contenu = new Map<string, string>()
  return {
    contenu,
    getItem: (cle) => contenu.get(cle) ?? null,
    setItem: (cle, valeur) => void contenu.set(cle, valeur),
    removeItem: (cle) => void contenu.delete(cle),
  }
}

/** Un stockage refusé, comme en navigation privée stricte. */
const STOCKAGE_REFUSE: Stockage = {
  getItem: () => {
    throw new Error('SecurityError')
  },
  setItem: () => {
    throw new Error('SecurityError')
  },
  removeItem: () => {
    throw new Error('SecurityError')
  },
}

/** Ce que Chrome rend quand le morceau n'existe plus sur le serveur. */
const MORCEAU_DISPARU = () =>
  new TypeError('Failed to fetch dynamically imported module: https://heritage.test/assets/PersonneView-3f9a1c.js')

const Ecran = { render: () => null }

function environnement(surcharge: Partial<Omit<Environnement, 'recharger' | 'avertir'>> = {}) {
  const fenetre = new EventTarget()
  const env = {
    stockage: stockageEnMemoire() as Stockage | null,
    enLigne: () => true,
    maintenant: () => 1_000_000,
    recharger: vi.fn<(href: string) => void>(),
    avertir: vi.fn<(message: string, recharger: () => void) => void>(),
    fenetre: fenetre as unknown as Pick<Window, 'addEventListener'>,
    journal: vi.fn(),
    ...surcharge,
  }
  return { env, fenetre }
}

/** `/fiche/:id` échoue tant que `disparu` est vrai — la mise en production. */
function monter(env: Environnement, etat = { disparu: true }) {
  const routes: RouteRecordRaw[] = [
    { path: '/', component: Ecran },
    { path: '/arbre', component: Ecran },
    {
      path: '/fiche/:id',
      component: () => (etat.disparu ? Promise.reject(MORCEAU_DISPARU()) : Promise.resolve(Ecran)),
    },
    { path: '/en-panne', component: () => Promise.reject(new Error('une vraie panne')) },
  ]
  const routeur = createRouter({ history: createMemoryHistory(), routes })
  const rattrapage = installerRattrapageChargement(routeur, env)
  return { routeur, rattrapage }
}

/* ------------------------------------------------------------------ */

describe('estEchecDeChargement', () => {
  it('reconnaît les messages des trois moteurs et de Vite', () => {
    for (const message of [
      'Failed to fetch dynamically imported module: https://x/assets/A-1.js',
      'error loading dynamically imported module: https://x/assets/A-1.js',
      'Importing a module script failed.',
      'Unable to preload CSS for /assets/A-1.css',
      'Couldn\'t resolve component "default" at "/personnes/:id(\\\\d+)"',
    ]) {
      expect(estEchecDeChargement(new TypeError(message)), message).toBe(true)
    }
  })

  it('laisse passer tout le reste — une panne n’est pas une mise en production', () => {
    expect(estEchecDeChargement(new Error('Cannot read properties of undefined'))).toBe(false)
    expect(estEchecDeChargement('Failed to fetch dynamically imported module')).toBe(false)
    expect(estEchecDeChargement(null)).toBe(false)
    expect(estEchecDeChargement(undefined)).toBe(false)
  })
})

describe('la garde', () => {
  it('pose une garde horodatée et demande de recharger, la première fois', () => {
    const stockage = stockageEnMemoire()
    expect(deciderApresEchec({ stockage, cible: '/fiche/17', maintenant: 5000, enLigne: true })).toBe('recharger')
    expect(lireGarde(stockage)).toEqual({ cible: '/fiche/17', horodatage: 5000 })
  })

  it('une garde fraîche interdit le second rechargement : on avertit', () => {
    const stockage = stockageEnMemoire()
    deciderApresEchec({ stockage, cible: '/fiche/17', maintenant: 5000, enLigne: true })
    expect(
      deciderApresEchec({ stockage, cible: '/fiche/17', maintenant: 5000 + DELAI_GARDE_MS - 1, enLigne: true }),
    ).toBe('avertir')
  })

  it('une garde périmée ne compte plus : une nouvelle mise en production recharge', () => {
    const stockage = stockageEnMemoire()
    deciderApresEchec({ stockage, cible: '/fiche/17', maintenant: 5000, enLigne: true })
    expect(
      deciderApresEchec({ stockage, cible: '/arbre', maintenant: 5000 + DELAI_GARDE_MS, enLigne: true }),
    ).toBe('recharger')
    expect(lireGarde(stockage)?.cible).toBe('/arbre')
  })

  it('une garde illisible est ignorée, une horloge reculée vaut une garde fraîche', () => {
    const stockage = stockageEnMemoire()
    stockage.setItem(CLE_GARDE, '{pas du json')
    expect(lireGarde(stockage)).toBeNull()

    stockage.setItem(CLE_GARDE, JSON.stringify({ cible: '/', horodatage: 9000 }))
    expect(deciderApresEchec({ stockage, cible: '/', maintenant: 1000, enLigne: true })).toBe('avertir')
  })

  it('sans stockage, aucune garde ne tiendrait : on ne recharge jamais à l’aveugle', () => {
    expect(deciderApresEchec({ stockage: null, cible: '/', maintenant: 0, enLigne: true })).toBe('avertir')
    expect(deciderApresEchec({ stockage: STOCKAGE_REFUSE, cible: '/', maintenant: 0, enLigne: true })).toBe('avertir')
  })

  it('hors connexion, on ne recharge pas et on ne pose rien', () => {
    const stockage = stockageEnMemoire()
    expect(deciderApresEchec({ stockage, cible: '/', maintenant: 0, enLigne: false })).toBe('hors_connexion')
    expect(stockage.contenu.size).toBe(0)
  })
})

describe('le rattrapage branché sur le routeur', () => {
  let doublure: ReturnType<typeof environnement>

  beforeEach(() => {
    doublure = environnement()
  })

  it('un morceau disparu recharge la page vers la destination, requête comprise', async () => {
    const { routeur } = monter(doublure.env)
    await routeur.push('/')

    await expect(routeur.push('/fiche/17?onglet=sources')).rejects.toThrow(/dynamically imported module/)

    expect(doublure.env.recharger).toHaveBeenCalledTimes(1)
    expect(doublure.env.recharger).toHaveBeenCalledWith('/fiche/17?onglet=sources')
    expect(doublure.env.avertir).not.toHaveBeenCalled()
    expect(lireGarde(doublure.env.stockage)?.cible).toBe('/fiche/17?onglet=sources')
  })

  it('la page rechargée échoue encore : pas de second rechargement, un message avec « Recharger »', async () => {
    // L'onglet d'avant, qui pose la garde…
    const avant = monter(doublure.env)
    await avant.routeur.push('/')
    await avant.routeur.push('/fiche/17').catch(() => undefined)
    doublure.env.recharger.mockClear()

    // … et la page rechargée, qui retrouve la garde dans le même `sessionStorage`.
    const apres = monter(doublure.env)
    await apres.routeur.push('/fiche/17').catch(() => undefined)

    expect(doublure.env.recharger).not.toHaveBeenCalled()
    expect(doublure.env.avertir).toHaveBeenCalledTimes(1)
    const [message, recharger] = doublure.env.avertir.mock.calls[0]!
    expect(message).toBe(MESSAGE_APRES_RECHARGEMENT)

    // « Recharger » est un geste de la personne : lui, recharge.
    recharger()
    expect(doublure.env.recharger).toHaveBeenCalledWith('/fiche/17')
  })

  it('une navigation réussie lève la garde', async () => {
    const etat = { disparu: true }
    const { routeur } = monter(doublure.env, etat)
    await routeur.push('/')
    await routeur.push('/fiche/17').catch(() => undefined)
    expect(lireGarde(doublure.env.stockage)).not.toBeNull()

    // La page rechargée arrive, cette fois, avec l'index du jour.
    etat.disparu = false
    const rechargee = monter(doublure.env, etat)
    await rechargee.routeur.push('/fiche/17')

    expect(doublure.env.stockage?.getItem(CLE_GARDE)).toBeNull()
  })

  it('un seul rechargement par page, même si les échecs s’enchaînent', async () => {
    const { routeur, rattrapage } = monter(doublure.env)
    await routeur.push('/')
    await routeur.push('/fiche/17').catch(() => undefined)
    await routeur.push('/fiche/18').catch(() => undefined)
    rattrapage.signalerEchec(MORCEAU_DISPARU())

    expect(doublure.env.recharger).toHaveBeenCalledTimes(1)
    expect(doublure.env.avertir).not.toHaveBeenCalled()
  })

  it('hors connexion : le message le dit, et rien ne recharge', async () => {
    const hors = environnement({ enLigne: () => false })
    const { routeur } = monter(hors.env)
    await routeur.push('/')
    await routeur.push('/fiche/17').catch(() => undefined)

    expect(hors.env.recharger).not.toHaveBeenCalled()
    expect(hors.env.avertir).toHaveBeenCalledWith(MESSAGE_HORS_CONNEXION, expect.any(Function))
  })

  it('une autre erreur de navigation est journalisée, sans recharger ni avertir', async () => {
    const { routeur } = monter(doublure.env)
    await routeur.push('/')
    await routeur.push('/en-panne').catch(() => undefined)

    expect(doublure.env.recharger).not.toHaveBeenCalled()
    expect(doublure.env.avertir).not.toHaveBeenCalled()
    expect(doublure.env.journal).toHaveBeenCalledWith(
      expect.stringContaining('navigation'),
      '/en-panne',
      expect.objectContaining({ message: 'une vraie panne' }),
    )
  })

  it('`vite:preloadError` est empêché et recharge vers la navigation en cours', async () => {
    const { routeur } = monter(doublure.env)
    await routeur.push('/')

    // Le préchargement échoue PENDANT la navigation : un écran dont le
    // chargement attend, et l'événement de Vite qui part entre-temps.
    let liberer: () => void = () => undefined
    routeur.addRoute({
      path: '/lent',
      component: () => new Promise((fin) => (liberer = () => fin(Ecran))),
    })
    const navigation = routeur.push('/lent?x=1')
    await new Promise((fin) => setTimeout(fin, 0))

    const evenement = Object.assign(new Event('vite:preloadError', { cancelable: true }), {
      payload: new Error('Unable to preload CSS for /assets/Lent-9.css'),
    })
    doublure.fenetre.dispatchEvent(evenement)

    expect(evenement.defaultPrevented).toBe(true)
    expect(doublure.env.recharger).toHaveBeenCalledWith('/lent?x=1')

    liberer()
    await navigation
  })

  it('hors navigation, `signalerEchec` recharge la page courante et ignore le reste', async () => {
    const { routeur, rattrapage } = monter(doublure.env)
    await routeur.push('/arbre')

    expect(rattrapage.signalerEchec(new Error('une vraie panne'))).toBe(false)
    expect(doublure.env.recharger).not.toHaveBeenCalled()

    expect(rattrapage.signalerEchec(MORCEAU_DISPARU())).toBe(true)
    expect(doublure.env.recharger).toHaveBeenCalledWith('/arbre')
  })
})

describe('rechargerVers', () => {
  function lieu(href: string) {
    return { href, assign: vi.fn(), reload: vi.fn() }
  }

  it('vers une autre adresse : `assign`, qui recharge et garde le retour arrière', () => {
    const ici = lieu('https://heritage.test/arbre')
    const historique = { state: null, replaceState: vi.fn() }
    rechargerVers('/personnes/17', ici, historique)
    expect(ici.assign).toHaveBeenCalledWith('https://heritage.test/personnes/17')
    expect(ici.reload).not.toHaveBeenCalled()
  })

  it('vers la même adresse, ou la même à l’ancre près : un vrai rechargement', () => {
    const ici = lieu('https://heritage.test/personnes/17?onglet=sources')
    const historique = { state: { position: 3 }, replaceState: vi.fn() }
    rechargerVers('/personnes/17?onglet=sources#depot', ici, historique)
    // `assign` n'aurait fait que défiler jusqu'à l'ancre.
    expect(ici.assign).not.toHaveBeenCalled()
    expect(historique.replaceState).toHaveBeenCalledWith(
      { position: 3 },
      '',
      'https://heritage.test/personnes/17?onglet=sources#depot',
    )
    expect(ici.reload).toHaveBeenCalledTimes(1)
  })
})
