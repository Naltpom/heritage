/* ------------------------------------------------------------------------ */
/* LA TIMELINE CENTRALE (vidéo verticale 1080 × 1920).                       */
/*                                                                           */
/* La vidéo est montée sur la prise de voix entière (narration/voix.json) : */
/* aucune durée de scène n'est inventée. Tout se règle ici :                 */
/*  - T0 : l'instant où la voix commence, QUEUE : la respiration finale ;    */
/*  - REPERES : chaque mouvement accroché à un mot de la voix :             */
/*      [début de la ligne du script, mot | 'debut' | 'fin', décalage s] ;    */
/*    les [pause] du script sont des repères 'pause:<ligne>' (début/fin) ;   */
/*  - SCENES : la plage de chaque scène, entre deux repères. Décaler une     */
/*    scène, c'est changer ses deux repères : les autres ne bougent pas.     */
/* Les scènes et les rushes (outils/rushes.mjs) ne lisent que `R` et `SCENE`. */
/* ------------------------------------------------------------------------ */
const T0 = 0.6 // un souffle de noir avant la première phrase
const QUEUE = 1.4 // la respiration après « Heritage. »

/* -------- la voix placée -------- */
const LIGNES = window.VOIX.lignes.map((l, i) => ({
  i, texte: l.texte, debut: T0 + l.debut, fin: T0 + l.fin, avant: l.avant,
  mots: l.mots.map((m) => ({ mot: m.mot, debut: T0 + m.debut, fin: T0 + m.fin })),
}))
const PAUSES = window.VOIX.pauses.map((p) => ({ apres: p.apres, debut: T0 + p.debut, fin: T0 + p.fin }))
const DUREE = LIGNES[LIGNES.length - 1].fin + QUEUE

