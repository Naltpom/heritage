// Une fausse API Heritage, pour filmer le VRAI front (app/) sur la famille fictive.
//
//   node outils/fausse-api.mjs            → http://127.0.0.1:8000/api/…
//   (puis, dans app/ : VITE_API_PROXY_TARGET=http://127.0.0.1:8000 npx vite)
//
// Les formes de réponse suivent docs/api-contract.md et app/src/lib/api/*.ts.
// Seules les routes dont les écrans filmés ont besoin sont servies ; toute autre
// route GET rend une liste vide, et elle est signalée dans la console.
import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const ici = path.dirname(fileURLToPath(import.meta.url))
const require = createRequire(import.meta.url)
const { construireFamille } = require('../donnees/famille.js')
const VOC = require('../donnees/vocabulaire.js')
const A = construireFamille()
const P = A.personnes, F = A.familles, byId = A.parId
const ASSETS = path.join(ici, '..', 'assets')
const PORT = Number(process.env.PORT || 8000)

/* ------------------------------------------------------------------ formes communes */
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']
const pad = (n) => String(n).padStart(2, '0')
const lieuComplet = (l) => (l ? `${l}` : null)
function blocDate(annee, mois, jour, lieu, exact = true) {
  if (annee == null) return null
  if (!exact) return { raw: String(annee), qualifier: 'annee', min: `${annee}-01-01`, max: `${annee}-12-31`, place: lieuComplet(lieu), texte: String(annee) }
  const d = `${annee}-${pad(mois)}-${pad(jour)}`
  return { raw: `${jour} ${['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'][mois - 1]} ${annee}`, qualifier: 'exacte', min: d, max: d, place: lieuComplet(lieu), texte: `${jour === 1 ? '1er' : jour} ${MOIS[mois - 1]} ${annee}` }
}
const exacte = (p) => p.generation >= -6 || p.id % 3 !== 0 // les dates anciennes sont parfois seulement une année
const nomAffiche = (p) => `${p.prenom} ${p.nom}`
const ligneDates = (p) => (p.vivant ? 'vivant·e' : `${p.naissance} – ${p.deces ?? ''}`.trim())
const ligneDatesFiche = (p) => (p.vivant ? `${p.naissance} –` : `${p.naissance} – ${p.deces}`)
const sexe = (p) => p.sexe
// la qualité des sources : les fiches proches sont souvent vérifiées, les lointaines moins
const quay = (p) => (p.id === A.vedette.id ? 3 : (p.id * 7) % 10 < (p.generation >= -5 ? 7 : 4) ? 2 : (p.id % 4 === 0 ? 0 : 1))
const nbCitations = (p) => (quay(p) === 0 && p.id % 2 ? 0 : 1 + (p.id % 4))
const avecPortrait = (p) => PORTRAITS.has(p.id)

/* Les portraits : les fichiers assets/photos/portrait-<n>.jpg, attribués à quelques personnes du XIXe–XXe s. */
const PORTRAITS = new Map()
{
  const fichiers = fs.existsSync(path.join(ASSETS, 'photos')) ? fs.readdirSync(path.join(ASSETS, 'photos')).filter((f) => /^portrait-.*\.jpg$/.test(f)).sort() : []
  const candidats = P.filter((p) => p.naissance >= 1840 && p.naissance <= 1960 && p.generation >= -5)
  const vedettes = [A.vedette, ...A.parentsVedette]
  let k = 0
  for (const p of [...vedettes, ...candidats]) {
    if (k >= fichiers.length) break
    if (PORTRAITS.has(p.id)) continue
    // les portraits sont genrés par leur nom : portrait-f-*, portrait-h-*
    const f = fichiers.find((n, i) => i >= 0 && !([...PORTRAITS.values()].includes(n)) && n.includes(p.sexe === 'feminin' ? '-f-' : '-h-'))
    if (!f) continue
    PORTRAITS.set(p.id, f); k++
    if (PORTRAITS.size > 60) break
  }
}

