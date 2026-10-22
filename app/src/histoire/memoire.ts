/**
 * **La mémoire de l'arbre, d'un F5 à l'autre** : le graphe reçu, son `ETag`, et les
 * dispositions déjà calculées dessus.
 *
 * Mesuré en production le 2026-10-07 (3 904 personnes, F5 sur `/arbre`) : le graphe
 * retéléchargé en entier à chaque fois — `requeteApi` force `cache: 'no-store'`, l'`ETag`
 * fort du serveur ne servait jamais —, puis `disposerLignees` refait sur le fil
 * principal, ~1 s, pour un arbre qui n'avait pas changé depuis la veille.
 *
 * Ce module garde donc, dans IndexedDB :
 *
 *  - **le graphe et son `ETag`** : au F5 suivant, on revalide (`If-None-Match`) ; sur
 *    `304`, on reprend le graphe gardé au lieu de le retélécharger ;
 *  - **les dernières dispositions** calculées sur ce graphe (au plus
 *    {@link DISPOSITIONS_GARDEES}), chacune sous une clé qui nomme TOUT ce qui entre
 *    dans `disposer()` — chaque bascule, les quarts masqués, le format des cartes — et
 *    l'empreinte des sources du front (`__EMPREINTE_DU_FRONT__`, `vite.config.ts`) : un
 *    déploiement qui change l'algorithme ne relit jamais une disposition de l'ancien.
 *
 * ── Les règles, et pourquoi ────────────────────────────────────────────────
 *
 * **1. On ne montre rien avant que le serveur ait répondu.** La mémoire ne sert qu'à
 * une réponse `304` à un `If-None-Match` portant l'`ETag` de CE graphe-là : c'est le
 * serveur qui dit « ce que vous avez est à jour », jamais nous. L'`ETag` couvre l'arbre,
 * le cercle effectif du lecteur, ses demandes en attente, son point de vue et la mise
 * en page (§ 5.2) : un `304` ne peut pas être faux.
 *
 * **2. Une entrée par lecteur, effacée à chaque changement de session.** Le graphe porte
 * des personnes vivantes, vues avec les droits d'UN lecteur ; IndexedDB est commun à
 * toute l'origine. Chaque entrée porte donc l'identité de son lecteur (le compte, la
 * bascule « voir comme », l'incarnation) et n'est relue que pour lui ; et la mémoire
 * entière est effacée à la connexion, à la déconnexion et à la session perdue
 * (`useSession.ts`) — sur un ordinateur partagé, le suivant ne trouve rien. Le serveur
 * sert ce graphe en `Cache-Control: private, no-cache` : un stockage privé du
 * navigateur lui est permis, à condition de revalider — c'est la règle 1.
 *
 * **3. Rien ici ne peut faire tomber l'écran.** Navigation privée, quota plein, base
 * bloquée, entrée corrompue ou d'un ancien format : chaque lecture rend `null`, chaque
 * écriture se tait, et l'arbre se charge comme avant. Une lecture qui traîne est
 * abandonnée au bout de {@link DELAI_DE_LECTURE} ms (300 ms pour l'`ETag`, lu avant la
 * requête).
 *
 * **4. Les dispositions ne se gardent qu'en production.** En développement, une
 * modification de `lignees.ts` rechargée à chaud ne change pas l'empreinte calculée au
 * démarrage du serveur : on relirait l'ancienne disposition.
 */
import type { ChargeDeSession, Graphe } from '@/lib/api'
import type { DispositionHistoire } from './lignees'

/** La forme des entrées. À changer si elle change : une entrée d'une autre forme est ignorée. */
const FORMAT = 1
/** Au-delà, la plus ancienne disposition est effacée : ~2 Mo chacune pour 4 000 personnes. */
export const DISPOSITIONS_GARDEES = 3
/** Une lecture plus lente est abandonnée : l'arbre se charge alors comme sans mémoire. */
export const DELAI_DE_LECTURE = 1500
/**
 * L'en-tête (l'`ETag`), lui, se lit AVANT d'envoyer la requête : une base lente ne doit
 * pas la retarder plus que ça. Au-delà, on revalide sans rien — un `200`, comme avant.
 */
