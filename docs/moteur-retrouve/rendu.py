# -*- coding: utf-8 -*-
"""Rend le graphe familial en SVG, puis assemble les deux pages HTML.

Entree : graphe.json (produit par graphe.py)
Sorties : arbre-complet.html   (vue statique, zoom/deplacement)
          arbre-interactif.html (idem + fiche laterale, recherche, filtres)
"""
import json, html

RACINE = r"C:\www\myHeritage"
G = json.load(open(RACINE + r"\graphe.json", encoding="utf-8"))
GENS, FAMS, POS, ARETES, BOITE = G["gens"], G["familles"], G["pos"], G["aretes"], G["boite"]


def e(t):
    return html.escape(str(t or ""), quote=True)


def nom_affiche(k):
    d = GENS[k]
    pre = d["usage"] or d["prenom"]
    # on raccourcit les prenoms multiples : seul le premier est affiche
    pre = pre.split()[0] if pre and pre != "?" else pre
    return (pre + " " + d["nom"]).strip() or "sans nom"


# ------------------------------------------------------------------ chemins
def poly(pts, r=9.0):
    """Trace orthogonal a coins arrondis passant par une suite de points."""
    pts = [p for i, p in enumerate(pts)
           if i == 0 or abs(p[0] - pts[i - 1][0]) > .3 or abs(p[1] - pts[i - 1][1]) > .3]
    if len(pts) < 2:
        return ""
    d = ["M%.1f,%.1f" % pts[0]]
    for i in range(1, len(pts) - 1):
        (xa, ya), (xb, yb), (xc, yc) = pts[i - 1], pts[i], pts[i + 1]
        r1 = min(r, ((xb - xa) ** 2 + (yb - ya) ** 2) ** .5 / 2)
        r2 = min(r, ((xc - xb) ** 2 + (yc - yb) ** 2) ** .5 / 2)
        ux, uy = (xa - xb), (ya - yb)
        n = (ux * ux + uy * uy) ** .5 or 1
        vx, vy = (xc - xb), (yc - yb)
        m = (vx * vx + vy * vy) ** .5 or 1
        d.append("L%.1f,%.1f" % (xb + ux / n * r1, yb + uy / n * r1))
        d.append("Q%.1f,%.1f %.1f,%.1f" % (xb, yb, xb + vx / m * r2, yb + vy / m * r2))
    d.append("L%.1f,%.1f" % pts[-1])
    return " ".join(d)


# Un peigne par famille : une seule barre horizontale partagee, et une descente
# par enfant. Sans cela, toutes les aretes d'une meme rangee passent par la meme
# mediane et se superposent — le graphe devient illisible des 40 familles.
def barres():
    """Calcule la hauteur de barre de chaque famille, decalee pour eviter les collisions."""
    par_rang = {}
    for fk, fd in FAMS.items():
        enf = [c for c in fd["enfants"] if c in POS]
        if not enf or fk not in POS:
            continue
        par_rang.setdefault(round(POS[fk]["y"]), []).append(fk)
    hauteur = {}
    for y, fks in par_rang.items():
        # Les peignes larges passent au plus pres des enfants, les etroits au
        # plus pres des parents : deux barres qui se croisent en x ne sont
        # jamais a la meme hauteur, donc elles restent distinguables.
        larg = {}
        for fk in fks:
            enf = [c for c in FAMS[fk]["enfants"] if c in POS]
            larg[fk] = max(POS[c]["x"] for c in enf) - min(POS[c]["x"] for c in enf)
        fks.sort(key=lambda k: larg[k])
        n = max(len(fks) - 1, 1)
        for i, fk in enumerate(fks):
            enf = [c for c in FAMS[fk]["enfants"] if c in POS]
            haut = POS[fk]["y"]
            bas = min(POS[c]["y"] - POS[c]["h"] / 2 for c in enf)
            hauteur[fk] = haut + (bas - haut) * (0.30 + 0.55 * i / n)
    return hauteur


BARRE = barres()


svg = []
svg.append('<svg id="g" xmlns="http://www.w3.org/2000/svg" '
           'viewBox="%.0f %.0f %.0f %.0f" preserveAspectRatio="xMidYMid meet">'
           % (BOITE["x"], BOITE["y"], BOITE["w"], BOITE["h"]))