/* ------------------------------------------------------------------ le graphe (§ 5.2) */
function etiquetteNom(p) { return `${p.prenom.split(/[ -]/)[0]} ${p.nom}` }
// la formule du contrat (§ 5.2), plus la place de la vignette (44 px) : sans elle, la carte Compact
// n'a la place que du prénom à l'échelle des fiches, et la vidéo veut des noms entiers
function largeur(p) { return Math.round(Math.max(104, Math.max(etiquetteNom(p).length * 6.6, ligneDates(p).length * 5.8) + 2 * 14) + 44) }
function graphe() {
  const noeuds = [], aretes = []
  for (const p of P) {
    const n = {
      id: p.noeud, type: 'personne', personId: p.id, x: 0, y: 0, w: largeur(p), h: 56,
      displayName: nomAffiche(p), etiquetteNom: etiquetteNom(p), sex: sexe(p), generation: p.generation,
      branch: p.branch, vivant: p.vivant, visibilite: 'complete', etiquetteDates: ligneDates(p), parente: 'famille',
      bestQuay: quay(p), citationCount: nbCitations(p), avecImage: avecPortrait(p),
    }
    if (avecPortrait(p)) n.portrait = `/api/medias/${1000 + p.id}/vignette`
    noeuds.push(n)
  }
  for (const f of F) {
    noeuds.push({ id: `f${f.id}`, type: 'famille', familyId: f.id, x: 0, y: 0, w: 22, h: 14, divorced: false })
    for (const e of [f.pere, f.mere]) if (e) aretes.push({ de: `p${e}`, vers: `f${f.id}`, genre: 'union' })
    for (const e of f.enfants) aretes.push({ de: `f${f.id}`, vers: `p${e}`, genre: 'filiation' })
  }
  return { empreinte: 'a'.repeat(64), ego: A.ego.noeud, boite: { x: 0, y: 0, w: 1000, h: 1000 }, calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] }, noeuds, aretes }
}
const GRAPHE = graphe()

