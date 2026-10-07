/**
 * **Des généalogies synthétiques, de la taille de la production** — pour le banc de
 * `disposerLignees` (`lignees.banc.ts`), jamais pour l'application.
 *
 * Pourquoi ce fichier existe : la disposition `lignees` prenait ~1 s sur l'arbre servi
 * (3 904 personnes, mesuré au F5 le 2026-10-07), et l'accélérer sans rien changer à son
 * résultat demande de la comparer, octet pour octet, sur des arbres qui ont les
 * PATHOLOGIES du réel : remariages, divorces, foyers à un seul parent, ascendance du
 * conjoint, familles par alliance (le mari d'une tante et toute son ascendance), îlots
 * sans lien, implexes (deux cousins qui s'épousent), fiches restreintes, sexes inconnus,
 * dates absentes, liens en attente. Les arbres d'essai des tests en ont une poignée
 * chacun ; le relevé servi, lui, ne peut pas entrer dans le dépôt (ADR 0006 : il porte
 * des personnes vivantes). D'où un générateur.
 *
 * **Déterministe** : un PRNG à graine (mulberry32), aucune lecture de l'horloge. La même
 * graine rend le même graphe, à l'octet — c'est ce qui permet de comparer deux versions
 * de l'algorithme sur des instantanés.
 *
 * Les branches sont posées comme le serveur les pose : `pp`/`pm`/`mp`/`mm` d'après le
 * grand-parent de tête, `cpp`… pour l'ascendance du conjoint, `tr` pour le tronc (l'ego,
 * sa fratrie, sa descendance). Une famille par alliance prend la branche de son pont,
 * comme le servait le serveur avant le 2026-10-01 : le front ne doit pas s'y fier.
 */
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne, Sexe } from '@/lib/api'