# --- aretes d'abord, sous les fiches
svg.append('<g class="aretes">')
for a in ARETES:
    d, v = a["de"], a["vers"]
    if d not in POS or v not in POS:
        continue
    pd, pv = POS[d], POS[v]
    cls = "ar " + a["genre"] + (" divorce" if a.get("divorce") else "")
    if a["genre"] == "union":
        # epoux -> noeud famille : on remonte a une barre juste au-dessus du noeud
        y1 = pd["y"] + pd["h"] / 2
        yb = pv["y"] - 16
        pts = [(pd["x"], y1), (pd["x"], yb), (pv["x"], yb), (pv["x"], pv["y"] - 6)]
    else:
        # noeud famille -> enfant : descente jusqu'a la barre partagee, puis
        # deplacement lateral, puis descente sur l'enfant
        yb = BARRE.get(d, (pd["y"] + pv["y"]) / 2)
        pts = [(pd["x"], pd["y"] + 6), (pd["x"], yb),
               (pv["x"], yb), (pv["x"], pv["y"] - pv["h"] / 2)]
    if abs(pd["x"] - pv["x"]) > 700:
        cls += " long"
    svg.append('<path class="%s" data-de="%s" data-vers="%s" d="%s"/>'
               % (cls, e(d.strip("@")), e(v.strip("@")), poly(pts)))
svg.append('</g>')

# --- noeuds famille
svg.append('<g class="familles">')
for fk, fd in FAMS.items():
    if fk not in POS:
        continue
    p = POS[fk]
    div = fd.get("a_divorce")
    svg.append('<g class="fam%s" data-id="%s">' % (" div" if div else "", e(fk.strip("@"))))
    svg.append('<circle cx="%.1f" cy="%.1f" r="6"/>' % (p["x"], p["y"]))
    if div:
        svg.append('<path class="barre" d="M%.1f,%.1f L%.1f,%.1f"/>'
                   % (p["x"] - 8, p["y"] + 8, p["x"] + 8, p["y"] - 8))
    lbl = fd.get("mariage") or ""
    if lbl:
        svg.append('<text class="lm" x="%.1f" y="%.1f">%s%s</text>'
                   % (p["x"] + 10, p["y"] + 3.5, "⚭ " if not div else "⚭ ", e(lbl)))
    svg.append('</g>')
svg.append('</g>')

# --- fiches
svg.append('<g class="fiches">')
for k, d in GENS.items():
    if k not in POS:
        continue
    p = POS[k]
    x = p["x"] - p["w"] / 2
    y = p["y"] - p["h"] / 2
    cls = ["fiche", {"M": "h", "F": "f"}.get(d["sexe"], "x")]
    if d["prive"]:
        cls.append("prive")
    if d["fiable"]:
        cls.append("fiable")
    if d.get("sans_source"):
        cls.append("nosrc")
    if k == G["ego"]:
        cls.append("ego")
    if d.get("unions", 0) > 1:
        cls.append("remarie")
    cherchable = " ".join(filter(None, [
        d["prenom"], d["nom"], d.get("usage", ""), d["ligne_dates"],
        d.get("lieu_nais", ""), d.get("lieu_deces", "")]))
    svg.append('<g class="%s" data-id="%s" data-rech="%s"><title>%s</title>'
               % (" ".join(cls), e(k.strip("@")), e(cherchable), e(nom_affiche(k))))
    svg.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="5"/>'
               % (x, y, p["w"], p["h"]))
    if d.get("branche"):
        svg.append('<rect class="br b-%s" x="%.1f" y="%.1f" width="4" height="%.1f"/>'
                   % (d["branche"], x + 1.2, y + 6, p["h"] - 12))
    svg.append('<text class="nm" x="%.1f" y="%.1f">%s</text>'
               % (p["x"], y + 17, e(nom_affiche(k))))
    svg.append('<text class="dt" x="%.1f" y="%.1f">%s</text>'
               % (p["x"], y + 31, e(d["ligne_dates"])))
    svg.append('</g>')
svg.append('</g>')
svg.append('</svg>')
SVG = "\n".join(svg)