/* ------------------------------------------------------------------ la fiche (§ 5.3) */
const PARENTE = { '-1': ['père', 'mère'], '-2': ['grand-père', 'grand-mère'], '-3': ['arrière-grand-père', 'arrière-grand-mère'], '-4': ['arrière-arrière-grand-père', 'arrière-arrière-grand-mère'] }
function parenteDe(p) {
  if (p.id === A.ego.id) return 'vous'
  if (p.sosa) { const g = String(p.generation); const l = PARENTE[g]; return l ? l[p.sexe === 'feminin' ? 1 : 0] : `ancêtre (${-p.generation}e génération)` }
  return p.sexe === 'feminin' ? 'cousine' : 'cousin'
}
function citations(p) {
  const res = []
  if (nbCitations(p) === 0) return res
  const vedette = p.id === A.vedette.id
  res.push({
    id: 9000 + p.id, scope: 'naissance', quay: quay(p), quayLabel: quay(p) >= 2 ? 'acte ou registre' : 'recopie non vérifiée',
    appui: quay(p) >= 2 ? 'acte' : 'arbre', appuiLabel: quay(p) >= 2 ? 'tenu par un acte' : 'tenu par un arbre en ligne',
    page: quay(p) >= 2 ? `${p.lieu}, registre des naissances ${p.naissance}, acte n° ${vedette ? 41 : 10 + (p.id % 80)}, vue ${vedette ? 17 : 3 + (p.id % 40)}` : null,
    evenContext: 'record', url: null, dataText: null,
    source: quay(p) >= 2
      ? { id: 1, title: `État civil de ${p.lieu} — naissances`, author: 'Archives départementales du Morbihan', sourceKind: 'acte_etat_civil', sourceKindLabel: 'Acte d’état civil' }
      : { id: 2, title: 'Arbre en ligne d’un cousin', author: null, sourceKind: 'arbre_en_ligne', sourceKindLabel: 'Arbre en ligne' },
    medias: vedette ? [{ mediaId: 120, kind: 'preuve', vignetteUrl: '/api/medias/120/vignette' }] : [],
    createdAt: '2026-09-12T18:04:00+02:00',
  })
  if (p.deces && nbCitations(p) > 1) res.push({ ...res[0], id: 9500 + p.id, scope: 'deces', page: `${p.lieuDeces ?? p.lieu}, registre des décès ${p.deces}`, source: { ...res[0].source, title: `État civil de ${p.lieuDeces ?? p.lieu} — décès` }, medias: [] })
  if (vedette) res.push({ id: 9900, scope: 'mariage', quay: 1, quayLabel: 'index ou déduction', appui: 'index', appuiLabel: 'tenu par un index ou un relevé', page: 'Tables décennales de Josselin, 1883-1892', evenContext: 'record', url: null, dataText: null, source: { id: 3, title: 'Tables décennales', author: null, sourceKind: 'table_decennale', sourceKindLabel: 'Table décennale' }, medias: [], createdAt: '2026-09-13T09:30:00+02:00' })
  return res
}
function fiche(p) {
  const pf = p.parents ? F[p.parents - 1] : null
  const parents = pf ? [pf.pere, pf.mere].filter(Boolean).map((i) => ({ personId: i, displayName: nomAffiche(byId.get(i)), relation: 'naissance', familyId: pf.id })) : []
  const fratrie = pf ? pf.enfants.filter((i) => i !== p.id).map((i, k) => ({ personId: i, displayName: nomAffiche(byId.get(i)), siblingOrder: k + 1 })) : []
  const unions = p.unions.map((u) => F[u - 1]).map((f) => {
    const c = byId.get(f.pere === p.id ? f.mere : f.pere)
    return {
      familyId: f.id, conjoint: c ? { personId: c.id, displayName: nomAffiche(c) } : null,
      marriage: f.mariage ? blocDate(f.mariage.annee, 1 + (f.id % 12), 1 + (f.id % 27), f.mariage.lieu, exacte(p)) : null,
      divorced: false, divorce: null, enfants: f.enfants.map((i) => ({ personId: i, displayName: nomAffiche(byId.get(i)) })),
    }
  })
  const faits = []
  if (p.metier) faits.push({ id: 300 + p.id, type: 'profession', label: p.metier, happened: blocDate(p.naissance + 25, 1, 1, p.lieu, false), address: null, age: null, cause: null })
  if (!p.vivant || p.generation < 0) faits.push({ id: 600 + p.id, type: 'résidence', label: p.id === A.vedette.id ? 'Rue des Tanneurs' : p.lieu, happened: blocDate(p.naissance + 30, 1, 1, p.lieu, false), address: null, age: null, cause: null })
  if (p.id === A.vedette.id) faits.push({ id: 9, type: 'résidence', label: 'Ploërmel', happened: blocDate(1921, 1, 1, 'Ploërmel', false), address: null, age: null, cause: null })
  const cit = citations(p)
  return {
    visibilite: 'complete', id: p.id, gedcomXref: `@I5${pad(p.id).padStart(5, '0')}@`, displayName: nomAffiche(p), givenNames: p.prenom, surname: p.nom,
    marriedName: null, nickname: null, sex: p.sexe, deceased: !p.vivant, vivant: p.vivant,
    birth: blocDate(p.naissance, p.mois, p.jour, p.lieu, exacte(p)),
    death: p.deces ? blocDate(p.deces, p.moisDeces, p.jourDeces, p.lieuDeces, exacte(p)) : null,
    ligneDates: ligneDatesFiche(p), branch: p.branch, generation: p.generation, bestQuay: quay(p), citationCount: cit.length,
    forcePrivacy: false, privacyNote: null, version: 3, parente: parenteDe(p),
    portrait: avecPortrait(p) ? { mediaId: 1000 + p.id, url: `/api/medias/${1000 + p.id}/fichier`, vignetteUrl: `/api/medias/${1000 + p.id}/vignette`, caption: p.id === A.vedette.id ? 'Portrait, vers 1895' : null } : null,
    parents, fratrie, unions, faits, citations: cit,
    notes: p.id === A.vedette.id ? [{ id: 77, scope: 'naissance', body: 'Retrouvée aux Archives du Morbihan : l’acte confirme le 12 mars, et non le 21 comme dans l’arbre d’un cousin.', authorLabel: 'Thomas Martin', createdAt: '2026-09-13T21:13:00+02:00' }] : [],
    medias: p.id === A.vedette.id ? [MEDIA_ACTE] : [],
    enAttente: {}, demandesEnAttente: [],
  }
}
const MEDIA_ACTE = { mediaId: 120, kind: 'preuve', caption: 'Acte de naissance n° 41, Josselin 1868', url: '/api/medias/120/fichier', vignetteUrl: '/api/medias/120/vignette', createdAt: '2026-09-14T10:22:00+02:00' }
function media(id, p, kind, caption) {
  return { id, kind, subjectKind: 'personne', subjectId: p.id, subjectLabel: nomAffiche(p), mimeType: 'image/jpeg', byteSize: 412000, width: 1600, height: 2200, pageCount: null, exifStripped: true, status: 'actif', isActivePortrait: kind === 'portrait', caption, taken: null, uploadedByLabel: 'Thomas Martin', createdAt: '2026-09-14T10:22:00+02:00', url: `/api/medias/${id}/fichier`, vignetteUrl: `/api/medias/${id}/vignette`, changeRequestId: null }
}

/* ------------------------------------------------------------------ recherche (§ 5.5) */
const sansAccent = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
function recherche(q, limite) {
  const m = sansAccent(q).split(/\s+/).filter(Boolean)
  const res = P.filter((p) => m.every((w) => sansAccent(nomAffiche(p)).includes(w)))
    .sort((a, b) => (a.id === A.vedette.id ? -1 : b.id === A.vedette.id ? 1 : Math.abs(a.generation) - Math.abs(b.generation)))
  return { elements: res.slice(0, limite).map((p) => ({ personId: p.id, displayName: nomAffiche(p), sex: p.sexe, generation: p.generation, branch: p.branch, vivant: p.vivant, ligneDates: ligneDatesFiche(p), visibilite: 'complete' })), tronque: res.length > limite }
}

