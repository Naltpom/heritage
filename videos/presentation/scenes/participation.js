/* ------------------------------------------------------------------------ */
/* PARTICIPATION — « Et surtout, la famille peut participer. Tu peux        */
/* raconter un souvenir, envoyer une photo d'un acte, enregistrer quelqu'un */
/* qui raconte son histoire… Heritage transforme tout ça en informations à  */
/* vérifier et à intégrer dans l'arbre. »                                    */
/*                                                                           */
/* Au centre, l'assistant réel (rush `assistant`) ; dans les marges, ce que  */
/* la famille apporte : un souvenir, la photo d'un acte, la voix de Jeanne.  */
/* La voix devient du texte, le texte des informations, qui rejoignent les  */
/* demandes proposées par l'application — puis l'arbre, en attente.         */
/* ------------------------------------------------------------------------ */
const MEMBRES = [
  { i: 'JM', nom: 'Jeanne', coul: C.mp, x: 190, y: 330 }, { i: 'PD', nom: 'Paul', coul: C.pp, x: 290, y: 520 },
  { i: 'CG', nom: 'Claire', coul: C.mm, x: 170, y: 700 }, { i: 'LD', nom: 'Louis', coul: C.pm, x: 1740, y: 300 },
  { i: 'AM', nom: 'Anne', coul: C.accent, x: 1650, y: 480 },
]
const TRANSCRIPTION = '« Ma grand-mère Joséphine était couturière, rue des Tanneurs… Son fils Pierre est parti à Nantes en 1912. »'
const EXTRAITS = [
  { s: 'couturière', libelle: 'Profession' }, { s: 'rue des Tanneurs', libelle: 'Résidence' },
  { s: 'née le 12 mars 1868', libelle: 'Naissance', acte: true }, { s: 'Pierre Dubois, son fils', libelle: 'Nouvelle fiche' },
]