# ------------------------------------------------------------------ styles
STYLE = """
  :root{
    --bg:#f4f2ef; --fg:#1b1b1b; --pan:#fffefc; --line:#dcd7ce; --mut:#6b6560;
    --acc:#8a5a2b; --acc-soft:#f0e3d4; --warn:#a8501e; --ok:#3f6b4a;
    --h:#dbe6f2; --h-b:#8ba9c9; --f:#f3dfe2; --f-b:#cf9aa4; --x:#e8e6e2; --x-b:#b5b0a8;
  }
  @media (prefers-color-scheme:dark){
    :root{--bg:#141517; --fg:#e8e6e2; --pan:#1d1f22; --line:#33363b; --mut:#9a948c;
          --acc:#d9a066; --acc-soft:#3a2c1d; --warn:#e08a5a; --ok:#7db38c;
          --h:#24354a; --h-b:#4a6b90; --f:#432b31; --f-b:#8e5b66; --x:#2a2c30; --x-b:#55585e;}
  }
  *{box-sizing:border-box} html,body{height:100%}
  body{margin:0;background:var(--bg);color:var(--fg);overflow:hidden;
    font:14px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  header{position:fixed;inset:0 0 auto 0;z-index:10;display:flex;gap:12px;align-items:center;
    flex-wrap:wrap;padding:9px 14px;background:var(--pan);border-bottom:1px solid var(--line)}
  h1{margin:0;font-size:14px;font-weight:640;letter-spacing:-.01em;white-space:nowrap}
  .sep{width:1px;height:20px;background:var(--line)}
  .meta{font-size:12px;color:var(--mut)}
  .grow{flex:1 1 auto}
  .hint{font-size:12px;color:var(--mut);white-space:nowrap}
  kbd{font:inherit;font-size:11px;border:1px solid var(--line);border-radius:4px;padding:0 4px}
  #wrap{position:absolute;inset:46px 0 0 0;overflow:hidden;cursor:grab;touch-action:none}
  #wrap.drag{cursor:grabbing}
  #g{display:block;width:100%;height:100%}

  /* fiches */
  .fiche{cursor:pointer}
  .fiche:hover rect{stroke-width:2.4}
  .fiche rect{stroke-width:1.2}
  .fiche .nm{font:600 11.5px ui-sans-serif,system-ui,sans-serif;text-anchor:middle;fill:var(--fg)}
  .fiche .dt{font:10px ui-sans-serif,system-ui,sans-serif;text-anchor:middle;fill:var(--mut)}
  .fiche.h rect{fill:var(--h);stroke:var(--h-b)}
  .fiche.f rect{fill:var(--f);stroke:var(--f-b)}
  .fiche.x rect{fill:var(--x);stroke:var(--x-b)}
  .fiche.prive rect{stroke-dasharray:4 3}
  .fiche.ego rect{stroke:var(--acc);stroke-width:2.6}
  .fiche.remarie rect{stroke-width:2.2}

  /* familles */
  .fam circle{fill:var(--pan);stroke:var(--mut);stroke-width:1.3}
  .fam.div circle{stroke:var(--warn)}
  .fam .barre{stroke:var(--warn);stroke-width:1.8}
  .fam .lm{font:9.5px ui-sans-serif,system-ui,sans-serif;fill:var(--mut)}

  /* Recherche : mise en retrait par proprietes de peinture uniquement.
     `opacity` creerait une couche de composition par fiche — a 280 noeuds,
     la frappe deviendrait saccadee. */
  body.rech .fiche rect{fill-opacity:.09;stroke-opacity:.09}
  body.rech .fiche text{fill-opacity:.11}
  body.rech .fiche .br{fill-opacity:.09}
  body.rech .fam circle,body.rech .fam .barre{stroke-opacity:.07;fill-opacity:.07}
  body.rech .fam .lm{fill-opacity:.07}
  body.rech .ar{stroke-opacity:.05}
  body.rech .fiche.trouve rect{fill-opacity:1;stroke-opacity:1;
    stroke:var(--acc);stroke-width:2.6}
  body.rech .fiche.trouve text{fill-opacity:1}
  body.rech .fiche.trouve .br{fill-opacity:1}

  #q{flex:0 1 250px;min-width:150px;padding:5px 9px;border:1px solid var(--line);
    border-radius:7px;background:var(--bg);color:var(--fg);font:inherit;font-size:13px}
  #q:focus{outline:2px solid var(--acc);outline-offset:-1px}
  #cpt{font-size:12px;color:var(--acc);font-weight:600;white-space:nowrap;min-width:80px}

  /* legende */
  #leg{position:fixed;left:14px;bottom:14px;z-index:8;background:var(--pan);
    border:1px solid var(--line);border-radius:10px;padding:0;overflow:hidden;
    box-shadow:0 6px 22px rgba(0,0,0,.14);font-size:12px}
  #leg summary{cursor:pointer;padding:8px 13px;font-weight:600;list-style:none;
    color:var(--mut);user-select:none}
  #leg summary::-webkit-details-marker{display:none}
  #leg summary::before{content:"▸ ";font-size:10px}
  #leg[open] summary::before{content:"▾ "}
  #leg[open] summary{border-bottom:1px solid var(--line);color:var(--fg)}
  #leg .corps{padding:11px 13px 13px;display:grid;gap:9px}
  #leg .l{display:flex;gap:9px;align-items:center;line-height:1.35}
  #leg .l span{color:var(--mut)}
  #leg h4{margin:4px 0 0;font-size:10.5px;text-transform:uppercase;
    letter-spacing:.07em;color:var(--mut);font-weight:700}
  #leg svg{flex:0 0 auto;overflow:visible}

  /* liseré de branche : quart d'ascendance dont la personne releve */
  .br{opacity:.9}
  .b-pp{fill:#d9a066}   /* Romarin   — branche paternelle du père */
  .b-pm{fill:#4f9d8c}   /* Bourrache — branche maternelle du père */
  .b-mp{fill:#8a7bc8}   /* Chardon   — branche paternelle de la mère */
  .b-mm{fill:#7fa855}   /* Tilleul   — branche maternelle de la mère */
  .b-tr{fill:var(--mut)} /* tronc : l'ego, sa fratrie, ses parents */

  /* aretes */
  .ar{fill:none;stroke:var(--fg);stroke-opacity:.42;stroke-width:1.1}
  .ar.union{stroke-width:1.4;stroke-opacity:.5}
  .ar.divorce{stroke:var(--warn);stroke-opacity:.6;stroke-dasharray:5 4}
  /* un lien qui traverse le graphe relie deux branches eloignees : on le garde
     lisible mais discret, pour qu'il ne concurrence pas la lecture locale */
  .ar.long{stroke-opacity:.16;stroke-dasharray:2 5}
"""