/* ------------------------------------------------------------------ explorer */
function chronologie(q) {
  const branches = q.get('branches') ? q.get('branches').split(',') : ['pp', 'pm', 'mp', 'mm', 'tr']
  const bande = Number(q.get('bande') || 25)
  // la lignée directe sur huit générations : assez pour voir les vies se relayer, pas trop pour les lire
  const choisies = P.filter((p) => branches.includes(p.branch) && p.sosa && p.generation >= -7)
  const min = Math.floor((Math.min(...choisies.map((p) => p.naissance)) - 5) / bande) * bande, max = 2030
  const bandes = []
  for (let a = min; a < max; a += bande) bandes.push({ debut: a, fin: a + bande, libelle: `${a}` })
  return {
    bande, orientation: q.get('orientation') || 'lignes', branches, showUncertainty: false, bornes: { min, max }, bandes,
    barres: choisies.map((p) => ({ personId: p.id, displayName: nomAffiche(p), branch: p.branch, generation: p.generation, sex: p.sexe, vivant: p.vivant, debut: { min: p.naissance, max: p.naissance, certain: true }, ...(p.vivant ? { ouverte: true, fin: null } : { ouverte: false, fin: { min: p.deces, max: p.deces, certain: true } }), ligneDates: ligneDatesFiche(p) })),
    jalons: [],
  }
}
const COORD = new Map(VOC.LIEUX.map(([n, la, lo], i) => [n, { placeId: i + 1, libelle: n, lat: la, lon: lo }]))
function carte() {
  const evenements = []
  for (const p of P) {
    for (const [genre, annee, lieu] of [['naissance', p.naissance, p.lieu], ['deces', p.deces, p.lieuDeces]]) {
      if (!annee || !lieu || !COORD.has(lieu)) continue
      const l = COORD.get(lieu)
      evenements.push({ cle: `${genre}-${p.id}`, genre, type: genre, placeId: l.placeId, place: lieu, annee, texte: String(annee), qualifier: 'annee', certain: true, sujets: [{ personId: p.id, displayName: nomAffiche(p), sex: p.sexe, vivant: p.vivant }], familyId: null, libelle: null })
    }
  }
  const parGenre = { naissance: 0, deces: 0, union: 0, bapteme: 0, autre: 0 }
  for (const e of evenements) parGenre[e.genre]++
  return { lieux: [...COORD.values()].map((l) => ({ ...l, precision: 'commune', confiance: 1, source: 'insee', brut: [l.libelle] })), evenements, resume: { parGenre, sansLieu: 0, lieuNonResolu: 0, sansDate: 0, bornes: { min: 1601, max: 2024 } } }
}
function palmares(valeurs, n = 8) {
  const c = new Map(); for (const v of valeurs) if (v) c.set(v, (c.get(v) ?? 0) + 1)
  return [...c.entries()].sort((a, b) => b[1] - a[1]).slice(0, n).map(([libelle, k]) => ({ libelle, n: k }))
}
const moy = (l) => ({ moyenne: l.length ? Math.round((l.reduce((a, b) => a + b, 0) / l.length) * 10) / 10 : null, n: l.length })
function stats() {
  const siecles = [17, 18, 19, 20]
  const siecle = (a) => Math.floor(a / 100) + 1
  const morts = P.filter((p) => p.deces)
  const parSiecle = (f) => siecles.map((s) => {
    const l = morts.filter((p) => siecle(p.naissance) === s)
    return { siecle: s, hommes: moy(l.filter((p) => p.sexe === 'masculin').map(f)), femmes: moy(l.filter((p) => p.sexe === 'feminin').map(f)), ensemble: moy(l.map(f)) }
  })
  const doyen = morts.reduce((a, b) => (b.deces - b.naissance > a.deces - a.naissance ? b : a))
  const grosseFratrie = F.reduce((a, b) => (b.enfants.length > a.enfants.length ? b : a))
  const verif = P.filter((p) => quay(p) >= 2).length
  const prenomsTransmis = []
  for (const prenom of ['Jean', 'Marie', 'Joseph', 'Louise', 'Mathurin']) {
    const porteurs = P.filter((p) => p.prenom === prenom)
    if (!porteurs.length) continue
    const premier = porteurs.reduce((a, b) => (b.naissance < a.naissance ? b : a))
    prenomsTransmis.push({ prenom, generations: new Set(porteurs.map((p) => p.generation)).size, porteurs: porteurs.length, depuis: { id: premier.id, nom: nomAffiche(premier) }, annee: premier.naissance })
  }
  return {
    calculeLe: '2026-10-07T09:00:00+02:00', portee: 'arbre', seuilDeDiscretion: 3,
    arbre: {
      personnes: P.length, familles: F.length, generations: 14, periode: { debut: 1601, fin: 2024, jusquAujourdhui: true },
      verification: { fiches: { verifiees: verif, total: P.length }, naissances: { verifiees: Math.round(verif * 0.9), total: P.length }, deces: { verifiees: Math.round(verif * 0.6), total: morts.length }, mariages: { verifiees: Math.round(F.length * 0.5), total: F.length }, historique: ['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'].map((mois, i) => ({ mois, verifiees: Math.round(verif * (0.55 + i * 0.09)), total: P.length })) },
      branches: [['pp', 'Martin'], ['pm', 'Bernard'], ['mp', 'Dubois'], ['mm', 'Lefèvre']].map(([b, nom]) => ({ branche: b, nom, personnes: P.filter((p) => p.branch === b).length, verifiees: P.filter((p) => p.branch === b && quay(p) >= 2).length })),
    },
    vie: {
      ageAuDeces: parSiecle((p) => p.deces - p.naissance),
      premierMariage: siecles.map((s) => ({ siecle: s, hommes: { moyenne: [27.4, 27.9, 28.6, 26.1][s - 17], n: 120 }, femmes: { moyenne: [24.2, 25.1, 24.8, 23.9][s - 17], n: 130 }, ensemble: { moyenne: [25.8, 26.5, 26.7, 25.0][s - 17], n: 250 } })),
      enfantsParCouple: [-12, -10, -8, -6, -4, -2, 0].map((g) => ({ generation: g, moyenne: [5.8, 5.4, 5.1, 4.6, 3.7, 2.6, 2.1][(g + 12) / 2], n: 40, annee: 1990 + g * 30 })),
      ageDesParents: siecles.map((s) => ({ siecle: s, peres: { moyenne: [34.1, 35.2, 33.8, 30.9][s - 17], n: 100 }, meres: { moyenne: [29.8, 30.4, 29.1, 27.6][s - 17], n: 100 } })),
      ecartDansLeCouple: { moyenne: 3.4, n: F.length, mariPlusAge: Math.round(F.length * 0.68), epousePlusAgee: Math.round(F.length * 0.24), memeAnnee: Math.round(F.length * 0.08), parSiecle: siecles.map((s) => ({ siecle: s, moyenne: [4.1, 3.9, 3.3, 2.6][s - 17], n: 80 })) },
      moisDesNaissances: { mois: [212, 205, 231, 198, 186, 170, 165, 172, 190, 201, 205, 204], n: P.length },
      moisDesDeces: { mois: [180, 171, 160, 140, 120, 101, 98, 110, 105, 126, 150, 170], n: morts.length },
      mortaliteInfantile: siecles.map((s) => ({ siecle: s, avantCinqAns: [0.31, 0.27, 0.19, 0.04][s - 17], n: 200 })),
    },
    records: {
      doyen: { valeur: doyen.deces - doyen.naissance, personnes: [{ id: doyen.id, nom: nomAffiche(doyen) }], debut: doyen.naissance, fin: doyen.deces, sexe: doyen.sexe },
      fratrie: { valeur: grosseFratrie.enfants.length, personnes: [grosseFratrie.pere, grosseFratrie.mere].filter(Boolean).map((i) => ({ id: i, nom: nomAffiche(byId.get(i)) })) },
      union: { valeur: 67, personnes: [{ id: 101, nom: nomAffiche(byId.get(101)) }, { id: 102, nom: nomAffiche(byId.get(102)) }], debut: 1891, fin: 1958 },
      ecartParentEnfant: { valeur: 54, personnes: [{ id: 210, nom: nomAffiche(byId.get(210)) }], debut: 1838, fin: 1892 },
      ancetres: [['pp', 'Martin'], ['pm', 'Bernard'], ['mp', 'Dubois'], ['mm', 'Lefèvre']].map(([b, nom]) => {
        const l = P.filter((p) => p.branch === b && p.sosa).reduce((a, c) => (c.naissance < a.naissance ? c : a))
        return { branche: b, nom, valeur: l.naissance, personnes: [{ id: l.id, nom: nomAffiche(l) }] }
      }),
    },
    prenomsEtNoms: {
      prenomsParSiecle: siecles.map((s) => {
        const l = P.filter((p) => siecle(p.naissance) === s)
        return { siecle: s, hommes: palmares(l.filter((p) => p.sexe === 'masculin').map((p) => p.prenom), 5), femmes: palmares(l.filter((p) => p.sexe === 'feminin').map((p) => p.prenom), 5), n: l.length }
      }),
      prenomsTransmis,
      noms: { frequents: palmares(P.map((p) => p.nom), 8), patronymes: new Set(P.map((p) => p.nom)).size, n: P.length },
    },
    lieux: {
      communes: { palmares: palmares(P.map((p) => p.lieu), 8), n: P.length, distinctes: new Set(P.map((p) => p.lieu)).size },
      departements: { palmares: [{ libelle: 'Morbihan', n: 1102 }, { libelle: 'Ille-et-Vilaine', n: 402 }, { libelle: 'Mayenne', n: 298 }, { libelle: 'Loire-Atlantique', n: 140 }, { libelle: 'Paris', n: 61 }], n: P.length, distincts: 31 },
      distance: siecles.map((s) => ({ siecle: s, moyenne: [6, 9, 21, 148][s - 17], mediane: [4, 6, 12, 41][s - 17], n: 200 })),
      metiers: siecles.map((s) => ({ siecle: s, metiers: palmares(P.filter((p) => siecle(p.naissance) === s).map((p) => p.metier), 6), n: 200 })),
      migrations: { pays: [{ code: 'CA', nom: 'Canada', personnes: 4, regions: [{ libelle: 'Québec', n: 4 }] }, { code: 'DZ', nom: 'Algérie', personnes: 3, regions: [] }], neesHorsDeFrance: 7, n: P.length },
    },
  }
}
function anniversaires() {
  const jours = new Map()
  for (const p of P.filter((p) => p.generation >= -4)) {
    const k = `${p.mois}-${p.jour}`
    if (!jours.has(k)) jours.set(k, { mois: p.mois, jour: p.jour, evenements: [] })
    jours.get(k).evenements.push({ cle: `n-${p.id}`, genre: 'naissance', type: 'naissance', annee: p.naissance, texte: String(p.naissance), lieu: p.lieu, sujets: [{ personId: p.id, displayName: nomAffiche(p), sex: p.sexe, vivant: p.vivant }], familyId: null, libelle: null })
  }
  return { jours: [...jours.values()], resume: { parGenre: { naissance: 400, deces: 0, union: 0, bapteme: 0, autre: 0 }, sansJour: { naissance: 0, deces: 0, union: 0, bapteme: 0, autre: 0 } } }
}

