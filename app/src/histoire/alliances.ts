/**
 * **Qui est de la famille du lecteur, et qui ne l'est que par alliance.**
 *
 * Décision du 2026-10-01 (captures « voir comme Camille ») : vue depuis la nièce,
 * toute l'ascendance du mari de sa tante — six à huit générations de Provost,
 * Bornerie, Savignac… sans aucun lien de sang avec elle — était rangée dans sa
 * branche Voulgre et mêlée à sa vraie ascendance : des barres de fratrie qui
 * traversaient tout le bloc, des étiquettes les unes sur les autres, un arbre de
 * 581 personnes. La règle posée par l'utilisateur :
 *
 *  - est **de la famille** du lecteur : ses ancêtres, les descendants de ses
 *    ancêtres (les collatéraux, lui et sa descendance compris), et les conjoints
 *    de toutes ces personnes — le conjoint LUI-MÊME, pas sa famille ;
 *  - la famille du conjoint DU LECTEUR garde son régime (« Famille du conjoint ») ;
 *  - **tout le reste qui est relié est « par alliance »** : masqué par défaut,
 *    et, quand on l'affiche, chaque famille dans son propre bloc, à droite.
 *
 * Le conjoint qui fait le pont (le mari de la tante) est de la famille : il reste
 * à côté de sa femme. Sa famille à lui forme un groupe, dont il est le `pont`.
 *
 * **Le serveur calcule ces deux clés** (`parente`, `pont`, § 5.2) pour le point de
 * vue servi. Ce module les lit quand elles sont là, et les RECALCULE sur les liens
 * sinon (serveur antérieur, arbres de test) — par la même règle : elles se déduisent
 * des liens, que tout lecteur reçoit déjà, et n'apprennent rien de plus.
 *
 * Une personne qu'aucun chemin ne relie au lecteur (un îlot) n'est pas « par
 * alliance » : elle n'est reliée à rien. Elle est classée `famille` ici et garde sa
 * zone « sans lien connu » dans la disposition.
 *
 * Pur, sans DOM : tout se vérifie dans `alliances.test.ts`.
 */
import type { Graphe, IdentifiantNoeud, Parente } from '@/lib/api'

export interface ClasseDeParente {
  parente: Parente
  /** Sur un nœud `alliance` : le conjoint « famille » qui rattache son groupe ; `null` sinon. */
  pont: IdentifiantNoeud | null
}

/* ------------------------------------------------------------------ */
/* Les liens, lus sur les arêtes                                       */
/* ------------------------------------------------------------------ */

interface Liens {
  personnes: IdentifiantNoeud[]
  /** Personne → familles dont elle est époux / enfant. */
  epouxDans: Map<string, string[]>
  enfantDans: Map<string, string[]>
  /** Famille → ses époux / ses enfants. */
  epoux: Map<string, string[]>
  enfants: Map<string, string[]>
}

const ajouter = (m: Map<string, string[]>, k: string, v: string): void => {
  const l = m.get(k)
  if (l) l.push(v)
  else m.set(k, [v])
}

function lireLiens(graphe: Graphe): Liens {
  const personnes = graphe.noeuds.filter((n) => n.type === 'personne').map((n) => n.id)
  const estPersonne = new Set(personnes)
  const l: Liens = { personnes, epouxDans: new Map(), enfantDans: new Map(), epoux: new Map(), enfants: new Map() }
  for (const a of graphe.aretes) {
    const [p, f] = estPersonne.has(a.de) ? [a.de, a.vers] : [a.vers, a.de]
    if (!estPersonne.has(p) || estPersonne.has(f)) continue
    if (a.genre === 'union') {
      ajouter(l.epouxDans, p, f)
      ajouter(l.epoux, f, p)
    } else {
      ajouter(l.enfantDans, p, f)
      ajouter(l.enfants, f, p)
    }
  }
  return l
}

/** Toutes les personnes qui partagent une famille avec `p` : parents, fratrie, conjoints, enfants. */
function voisins(l: Liens, p: string): string[] {
  const v: string[] = []
  for (const f of [...(l.epouxDans.get(p) ?? []), ...(l.enfantDans.get(p) ?? [])]) {
    for (const q of [...(l.epoux.get(f) ?? []), ...(l.enfants.get(f) ?? [])]) if (q !== p) v.push(q)
  }
  return v
}

/* ------------------------------------------------------------------ */
/* La famille du conjoint du lecteur (régime existant)                 */
/* ------------------------------------------------------------------ */

/**
 * La famille des conjoints de l'ego — leur ascendance, leurs collatéraux et leurs alliés.
 *
 * Le partage se lit sur les LIENS, pas sur les couleurs : le serveur ne découpe en
 * branches « conjoint » que l'ascendance d'un seul conjoint, et un second mariage doit
 * se masquer aussi. Est du côté de l'ego tout ce qu'on atteint depuis lui sans passer
 * par un de ses conjoints ; est de la famille d'un conjoint ce qu'on atteint depuis ses
 * parents sans repasser par le côté de l'ego. Une personne des deux côtés (un arbre
 * endogame) n'en est pas. Les conjoints eux-mêmes et les enfants n'en sont pas.
 */