FOCUS = """
  /* Mise en retrait par proprietes de peinture uniquement : `opacity` creerait
     une couche de composition par element et figerait le rendu. */
  body.focus .fiche rect{fill-opacity:.12;stroke-opacity:.12}
  body.focus .fiche text{fill-opacity:.14}
  body.focus .fam circle,body.focus .fam .barre{stroke-opacity:.1;fill-opacity:.1}
  body.focus .fam .lm{fill-opacity:.1}
  body.focus .ar{stroke-opacity:.06}
  body.focus .on rect,body.focus .on circle{fill-opacity:1;stroke-opacity:1}
  body.focus .on text{fill-opacity:1}
  body.focus .on .barre{stroke-opacity:1}
  body.focus .ar.on{stroke-opacity:.75}
  .fiche.sel rect{stroke:var(--acc)!important;stroke-width:3!important}
  .fiche.lien rect{stroke:var(--acc)!important;stroke-width:2!important}
"""

VUE = """
const VB0 = {x:%(bx).1f, y:%(by).1f, w:%(bw).1f, h:%(bh).1f};
let vb = {...VB0};
const wrap = document.getElementById('wrap'), g = document.getElementById('g');
const applyVB = () => g.setAttribute('viewBox', `${vb.x} ${vb.y} ${vb.w} ${vb.h}`);
function echelle(){ const r=g.getBoundingClientRect(); return Math.min(r.width/vb.w, r.height/vb.h); }
function versContenu(cx,cy){
  const r=g.getBoundingClientRect(), k=echelle();
  const ox=(r.width-vb.w*k)/2, oy=(r.height-vb.h*k)/2;
  return {x: vb.x+(cx-r.left-ox)/k, y: vb.y+(cy-r.top-oy)/k};
}
function fit(){ vb={...VB0}; applyVB(); }
wrap.addEventListener('wheel', ev=>{
  ev.preventDefault();
  const p=versContenu(ev.clientX,ev.clientY);
  const f=Math.exp(ev.deltaY*0.0016);
  const nw=Math.min(VB0.w*2.5, Math.max(VB0.w/500, vb.w*f)), k=nw/vb.w;
  vb={x:p.x-(p.x-vb.x)*k, y:p.y-(p.y-vb.y)*k, w:nw, h:vb.h*k};
  applyVB();
},{passive:false});
let px=0,py=0,down=false,bouge=false,pid=null;
wrap.addEventListener('pointerdown',ev=>{
  down=true;bouge=false;px=ev.clientX;py=ev.clientY;pid=ev.pointerId;
});
wrap.addEventListener('pointermove',ev=>{
  if(!down)return;
  if(!bouge){
    if(Math.abs(ev.clientX-px)+Math.abs(ev.clientY-py)<=3) return;
    bouge=true; wrap.classList.add('drag');
    try{wrap.setPointerCapture(pid)}catch(e){}
  }
  const k=echelle(); vb.x-=(ev.clientX-px)/k; vb.y-=(ev.clientY-py)/k;
  px=ev.clientX;py=ev.clientY;applyVB();
});
function fin(){
  down=false; wrap.classList.remove('drag');
  if(pid!=null){try{wrap.releasePointerCapture(pid)}catch(e){}pid=null;}
}
wrap.addEventListener('pointerup',fin);
wrap.addEventListener('pointercancel',fin);
addEventListener('keydown',ev=>{ if(ev.target.tagName==='INPUT')return; if(ev.key==='0')fit(); });
addEventListener('resize',fit); fit();
""" % {"bx": BOITE["x"], "by": BOITE["y"], "bw": BOITE["w"], "bh": BOITE["h"]}