const sansAccent = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
/** La ligne du script qui commence par ce texte (ou le contient). */
function ligne(debutDeLigne) {
  const q = sansAccent(debutDeLigne)
  const l = LIGNES.find((l) => sansAccent(l.texte).startsWith(q)) ?? LIGNES.find((l) => sansAccent(l.texte).includes(q))
  if (!l) throw new Error('ligne introuvable : ' + debutDeLigne)
  return l
}
/** L'instant d'un mot d'une ligne (par son rang, ou par son texte), ou du début / de la fin de la ligne. */
function instant(debutDeLigne, quoi = 'debut', decalage = 0) {
  const l = ligne(debutDeLigne)
  if (quoi === 'debut') return l.debut + decalage
  if (quoi === 'fin') return l.fin + decalage
  if (typeof quoi === 'number') return l.mots[quoi < 0 ? l.mots.length + quoi : quoi].debut + decalage
  const q = sansAccent(quoi)
  const nu = (w) => sansAccent(w.mot).split(/[’']/).pop()
  const m = l.mots.find((w) => sansAccent(w.mot) === q) ?? l.mots.find((w) => nu(w) === q) ?? l.mots.find((w) => sansAccent(w.mot).startsWith(q))
  if (!m) throw new Error(`mot introuvable : « ${quoi} » dans « ${l.texte} »`)
  return m.debut + decalage
}

/* -------- les repères -------- */
const REPERES = {
  // 1. mémoire
  memoire: ['Dans ma famille', 'debut', -0.4],
  souvenirs: ['Dans ma famille', 'arbre', 0],
  recul: ['2 307', 'debut', -0.45],
  personnes: ['2 307', 'personnes', 0],
  generations: ['14 générations', 'debut', 0],
  // 2. dispersion
  disperse: ['Et plus l’arbre grandit', 'disperse', -0.3],
  telephones: ['Des photos sur les téléphones', 'debut', -0.15],
  dossiers: ['Des actes dans des dossiers', 'debut', -0.15],
  notes: ['Des noms et des dates', 'debut', -0.15],
  disparaitre: ['Et surtout', 'disparaître', -0.3],
  regroupe: ['Et surtout', 'fin', 0.15],
  // 3. création
  cree: ['Du coup', 'créé', -0.1],
  nom: ['Du coup', 'Heritage', -0.05],
  reunie: ['Un endroit où', 'debut', -0.2],
  // 4. l'arbre
  parcourir: ['Tu peux parcourir', 'parcourir', -0.2],
  zoomer: ['Tu peux parcourir', 'zoomer', -0.1],
  retrouver: ['Tu peux parcourir', 'retrouver', -0.1],
  secondes: ['Tu peux parcourir', 'secondes', -0.2],
  parents: ['Voir ses parents', 'parents', -0.05],
  enfants: ['Voir ses parents', 'enfants', -0.05],
  lieux: ['Voir ses parents', 'lieux', -0.05],
  // 5. les sources
  document: ['Et quand une information', 'document', -0.2],
  source: ['Et quand une information', 'source', -0.25],
  verifiee: ['Une date vérifiée', 'vérifiée', -0.2],
  // 6. la famille participe
  fige: ['Mais ce que je voulais', 'figé', -0.3],
  enrichir: ['La famille peut continuer', 'debut', -0.2],
  souvenir: ['Tu peux raconter', 'souvenir', -0.35],
  photo: ['Envoyer une vieille photo', 'debut', -0.1],
  acte: ['Prendre en photo un acte', 'debut', -0.1],
  enregistrer: ['Ou même enregistrer', 'enregistrer', -0.15],
  transforme: ['Heritage transforme', 'transforme', -0.1],
  aVerifier: ['Heritage transforme', 'vérifier', -0.1],
  relit: ['Ensuite, la famille relit', 'relit', -0.1],
  corrige: ['Ensuite, la famille relit', 'corrige', -0.1],
  valide: ['Ensuite, la famille relit', 'valide', -0.1],
  grandir: ['et l’arbre continue', 'grandir', -0.3],
  // 7. questions
  questions: ['Et évidemment', 'debut', -0.25],
  question: ['« Qui étaient', 'debut', -0.15],
  reponse: ['Heritage retrouve', 'debut', -0.1],
  dansLArbre: ['Heritage retrouve', 'l’arbre', -0.2],
  // 8. explorer
  explorer: ['Et puis il y a', 'explorant', -0.3],
  carte: ['Les lieux où la famille', 'debut', -0.15],
  metiers: ['Les métiers', 'debut', -0.15],
  prenoms: ['Les prénoms transmis', 'debut', -0.15],
  histoires: ['Les histoires', 'debut', -0.15],
  // 9. fin
  retour: ['Les histoires', 'fin', 0.15],
  auFond: ['Au fond', 'debut', -0.1],
  pasJuste: ['Heritage, ce n’est pas', 'debut', 0],
  trace: ['C’est une façon', 'trace', -0.2],
  sait: ['De ce qu’on sait', 'debut', 0],
  decouvre: ['De ce qu’on découvre', 'debut', 0],
  demain: ['Et de tout ce qu’on', 'demain', -0.1],
  change: ['Parce qu’une famille', 'change', -0.2],
  rester: ['Mais son histoire', 'debut', -0.1],
  immobile: ['Mais son histoire', 'rester', 0],
  heritage: ['Heritage.', 'debut', -0.05],
}
const R = {}
for (const [nom, [l, quoi, dec]] of Object.entries(REPERES)) R[nom] = instant(l, quoi, dec)
R.FIN = DUREE

/* -------- les scènes : chacune sa plage, entre deux repères -------- */
const SCENE = {
  memoire: [0, R.disperse],
  dispersion: [R.disperse - 0.6, R.reunie + 0.4],
  creation: [R.cree - 0.3, R.parcourir + 0.3],
  arbre: [R.parcourir - 0.1, R.document + 0.4],
  sources: [R.document - 0.4, R.fige + 0.5],
  famille: [R.fige - 0.3, R.questions + 0.4],
  questions: [R.questions - 0.4, R.explorer + 0.3],
  explorer: [R.explorer - 0.3, R.retour + 0.6],
  fin: [R.retour - 0.2, DUREE],
}
/** Où en est une scène : 0 avant, 1 après (pour les fondus d'entrée et de sortie). */
const dansScene = (t, nom, marge = 0) => t >= SCENE[nom][0] - marge && t <= SCENE[nom][1] + marge
