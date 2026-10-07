/* ------------------------------------------------------------------------ */
/* SCÈNE 6 — LA FAMILLE PARTICIPE                                            */
/* « Mais ce que je voulais surtout, c'est que l'arbre ne soit pas quelque  */
/* chose de figé. La famille peut continuer à l'enrichir. Tu peux raconter  */
/* un souvenir. Envoyer une vieille photo. Prendre en photo un acte         */
/* retrouvé dans une boîte. Ou même enregistrer quelqu'un qui raconte son   */
/* histoire. Heritage transforme tout ça en informations à vérifier.        */
/* Ensuite, la famille relit, corrige, valide… et l'arbre continue de       */
/* grandir. »                                                                */
/*                                                                           */
/* L'arbre vivant ; l'accueil et « Ajouter une personne » ; l'assistant qui */
/* reçoit le souvenir, la photo, l'acte, l'enregistrement ; le texte brut   */
/* d'où émergent nom, date, lieu, relation ; les demandes à vérifier ; le   */
/* formulaire « Ajouter une personne » relu, corrigé, validé ; et la fiche  */
/* proposée qui prend sa place, en attente, dans l'arbre.                   */
/* ------------------------------------------------------------------------ */
ASSETS_A_CHARGER.push('photos/dos-1.jpg', 'documents/acte-mauron-1903.jpg')
const SOUVENIR = 'Mon arrière-grand-père s’appelait Jean Martin. Il est né en 1903 à Mauron, il était menuisier, comme son père.'
const CHAMPS = [
  { libelle: 'Nom', valeur: 'Jean Martin', mot: 'Jean Martin' },
  { libelle: 'Date', valeur: 'né en 1903', mot: '1903' },
  { libelle: 'Lieu', valeur: 'Mauron', mot: 'Mauron' },
  { libelle: 'Relation', valeur: 'arrière-grand-père paternel', mot: 'arrière-grand-père' },
]

function sceneFamille(t) {
  if (!dansScene(t, 'famille')) return
  /* l'arbre vivant (« pas figé ») */
  if (t < R.enrichir + 0.4) {
    const a = eout(prog(t, R.fige - 0.3, R.fige + 0.4))
    dessinerRush('arbre-vivant', t, { a })
  }
  /* l'accueil : « Ajouter une personne » */
  if (t >= R.enrichir - 0.4 && t < R.souvenir) {
    const a = eout(prog(t, R.enrichir - 0.4, R.enrichir + 0.1))
    const ve = dessinerRush('accueil', t, { a })
    const aj = rectEcran(ve, repere('accueil', R.enrichir + 0.4, 'ajouter'), 10)
    projecteur(aj, fenetre(t, R.enrichir + 0.1, R.enrichir + 0.95, 0.25, 0.2), { ombre: 0.35, rayon: 26 })
    toucher(...centreDe(aj), t, R.enrichir + 0.75)
  }
  /* l'assistant : un souvenir, une vieille photo, un acte, un enregistrement */
  if (t >= R.souvenir - 0.6 && t < R.relit + 0.1) assistant(t)
  /* « relit, corrige, valide » : le formulaire « Ajouter une personne » */
  if (t >= R.relit - 0.5 && t < R.grandir + 0.2) {
    const a = eout(prog(t, R.relit - 0.5, R.relit))
    const n = 'formulaire'
    const lieu = repere(n, R.corrige + 0.2, 'lieu')
    const cam = camRush(t, [{ t: R.relit - 0.5, cx: W / 2, cy: H / 2, z: 1 }, { t: R.corrige - 0.2, cx: W / 2, cy: H / 2, z: 1 }, { t: R.corrige + 0.2, ...cadrerSur(lieu, 1.1, 0, 200) }, { t: R.valide, ...cadrerSur(lieu, 1.1, 0, 200) }, { t: R.valide + 0.4, cx: W / 2, cy: H / 2, z: 1 }])
    const ve = dessinerRush(n, t, { a, cam })
    projecteur(rectEcran(ve, repere(n, t, 'prenoms'), 10), fenetre(t, R.relit - 0.2, R.relit + 0.5, 0.2, 0.2), { ombre: 0, rayon: 10 })
    projecteur(rectEcran(ve, repere(n, t, 'lieu'), 8), fenetre(t, R.corrige - 0.1, R.valide - 0.05, 0.2, 0.2), { ombre: 0.4, rayon: 16 })
    const env = rectEcran(ve, repere(n, R.valide + 0.5, 'envoyer'), 8)
    projecteur(env, fenetre(t, R.valide + 0.3, R.grandir - 0.3, 0.2, 0.2), { ombre: 0.45, rayon: 20 })
    toucher(...centreDe(env), t, R.valide + 0.6)
  }
  /* « et l'arbre continue de grandir » : Marie Martin, proposée, prend sa place à côté de son frère */
  if (t >= R.grandir - 0.4) {
    const a = eout(prog(t, R.grandir - 0.4, R.grandir + 0.1))
    dessinerRush('arbre-grandit', t, { a })
    const j = repere('arbre-grandit', t, 'p' + JEAN.id)
    const k = eout(prog(t, R.grandir + 0.05, R.grandir + 0.7))
    if (j && k > 0) {
      const nx = j.x + j.w + 40, ny = j.y
      x.save(); x.strokeStyle = alpha('#6b6f76', k); x.lineWidth = 3
      x.beginPath(); x.moveTo(j.x + j.w / 2, j.y - 30); x.lineTo(nx + j.w / 2, j.y - 30); x.lineTo(nx + j.w / 2, ny); x.stroke(); x.restore()
      ficheArbre({ prenom: 'Marie', nom: 'Martin', sexe: 'feminin', branch: 'pp', dates: 'vers 1906' }, nx, ny + (1 - k) * 30, j.w, j.h, { a: k, attente: true })
      pastille('en attente de validation', nx + j.w / 2, ny + j.h + 52, { taille: 26, a: k })
    }
  }
}