NB = len(GENS)
NF = len(FAMS)
NDIV = sum(1 for f in FAMS.values() if f.get("a_divorce"))
NREM = sum(1 for d in GENS.values() if d.get("unions", 0) > 1)
NACTE = sum(1 for d in GENS.values() if d.get("fiable"))
NGEN = max(d["gen"] for d in GENS.values()) - min(d["gen"] for d in GENS.values()) + 1

RECH = r"""
/* ---------- recherche : surligne toutes les fiches qui contiennent le texte ---------- */
const q = document.getElementById('q'), cpt = document.getElementById('cpt');
const sa = t => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
/* L'index vient de l'attribut data-rech : il contient les prenoms complets et
   les lieux, qui ne tiennent pas sur la fiche mais doivent rester trouvables. */
const idx = [...document.querySelectorAll('.fiche')].map(el => ({
  el, t: sa(el.dataset.rech || '')
}));
let trouves = [];

function rech() {
  const v = sa(q.value.trim());
  if (!v) {
    document.body.classList.remove('rech');
    for (const o of idx) o.el.classList.remove('trouve');
    cpt.textContent = ''; trouves = []; return;
  }
  trouves = [];
  for (const o of idx) {
    const m = o.t.includes(v);
    o.el.classList.toggle('trouve', m);
    if (m) trouves.push(o.el);
  }
  document.body.classList.add('rech');
  cpt.textContent = trouves.length
    ? trouves.length + (trouves.length > 1 ? ' fiches' : ' fiche')
    : 'aucune';
}

function cadre(els) {
  let x1 = 1e9, y1 = 1e9, x2 = -1e9, y2 = -1e9;
  for (const el of els) {
    const b = el.getBBox();
    x1 = Math.min(x1, b.x); y1 = Math.min(y1, b.y);
    x2 = Math.max(x2, b.x + b.width); y2 = Math.max(y2, b.y + b.height);
  }
  const m = 240, r = wrap.getBoundingClientRect();
  let w = Math.max(x2 - x1 + 2 * m, 900);
  let h = w * (r.height / Math.max(r.width, 1));
  if (h < y2 - y1 + 2 * m) { h = y2 - y1 + 2 * m; w = h * (r.width / Math.max(r.height, 1)); }
  vb = {x: (x1 + x2) / 2 - w / 2, y: (y1 + y2) / 2 - h / 2, w, h};
  applyVB();
}

q.addEventListener('input', rech);
q.addEventListener('keydown', ev => {
  if (ev.key === 'Escape') { q.value = ''; rech(); q.blur(); }
  if (ev.key === 'Enter' && trouves.length) { ev.preventDefault(); cadre(trouves); }
});
addEventListener('keydown', ev => {
  if (ev.target === q) return;
  if (ev.key === '/') { ev.preventDefault(); q.focus(); }
  if (ev.key === 'Escape') { q.value = ''; rech(); }
});
"""

