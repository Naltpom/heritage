/**
 * Les branches d'un arbre synthétique, posées COMME LE SERVEUR LES POSE
 * (`CalculAscendance::branches()`), pour le banc de `/arbre-review` — jamais pour
 * l'application.
 *
 * `histoire/banc/genealogie.ts` donne à chaque personne la branche de la souche qui l'a
 * fait naître : les oncles d'un côté se retrouvent au tronc, les cousins aussi. Le
 * serveur, lui, part des quatre grands-parents (`pp`, `pm`, `mp`, `mm` : toute leur
 * ascendance), pose le tronc (l'ego, ses parents, sa fratrie), les quatre quarts du
 * conjoint, puis fait HÉRITER chacun de la branche d'un voisin (parents d'abord, puis
 * enfants, puis conjoints — le tronc ne se propage pas). Sur `/arbre`, c'est cette
 * répartition qui fait les rectangles : le banc doit la reproduire, sinon il mesure un
 * autre arbre (un tronc de 271 personnes au lieu de 5).
 */
import type { Branche, Graphe, NoeudPersonne } from '@/lib/api'

export function brancherCommeLeServeur(graphe: Graphe): Graphe {
  const personnes = graphe.noeuds.filter((n): n is NoeudPersonne => n.type === 'personne')
  const estPersonne = new Set(personnes.map((p) => p.id))
  const sexes = new Map(personnes.map((p) => [p.id, p.sex]))
  const parents = new Map<string, string[]>()
  const enfants = new Map<string, string[]>()
  const conjoints = new Map<string, string[]>()
  const epoux = new Map<string, string[]>()
  const enfantsDe = new Map<string, string[]>()
  const ajouter = (m: Map<string, string[]>, k: string, v: string): void => {
    const l = m.get(k)
    if (l) l.push(v)
    else m.set(k, [v])
  }
  for (const a of graphe.aretes) {
    const [p, f] = estPersonne.has(a.de) ? [a.de, a.vers] : [a.vers, a.de]
    if (a.genre === 'union') ajouter(epoux, f, p)
    else ajouter(enfantsDe, f, p)
  }
  for (const [f, ep] of epoux) {
    // Le mari d'abord : c'est l'ordre des parents du serveur.
    const tri = [...ep].sort((a, b) => Number(sexe(b) === 'masculin') - Number(sexe(a) === 'masculin'))
    for (const c of enfantsDe.get(f) ?? []) for (const p of tri) ajouter(parents, c, p)
    for (const p of tri) {
      for (const c of enfantsDe.get(f) ?? []) ajouter(enfants, p, c)
      for (const q of tri) if (q !== p) ajouter(conjoints, p, q)
    }
  }
  function sexe(id: string): string | undefined {
    return sexes.get(id)
  }
  const pere = (id: string): string | undefined => (parents.get(id) ?? []).find((p) => sexe(p) === 'masculin') ?? parents.get(id)?.[0]
  const mere = (id: string): string | undefined => (parents.get(id) ?? []).find((p) => sexe(p) === 'feminin') ?? parents.get(id)?.[1]
  const remonter = (depart: string): string[] => {
    const vus = [depart]
    for (let i = 0; i < vus.length; i++) for (const p of parents.get(vus[i]!) ?? []) if (!vus.includes(p)) vus.push(p)
    return vus
  }

  const branche = new Map<string, Branche>()
  const ego = graphe.ego
  const souches = (racine: string, prefixe: '' | 'c'): void => {
    const p = pere(racine)
    const m = mere(racine)
    const quatre: [string | undefined, string][] = [
      [p ? pere(p) : undefined, 'pp'],
      [p ? mere(p) : undefined, 'pm'],
      [m ? pere(m) : undefined, 'mp'],
      [m ? mere(m) : undefined, 'mm'],
    ]
    for (const [s, code] of quatre) {
      if (!s) continue
      for (const a of remonter(s)) if (!branche.has(a)) branche.set(a, `${prefixe}${code}` as Branche)
    }
  }
  souches(ego, '')
  // Le tronc : l'ego, ses parents, sa fratrie, sa descendance et son conjoint (`tronc()`).
  const tronc = [ego, ...(parents.get(ego) ?? [])]
  for (const p of parents.get(ego) ?? []) for (const e of enfants.get(p) ?? []) tronc.push(e)
  for (let i = 0, pile = [ego]; i < pile.length; i++)
    for (const e of enfants.get(pile[i]!) ?? [])
      if (!pile.includes(e)) {
        pile.push(e)
        tronc.push(e)
      }
  for (const c of conjoints.get(ego) ?? []) tronc.push(c)
  for (const t of tronc) branche.set(t, 'tr')
  // Les quarts du conjoint (le premier).
  const conjoint = conjoints.get(ego)?.[0]
  if (conjoint) {
    const avant = new Map(branche)
    souches(conjoint, 'c')
    for (const [k, v] of avant) branche.set(k, v)
  }
  // L'héritage : parents, puis enfants, puis conjoints ; le tronc ne se propage pas.
  for (let passe = 0; passe < 50; passe++) {
    let bouge = false
    for (const p of personnes) {
      if (branche.has(p.id)) continue
      for (const v of [...(parents.get(p.id) ?? []), ...(enfants.get(p.id) ?? []), ...(conjoints.get(p.id) ?? [])]) {
        const b = branche.get(v)
        if (b !== undefined && b !== 'tr') {
          branche.set(p.id, b)
          bouge = true
          break
        }
      }
    }
    if (!bouge) break
  }
  // Ce qui reste prend la branche d'un conjoint, même le tronc.
  for (const p of personnes) {
    if (branche.has(p.id)) continue
    for (const v of conjoints.get(p.id) ?? []) {
      const b = branche.get(v)
      if (b !== undefined) {
        branche.set(p.id, b)
        break
      }
    }
  }
  return {
    ...graphe,
    noeuds: graphe.noeuds.map((n) => (n.type === 'personne' ? { ...n, branch: branche.get(n.id) ?? (null as unknown as Branche) } : n)),
  }
}