function assistant(t) {
  const n = 'assistant'
  const a = eout(prog(t, R.souvenir - 0.6, R.souvenir - 0.1))
  const ve = dessinerRush(n, t, { a })
  projecteur(rectEcran(ve, repere(n, t, 'texte'), 6), fenetre(t, R.souvenir - 0.2, R.photo, 0.25, 0.25), { ombre: 0.3, rayon: 18 })
  const docs = repere(n, R.enregistrer + 1.2, 'documents')
  const cible = docs ? [docs.x + 200, docs.y + docs.h + 280] : [W / 2, 1500]
  /* une vieille photo : elle descend se poser dans les documents */
  const kp = prog(t, R.photo - 0.35, R.photo + 0.45)
  if (kp > 0 && kp < 1) {
    const e = eio(kp)
    tirage(IMAGES['photos/dos-1.jpg'], lerp(780, cible[0], e), lerp(380, cible[1], e), lerp(420, 140, e), { rot: lerp(0.15, 0, e), a: 1 - prog(kp, 0.85, 1) })
  }
  /* un acte retrouvé dans une boîte : la boîte s'ouvre, l'acte en sort, on le photographie */
  const kb = fenetre(t, R.acte - 0.2, R.acte + 1.1, 0.35, 0.3)
  if (kb > 0) {
    boite(W / 2, 1180, 560, { a: kb, ouverture: eout(prog(t, R.acte - 0.1, R.acte + 0.3)) })
    const sort = eout(prog(t, R.acte + 0.1, R.acte + 0.6)), vole = eio(prog(t, R.acte + 0.75, R.acte + 1.25))
    if (sort > 0 && vole < 1) tirage(IMAGES['documents/acte-mauron-1903.jpg'], lerp(W / 2, cible[0] + 150, vole), lerp(1150 - 300 * sort, cible[1], vole), lerp(440, 140, vole), { rot: lerp(-0.05, 0, vole), a: 1 - prog(vole, 0.85, 1) })
    // le déclic de l'appareil : un éclat blanc très bref
    const flash = 1 - prog(t, R.acte + 0.62, R.acte + 0.82)
    if (t >= R.acte + 0.62 && flash > 0) { x.save(); x.fillStyle = `rgba(255,255,255,${0.35 * flash})`; x.fillRect(0, 0, W, H); x.restore() }
  }
  /* quelqu'un raconte son histoire : un enregistrement, la voix de Jeanne */
  const kv = fenetre(t, R.enregistrer - 0.3, R.transforme + 0.15, 0.35, 0.35)
  if (kv > 0) {
    const bx = 120, by = 880, bl = 840, bh = 250
    x.save(); x.globalAlpha = kv
    x.shadowColor = 'rgba(0,0,0,0.55)'; x.shadowBlur = 50; x.shadowOffsetY = 16
    rr(bx, by + (1 - eout(kv)) * 30, bl, bh, 30); x.fillStyle = C.panneau; x.fill(); x.shadowColor = 'transparent'
    x.strokeStyle = C.ligne; x.lineWidth = 2; x.stroke()
    avatar(bx + 80, by + 78, 40, 'JM', C.mp)
    texte('Tante Jeanne raconte', bx + 142, by + 62, { taille: 36, poids: 600 })
    const d = Math.max(0, t - R.enregistrer)
    texte(`● enregistrement · ${Math.floor(d / 60)}:${String(Math.floor(d % 60)).padStart(2, '0')}`, bx + 142, by + 104, { taille: 26, coul: C.alerte, famille: MONO })
    onde(bx + 40, by + 180, bl - 80, 80, t, { coul: C.accent })
    x.restore()
  }
  /* « Heritage transforme tout ça » : du texte brut, les champs détectés émergent */
  const kt = fenetre(t, R.transforme - 0.05, R.aVerifier + 0.55, 0.35, 0.4)
  if (kt > 0) {
    const bx = 80, by = 330, bl = 920
    const lignes = couper('« ' + SOUVENIR + ' »', 38, 400, bl - 80)
    const hT = 60 + lignes.length * 52, hC = CHAMPS.length * 96
    x.save(); x.globalAlpha = kt
    x.shadowColor = 'rgba(0,0,0,0.6)'; x.shadowBlur = 60
    rr(bx, by, bl, hT + hC + 70, 30); x.fillStyle = 'rgba(29,31,34,0.97)'; x.fill(); x.shadowColor = 'transparent'
    x.strokeStyle = C.ligne; x.lineWidth = 2; x.stroke()
    // le texte brut ; les mots reconnus s'éclairent
    lignes.forEach((l, i) => texte(l, bx + 40, by + 52 + i * 52, { taille: 38, poids: 400, coul: C.attenue }))
    // les champs détectés : nom, date, lieu, relation — chacun « à vérifier »
    CHAMPS.forEach((c, i) => {
      const k = eout(prog(t, R.transforme + 0.35 + i * 0.28, R.transforme + 0.75 + i * 0.28))
      if (k <= 0) return
      const y = by + hT + 40 + i * 96 + (1 - k) * 24
      x.save(); x.globalAlpha *= k
      rr(bx + 30, y - 38, bl - 60, 80, 16); x.fillStyle = C.panneau2; x.fill()
      texte(c.libelle.toUpperCase(), bx + 60, y + 2, { taille: 24, poids: 600, coul: C.attenue, espacement: 3 })
      texte(c.valeur, bx + 260, y + 2, { taille: 36, poids: 600 })
      const lp = mesure('à vérifier', 22, 600) + 30
      rr(bx + bl - 60 - lp, y - 18, lp, 38, 19); x.strokeStyle = alpha(C.accent, 0.7); x.lineWidth = 2; x.stroke()
      texte('à vérifier', bx + bl - 60 - lp / 2, y + 1, { taille: 22, poids: 600, coul: C.accent, align: 'center' })
      x.restore()
    })
    x.restore()
  }
  /* les demandes proposées par l'assistant : à vérifier */
  const carte = rectEcran(ve, repere(n, t, 'carte1'), 0)
  const conf = rectEcran(ve, repere(n, t, 'confiance'), 0)
  if (carte && conf) projecteur({ x: 30, y: conf.y - 40, w: 1020, h: carte.y - conf.y + carte.h + 520 }, fenetre(t, R.aVerifier + 0.5, R.relit - 0.1, 0.3, 0.2), { ombre: 0.35, rayon: 28 })
}

function sonsFamille() {
  son(R.enrichir + 0.75, 'tic', 0.6)
  son(R.souvenir - 0.3, 'tic', 0.5)
  for (let i = 0; i < 110; i += 6) son(R.souvenir - 0.15 + i * 0.016, 'touche', 0.35)
  son(R.photo - 0.3, 'papier', 0.5)
  son(R.acte - 0.15, 'papier', 0.45); son(R.acte + 0.62, 'declic', 0.7)
  son(R.enregistrer - 0.25, 'tic', 0.5)
  son(R.transforme - 0.1, 'tic', 0.55)
  CHAMPS.forEach((c, i) => son(R.transforme + 0.4 + i * 0.28, 'pose', 0.35))
  son(R.corrige, 'tic', 0.45)
  son(R.valide + 0.6, 'tic', 0.6)
  son(R.grandir + 0.1, 'pose', 0.5)
}