function sceneParticipation(t) {
  if (t < R.participer - 0.7 || t > R.questions + 0.6) return
  const n = 'assistant'
  // le plan : depuis les statistiques, fondu vers l'assistant
  const entree = eout(prog(t, R.participer - 0.6, R.participer + 0.1))
  const zone = repere(n, R.souvenir, 'texte')
  const cam = camRush(t, [
    { t: R.participer - 0.6, cx: 960, cy: 540, z: 1.0 },
    { t: R.souvenir - 0.3, cx: 960, cy: 600, z: 1.08 },
    { t: R.transforme, cx: 960, cy: 620, z: 1.08 },
    { t: R.transforme + 1.2, cx: 960, cy: 600, z: 1.12 },
  ])
  const ve = dessinerRush(n, t, { cam, a: entree })

  /* la famille : des membres apparaissent dans les marges, reliés à la page */
  const kFam = fenetre(t, R.participer - 0.1, R.transforme - 0.2, 0.5, 0.6)
  MEMBRES.forEach((mb, i) => {
    const k = kFam * eout(prog(t, R.participer + i * 0.12, R.participer + 0.5 + i * 0.12))
    if (k <= 0) return
    const flotte = Math.sin(t * 0.9 + i) * 5
    x.save(); x.setLineDash([3, 6]); x.strokeStyle = alpha(mb.coul, 0.28 * k); x.lineWidth = 1.2
    x.beginPath(); x.moveTo(mb.x + (mb.x < 960 ? 34 : -34), mb.y + flotte); x.lineTo(mb.x < 960 ? 560 : 1360, 560); x.stroke(); x.restore()
    avatar(mb.x, mb.y + flotte, 30, mb.i, mb.coul, { a: k })
    texte(mb.nom, mb.x, mb.y + 50 + flotte, { taille: 17, poids: 500, coul: C.attenue, align: 'center', a: k })
  })

  /* un souvenir : le champ de texte réel s'éclaire pendant qu'on écrit */
  projecteur(rectEcran(ve, repere(n, t, 'texte'), 4), fenetre(t, R.souvenir - 0.2, R.photoActe - 0.3, 0.3, 0.3), { ombre: 0.35 })

  /* la photo d'un acte : prise de biais, elle vient se poser dans « Documents » */
  const doc = rectEcran(ve, repere(n, R.photoActe + 0.2, 'documents'))
  const kp = prog(t, R.photoActe - 0.45, R.photoActe + 0.35)
  if (kp > 0 && kp < 1 && doc) {
    const e = eio(kp)
    const cx = lerp(1720, doc.x + doc.w / 2, e), cy = lerp(880, doc.y + doc.h / 2, e)
    tirage(IMAGES['documents/acte-josselin-1868.jpg'], cx, cy, lerp(300, 90, e), { rot: lerp(-0.22, 0, e), a: 1 - prog(kp, 0.85, 1) })
  }

  /* la voix de Jeanne : un enregistrement, à droite */
  const kv = fenetre(t, R.enregistrer - 0.25, R.aVerifier, 0.4, 0.5)
  if (kv > 0) {
    const bx = 1590, by = 600, bl = 300, bh = 150
    const parle = 1 - eio(prog(t, R.transforme - 0.2, R.transforme + 0.5)) // la voix devient du texte
    x.save(); x.globalAlpha = kv
    x.shadowColor = 'rgba(0,0,0,0.5)'; x.shadowBlur = 40; x.shadowOffsetY = 14
    rr(bx, by + (1 - eout(kv)) * 24, bl, bh, 12); x.fillStyle = C.panneau; x.fill(); x.shadowColor = 'transparent'
    x.strokeStyle = C.ligne; x.lineWidth = 1; x.stroke()
    avatar(bx + 44, by + 46, 22, 'JM', C.mp)
    texte('Jeanne, 84 ans', bx + 80, by + 38, { taille: 19, poids: 600 })
    const duree = Math.max(0, t - R.enregistrer)
    texte(`● ${Math.floor(duree / 60)}:${String(Math.floor(duree % 60)).padStart(2, '0')}`, bx + 80, by + 62, { taille: 15, coul: parle > 0.5 ? C.alerte : C.attenue, famille: MONO })
    onde(bx + 26, by + 108, bl - 52, 52 * parle + 2, t, { coul: C.accent, energie: parle, graine: 3 })
    x.restore()
    // la transcription : les mots apparaissent sous la carte, au rythme de la parole
    const kt = prog(t, R.transforme - 0.1, R.transforme + 1.1)
    if (kt > 0) {
      const mots = TRANSCRIPTION.split(' '), visibles = Math.ceil(mots.length * eout(kt))
      let lx = bx, ly = by + bh + 34
      x.save(); x.font = police(17, 400)
      for (let i = 0; i < visibles; i++) {
        const m = mots[i] + ' ', l = x.measureText(m).width
        if (lx + l > bx + bl) { lx = bx; ly += 26 }
        const important = /couturière|Tanneurs|Pierre/.test(m)
        texte(m, lx, ly, { taille: 17, poids: important ? 600 : 400, coul: important ? C.accent : C.attenue, a: kv * (1 - prog(t, R.aVerifier - 0.6, R.aVerifier)) })
        lx += l
      }
      x.restore()
    }
  }

  /* les informations extraites : des pastilles qui rejoignent les demandes proposées */
  const cible = rectEcran(ve, repere(n, R.aVerifier, 'carte1'))
  EXTRAITS.forEach((e, i) => {
    const t0 = R.transforme + 0.5 + i * 0.22
    const k = prog(t, t0, t0 + 0.4), vol = eio(prog(t, R.transforme + 1.7 + i * 0.1, R.aVerifier - 0.1 + i * 0.1))
    if (k <= 0 || vol >= 1) return
    const sx = 1590, sy = 880 + i * 46
    const cx = lerp(sx, cible ? cible.x + cible.w * 0.6 : 960, vol), cy = lerp(sy, cible ? cible.y + 20 + i * 40 : 540, vol)
    x.save(); x.globalAlpha = eout(k) * (1 - prog(vol, 0.75, 1))
    const l = pastille(`${e.libelle} · ${e.s}`, cx, cy, { taille: 17, coul: e.acte ? C.ok : C.accent, fond: 'rgba(29,31,34,0.95)', align: 'left' })
    x.restore()
  })

  /* « à vérifier » : la confiance et la case de la première demande réelle */
  projecteur(rectEcran(ve, union(repere(n, t, 'confiance'), repere(n, t, 'carte1')), 8), fenetre(t, R.aVerifier - 0.1, R.integrer + 0.2, 0.3, 0.3), { ombre: 0.4 })

  /* « et à intégrer dans l'arbre » : la nouvelle fiche, en attente, à sa place sous Joséphine */
  const ki = fenetre(t, R.integrer - 0.15, R.questions + 0.5, 0.5, 0.4)
  if (ki > 0) {
    const ox = 1240, oy = 770, s = 1.25
    x.save(); x.globalAlpha = ki
    x.shadowColor = 'rgba(0,0,0,0.5)'; x.shadowBlur = 50; rr(ox - 30, oy - 40, 600, 270, 12); x.fillStyle = 'rgba(20,21,23,0.96)'; x.fill(); x.shadowColor = 'transparent'
    x.strokeStyle = C.ligne; x.lineWidth = 1; x.stroke()
    ficheArbre(JOS, ox, oy, 190 * s * 0.85, 56 * s * 0.85)
    ficheArbre(FRANCOIS, ox + 330, oy, 190 * s * 0.85, 56 * s * 0.85)
    const yE = oy + 130
    x.strokeStyle = '#4d5057'; x.lineWidth = 1.5
    x.beginPath(); x.moveTo(ox + 202, oy + 30); x.lineTo(ox + 330, oy + 30); x.moveTo(ox + 266, oy + 30); x.lineTo(ox + 266, yE - 16); x.moveTo(ox + 60, yE - 16); x.lineTo(ox + 470, yE - 16)
    x.moveTo(ox + 60, yE - 16); x.lineTo(ox + 60, yE); x.stroke()
    ficheArbre(FAM.enfantsDe(JOS)[0], ox - 40, yE, 190 * s * 0.8, 56 * s * 0.8, { a: 0.8 })
    // la fiche proposée : le pointillé d'accent de l'application pour ce qui est en attente
    const kn = eout(prog(t, R.integrer + 0.15, R.integrer + 0.75))
    const nx = ox + 380, ny = yE + (1 - kn) * 26
    x.strokeStyle = alpha('#4d5057', kn); x.beginPath(); x.moveTo(ox + 470, yE - 16); x.lineTo(ox + 470, yE); x.stroke()
    x.globalAlpha = ki * kn
    ficheArbre({ prenom: 'Pierre', nom: 'Dubois', sexe: 'masculin', branch: 'mp', naissance: 'vers 1894', deces: null }, nx - 90, ny, 190, 56, { dates: false })
    x.setLineDash([5, 4]); rr(nx - 90, ny, 190, 56, 6); x.strokeStyle = C.accent; x.lineWidth = 1.5; x.stroke(); x.setLineDash([])
    pastille('en attente de validation', nx + 5, ny + 84, { taille: 14, coul: C.accent })
    x.restore()
  }
}

function sonsParticipation() {
  MEMBRES.forEach((m, i) => son(R.participer + i * 0.12, 'pose', 0.22))
  son(R.souvenir - 0.2, 'tic', 0.5)
  for (let i = 0; i < 96; i += 4) son(R.souvenir + i * 0.022, 'touche', 0.35)
  son(R.photoActe - 0.3, 'papier', 0.5); son(R.photoActe + 0.3, 'tic', 0.4)
  son(R.enregistrer - 0.2, 'tic', 0.5)
  son(R.transforme - 0.1, 'tic', 0.6)
  EXTRAITS.forEach((e, i) => son(R.transforme + 0.5 + i * 0.22, 'pose', 0.35))
  son(R.aVerifier - 0.1, 'note', 0.45)
  son(R.integrer + 0.2, 'pose', 0.5)
}
