/* ------------------------------------------------------------------------ */
/* LA TIMELINE CENTRALE.                                                     */
/*                                                                           */
/* Tout ce qui décide du rythme est ici, et seulement ici :                  */
/*  1. PAUSES : le silence laissé après chaque segment de voix. C'est ce qui */
/*     place la voix ; les images suivent.                                   */
/*  2. REPERES : chaque mouvement important est accroché à un MOT de la voix */
/*     ([segment, mot, décalage en s]). Si la voix change (nouvelle prise),  */
/*     les images se recalent d'elles-mêmes.                                 */
/*                                                                           */
/* Les scènes ne contiennent aucun temps absolu : elles lisent `R.<repère>`. */
/* ------------------------------------------------------------------------ */

const DEBUT = 1.4 // les premiers fragments apparaissent avant que la voix parle
const QUEUE = 5.2 // après « Ça, c'est Heritage » : le logo et la phrase de fin

// le silence après chaque segment (secondes)
const PAUSES = {
  'enorme': 0.45,
  'pas-le-mot': 0.35,
  'chiffres': 1.5, // on laisse respirer l'arbre entier
  'eparpille': 0.55,
  'cree': 0.5,
  'idee': 0.45,
  'parcourir': 0.35,
  'source': 0.2,
  'acte': 0.75, // le temps de lire « Vérifiée par acte »
  'pas-juste': 0.25,
  'explorer': 0.55,
  'participer': 0.25,
  'raconter': 0.3,
  'transforme': 0.6,
  'questions': 0.15,
  'demander': 0.45,
  'reponse': 0.7,
  'but': 0.3,
  'perde': 0.4,
  'cinquante': 0.85,
  'fin': 0,
}

/* -------- la voix placée -------- */
const SEG = {}
let DUREE = 0
;(function placer() {
  let t = DEBUT
  for (const s of window.VOIX) {
    SEG[s.id] = { id: s.id, texte: s.texte, debut: t, fin: t + s.duree, mots: s.mots.map((m) => ({ mot: m.mot, debut: t + m.debut, fin: t + m.fin })) }
    t += s.duree + (PAUSES[s.id] ?? 0.4)
  }
  DUREE = t + QUEUE
})()

/** L'instant où se dit un mot : par son rang (0, 1…) ou par son texte (premier trouvé, sans tenir compte de la casse). */
function mot(seg, quoi, decalage = 0, bord = 'debut') {
  const s = SEG[seg]
  if (!s) throw new Error('segment inconnu : ' + seg)
  let m
  if (typeof quoi === 'number') m = s.mots[quoi < 0 ? s.mots.length + quoi : quoi]
  else {
    const q = quoi.toLowerCase()
    // « arbre » trouve aussi « l’arbre » : on compare aussi ce qui suit une apostrophe
    const nu = (w) => w.mot.toLowerCase().split(/[’']/).pop()
    m = s.mots.find((w) => w.mot.toLowerCase() === q) ?? s.mots.find((w) => nu(w) === q) ?? s.mots.find((w) => w.mot.toLowerCase().startsWith(q))
  }
  if (!m) throw new Error(`mot introuvable : ${seg} / ${quoi}`)
  return m[bord] + decalage
}

/* -------- les repères -------- */
/* [segment, mot, décalage]  ou  [segment, 'debut' | 'fin', décalage] */
const REPERES = {
  // INTRO — des fragments, puis les liens, puis l'immensité
  liens: ['enorme', 'arbre', 0],
  recul1: ['pas-le-mot', 'énorme', -0.1],
  zoomArriere: ['chiffres', 'plus', -0.35],
  compteur: ['chiffres', 'deux', 0],
  generations: ['chiffres', 'quatorze', -0.1],
  // le problème : tout était éparpillé
  eparpille: ['eparpille', 'debut', -0.2],
  frNoms: ['eparpille', 'noms', -0.05],
  frDates: ['eparpille', 'dates', -0.05],
  frPhotos: ['eparpille', 'photos', -0.05],
  frActes: ['eparpille', 'actes', -0.05],
  partout: ['eparpille', 'peu', 0],
  // HERITAGE — tout converge, l'interface se construit
  converge: ['cree', 'coup', 0],
  logo: ['cree', 'Héritage', -0.15],
  interface: ['idee', 'rassembler', -0.2],
  // ARBRE
  parcourir: ['parcourir', 'parcourir', -0.3],
  remonter: ['parcourir', 'remonter', 0],
  recherche: ['parcourir', 'retrouver', -0.15],
  vol: ['parcourir', 'secondes', -0.1],
  fiche: ['parcourir', 'voir', -0.25],
  parents: ['parcourir', 'parents', -0.05],
  enfants: ['parcourir', 'enfants', -0.05],
  lieux: ['parcourir', 'lieux', -0.05],
  source: ['source', 'information', 0],
  sourceLien: ['source', 'source', -0.1],
  acte: ['acte', 'date', -0.1],
  verifiee: ['acte', 'sait', -0.25],
  // EXPLORATION
  pasJuste: ['pas-juste', 'debut', -0.1],
  temps: ['explorer', 'temps', -0.35],
  carte: ['explorer', 'lieux', -0.2],
  metiers: ['explorer', 'métiers', -0.15],
  prenoms: ['explorer', 'prénoms', -0.15],
  stats: ['explorer', 'statistiques', -0.2],
  // PARTICIPATION
  participer: ['participer', 'famille', -0.2],
  souvenir: ['raconter', 'souvenir', -0.3],
  photoActe: ['raconter', 'photo', -0.2],
  enregistrer: ['raconter', 'enregistrer', -0.15],
  transforme: ['transforme', 'transforme', -0.1],
  aVerifier: ['transforme', 'vérifier', -0.1],
  integrer: ['transforme', 'intégrer', -0.1],
  // IA
  questions: ['questions', 'debut', -0.15],
  frappe: ['demander', 'Qui', -0.1],
  reponse: ['reponse', 'retrouve', -0.2],
  navigation: ['reponse', 'arbre', -0.1],
  // FIN
  but: ['but', 'debut', -0.2],
  reculFinal: ['perde', 'debut', -0.3],
  epure: ['cinquante', 'quelqu’un', 0],
  signature: ['fin', 'debut', -0.1],
  logoFin: ['fin', 'Héritage', 0.15],
}

const R = {}
for (const [nom, [seg, quoi, dec]] of Object.entries(REPERES)) {
  R[nom] = quoi === 'debut' ? SEG[seg].debut + dec : quoi === 'fin' ? SEG[seg].fin + dec : mot(seg, quoi, dec)
}
R.FIN = DUREE

/* -------- les actes (grandes parties), pour les aperçus et le découpage -------- */
const ACTES = [
  { nom: 'intro', debut: 0, fin: R.converge },
  { nom: 'heritage', debut: R.converge, fin: R.parcourir },
  { nom: 'arbre', debut: R.parcourir, fin: R.pasJuste },
  { nom: 'exploration', debut: R.pasJuste, fin: R.participer },
  { nom: 'participation', debut: R.participer, fin: R.questions },
  { nom: 'ia', debut: R.questions, fin: R.but },
  { nom: 'fin', debut: R.but, fin: DUREE },
]
