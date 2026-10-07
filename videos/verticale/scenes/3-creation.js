/* ------------------------------------------------------------------------ */
/* SCÈNE 3 — CRÉATION DE HERITAGE                                            */
/* « Du coup, j'ai créé Heritage. [softly] Un endroit où toute l'histoire   */
/* de la famille peut simplement être réunie. »                              */
/*                                                                           */
/* Les objets regroupés au centre se réorganisent : chacun devient une       */
/* pièce de l'interface (une barre, une fiche de l'arbre) et va se poser à   */
/* sa place exacte dans l'écran mobile de l'application. Le nom apparaît,   */
/* sobrement ; puis l'application réelle (rush `arbre`) prend le relais,    */
/* région par région : l'arbre, l'en-tête, les modes, la recherche, les      */
/* onglets.                                                                  */
/* ------------------------------------------------------------------------ */

// les régions de l'écran Arbre (app mobile, relevées dans le DOM, en pixels 1080 × 1920)
const REGIONS = {
  entete: { x: 0, y: 0, w: 1080, h: 139 }, modes: { x: 0, y: 139, w: 1080, h: 146 }, arbre: { x: 0, y: 285, w: 1080, h: 1288 },
  recherche: { x: 0, y: 1573, w: 1080, h: 195 }, onglets: { x: 0, y: 1768, w: 1080, h: 152 },
}
// où chaque objet va se poser : quatre deviennent des barres, les autres des fiches de l'arbre
const PLACES = (() => {
  const places = [
    { r: { x: 24, y: 30, w: 520, h: 80 }, region: 'entete' }, { r: { x: 32, y: 160, w: 1016, h: 104 }, region: 'modes' },
    { r: { x: 32, y: 1600, w: 860, h: 130 }, region: 'recherche' }, { r: { x: 0, y: 1768, w: 1080, h: 152 }, region: 'onglets' },
  ]
  // une petite généalogie : 2, 4, 4 fiches
  const rangees = [[2, 650], [4, 900], [4, 1150]]
  for (const [n, y] of rangees) for (let i = 0; i < n; i++) places.push({ r: { x: (W - n * 230) / 2 + i * 230 + 10, y, w: 210, h: 84 }, region: 'arbre', fiche: true })
  return places
})()

function sceneCreation(t) {
  if (!dansScene(t, 'creation')) return
  const tA = RUSH['arbre']?.debut ?? R.reunie - 1.2
  /* 1. les objets regroupés deviennent des pièces d'interface, et vont à leur place */
  const forme = eio(prog(t, R.cree - 0.05, R.cree + 0.9)) // du papier à la pièce d'interface
  const vol = eio(prog(t, R.cree + 0.15, R.reunie + 0.2)) // vers leur place
  const releve = prog(t, R.reunie - 0.1, R.reunie + 0.9) // l'application réelle prend le relais
  if (releve < 1) {
    OBJETS.forEach((o, i) => {
      const e = etatObjet(o, i, Math.min(t, R.cree))
      const p = PLACES[i % PLACES.length]
      const cx = lerp(e.cx, p.r.x + p.r.w / 2, vol), cy = lerp(e.cy, p.r.y + p.r.h / 2, vol)
      const l0 = 260 * e.echelle * 1.4, h0 = 190 * e.echelle * 1.4
      const l = lerp(l0, p.r.w, vol), h = lerp(h0, p.r.h, vol)
      const a = 1 - eio(releve)
      // le papier s'efface pendant que la pièce d'interface apparaît
      if (forme < 1) { x.save(); x.globalAlpha = 1 - forme; dessinerObjet(o, { ...e, cx, cy, a: e.a }, t); x.restore() }
      x.save(); x.globalAlpha = a * forme
      x.translate(cx, cy); x.rotate(e.rot * (1 - vol))
      rr(-l / 2, -h / 2, l, h, p.fiche ? 14 : 22)
      x.fillStyle = p.fiche ? (i % 2 ? C.femme : C.homme) : C.panneau; x.fill()
      x.strokeStyle = alpha('#ffffff', 0.08); x.lineWidth = 2; x.stroke()
      if (p.fiche) { x.save(); rr(-l / 2, -h / 2, l, h, 14); x.clip(); x.fillStyle = C[['pp', 'pm', 'mp', 'mm'][i % 4]]; x.fillRect(-l / 2, -h / 2, 9, h); x.restore() }
      x.restore()
    })
    // les traits de la petite généalogie
    const traits = eout(prog(t, R.reunie - 0.6, R.reunie)) * (1 - eio(releve))
    if (traits > 0) {
      x.save(); x.strokeStyle = alpha('#6a6e76', traits); x.lineWidth = 3
      x.beginPath(); x.moveTo(W / 2, 692); x.lineTo(W / 2, 800); x.moveTo(W / 2 - 345, 800); x.lineTo(W / 2 + 345, 800)
      for (const xx of [-345, -115, 115, 345]) { x.moveTo(W / 2 + xx, 800); x.lineTo(W / 2 + xx, 858) }
      x.stroke(); x.restore()
    }
  }
  /* 2. l'application réelle, région par région */
  if (t >= tA) {
    const ordre = { arbre: 0, entete: 0.45, modes: 0.75, recherche: 1.05, onglets: 1.3 }
    const ve = (px, py) => [px, py]
    const im = imageRush('arbre', t)
    if (im) {
      for (const [nom, reg] of Object.entries(REGIONS)) {
        const k = eout(prog(t, R.reunie - 0.1 + ordre[nom], R.reunie + 0.6 + ordre[nom]))
        if (k <= 0) continue
        x.save(); x.globalAlpha = k
        x.beginPath(); x.rect(reg.x, reg.y, reg.w, reg.h); x.clip()
        x.drawImage(im.im, 0, (1 - k) * (nom === 'entete' || nom === 'modes' ? -24 : nom === 'arbre' ? 0 : 24), W, H)
        x.restore()
      }
    }
  }
  /* 3. le nom, sobrement : le logo se trace, le mot apparaît ; ils s'effacent quand l'en-tête arrive */
  const aNom = fenetre(t, R.nom - 0.1, R.reunie + 0.9, 0.6, 0.6)
  if (aNom > 0) {
    x.save(); x.globalAlpha = 0.55 * aNom; x.fillStyle = C.fond; rr(W / 2 - 300, 300, 600, 300, 40); x.restore()
    logo(W / 2, 395, 112, prog(t, R.nom - 0.1, R.nom + 1.0), { a: aNom })
    motHeritage(W / 2, 520, 86, aNom * eout(prog(t, R.nom + 0.1, R.nom + 0.7)))
  }
}

function sonsCreation() {
  son(R.cree - 0.05, 'souffle', 0.5)
  son(R.nom, 'note', 0.6)
  son(R.reunie + 0.1, 'pose', 0.35); son(R.reunie + 0.55, 'tic', 0.3); son(R.reunie + 0.85, 'tic', 0.25); son(R.reunie + 1.15, 'tic', 0.25)
}