export function familleDesConjoints(graphe: Graphe): Set<string> {
  return familleDesConjointsSurLiens(graphe.ego, lireLiens(graphe))
}

function familleDesConjointsSurLiens(ego: string, l: Liens): Set<string> {
  const conjoints = new Set(
    (l.epouxDans.get(ego) ?? []).flatMap((f) => l.epoux.get(f) ?? []).filter((e) => e !== ego),
  )
  const retires = new Set<string>()
  if (!conjoints.size) return retires

  const parcourir = (depart: string[], interdits: Set<string>): Set<string> => {
    const vus = new Set<string>()
    const file = depart.filter((p) => !interdits.has(p))
    for (const p of file) vus.add(p)
    while (file.length) {
      const p = file.shift()!
      for (const f of [...(l.epouxDans.get(p) ?? []), ...(l.enfantDans.get(p) ?? [])]) {
        for (const v of [...(l.epoux.get(f) ?? []), ...(l.enfants.get(f) ?? [])]) {
          if (vus.has(v) || interdits.has(v)) continue
          vus.add(v)
          file.push(v)
        }
      }
    }
    return vus
  }
  const coteEgo = parcourir([ego], conjoints)
  for (const c of conjoints) {
    if (coteEgo.has(c)) continue
    const parents = (l.enfantDans.get(c) ?? []).flatMap((f) => l.epoux.get(f) ?? [])
    for (const p of parcourir(parents, new Set([...coteEgo, ...conjoints]))) retires.add(p)
  }
  return retires
}

/* ------------------------------------------------------------------ */
/* La classification                                                   */
/* ------------------------------------------------------------------ */

/** Le calcul sur les liens seuls — ce que le serveur fait de son côté. */
function classerSurLiens(graphe: Graphe, l: Liens): Map<string, ClasseDeParente> {
  const ego = graphe.ego

  // Les ancêtres (l'ego compris), en remontant par les familles dont on est l'enfant.
  const ancetres = new Set([ego])
  for (const file = [ego]; file.length; ) {
    const p = file.shift()!
    for (const f of l.enfantDans.get(p) ?? [])
      for (const parent of l.epoux.get(f) ?? []) {
        if (ancetres.has(parent)) continue
        ancetres.add(parent)
        file.push(parent)
      }
  }
  // Le SANG : les descendants des ancêtres, ancêtres compris.
  const sang = new Set(ancetres)
  for (const file = [...ancetres]; file.length; ) {
    const p = file.shift()!
    for (const f of l.epouxDans.get(p) ?? [])
      for (const e of l.enfants.get(f) ?? []) {
        if (sang.has(e)) continue
        sang.add(e)
        file.push(e)
      }
  }
  // La famille : le sang, et les conjoints du sang — eux seuls, pas leur famille.
  const famille = new Set(sang)
  /** Les conjoints « purs » (pas du sang) : les seuls ponts possibles vers une alliance. */
  const ponts: string[] = []
  for (const p of sang)
    for (const f of l.epouxDans.get(p) ?? [])
      for (const c of l.epoux.get(f) ?? []) {
        if (famille.has(c)) continue
        famille.add(c)
        ponts.push(c)
      }

  /*
   * La famille du conjoint DU LECTEUR, telle que le serveur la calcule (§ 5.2) : tout ce
   * qu'on atteint depuis ses conjoints, par n'importe quel lien, sans repasser par la
   * famille — ses parents, sa fratrie, mais aussi les enfants d'une autre union et leur
   * autre parent. (Le filtre « Famille du conjoint », lui, garde sa propre règle,
   * `familleDesConjoints()`, inchangée : ce classement ne sert qu'à ne pas prendre ces
   * personnes pour une famille par alliance.)
   */
  const conjoint = new Set<string>()
  {
    const file: string[] = []
    for (const f of l.epouxDans.get(ego) ?? [])
      for (const c of l.epoux.get(f) ?? []) if (c !== ego) file.push(...voisins(l, c))
    while (file.length) {
      const p = file.shift()!
      if (famille.has(p) || conjoint.has(p)) continue
      conjoint.add(p)
      file.push(...voisins(l, p))
    }
  }

  /*
   * Les ALLIANCES, groupe par groupe : un parcours en largeur parti de tous les ponts à
   * la fois, qui ne traverse ni la famille ni celle du conjoint du lecteur. Chaque
   * allié prend le pont le plus proche ; à égalité, le premier dans l'ordre des nœuds.
   * Un voisin d'une personne du SANG est toujours de la famille (ses parents, ses
   * enfants, ses conjoints le sont) : une alliance ne s'atteint que par un conjoint pur.
   */
  const pontDe = new Map<string, string>()
  const ordre = new Map(l.personnes.map((p, i) => [p, i]))
  ponts.sort((a, b) => (ordre.get(a) ?? 0) - (ordre.get(b) ?? 0))
  const file: string[] = []
  for (const p of ponts) {
    for (const v of voisins(l, p)) {
      if (famille.has(v) || conjoint.has(v) || pontDe.has(v)) continue
      pontDe.set(v, p)
      file.push(v)
    }
  }
  while (file.length) {
    const p = file.shift()!
    for (const v of voisins(l, p)) {
      if (famille.has(v) || conjoint.has(v) || pontDe.has(v)) continue
      pontDe.set(v, pontDe.get(p)!)
      file.push(v)
    }
  }

  const classes = new Map<string, ClasseDeParente>()
  for (const p of l.personnes) {
    if (pontDe.has(p)) classes.set(p, { parente: 'alliance', pont: pontDe.get(p)! })
    else classes.set(p, { parente: conjoint.has(p) ? 'conjoint' : 'famille', pont: null })
  }
  return classes
}