const DELAI_DE_L_ENTETE = 300

/* ------------------------------------------------------------------ */
/* Le coffre : où les entrées vivent                                   */
/* ------------------------------------------------------------------ */

/** Un magasin clé → valeur, chaque écriture d'un seul tenant. Rend des promesses qui peuvent échouer. */
export interface Coffre {
  lire(cle: string): Promise<unknown>
  /** Écrit `entrees` et efface `effacees`, dans une même transaction. */
  ecrire(entrees: [string, unknown][], effacees: string[]): Promise<void>
  vider(): Promise<void>
}

const NOM_DE_LA_BASE = 'heritage.arbre'
const MAGASIN = 'memoire'

/** Le coffre IndexedDB du navigateur ; `null` sans IndexedDB (SSR, tests, navigateur ancien). */
export function coffreIndexedDb(fabrique: IDBFactory | undefined = globalThis.indexedDB): Coffre | null {
  if (!fabrique) return null
  let base: Promise<IDBDatabase> | null = null
  const ouvrir = (): Promise<IDBDatabase> =>
    (base ??= new Promise<IDBDatabase>((ok, ko) => {
      const demande = fabrique.open(NOM_DE_LA_BASE, 1)
      demande.onupgradeneeded = () => {
        if (!demande.result.objectStoreNames.contains(MAGASIN)) demande.result.createObjectStore(MAGASIN)
      }
      demande.onsuccess = () => {
        const db = demande.result
        // Un autre onglet qui changerait de version : on se retire plutôt que le bloquer.
        db.onversionchange = () => {
          db.close()
          base = null
        }
        ok(db)
      }
      demande.onerror = () => ko(demande.error)
      demande.onblocked = () => ko(new Error('base bloquée'))
    }).catch((e: unknown) => {
      base = null
      throw e
    }))
  const transaction = async <T>(
    mode: IDBTransactionMode,
    agir: (magasin: IDBObjectStore) => IDBRequest<T> | void,
  ): Promise<T | undefined> => {
    const db = await ouvrir()
    return new Promise<T | undefined>((ok, ko) => {
      const tx = db.transaction(MAGASIN, mode)
      const demande = agir(tx.objectStore(MAGASIN))
      tx.oncomplete = () => ok(demande ? demande.result : undefined)
      tx.onerror = () => ko(tx.error)
      tx.onabort = () => ko(tx.error ?? new Error('transaction abandonnée'))
    })
  }
  return {
    lire: (cle) => transaction('readonly', (m) => m.get(cle)),
    ecrire: async (entrees, effacees) => {
      await transaction('readwrite', (m) => {
        for (const cle of effacees) m.delete(cle)
        for (const [cle, valeur] of entrees) m.put(valeur, cle)
      })
    },
    vider: async () => {
      await transaction('readwrite', (m) => {
        m.clear()
      })
    },
  }
}

/** Un coffre en mémoire vive, pour les tests (les valeurs sont recopiées, comme IndexedDB le fait). */
export function coffreEnMemoire(): Coffre & { contenu: Map<string, unknown> } {
  const contenu = new Map<string, unknown>()
  return {
    contenu,
    lire: async (cle) => (contenu.has(cle) ? structuredClone(contenu.get(cle)) : undefined),
    ecrire: async (entrees, effacees) => {
      for (const cle of effacees) contenu.delete(cle)
      for (const [cle, valeur] of entrees) contenu.set(cle, structuredClone(valeur))
    },
    vider: async () => contenu.clear(),
  }
}

/* ------------------------------------------------------------------ */
/* L'identité du lecteur, et la clé d'une disposition                  */
/* ------------------------------------------------------------------ */

/**
 * Qui lit : le compte, le membre emprunté sous « voir comme », l'administrateur qui
 * incarne. Trois lecteurs différents, trois mémoires différentes. `null` sans session :
 * rien n'est gardé pour un visiteur.
 */