/* ------------------------------------------------------------------ l'assistant */
const V_ = A.vedette, [PERE_V, MERE_V] = A.parentsVedette
const ref = (p) => ({ personId: p.id, displayName: nomAffiche(p), ligneDates: ligneDatesFiche(p), visibilite: 'complete' })
function analyse() {
  // ce qu'un enregistrement de la grand-tante et la photo d'un acte ont permis de proposer
  const enfant = V_.unions.length ? F[V_.unions[0] - 1].enfants.map((i) => byId.get(i))[0] : null
  return {
    analyseId: 'a-1868', restant: 18, avertissements: [],
    resume: 'L’enregistrement de Jeanne et la photo de l’acte parlent de Joséphine Perrin : son métier, son mariage à Josselin, et un fils qui n’est pas encore dans l’arbre.',
    demandes: [
      { cle: 'd1', titre: `Ajouter le métier de ${nomAffiche(V_)}`, justification: 'Enregistrement audio (document 1), à 0:42 : « elle était couturière, rue des Tanneurs ».', confiance: 'haute', corps: { kind: 'modification_personne', targetKind: 'personne', targetId: V_.id, ops: [] }, personnes: [ref(V_)], changements: [{ libelle: 'Profession', avant: 'non renseignée', apres: 'couturière' }, { libelle: 'Résidence', avant: 'Josselin', apres: 'rue des Tanneurs, Josselin' }] },
      { cle: 'd2', titre: `Préciser la naissance de ${nomAffiche(V_)}`, justification: 'Photo de l’acte de naissance n° 41 (document 2) : née le 12 mars 1868 à Josselin.', confiance: 'haute', corps: { kind: 'modification_personne', targetKind: 'personne', targetId: V_.id, ops: [] }, personnes: [ref(V_)], changements: [{ libelle: 'Date de naissance', avant: '21 mars 1868', apres: '12 mars 1868' }] },
      { cle: 'd3', titre: 'Ajouter Pierre Dubois, son fils', justification: 'Enregistrement audio (document 1), à 1:15 : « son fils Pierre, parti à Nantes en 1912 ».', confiance: 'moyenne', corps: { kind: 'creation_personne', brouillons: [{ localRef: '#p1', givenNames: 'Pierre', surname: 'Dubois', marriedName: null, nickname: null, sex: 'masculin', deceased: true, birth: null, death: null, note: 'Parti à Nantes en 1912' }], liens: [] }, personnes: enfant ? [ref(V_)] : [ref(V_)], changements: [{ libelle: 'Nouvelle fiche', avant: '—', apres: 'Pierre Dubois, vers 1894' }] },
    ],
  }
}
// la conversation : une première question sur Joséphine, puis la question de suivi tapée dans la vidéo
const QUESTION_1 = `Que sait-on de ${nomAffiche(V_)} ?`
const QUESTION = 'Qui étaient les parents de cette personne ?'
const ECHANGE_1 = {
  id: 69, question: QUESTION_1, createdAt: '2026-10-07T10:10:00+02:00',
  reponse: `[${nomAffiche(V_)}](personne:${V_.id}) est née le 12 mars 1868 à Josselin, d’après son acte de naissance. Couturière, elle a vécu rue des Tanneurs, puis à Ploërmel, où elle est morte en 1949.`,
  personnes: [ref(V_)],
}
const ECHANGE_2 = {
  id: 70, question: QUESTION, createdAt: '2026-10-07T10:12:00+02:00',
  reponse: `Ses parents sont [${nomAffiche(PERE_V)}](personne:${PERE_V.id}), tisserand à Josselin (${PERE_V.naissance} – ${PERE_V.deces}), et [${nomAffiche(MERE_V)}](personne:${MERE_V.id}), fileuse, née à Guilliers (${MERE_V.naissance} – ${MERE_V.deces}). Les deux noms figurent sur l’acte de naissance de leur fille, en 1868.`,
  personnes: [ref(PERE_V), ref(MERE_V)],
}
let echanges = [ECHANGE_1]
const resumeConv = () => ({ id: 7, titre: QUESTION_1, createdAt: '2026-10-07T10:10:00+02:00', updatedAt: '2026-10-07T10:12:00+02:00', nombreDEchanges: echanges.length })
const conversation = () => ({ ...resumeConv(), echanges })

