/* ------------------------------------------------------------------------ */
/* SCÈNE 9 — FIN                                                             */
/* « Au fond… [softly] Heritage, ce n'est pas juste un arbre généalogique.  */
/* C'est une façon de garder une trace. De ce qu'on sait. De ce qu'on       */
/* découvre. Et de tout ce qu'on racontera encore demain. [softly] Parce    */
/* qu'une famille, ça change tout le temps. Mais son histoire… elle, mérite */
/* de rester. Heritage. »                                                    */
/*                                                                           */
/* Retour à l'arbre ; la caméra recule ; l'interface s'efface, il ne reste  */
/* que l'arbre ; le mouvement ralentit ; quelques photos anciennes se posent */
/* discrètement dans l'arbre ; il évolue un peu ; tout s'immobilise ; le    */
/* logo, seul. Pas de slogan.                                                */
/* ------------------------------------------------------------------------ */
ASSETS_A_CHARGER.push('photos/lieu-1.jpg', 'photos/dos-1.jpg', 'photos/lieu-2.jpg', 'photos/dos-4.jpg')
// les photos qui se posent dans l'arbre, une par phrase, chacune près d'une personne
const PHOTOS_FIN = [
  { src: 'photos/lieu-1.jpg', qui: () => JOS, t: 'trace', dx: -170, dy: -260, rot: -0.05 },
  { src: 'photos/dos-1.jpg', qui: () => MAT, t: 'sait', dx: 150, dy: -300, rot: 0.06 },
  { src: 'photos/lieu-2.jpg', qui: () => JEAN, t: 'decouvre', dx: -120, dy: 230, rot: 0.04 },
  { src: 'photos/dos-4.jpg', qui: () => FAM.ego, t: 'demain', dx: 140, dy: 210, rot: -0.06 },
]
const ORDRE_EFFACEMENT = { onglets: 0, recherche: 0.25, modes: 0.55, entete: 0.85 }

function sceneFin(t) {
  if (!dansScene(t, 'fin')) return
  const entree = eout(prog(t, R.retour - 0.3, R.retour + 0.2))
  /* 1. l'arbre plein écran (dessous), et l'application qui s'efface (dessus) */
  const plein = RUSH['arbre-fin-plein']
  const signe = eio(prog(t, R.heritage - 0.25, R.heritage + 0.8)) // l'arbre se retire derrière le logo
  if (plein && t >= plein.debut) {
    x.save(); if (signe > 0) x.filter = `brightness(${1 - 0.78 * signe}) blur(${(3 * signe).toFixed(1)}px)`
    dessinerRush('arbre-fin-plein', t, { a: eout(prog(t, R.auFond - 0.4, R.pasJuste + 0.4)) })
    x.restore()
  }
  if (t < R.pasJuste + 1.0) {
    const im = imageRush('arbre-fin', t)
    if (im) {
      for (const [nom, reg] of Object.entries(REGIONS)) {
        const d = ORDRE_EFFACEMENT[nom]
        const k = nom === 'arbre' ? 1 - eio(prog(t, R.pasJuste - 0.6, R.pasJuste + 0.9)) : 1 - eio(prog(t, R.auFond - 0.5 + d, R.auFond + 0.2 + d))
        if (k <= 0) continue
        x.save(); x.globalAlpha = k * entree; x.beginPath(); x.rect(reg.x, reg.y, reg.w, reg.h); x.clip()
        x.drawImage(im.im, 0, 0, W, H); x.restore()
      }
    }
  }
  /* 2. « garder une trace » : des photos anciennes, discrètement, dans l'arbre */
  const fige = Math.min(t, R.immobile)
  PHOTOS_FIN.forEach((p, i) => {
    const k = eout(prog(t, R[p.t] - 0.1, R[p.t] + 0.9)) * (1 - signe)
    if (k <= 0) return
    const r = repere('arbre-fin-plein', fige, 'p' + p.qui().id)
    if (!r) return
    const ax = r.x + r.w / 2, ay = r.y + r.h / 2
    const px = clamp(ax + p.dx, 170, W - 170), py = clamp(ay + p.dy, 300, 1250)
    x.save(); x.globalAlpha = 0.85 * k
    x.strokeStyle = alpha('#e9dcc0', 0.5); x.lineWidth = 2; x.setLineDash([4, 6]); x.beginPath(); x.moveTo(ax, ay); x.lineTo(px, py); x.stroke(); x.setLineDash([])
    x.beginPath(); x.arc(ax, ay, 9, 0, Math.PI * 2); x.fillStyle = '#e9dcc0'; x.fill()
    x.restore()
    tirage(IMAGES[p.src], px, py + (1 - k) * 16, 250, { rot: p.rot, a: 0.9 * k })
  })
  /* 3. « une famille, ça change tout le temps » : une onde de lumière parcourt les générations, de nouvelles fiches apparaissent */
  const vague = prog(t, R.change - 0.2, R.change + 1.6)
  if (vague > 0 && vague < 1 && signe < 1) {
    const y = lerp(H * 0.15, H * 0.8, eio(vague))
    const g = x.createLinearGradient(0, y - 120, 0, y + 120)
    g.addColorStop(0, 'rgba(217,160,102,0)'); g.addColorStop(0.5, 'rgba(217,160,102,0.16)'); g.addColorStop(1, 'rgba(217,160,102,0)')
    x.save(); x.globalCompositeOperation = 'lighter'; x.fillStyle = g; x.fillRect(0, y - 120, W, 240); x.restore()
  }
  const nouveaux = FAM.enfantsDe(FAM.ego)
  nouveaux.forEach((e, i) => {
    const r = repere('arbre-fin-plein', fige, 'p' + e.id)
    const k = eout(prog(t, R.change + 0.3 + i * 0.25, R.change + 0.9 + i * 0.25)) * (1 - signe)
    if (!r || k <= 0) return
    x.save(); x.globalAlpha = k; x.shadowColor = alpha(C.accent, 0.9); x.shadowBlur = 20
    rr(r.x + r.w * 0.1, r.y + r.h + 46, Math.max(14, r.w * 0.8), Math.max(10, r.h * 0.8), 3); x.fillStyle = C.accent; x.fill()
    x.restore()
  })
  /* 4. « Heritage. » : le logo, seul */
  const kL = prog(t, R.heritage - 0.15, R.heritage + 1.0)
  logo(W / 2, 830, 150, kL, { a: eout(prog(t, R.heritage - 0.15, R.heritage + 0.3)) })
  motHeritage(W / 2, 1010, 112, eout(prog(t, R.heritage + 0.05, R.heritage + 0.6)))
}

function sonsFin() {
  son(R.retour - 0.2, 'recul', 0.55)
  PHOTOS_FIN.forEach((p) => son(R[p.t], 'papier', 0.25))
  son(R.change, 'souffle', 0.3)
  son(R.heritage - 0.1, 'final', 1)
}