export function identiteDuLecteur(session: ChargeDeSession | null): string | null {
  if (!session) return null
  const { utilisateur, voirComme, incarnation } = session
  return `compte:${utilisateur.id}|voirComme:${voirComme?.memberId ?? ''}|par:${incarnation?.par.id ?? ''}`
}

/** Tout ce qui entre dans `disposer()` (`HistoireView.vue`, `ArbreGroupesView.vue`), hors le graphe lui-même. */
export interface EntreesDeLaDisposition {
  /**
   * Le moteur de placement : `lignees` (`/arbre-2`, par défaut), `groupes` (`/arbre`,
   * les rectangles de familles) ou `revue` (`/arbre-review`, les mêmes rectangles
   * redessinés en couches). Les écrans partagent le graphe gardé, et les mêmes bascules
   * donnent des dispositions différentes : sans lui, l'un relirait celle de l'autre.
   */
  moteur?: 'lignees' | 'groupes' | 'revue'
  avecAlliances: boolean
  avecConjoint: boolean
  avecSansLien: boolean
  /** L'ordre ne compte pas (`sansBranches` en fait un ensemble) : il est trié ici. */
  masquees: readonly string[]
  format: string
  alliancesDansLArbre: boolean
  fratriesParNaissance: boolean
}

/** La clé d'une disposition : ses entrées, et l'empreinte du code qui la calcule. */
export function cleDeDisposition(e: EntreesDeLaDisposition, version: string = EMPREINTE_DU_FRONT): string {
  return JSON.stringify([
    version,
    e.moteur ?? 'lignees',
    e.avecAlliances,
    e.avecConjoint,
    e.avecSansLien,
    [...e.masquees].sort(),
    e.format,
    e.alliancesDansLArbre,
    e.fratriesParNaissance,
  ])
}

declare const __EMPREINTE_DU_FRONT__: string | undefined
/** Posée par `vite.config.ts` ; absente hors de Vite, et alors rien ne se garde. */
const EMPREINTE_DU_FRONT = typeof __EMPREINTE_DU_FRONT__ === 'string' ? __EMPREINTE_DU_FRONT__ : ''

/* ------------------------------------------------------------------ */
/* La mémoire                                                          */
/* ------------------------------------------------------------------ */

interface Entete {
  format: number
  identite: string
  etag: string
  /** Les clés des dispositions gardées, la plus récente d'abord. */
  dispositions: string[]
}
interface EntreeGraphe {
  format: number
  identite: string
  etag: string
  graphe: Graphe
}
interface EntreeDisposition {
  format: number
  identite: string
  etag: string
  cle: string
  disposition: DispositionHistoire
}

const CLE_ENTETE = 'entete'
const CLE_GRAPHE = 'graphe'
const cleDEntree = (cle: string): string => `disposition:${cle}`

const estObjet = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null

function enteteValide(v: unknown, identite: string): Entete | null {
  if (!estObjet(v) || v['format'] !== FORMAT || v['identite'] !== identite) return null
  if (typeof v['etag'] !== 'string' || !Array.isArray(v['dispositions'])) return null
  return v as unknown as Entete
}

export interface MemoireDeLArbre {
  /** L'`ETag` du graphe gardé pour ce lecteur, à présenter en `If-None-Match` ; `null` sinon. */
  etagGarde(identite: string): Promise<string | null>
  /** Le graphe gardé sous cet `ETag` (après un `304`), ou `null`. */
  grapheGarde(identite: string, etag: string): Promise<Graphe | null>
  /** Garde un graphe reçu en `200` : il remplace le précédent et ses dispositions. */
  garderLeGraphe(identite: string, etag: string, graphe: Graphe): Promise<void>
  /** Charge une disposition gardée, pour que {@link dispositionGardee} la trouve. */
  prechargerLaDisposition(identite: string, etag: string, cle: string): Promise<void>
  /** La disposition déjà calculée pour ces entrées, s'il y en a une en main. Synchrone. */
  dispositionGardee(identite: string, etag: string, cle: string): DispositionHistoire | null
  /** Garde une disposition qu'on vient de calculer. */
  garderLaDisposition(identite: string, etag: string, cle: string, d: DispositionHistoire): Promise<void>
  /** Tout effacer — à chaque changement de session. */
  oublier(): Promise<void>
}