/* ------------------------------------------------------------------ la session */
const SESSION = {
  utilisateur: { id: 1, email: 'thomas@example.net', displayName: 'Thomas Martin', roles: ['ROLE_USER'], status: 'actif', totpEnabledAt: null, lastLoginAt: '2026-10-07T09:00:00+02:00' },
  membre: { id: 1, status: 'actif', personId: A.ego.id, personLabel: 'Thomas Martin', matchKind: 'pre_lie', activatedAt: '2026-03-02T09:14:00+01:00' },
  cercle: { regime: 'cercle', generations: 14, taille: P.length }, voirComme: null, incarnation: null, demandesEnAttente: [],
  portrait: { mediaId: 1, url: '/api/medias/1/fichier', vignetteUrl: '/api/medias/1/vignette', deposeParMoi: true },
  partageNaissance: { annee: true, mois: true, jour: true, modifieLe: null }, reciprocite: { restrictionIgnoree: true, photo: true },
}
const QUOTAS = { quotas: [
  { cle: 'assistant.analyses', libelle: 'Analyses par l’assistant', fenetre: 'jour', consomme: 2, limite: 20, limiteParDefaut: 20, personnalisee: false, exempte: false },
  { cle: 'assistant.questions', libelle: 'Questions à l’assistant', fenetre: 'jour', consomme: 1, limite: 30, limiteParDefaut: 30, personnalisee: false, exempte: false },
] }