/** mulberry32 : 32 bits d'état, assez pour des arbres d'essai, et identique partout. */
function prng(graine: number): () => number {
  let a = graine >>> 0
  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

const PRENOMS_H = ['Jean', 'Pierre', 'Louis', 'Paul', 'Henri', 'Jules', 'Émile', 'Marcel', 'André', 'Hugo', 'Léon', 'Gaston']
const PRENOMS_F = ['Marie', 'Jeanne', 'Louise', 'Anne', 'Rose', 'Lucie', 'Alice', 'Berthe', 'Céline', 'Léa', 'Emma', 'Odile']
const NOMS = [
  'Provost', 'Voulgre', 'Destombes', 'Figarol', 'Bornerie', 'Savignac', 'Lalot', 'Bonmartin', 'Basset',
  'Becquelin', 'Dumortier', 'Damay', 'Ducatteau', 'Delobel', 'Lemay', 'Hassebroucq', 'Carette', 'Lezy',
  'de La Tour', 'Martin', 'Bernard', 'Petit', 'Durand', 'Leroy', 'Moreau', 'Simon', 'Laurent', 'Michel',
]

export interface OptionsDeGeneration {
  /** Le nombre de personnes visé (atteint à quelques unités près). */
  personnes: number
  graine: number
}

/**
 * Une généalogie d'environ `personnes` personnes autour d'un ego, avec toutes les
 * formes que la disposition doit savoir poser.
 */
export function genealogieSynthetique({ personnes: cible, graine }: OptionsDeGeneration): Graphe {
  const hasard = prng(graine)
  const tirer = (p: number): boolean => hasard() < p
  const entre = (a: number, b: number): number => a + Math.floor(hasard() * (b - a + 1))
  const parmi = <T>(l: readonly T[]): T => l[Math.floor(hasard() * l.length)]!

  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  let prochain = 1
  /** Génération (0 = ego), nom de famille, sexe de chaque personne : pour marier et nommer. */
  const generationDe = new Map<string, number>()
  const nomDe = new Map<string, string>()
  const sexeDe = new Map<string, Sexe>()
  /** Les personnes sans conjoint, par génération : un implexe en épouse une. */
  const celibataires = new Map<number, string[]>()

  const personne = (generation: number, branch: Branche, nom: string, sexe?: Sexe): string => {
    const n = prochain++
    const sex: Sexe = sexe ?? (tirer(0.02) ? 'inconnu' : tirer(0.5) ? 'masculin' : 'feminin')
    const prenom = parmi(sex === 'feminin' ? PRENOMS_F : PRENOMS_H)
    const annee = 1995 - 28 * generation + entre(-6, 6)
    const vivant = annee > 1940
    const restreinte = vivant && tirer(0.12)
    const avecDates = !tirer(0.1)
    const etiquette = `${prenom} ${nom}`
    const p: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: tirer(0.01) ? null : n,
      x: 0,
      y: 0,
      w: restreinte ? 132 : Math.max(96, 40 + etiquette.length * 7),
      h: 56,
      displayName: etiquette,
      etiquetteNom: restreinte ? '' : etiquette,
      sex,
      generation,
      branch,
      vivant,
      visibilite: restreinte ? 'restreinte' : 'complete',
      ...(avecDates && !restreinte ? { etiquetteDates: `${annee} – ${vivant ? '' : annee + entre(20, 90)}` } : {}),
    }
    noeuds.push(p)
    generationDe.set(p.id, generation)
    nomDe.set(p.id, nom)
    sexeDe.set(p.id, sex)
    if (coteEgo && !dansUneAlliance) {
      const l = celibataires.get(generation)
      if (l) l.push(p.id)
      else celibataires.set(generation, [p.id])
    }
    return p.id
  }
  const marier = (id: string): void => {
    const l = celibataires.get(generationDe.get(id)!)
    const i = l?.indexOf(id) ?? -1
    if (l && i >= 0) l.splice(i, 1)
  }
  const famille = (epoux: string[], enfants: string[]): string => {
    const f: NoeudFamille = {
      id: `f${prochain++}`,
      type: 'famille',
      familyId: prochain,
      x: 0,
      y: 0,
      w: 10,
      h: 10,
      divorced: epoux.length === 2 && tirer(0.1),
    }
    noeuds.push(f)
    for (const e of epoux) {
      marier(e)
      aretes.push({ de: e, vers: f.id, genre: 'union', ...(tirer(0.01) ? { enAttente: true as const } : {}) })
    }
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation', ...(tirer(0.01) ? { enAttente: true as const } : {}) })
    return f.id
  }
  const nbPersonnes = (): number => generationDe.size
  /** Le budget de la partie en cours : chaque partie de l'arbre a sa part de la cible. */
  let plafond = cible
  let dansUneAlliance = false
  /** Vrai pendant l'ascendance de l'ego : un implexe ne relie pas deux familles étrangères. */
  let coteEgo = false
  const conjointPour = (id: string, branch: Branche): string => {
    const sexe = sexeDe.get(id)
    const autre: Sexe = sexe === 'masculin' ? 'feminin' : sexe === 'feminin' ? 'masculin' : tirer(0.5) ? 'masculin' : 'feminin'
    return personne(generationDe.get(id)!, branch, parmi(NOMS), autre)
  }

  /**
   * La descendance d'une personne, jusqu'à la génération +2 de l'ego : un conjoint (ou
   * deux, un remariage), des enfants, parfois un enfant sans autre parent connu.
   */
  const descendance = (id: string, branch: Branche, profondeur: number): void => {
    if (nbPersonnes() >= plafond || generationDe.get(id)! >= 2 || profondeur > 6) return
    const unions = tirer(0.65) ? (tirer(0.12) ? 2 : 1) : tirer(0.06) ? -1 : 0
    if (unions === -1) {
      // Un foyer à un seul parent connu.
      const enfants = Array.from({ length: entre(1, 2) }, () => personne(generationDe.get(id)! + 1, branch, nomDe.get(id)!))
      famille([id], enfants)
      for (const e of enfants) descendance(e, branch, profondeur + 1)
      return
    }
    for (let u = 0; u < unions; u++) {
      const c = conjointPour(id, branch)
      const nom = sexeDe.get(id) === 'masculin' ? nomDe.get(id)! : nomDe.get(c)!
      const enfants = Array.from({ length: entre(0, 3) }, () => personne(generationDe.get(id)! + 1, branch, nom))
      famille([id, c], enfants)
      // Une famille par alliance : le conjoint a ses parents, ses grands-parents, sa fratrie.
      if (!dansUneAlliance && tirer(coteEgo ? 0.03 : 0.25)) {
        // Une seule profondeur d'alliance : l'ascendance d'un allié n'en ouvre pas d'autre.
        dansUneAlliance = true
        ascendance(c, () => branch, entre(2, 4), tirer(0.3))
        dansUneAlliance = false
      }
      // Le conjoint s'est remarié lui aussi, ailleurs.
      if (tirer(0.04)) {
        const c2 = conjointPour(c, branch)
        famille([c, c2], Array.from({ length: entre(0, 2) }, () => personne(generationDe.get(c)! + 1, branch, nomDe.get(c2)!)))
      }
      for (const e of enfants) descendance(e, branch, profondeur + 1)
    }
  }

  /**
   * L'ascendance d'une personne sur `profondeur` générations : chaque couple avec la
   * fratrie de l'enfant (et sa descendance), parfois un remariage d'un ancêtre.
   */
  const ascendance = (racine: string, branchDe: (sosa: number) => Branche, profondeur: number, collateraux: boolean): void => {
    // En largeur, génération par génération : le côté père ne prend pas tout le budget.
    const file: { id: string; sosa: number; reste: number }[] = [{ id: racine, sosa: 1, reste: profondeur }]
    while (file.length) {
      const { id, sosa, reste } = file.shift()!
      if (reste <= 0 || nbPersonnes() >= plafond || (sosa > 3 && tirer(0.12))) continue
      const g = generationDe.get(id)! - 1
      const pere = tirer(0.05) ? null : personne(g, branchDe(2 * sosa), nomDe.get(id)!, 'masculin')
      const mere = tirer(0.05) ? null : personne(g, branchDe(2 * sosa + 1), parmi(NOMS), 'feminin')
      const epoux = [pere, mere].filter((e): e is string => e !== null)
      if (!epoux.length) continue
      const fratrie = collateraux ? Array.from({ length: entre(0, 4) }, () => personne(g + 1, branchDe(sosa), nomDe.get(id)!)) : []
      const aine = entre(0, fratrie.length)
      famille(epoux, [...fratrie.slice(0, aine), id, ...fratrie.slice(aine)])
      for (const e of fratrie) descendance(e, branchDe(sosa), 0)
      // Un ancêtre remarié : un second conjoint et des demi-frères et sœurs.
      for (const parent of epoux) {
        if (!tirer(0.08)) continue
        const c = conjointPour(parent, branchDe(sosa))
        const demi = Array.from({ length: entre(0, 3) }, () => personne(g + 1, branchDe(sosa), nomDe.get(parent)!))
        famille([parent, c], demi)
        for (const e of demi) descendance(e, branchDe(sosa), 0)
      }
      if (pere) file.push({ id: pere, sosa: 2 * sosa, reste: reste - 1 })
      if (mere) file.push({ id: mere, sosa: 2 * sosa + 1, reste: reste - 1 })
    }
  }

  /** Le quart d'un Sosa : celui de son grand-parent de tête. */
  const quart = (prefixe: '' | 'c') => (sosa: number): Branche => {
    if (sosa < 4) return 'tr'
    let n = sosa
    while (n >= 8) n >>= 1
    return `${prefixe}${(['pp', 'pm', 'mp', 'mm'] as const)[n - 4]}` as Branche
  }

  // L'ego, son conjoint (avec son ascendance), leurs enfants et petits-enfants.
  plafond = cible * 0.03
  const ego = personne(0, 'tr', parmi(NOMS), 'masculin')
  const conjoint = personne(0, 'tr', parmi(NOMS), 'feminin')
  const enfants = Array.from({ length: entre(1, 3) }, () => personne(1, 'tr', nomDe.get(ego)!))
  famille([ego, conjoint], enfants)
  for (const e of enfants) descendance(e, 'tr', 0)
  // Un second mariage de l'ego, divorcé du premier.
  if (tirer(0.5)) famille([ego, conjointPour(ego, 'tr')], [personne(1, 'tr', nomDe.get(ego)!)])

  // Les profondeurs se calent sur la taille visée : ~11 générations pour 10 000.
  const profondeur = Math.max(4, Math.round(Math.log2(cible) * 0.85))
  // Le conjoint d'abord, moins profond : l'ascendance de l'ego prend ensuite le reste.
  plafond = cible * 0.2
  ascendance(conjoint, quart('c'), Math.max(3, profondeur - 4), true)
  plafond = cible * 0.97
  coteEgo = true
  ascendance(ego, quart(''), profondeur, true)

  // Des implexes : quelques célibataires d'une même génération, du côté de l'ego, s'épousent.
  coteEgo = false
  for (const [g, l] of celibataires) {
    for (let k = 0; k < Math.floor(l.length / 12); k++) {
      if (l.length < 2) break
      const a = l[entre(0, l.length - 1)]!
      const b = l[entre(0, l.length - 1)]!
      if (a === b) continue
      famille([a, b], g < 2 && tirer(0.5) ? [personne(g + 1, 'tr', nomDe.get(a)!)] : [])
    }
  }

  // Des îlots : des familles qu'aucun lien ne relie à l'ego.
  const ilots = Math.max(2, Math.round(cible / 400))
  for (let i = 0; i < ilots; i++) {
    const g = entre(-6, 0)
    const nom = parmi(NOMS)
    const a = personne(g, 'tr', nom, 'masculin')
    const b = personne(g, 'tr', parmi(NOMS), 'feminin')
    const enf = Array.from({ length: entre(0, 4) }, () => personne(g + 1, 'tr', nom))
    famille([a, b], enf)
    if (tirer(0.5) && enf.length) famille([enf[0]!, conjointPour(enf[0]!, 'tr')], [personne(g + 2, 'tr', nom)])
    if (tirer(0.3)) personne(entre(-5, 0), 'tr', parmi(NOMS)) // seul, sans aucune famille
  }

  return {
    empreinte: '0'.repeat(64),
    ego,
    boite: { x: 0, y: 0, w: 1000, h: 1000 },
    calque: {} as Graphe['calque'],
    noeuds,
    aretes,
  }
}