# ------------------------------------------------------------------ page 1
page1 = """<meta charset="utf-8">
<title>Arbre Romarin — réseau complet</title>
<style>%(style)s</style>
<header>
  <h1>Arbre Romarin</h1><div class="sep"></div>
  <input id="q" type="search" placeholder="Chercher un nom, une date, un lieu…"
         autocomplete="off" spellcheck="false">
  <span id="cpt"></span>
  <div class="sep"></div>
  <span class="meta">%(nb)d personnes · %(ngen)d générations ·
    %(ndiv)d divorce · %(nrem)d remariages</span>
  <span class="grow"></span>
  <span class="hint"><kbd>/</kbd>&nbsp;: chercher · <kbd>Entrée</kbd>&nbsp;: cadrer ·
    <kbd>0</kbd>&nbsp;: recadrer · molette&nbsp;: zoom</span>
</header>
<div id="wrap">%(svg)s</div>
<details id="leg">
  <summary>Légende</summary>
  <div class="corps">
    <h4>Fiches</h4>
    <div class="l"><svg width="30" height="16"><rect x="0" y="1" width="30" height="14" rx="3"
      fill="var(--h)" stroke="var(--h-b)"/></svg>homme</div>
    <div class="l"><svg width="30" height="16"><rect x="0" y="1" width="30" height="14" rx="3"
      fill="var(--f)" stroke="var(--f-b)"/></svg>femme</div>
    <div class="l"><svg width="30" height="16"><rect x="0" y="1" width="30" height="14" rx="3"
      fill="var(--h)" stroke="var(--h-b)" stroke-dasharray="4 3"/></svg>
      <span><b>bordure pointillée</b> : personne vivante</span></div>
    <div class="l"><svg width="30" height="16"><rect x="1" y="2" width="28" height="12" rx="3"
      fill="var(--h)" stroke="var(--acc)" stroke-width="2.6"/></svg>toi</div>

    <h4>Liseré de gauche — branche</h4>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="#d9a066"/></svg>Romarin</div>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="#4f9d8c"/></svg>Bourrache</div>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="#8a7bc8"/></svg>Chardon</div>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="#7fa855"/></svg>Tilleul</div>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="var(--mut)"/></svg>
      <span>tronc : toi, ta fratrie, tes parents</span></div>

    <h4>Liens</h4>
    <div class="l"><svg width="30" height="16"><circle cx="15" cy="8" r="5" fill="var(--pan)"
      stroke="var(--mut)" stroke-width="1.3"/></svg><span>union, suivie de sa date</span></div>
    <div class="l"><svg width="30" height="16"><circle cx="15" cy="8" r="5" fill="var(--pan)"
      stroke="var(--warn)" stroke-width="1.3"/><path d="M9,14 L21,2" stroke="var(--warn)"
      stroke-width="1.8"/></svg><span><b>cercle barré</b> : divorce</span></div>
    <div class="l"><svg width="30" height="16"><path d="M0,8 L30,8" stroke="var(--warn)"
      stroke-width="1.4" stroke-dasharray="5 4" opacity=".7"/></svg>
      <span>lien d’un couple divorcé</span></div>
    <div class="l"><svg width="30" height="16"><path d="M0,8 L30,8" stroke="var(--fg)"
      stroke-width="1.1" stroke-dasharray="2 5" opacity=".3"/></svg>
      <span><b>pointillé pâle</b> : lien qui traverse le graphe — un conjoint
      venu d’une branche éloignée</span></div>
  </div>
</details>
<script>%(vue)s
%(rech)s</script>
""" % {"style": STYLE, "svg": SVG, "vue": VUE, "nb": NB, "nf": NF,
       "ngen": NGEN, "nacte": NACTE, "ndiv": NDIV, "nrem": NREM,
       "rech": RECH}

open(RACINE + r"\arbre-complet.html", "w", encoding="utf-8").write(page1)

print("personnes %d · familles %d · generations %d" % (NB, NF, NGEN))
print("divorces %d · remariages %d · appuyees sur un acte %d" % (NDIV, NREM, NACTE))
print("ecrit : arbre-complet.html (%d Ko)" % (len(page1) // 1024))

# le SVG et les styles sont reutilises par build_interactif.py
json.dump({"svg": SVG, "style": STYLE, "focus": FOCUS, "vue": VUE,
           "stats": {"nb": NB, "nf": NF, "ngen": NGEN, "nacte": NACTE,
                     "ndiv": NDIV, "nrem": NREM}},
          open(RACINE + r"\rendu-parts.json", "w", encoding="utf-8"), ensure_ascii=False)