/* ------------------------------------------------------------------ le serveur */
const liste = (elements) => ({ elements, total: elements.length, page: 1, parPage: 25 })
function fichierMedia(id, vignette) {
  if (id === 120) return path.join(ASSETS, 'documents', vignette ? 'acte-josselin-1868-vignette.jpg' : 'acte-josselin-1868.jpg')
  if (id === 1) return path.join(ASSETS, 'photos', 'avatar-thomas.jpg')
  const p = byId.get(id - 1000)
  return p && PORTRAITS.has(p.id) ? path.join(ASSETS, 'photos', PORTRAITS.get(p.id)) : null
}
const routes = [
  ['GET', /^\/api\/session$/, () => SESSION],
  ['GET', /^\/api\/session\/quotas$/, () => QUOTAS],
  ['GET', /^\/api\/notifications\/compteur$/, () => ({ nonLues: 0 })],
  ['GET', /^\/api\/notifications$/, () => liste([])],
  ['GET', /^\/api\/tirages$/, () => ({ moi: null, tirages: [] })],
  ['GET', /^\/api\/evenements$/, () => ({ moi: null, evenements: [] })],
  ['GET', /^\/api\/cles-d-acces$/, () => []],
  ['GET', /^\/api\/arbre\/graphe$/, () => GRAPHE],
  ['GET', /^\/api\/arbre\/recherche$/, (m, q) => recherche(q.get('q') ?? '', Number(q.get('limite') ?? 8))],
  ['GET', /^\/api\/arbre\/personnes\/(\d+)$/, (m) => (byId.get(Number(m[1])) ? fiche(byId.get(Number(m[1]))) : null)],
  ['GET', /^\/api\/medias$/, (m, q) => {
    const p = byId.get(Number(q.get('subjectId')))
    if (!p || q.get('status')) return []
    const res = []
    if (p.id === V_.id) res.push(media(120, p, 'preuve', MEDIA_ACTE.caption))
    if (avecPortrait(p)) res.push(media(1000 + p.id, p, 'portrait', p.id === V_.id ? 'Portrait, vers 1895' : null))
    return res
  }],
  ['GET', /^\/api\/demarches$/, () => liste([])],
  ['GET', /^\/api\/demandes\/historique\/personne\/\d+$/, () => liste([])],
  ['GET', /^\/api\/chronologie$/, (m, q) => chronologie(q)],
  ['GET', /^\/api\/chronologie\/preferences$/, () => ({ bandYears: 25, orientation: 'lignes', branches: ['pp', 'pm', 'mp', 'mm', 'tr'], showUncertainty: false })],
  ['PUT', /^\/api\/chronologie\/preferences$/, (m, q, corps) => corps],
  ['GET', /^\/api\/carte$/, () => carte()],
  ['GET', /^\/api\/anniversaires$/, () => anniversaires()],
  ['GET', /^\/api\/explorer\/stats$/, () => stats()],
  ['POST', /^\/api\/assistant\/analyses$/, async () => { await new Promise((r) => setTimeout(r, Number(process.env.DELAI_ANALYSE || 400))); return analyse() }],
  ['GET', /^\/api\/assistant\/conversations$/, () => ({ elements: [resumeConv()] })],
  ['GET', /^\/api\/assistant\/conversations\/(\d+)$/, () => ({ conversation: conversation() })],
  ['POST', /^\/api\/assistant\/conversations\/\d+\/questions$/, async () => { await new Promise((r) => setTimeout(r, Number(process.env.DELAI_QUESTION || 400))); echanges = [ECHANGE_1, ECHANGE_2]; return { conversation: conversation(), restant: 28 } }],
  // remet la conversation à son premier échange (avant chaque prise)
  ['POST', /^\/api\/__reinitialiser$/, () => { echanges = [ECHANGE_1]; return {} }],
]

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://local')
  const m = url.pathname.match(/^\/api\/medias\/(\d+)\/(vignette|fichier)$/)
  if (m) {
    const f = fichierMedia(Number(m[1]), m[2] === 'vignette')
    if (f && fs.existsSync(f)) { res.writeHead(200, { 'Content-Type': 'image/jpeg', 'Cache-Control': 'max-age=3600' }); return fs.createReadStream(f).pipe(res) }
    res.writeHead(404); return res.end()
  }
  let corps = ''
  for await (const c of req) corps += c
  for (const [meth, re, f] of routes) {
    const r = url.pathname.match(re)
    if (meth !== req.method || !r) continue
    let json = null
    try { json = corps && req.headers['content-type']?.includes('json') ? JSON.parse(corps) : null } catch {}
    const out = await f(r, url.searchParams, json)
    if (out === null) { res.writeHead(404, { 'Content-Type': 'application/json' }); return res.end(JSON.stringify({ erreur: { code: 'introuvable', message: 'Introuvable', champs: null, details: null } })) }
    res.writeHead(req.method === 'POST' && url.pathname.endsWith('conversations') ? 201 : 200, { 'Content-Type': 'application/json; charset=utf-8' })
    return res.end(JSON.stringify(out))
  }
  console.log('[fausse-api] non servie :', req.method, url.pathname + url.search)
  res.writeHead(200, { 'Content-Type': 'application/json' })
  res.end(JSON.stringify(req.method === 'GET' ? liste([]) : {}))
}).listen(PORT, '127.0.0.1', () => console.log(`fausse API sur http://127.0.0.1:${PORT} — ${P.length} personnes, vedette : ${nomAffiche(V_)} (${V_.id})`))