export interface OptionsDeLaMemoire {
  coffre: Coffre | null
  /** Faux en développement : voir la règle 4. */
  dispositions: boolean
  delai?: number
  /**
   * Ce qu'une écriture attend avant de commencer. Dans l'application, un moment creux
   * après la première image : IndexedDB recopie la valeur (quelques Mo pour un grand
   * arbre) sur le fil principal, et cette copie ne doit pas retarder l'affichage.
   */
  differer?: () => Promise<void>
}

/**
 * Le délai de garde : une promesse qui ne répond pas à temps rend `null`, une promesse
 * qui échoue aussi. IndexedDB peut rester bloqué (un autre onglet, un disque plein).
 */
function auPlusTard<T>(p: Promise<T>, ms: number): Promise<T | null> {
  return new Promise((ok) => {
    const minuterie = setTimeout(() => ok(null), ms)
    p.then(
      (v) => {
        clearTimeout(minuterie)
        ok(v)
      },
      () => {
        clearTimeout(minuterie)
        ok(null)
      },
    )
  })
}

export function creerMemoire({
  coffre,
  dispositions: avecDispositions,
  delai = DELAI_DE_LECTURE,
  differer = () => Promise.resolve(),
}: OptionsDeLaMemoire): MemoireDeLArbre {
  /*
   * Ce qu'on a EN MAIN, pour le lecteur et l'`ETag` courants : le graphe, et les
   * dispositions — lues du coffre ou calculées dans cette visite. Revenir sur l'arbre
   * sans F5, ou rebasculer un filtre, ne relit rien du disque.
   */
  interface EnMain {
    identite: string
    etag: string
    graphe: Graphe | null
    dispositions: Map<string, DispositionHistoire>
  }
  let enMain: EnMain | null = null
  const enMainPour = (identite: string, etag: string): EnMain => {
    if (!enMain || enMain.identite !== identite || enMain.etag !== etag)
      enMain = { identite, etag, graphe: null, dispositions: new Map() }
    return enMain
  }
  /** Les écritures se suivent : une disposition ne s'écrit pas avant le graphe dont elle vient. */
  let ecritures: Promise<void> = Promise.resolve()
  /** Chaque `oublier()` change de génération : une écriture d'avant ne s'applique plus. */
  let generation = 0
  const enSuite = (agir: (c: Coffre) => Promise<void>): Promise<void> => {
    const g = generation
    ecritures = ecritures
      .then(differer)
      .then(() => (coffre && g === generation ? agir(coffre) : undefined))
      .catch(() => undefined)
    return ecritures
  }
  const lireEntete = async (identite: string): Promise<Entete | null> =>
    coffre ? enteteValide(await auPlusTard(coffre.lire(CLE_ENTETE), Math.min(delai, DELAI_DE_L_ENTETE)), identite) : null

  return {
    async etagGarde(identite) {
      if (enMain?.identite === identite && enMain.graphe) return enMain.etag
      return (await lireEntete(identite))?.etag ?? null
    },

    async grapheGarde(identite, etag) {
      if (enMain?.identite === identite && enMain.etag === etag && enMain.graphe) return enMain.graphe
      if (!coffre) return null
      const v = await auPlusTard(coffre.lire(CLE_GRAPHE), delai)
      if (!estObjet(v) || v['format'] !== FORMAT || v['identite'] !== identite || v['etag'] !== etag) return null
      const graphe = v['graphe']
      const lisible =
        estObjet(graphe) && Array.isArray(graphe['noeuds']) && Array.isArray(graphe['aretes']) && typeof graphe['ego'] === 'string'
      if (!lisible) return null
      enMainPour(identite, etag).graphe = graphe as unknown as Graphe
      return graphe as unknown as Graphe
    },

    garderLeGraphe(identite, etag, graphe) {
      enMainPour(identite, etag).graphe = graphe
      return enSuite(async (c) => {
        // Un autre lecteur, ou une base d'une autre forme : on repart de rien.
        const avant = await c.lire(CLE_ENTETE)
        const precedent = enteteValide(avant, identite)
        if (avant !== undefined && !precedent) await c.vider()
        const entete: Entete = { format: FORMAT, identite, etag, dispositions: [] }
        const entree: EntreeGraphe = { format: FORMAT, identite, etag, graphe }
        await c.ecrire(
          [
            [CLE_ENTETE, entete],
            [CLE_GRAPHE, entree],
          ],
          // Les dispositions du graphe d'avant ne serviront plus.
          (precedent?.dispositions ?? []).map(cleDEntree),
        )
      })
    },

    async prechargerLaDisposition(identite, etag, cle) {
      if (!avecDispositions || !coffre) return
      const main = enMainPour(identite, etag)
      if (main.dispositions.has(cle)) return
      const v = await auPlusTard(coffre.lire(cleDEntree(cle)), delai)
      if (!estObjet(v) || v['format'] !== FORMAT || v['identite'] !== identite || v['etag'] !== etag || v['cle'] !== cle)
        return
      const d = v['disposition']
      const lisible =
        estObjet(d) && Array.isArray(d['fiches']) && d['parId'] instanceof Map && d['parPersonId'] instanceof Map
      if (!lisible) return
      // Entre-temps, un autre graphe a pu arriver : on ne range que sous celui-ci.
      if (enMain === main) main.dispositions.set(cle, d as unknown as DispositionHistoire)
    },

    dispositionGardee(identite, etag, cle) {
      if (!avecDispositions || enMain?.identite !== identite || enMain.etag !== etag) return null
      return enMain.dispositions.get(cle) ?? null
    },

    garderLaDisposition(identite, etag, cle, d) {
      if (!avecDispositions) return Promise.resolve()
      const main = enMainPour(identite, etag)
      main.dispositions.delete(cle)
      main.dispositions.set(cle, d)
      // En main aussi, on n'en garde que quelques-unes (la plus ancienne d'abord, ordre d'insertion).
      while (main.dispositions.size > DISPOSITIONS_GARDEES) main.dispositions.delete(main.dispositions.keys().next().value!)
      return enSuite(async (c) => {
        const entete = enteteValide(await c.lire(CLE_ENTETE), identite)
        // Sans le graphe dont elle vient, une disposition ne servirait à rien.
        if (!entete || entete.etag !== etag) return
        const liste = [cle, ...entete.dispositions.filter((k) => k !== cle)]
        const entree: EntreeDisposition = { format: FORMAT, identite, etag, cle, disposition: d }
        await c.ecrire(
          [
            [CLE_ENTETE, { ...entete, dispositions: liste.slice(0, DISPOSITIONS_GARDEES) }],
            [cleDEntree(cle), entree],
          ],
          liste.slice(DISPOSITIONS_GARDEES).map(cleDEntree),
        )
      })
    },

    oublier() {
      enMain = null
      // Les écritures encore en file ne s'appliqueront pas. On efface tout de suite (la
      // page peut se fermer dans la seconde), puis encore après celle qui serait en
      // cours, pour qu'aucune ne revienne après l'effacement.
      generation++
      const c = coffre
      if (!c) return Promise.resolve()
      const maintenant = c.vider().catch(() => undefined)
      ecritures = ecritures.then(() => c.vider()).catch(() => undefined)
      return Promise.all([maintenant, ecritures]).then(() => undefined)
    },
  }
}

/** La mémoire de l'application. */
export const memoireDeLArbre: MemoireDeLArbre = creerMemoire({
  coffre: (() => {
    try {
      return coffreIndexedDb()
    } catch {
      return null
    }
  })(),
  dispositions: import.meta.env.PROD && EMPREINTE_DU_FRONT !== '',
  differer: () =>
    new Promise<void>((suite) => {
      const ric = (globalThis as { requestIdleCallback?: (f: () => void, o?: { timeout: number }) => number })
        .requestIdleCallback
      if (typeof ric === 'function') ric(() => suite(), { timeout: 3000 })
      else setTimeout(suite, 200)
    }),
})
