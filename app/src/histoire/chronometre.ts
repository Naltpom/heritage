/*
 * LE CHRONOMÈTRE DU CHARGEMENT de l'arbre : où passent les secondes d'un F5.
 *
 * À 3 000 personnes, l'arbre mettait 7 s à paraître. Ce relevé découpe ce temps :
 * le serveur (son en-tête `Server-Timing`, étape par étape), le réseau (attente,
 * téléchargement, poids), la disposition refaite ici (filtres, puis lignées), le
 * premier rendu Vue, puis la première image peinte. Il ne change rien au calcul : il
 * l'entoure, et l'écrit une fois dans la console, à copier tel quel.
 *
 * Aussi lisible depuis la console : `window.__chargementArbre`.
 */

/** Une durée mesurée ici, en millisecondes. */
export interface EtapeMesuree {
  etape: string
  ms: number
}

/** Ce que le navigateur sait de la requête du graphe (Resource Timing). */
export interface ReseauDuGraphe {
  attente: number
  telechargement: number
  transfertKo: number
  decodeKo: number
  serveur: { etape: string; ms: number; note: string }[]
}

export interface ReleveDuChargement {
  /** Depuis la navigation : n'a de sens qu'après un F5, pas après un clic dans l'app. */
  depuisLaNavigation: number | null
  etapes: EtapeMesuree[]
  reseau: ReseauDuGraphe | null
  personnes: number
  noeuds: number
}

export interface Chronometre {
  mesure<T>(etape: string, calcul: () => T): T
  mesureAsync<T>(etape: string, calcul: () => Promise<T>): Promise<T>
  etapes(): EtapeMesuree[]
}

export function creerChronometre(maintenant: () => number = () => performance.now()): Chronometre {
  const liste: EtapeMesuree[] = []
  const noter = (etape: string, debut: number): void => {
    const ms = maintenant() - debut
    const deja = liste.find((e) => e.etape === etape)
    if (deja) deja.ms += ms
    else liste.push({ etape, ms })
  }
  return {
    mesure(etape, calcul) {
      const debut = maintenant()
      try {
        return calcul()
      } finally {
        noter(etape, debut)
      }
    },
    async mesureAsync(etape, calcul) {
      const debut = maintenant()
      try {
        return await calcul()
      } finally {
        noter(etape, debut)
      }
    },
    etapes: () => liste.map((e) => ({ ...e })),
  }
}

/** La prochaine image réellement peinte : deux `requestAnimationFrame`. */
export function apresLaPeinture(): Promise<void> {
  return new Promise((fin) => {
    if (typeof requestAnimationFrame !== 'function') return fin()
    requestAnimationFrame(() => requestAnimationFrame(() => fin()))
  })
}

/** Le dernier relevé Resource Timing d'une URL se terminant par `suffixe`. */
export function reseauDe(suffixe: string): ReseauDuGraphe | null {
  let entrees: PerformanceResourceTiming[]
  try {
    entrees = performance.getEntriesByType('resource') as PerformanceResourceTiming[]
  } catch {
    return null
  }
  const e = [...entrees].reverse().find((r) => new URL(r.name, location.href).pathname.endsWith(suffixe))
  if (!e) return null
  return {
    attente: e.responseStart - e.requestStart,
    telechargement: e.responseEnd - e.responseStart,
    transfertKo: e.transferSize / 1024,
    decodeKo: e.decodedBodySize / 1024,
    serveur: (e.serverTiming ?? []).map((s) => ({ etape: s.name, ms: s.duration, note: s.description })),
  }
}

/** Le relevé en texte : une ligne par mesure, à coller tel quel. */
export function enTexte(r: ReleveDuChargement): string {
  const ms = (v: number): string => `${Math.round(v)} ms`
  const lignes = [`Arbre : ${r.personnes} personnes, ${r.noeuds} nœuds`]
  if (r.depuisLaNavigation !== null) lignes.push(`Depuis le F5 : ${ms(r.depuisLaNavigation)}`)
  for (const e of r.etapes) lignes.push(`  ${e.etape} : ${ms(e.ms)}`)
  if (r.reseau) {
    const n = r.reseau
    lignes.push(`Réseau : attente ${ms(n.attente)}, téléchargement ${ms(n.telechargement)}, ${Math.round(n.transfertKo)} Ko transférés (${Math.round(n.decodeKo)} Ko décodés)`)
    if (n.serveur.length) lignes.push('Serveur :')
    for (const s of n.serveur) lignes.push(`  ${s.etape} : ${[s.ms ? ms(s.ms) : '', s.note].filter(Boolean).join(' ')}`)
  }
  return lignes.join('\n')
}

let premier = true
/**
 * Vrai au premier chargement de l'arbre depuis l'ouverture de la page, une seule fois :
 * après un F5 sur l'arbre, `performance.now()` est alors le temps entier qu'a vu
 * l'utilisateur, le téléchargement du code compris.
 */
export function premierChargement(): boolean {
  const oui = premier
  premier = false
  return oui
}

/** Écrit le relevé dans la console et le garde sous `window.__chargementArbre`. */
export function publier(r: ReleveDuChargement): void {
  const texte = enTexte(r)
  try {
    ;(window as unknown as { __chargementArbre?: unknown }).__chargementArbre = { ...r, texte }
  } catch {
    /* fenêtre figée : la console suffit */
  }
  console.info(`[arbre] chargement\n${texte}`)
}