const cache = new WeakMap<Graphe, Map<string, ClasseDeParente>>()

/**
 * La parenté de chaque personne du graphe pour son lecteur (`graphe.ego`).
 *
 * Les clés servies (`parente`, `pont`) font foi quand elles sont là ; un `pont` absent
 * ou inconnu du graphe est complété par le calcul sur les liens. Mémoïsé par graphe.
 */
export function classerParente(graphe: Graphe): Map<string, ClasseDeParente> {
  const deja = cache.get(graphe)
  if (deja) return deja
  const classes = classerSurLiens(graphe, lireLiens(graphe))
  const personnes = new Set(classes.keys())
  for (const n of graphe.noeuds) {
    if (n.type !== 'personne' || n.parente === undefined) continue
    const local = classes.get(n.id)!
    const pont =
      n.parente !== 'alliance'
        ? null
        : n.pont !== undefined && personnes.has(n.pont)
          ? n.pont
          : local.parente === 'alliance'
            ? local.pont
            : null
    classes.set(n.id, { parente: n.parente, pont })
  }
  cache.set(graphe, classes)
  return classes
}

/**
 * Les familles par alliance, groupées par pont : `pont → membres`, dans l'ordre des
 * nœuds. Un pont lui-même par alliance (ou absent) ne fait pas de groupe : ses membres
 * restent rattachés à l'arbre comme avant, plutôt que perdus.
 */
export function groupesParAlliance(graphe: Graphe): Map<string, string[]> {
  const classes = classerParente(graphe)
  const groupes = new Map<string, string[]>()
  for (const [id, c] of classes) {
    if (c.parente !== 'alliance' || c.pont === null) continue
    if (classes.get(c.pont)?.parente === 'alliance') continue
    ajouter(groupes, c.pont, id)
  }
  return groupes
}

/**
 * La personne `personId` est-elle d'une famille par alliance ? C'est la question que
 * pose « Aller à une personne » : la recherche (par l'API) trouve aussi ces personnes
 * quand leur famille est masquée, et la choisir doit allumer la bascule.
 */
export function estParAlliance(graphe: Graphe, personId: number): boolean {
  const noeud = graphe.noeuds.find((n) => n.type === 'personne' && n.personId === personId)
  return noeud !== undefined && classerParente(graphe).get(noeud.id)?.parente === 'alliance'
}

/** Y a-t-il au moins une personne par alliance ? (Le filtre ne s'affiche que dans ce cas.) */
export function aDesFamillesParAlliance(graphe: Graphe): boolean {
  for (const c of classerParente(graphe).values()) if (c.parente === 'alliance') return true
  return false
}

/**
 * Le graphe sans les familles par alliance : ces fiches et les liens qui les touchent
 * sont RETIRÉS avant la disposition, pour que l'arbre se recompacte — c'est l'état par
 * défaut de `/arbre`. Le pont (le mari de la tante) reste : il est de la famille.
 */
export function sansFamillesParAlliance(graphe: Graphe): Graphe {
  const classes = classerParente(graphe)
  const retires = new Set<string>()
  for (const [id, c] of classes) if (c.parente === 'alliance') retires.add(id)
  if (!retires.size) return graphe
  const reste: Graphe = {
    ...graphe,
    noeuds: graphe.noeuds.filter((n) => !retires.has(n.id)),
    aretes: graphe.aretes.filter((a) => !retires.has(a.de) && !retires.has(a.vers)),
  }
  // Ce qui reste est de la famille ou de celle du conjoint : rien à recalculer.
  const restantes = new Map([...classes].filter(([id]) => !retires.has(id)))
  cache.set(reste, restantes)
  return reste
}
