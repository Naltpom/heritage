# -*- coding: utf-8 -*-
"""Assemble arbre-interactif.html : le SVG + fiche laterale, recherche, filtres."""
import json

RACINE = r"C:\www\myHeritage"
G = json.load(open(RACINE + r"\graphe.json", encoding="utf-8"))
PARTS = json.load(open(RACINE + r"\rendu-parts.json", encoding="utf-8"))
GENS, FAMS, EGO = G["gens"], G["familles"], G["ego"]

# ------------------------------------------------------------ parente
ORD = {1: "", 2: "arrière-", 3: "arrière-arrière-"}


def prefixe(n):
    v = ORD.get(n)
    return v if v is not None else "arrière-" * (n - 1)


def ascendants(p):
    dist, file = {p: 0}, [p]
    while file:
        x = file.pop(0)
        for q in GENS.get(x, {}).get("parents", []):
            if q not in dist:
                dist[q] = dist[x] + 1
                file.append(q)
    return dist


ASC = ascendants(EGO)


def parente_sang(p, sexe):
    if p == EGO:
        return "toi"
    ap = ascendants(p)
    comm = [(ASC[a] + ap[a], ASC[a], ap[a]) for a in ASC if a in ap]
    if not comm:
        return None
    _, dE, dP = min(comm)
    H = sexe == "M"
    e = "" if H else "e"
    if dP == 0:
        if dE == 1:
            return "père" if H else "mère"
        if dE == 2:
            return "grand-père" if H else "grand-mère"
        return prefixe(dE - 1) + ("grand-père" if H else "grand-mère")
    if dE == 0:
        if dP == 1:
            return "fils" if H else "fille"
        return prefixe(dP - 1) + ("petit-fils" if H else "petite-fille")
    if dE == 1 and dP == 1:
        return "frère" if H else "sœur"
    if dE == 1:
        if dP == 2:
            return "neveu" if H else "nièce"
        return prefixe(dP - 2) + ("petit-neveu" if H else "petite-nièce")
    if dP == 1:
        if dE == 2:
            return "oncle" if H else "tante"
        return prefixe(dE - 2) + ("grand-oncle" if H else "grand-tante")
    k, ecart = min(dE, dP), abs(dE - dP)
    base = "cousin" if H else "cousine"
    lib = (base + " germain" + e if k == 2
           else base + " issu" + e + " de germain" if k == 3
           else base + " au " + str(k - 1) + "ᵉ degré")
    if ecart:
        lib += ", éloigné" + e + " de " + str(ecart) + " génération" + ("s" if ecart > 1 else "")
    return lib


sang = {}
for k, d in GENS.items():
    sang[k] = parente_sang(k, d["sexe"])
for k, d in GENS.items():
    if sang[k]:
        d["parente"] = sang[k]
        continue
    lien = next((sang[c] for c in d["conjoints"] if sang.get(c) and sang[c] != "toi"), None)
    d["parente"] = (("époux" if d["sexe"] == "M" else "épouse") + " de ton " + lien
                    if lien else "relié par alliance")

# unions detaillees, pour la fiche
for k, d in GENS.items():
    d["unions_detail"] = []
for fk, fd in FAMS.items():
    for p in fd["epoux"]:
        if p in GENS:
            autre = [q for q in fd["epoux"] if q != p]
            GENS[p]["unions_detail"].append({
                "avec": autre[0] if autre else None,
                "mariage": fd["mariage"], "lieu": fd["lieu_mariage"],
                "divorce": fd["divorce"], "a_divorce": fd["a_divorce"],
                "enfants": [c for c in fd["enfants"] if c in GENS],
            })

DATA = json.dumps({"gens": GENS, "familles": FAMS, "ego": EGO},
                  ensure_ascii=False, separators=(",", ":"))

S = PARTS["stats"]

PANNEAU = """
  aside{position:fixed;top:46px;right:0;bottom:0;width:340px;max-width:88vw;z-index:9;
    background:var(--pan);border-left:1px solid var(--line);padding:18px 18px 30px;
    overflow-y:auto;transform:translateX(102%);transition:transform .2s ease}
  aside.open{transform:none}
  aside h2{margin:0 0 2px;font-size:17px;line-height:1.25;letter-spacing:-.015em}
  .rel{display:inline-block;margin:6px 0 14px;padding:3px 9px;border-radius:99px;
    background:var(--acc-soft);color:var(--acc);font-size:12px;font-weight:640}
  dl{margin:0 0 14px;display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:13px}
  dt{color:var(--mut)} dd{margin:0}
  h3{margin:16px 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--mut)}
  ul{margin:0;padding:0;list-style:none;font-size:13px} li{padding:2px 0}
  .lk{background:none;border:0;padding:0;font:inherit;color:var(--acc);cursor:pointer;text-align:left}
  .lk:hover{text-decoration:underline}
  .an{color:var(--mut);font-size:12px}
  .flag{margin:12px 0 0;padding:9px 11px;border-radius:8px;font-size:12px;line-height:1.45}
  .flag.bad{background:color-mix(in srgb,var(--warn) 13%,transparent);
    border:1px solid color-mix(in srgb,var(--warn) 35%,transparent)}
  .flag.good{background:color-mix(in srgb,var(--ok) 13%,transparent);
    border:1px solid color-mix(in srgb,var(--ok) 35%,transparent)}
  .src{border-left:3px solid var(--line);padding:2px 0 2px 10px;margin:9px 0;font-size:12px}
  .src.q2,.src.q3{border-color:var(--ok)}
  .src.q1{border-color:var(--acc)}
  .src.q0{border-color:var(--warn)}
  .src .st{font-weight:620;line-height:1.3}
  .src .sp{color:var(--mut);margin-top:2px;line-height:1.4;word-break:break-word}
  .src .sq{display:inline-block;margin-top:4px;padding:1px 7px;border-radius:99px;
    font-size:10.5px;font-weight:650;background:var(--acc-soft);color:var(--acc)}
  .src.q2 .sq,.src.q3 .sq{background:color-mix(in srgb,var(--ok) 18%,transparent);color:var(--ok)}
  .src.q0 .sq{background:color-mix(in srgb,var(--warn) 16%,transparent);color:var(--warn)}
  .src a{color:var(--acc);word-break:break-all}
  .src img{display:block;width:100%;margin-top:7px;border-radius:6px;
    border:1px solid var(--line);cursor:zoom-in;background:#fff}
  #loupe{position:fixed;inset:0;z-index:50;background:rgba(0,0,0,.86);display:none;
    align-items:center;justify-content:center;cursor:zoom-out;padding:20px}
  #loupe.on{display:flex}
  #loupe img{max-width:100%;max-height:100%;border-radius:8px;background:#fff}
  .union{border-left:2px solid var(--line);padding-left:10px;margin:8px 0}
  .union.div{border-color:var(--warn)}
  .x{position:absolute;top:12px;right:14px;border:0;background:none;color:var(--mut);
    font-size:20px;line-height:1;cursor:pointer;padding:2px 6px}
  .x:hover{color:var(--fg)}
  #q{flex:0 1 250px;min-width:140px;padding:5px 9px;border:1px solid var(--line);
    border-radius:7px;background:var(--bg);color:var(--fg);font:inherit;font-size:13px}
  #q:focus{outline:2px solid var(--acc);outline-offset:-1px}
  .chip{font:inherit;font-size:12px;padding:4px 10px;border:1px solid var(--line);
    border-radius:99px;background:var(--bg);color:var(--mut);cursor:pointer;white-space:nowrap}
  .chip:hover{border-color:var(--acc)}
  .chip[aria-pressed="true"]{background:var(--acc-soft);border-color:var(--acc);
    color:var(--fg);font-weight:600}
  #res{position:fixed;top:44px;z-index:11;background:var(--pan);border:1px solid var(--line);
    border-radius:9px;box-shadow:0 8px 26px rgba(0,0,0,.18);max-height:54vh;overflow-y:auto;
    min-width:250px;padding:5px;display:none}
  #res button{display:block;width:100%;text-align:left;padding:6px 9px;border:0;
    border-radius:6px;background:none;color:var(--fg);font:inherit;font-size:13px;cursor:pointer}
  #res button:hover,#res button.k{background:var(--acc-soft)}
  #res small{color:var(--mut)}
  #cpt{font-size:12px;color:var(--acc);font-weight:600;white-space:nowrap;min-width:74px}
  /* surlignage par proprietes de peinture : `opacity` creerait une couche de
     composition par fiche et rendrait la frappe saccadee a 280 noeuds */
  body.rech .fiche rect{fill-opacity:.09;stroke-opacity:.09}
  body.rech .fiche text{fill-opacity:.11}
  body.rech .fiche .br{fill-opacity:.09}
  body.rech .fam circle,body.rech .fam .barre{stroke-opacity:.07;fill-opacity:.07}
  body.rech .fam .lm{fill-opacity:.07}
  body.rech .ar{stroke-opacity:.05}
  body.rech .fiche.trouve rect{fill-opacity:1;stroke-opacity:1;stroke:var(--acc);stroke-width:2.6}
  body.rech .fiche.trouve text{fill-opacity:1}
  body.rech .fiche.trouve .br{fill-opacity:1}

  #leg{position:fixed;left:14px;bottom:14px;z-index:8;background:var(--pan);
    border:1px solid var(--line);border-radius:10px;overflow:hidden;
    box-shadow:0 6px 22px rgba(0,0,0,.14);font-size:12px;max-width:280px}
  #leg summary{cursor:pointer;padding:8px 13px;font-weight:600;list-style:none;color:var(--mut)}
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
  @media (max-width:760px){.hint{display:none} aside{width:100%} #leg{display:none}}
"""

JS = r"""
const D = __DATA__, GENS = D.gens, FAMS = D.familles, EGO = D.ego;
const X = id => '@' + id + '@';
const nodeEl = {}, famEl = {}, arEls = [];
document.querySelectorAll('.fiche').forEach(el => nodeEl[X(el.dataset.id)] = el);
document.querySelectorAll('.fam').forEach(el => famEl[X(el.dataset.id)] = el);
document.querySelectorAll('.ar').forEach(el =>
  arEls.push({el, a: X(el.dataset.de), b: X(el.dataset.vers)}));

const voisins = id => {
  const p = GENS[id]; if (!p) return [];
  return [...p.parents, ...p.enfants, ...p.conjoints];
};
function chemin(de, vers) {
  if (de === vers) return [de];
  const prec = {[de]: null}, file = [de];
  while (file.length) {
    const x = file.shift();
    for (const y of voisins(x)) {
      if (y in prec) continue;
      prec[y] = x;
      if (y === vers) { const o = []; let c = y; while (c != null) { o.push(c); c = prec[c]; } return o.reverse(); }
      file.push(y);
    }
  }
  return [];
}

const panel = document.getElementById('panel');
const nom = p => ((p.usage || p.prenom).split(' ')[0] + ' ' + p.nom).trim() || 'sans nom';
const complet = p => ((p.prenom === '?' ? '' : p.prenom) + ' ' + p.nom).trim() || 'Sans nom';
const esc = t => String(t ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

function eclairer(garder, sel, ch) {
  const K = new Set(garder);
  document.body.classList.add('focus');
  for (const [id, el] of Object.entries(nodeEl)) {
    el.classList.toggle('on', K.has(id));
    el.classList.toggle('sel', id === sel);
    el.classList.toggle('lien', !!ch && ch.has(id) && id !== sel);
  }
  for (const [id, el] of Object.entries(famEl)) el.classList.toggle('on', K.has(id));
  for (const {el, a, b} of arEls) el.classList.toggle('on', K.has(a) && K.has(b));
}
function tout() {
  document.body.classList.remove('focus');
  for (const el of [...Object.values(nodeEl), ...Object.values(famEl)])
    el.classList.remove('on', 'sel', 'lien');
  for (const {el} of arEls) el.classList.remove('on');
  panel.classList.remove('open');
  for (const b of CHIPS) b.setAttribute('aria-pressed', 'false');
}
function centrer(id, largeur) {
  const el = nodeEl[id]; if (!el) return;
  const b = el.getBBox(), r = wrap.getBoundingClientRect();
  const w = Math.min(largeur || 1700, VB0.w);
  const h = w * (r.height / Math.max(r.width, 1));
  const biais = r.width > 760 ? (340 / r.width) * w / 2 : 0;
  vb = {x: b.x + b.width / 2 - w / 2 + biais, y: b.y + b.height / 2 - h / 2, w, h};
  applyVB();
}

function liste(titre, ids) {
  if (!ids || !ids.length) return '';
  return '<h3>' + titre + '</h3><ul>' + ids.map(i => {
    const q = GENS[i]; if (!q) return '';
    return '<li><button class="lk" data-go="' + i.replace(/@/g, '') + '">' + esc(nom(q)) +
      '</button> <span class="an">' + esc(q.ligne_dates) + '</span></li>';
  }).join('') + '</ul>';
}

function ouvrir(id) {
  const p = GENS[id]; if (!p) return;
  if (document.body.classList.contains('rech')) {
    q.value = ''; document.body.classList.remove('rech');
    for (const o of idx) o.el.classList.remove('trouve');
    cpt.textContent = '';
  }
  document.getElementById('p-nom').textContent = complet(p);
  document.getElementById('p-rel').textContent = p.parente;

  const L = [];
  if (p.nais || p.lieu_nais) L.push(['Naissance', (p.nais || '?') + (p.lieu_nais ? ' — ' + p.lieu_nais : '')]);
  if (p.deces || p.lieu_deces) L.push(['Décès', (p.deces || '?') + (p.lieu_deces ? ' — ' + p.lieu_deces : '')]);
  // Une date approchee (« BET … AND … », « ABT ») donne une fourchette d'age :
  // l'afficher comme un nombre unique serait une affirmation que la source ne
  // porte pas. On montre alors l'intervalle, et rien du tout s'il est trop large.
  if (p.an_nais && p.an_deces) {
    const b = p.an_deces - p.an_nais_max || p.an_deces - p.an_nais;
    const h = (p.an_deces_max || p.an_deces) - p.an_nais;
    if (b === h) L.push(['Âge', b + ' ans']);
    else if (h - b <= 30) L.push(['Âge', 'entre ' + b + ' et ' + h + ' ans']);
  }
  L.push(['Sexe', p.sexe === 'M' ? 'Homme' : p.sexe === 'F' ? 'Femme' : 'Inconnu']);
  document.getElementById('p-dl').innerHTML =
    L.map(([k, v]) => '<dt>' + k + '</dt><dd>' + esc(v) + '</dd>').join('');

  let f = '';
  if (p.prive) f = '<div class="flag">Personne vivante — fiche à diffusion restreinte.</div>';
  else if (p.sans_source) f = '<div class="flag bad"><strong>Aucune source.</strong> Rien n’appuie ces informations.</div>';
  else if (!p.fiable) f = '<div class="flag bad"><strong>Non vérifié.</strong> Recopies d’arbres, pas d’acte.</div>';
  else f = '<div class="flag good"><strong>Appuyé sur un acte.</strong></div>';
  document.getElementById('p-flag').innerHTML = f;

  let u = '';
  if (p.unions_detail && p.unions_detail.length) {
    u = '<h3>Union' + (p.unions_detail.length > 1 ? 's — ' + p.unions_detail.length : '') + '</h3>';
    for (const un of p.unions_detail) {
      const q = un.avec ? GENS[un.avec] : null;
      u += '<div class="union' + (un.a_divorce ? ' div' : '') + '">';
      u += q ? '<button class="lk" data-go="' + un.avec.replace(/@/g, '') + '">' + esc(nom(q)) + '</button>'
             : '<span class="an">conjoint inconnu</span>';
      if (un.mariage) u += '<div class="an">⚭ ' + esc(un.mariage) + (un.lieu ? ' — ' + esc(un.lieu) : '') + '</div>';
      if (un.a_divorce) u += '<div class="an" style="color:var(--warn)">divorce' + (un.divorce ? ' ' + esc(un.divorce) : '') + '</div>';
      if (un.enfants.length) u += '<div class="an">' + un.enfants.length + ' enfant' + (un.enfants.length > 1 ? 's' : '') + '</div>';
      u += '</div>';
    }
  }
  let src = '';
  const cits = (p.citations || []).slice().sort((a, b) => (b.quay ?? -1) - (a.quay ?? -1));
  if (cits.length) {
    src = '<h3>Source' + (cits.length > 1 ? 's — ' + cits.length : '') + '</h3>';
    for (const c of cits) {
      src += '<div class="src q' + (c.quay ?? 'x') + '">';
      src += '<div class="st">' + esc(c.titre) + '</div>';
      if (c.auteur) src += '<div class="sp">' + esc(c.auteur) + '</div>';
      if (c.page) src += '<div class="sp">' + esc(c.page) + '</div>';
      else if (c.texte) src += '<div class="sp">' + esc(c.texte) + '</div>';
      if (c.quay_lbl) src += '<span class="sq">' + esc(c.quay_lbl) + '</span>';
      if (c.url) src += '<div class="sp"><a href="' + esc(c.url) +
        '" target="_blank" rel="noopener">consulter en ligne ↗</a></div>';
      for (const im of (c.images || []))
        src += '<img loading="lazy" decoding="async" src="' + esc(vign(im)) +
               '" data-plein="' + esc(im) + '" alt="image d’archive">';
      src += '</div>';
    }
  }
  document.getElementById('p-fam').innerHTML =
    u + liste('Parents', p.parents) + liste('Enfants', p.enfants)
      + liste('Frères et sœurs', p.fratrie) + src;
  panel.classList.add('open');

  const ch = chemin(id, EGO);
  const garder = new Set([id, ...voisins(id), ...p.fratrie, ...ch]);
  for (const fk in FAMS) {
    const fd = FAMS[fk];
    if ([...fd.epoux, ...fd.enfants].some(m => garder.has(m))) garder.add(fk);
  }
  eclairer(garder, id, new Set(ch));
  centrer(id);
}
// L'image d'archive pese jusqu'a 3 000 px de large : on affiche la vignette et
// on ne decode l'original qu'a la loupe, une seule a la fois.
const vign = u => 'vignettes/' + u.replace(/^recherche\//, '').replace(/\.(png|jpe?g)$/i, '.jpg');

document.getElementById('p-fam').addEventListener('click', ev => {
  const b = ev.target.closest('[data-go]'); if (b) ouvrir(X(b.dataset.go));
});
document.getElementById('close').addEventListener('click', tout);
const loupe = document.getElementById('loupe');
document.getElementById('p-fam').addEventListener('click', ev => {
  if (ev.target.tagName === 'IMG') {
    loupe.firstElementChild.src = ev.target.dataset.plein || ev.target.src;
    loupe.classList.add('on');
  }
});
loupe.addEventListener('click', () => loupe.classList.remove('on'));
g.addEventListener('click', ev => {
  if (bouge) return;
  const n = ev.target.closest('.fiche'); if (n) ouvrir(X(n.dataset.id));
});

const fD = document.getElementById('f-doute'), fN = document.getElementById('f-nosrc'),
      fV = document.getElementById('f-vivant'), fO = document.getElementById('f-ok'),
      fI = document.getElementById('f-img');
const CHIPS = [fO, fD, fN, fI, fV];
function filtrer(btn, test) {
  const actif = btn.getAttribute('aria-pressed') !== 'true';
  for (const b of CHIPS) b.setAttribute('aria-pressed', 'false');
  panel.classList.remove('open');
  if (!actif) { tout(); return; }
  btn.setAttribute('aria-pressed', 'true');
  eclairer(Object.keys(GENS).filter(i => test(GENS[i])), null, null);
}
fO.addEventListener('click', () => filtrer(fO, p => p.fiable));
fI.addEventListener('click', () => filtrer(fI, p => (p.citations || []).some(c => c.images && c.images.length)));
fD.addEventListener('click', () => filtrer(fD, p => !p.fiable && !p.sans_source && !p.prive));
fN.addEventListener('click', () => filtrer(fN, p => p.sans_source));
fV.addEventListener('click', () => filtrer(fV, p => p.prive));
document.getElementById('reset').addEventListener('click', () => { tout(); fit(); });

const q = document.getElementById('q'), cpt = document.getElementById('cpt');
const sa = t => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
/* L'index vient de data-rech : il porte les prenoms complets et les lieux,
   qui ne tiennent pas sur la fiche mais doivent rester trouvables. */
const idx = [...document.querySelectorAll('.fiche')].map(el => ({el, t: sa(el.dataset.rech || '')}));
let trouves = [];

function rech() {
  const v = sa(q.value.trim());
  if (!v) {
    document.body.classList.remove('rech');
    for (const o of idx) o.el.classList.remove('trouve');
    cpt.textContent = ''; trouves = []; return;
  }
  tout();                       /* une recherche annule la selection en cours */
  trouves = [];
  for (const o of idx) {
    const m = o.t.includes(v);
    o.el.classList.toggle('trouve', m);
    if (m) trouves.push(o.el);
  }
  document.body.classList.add('rech');
  cpt.textContent = trouves.length
    ? trouves.length + (trouves.length > 1 ? ' fiches' : ' fiche') : 'aucune';
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
  if (ev.key === 'Escape') { q.value = ''; rech(); tout(); }
  if (ev.key === '/') { ev.preventDefault(); q.focus(); }
  if (ev.key === '0') fit();
});
"""

page = """<meta charset="utf-8">
<title>Arbre Romarin — vue interactive</title>
<style>%(style)s%(focus)s%(panneau)s</style>
<header>
  <h1>Arbre Romarin</h1><div class="sep"></div>
  <input id="q" type="search" placeholder="Chercher un nom, une date, un lieu…" autocomplete="off" spellcheck="false">
  <button class="chip" id="f-ok" aria-pressed="false" title="Au moins une information appuyée sur un acte ou un registre">Vérifiés</button>
  <button class="chip" id="f-doute" aria-pressed="false" title="Des sources, mais aucune qui soit un acte">Non vérifiés</button>
  <button class="chip" id="f-nosrc" aria-pressed="false" title="Aucune citation de source">Sans source</button>
  <button class="chip" id="f-img" aria-pressed="false" title="Une image d’archive est jointe à la fiche">Avec image</button>
  <button class="chip" id="f-vivant" aria-pressed="false" title="Fiches à diffusion restreinte">Vivants</button>
  <button class="chip" id="reset">Tout afficher</button>
  <span id="cpt"></span>
  <span class="grow"></span>
  <span class="hint">%(nb)d personnes · %(ngen)d générations · <b>clic sur une fiche</b> ·
    <kbd>/</kbd>&nbsp;chercher · <kbd>Entrée</kbd>&nbsp;cadrer · <kbd>0</kbd>&nbsp;recadrer</span>
</header>
<div id="wrap">%(svg)s</div>
<details id="leg">
  <summary>Légende</summary>
  <div class="corps">
    <h4>Fiches</h4>
    <div class="l"><svg width="30" height="16"><rect x="0" y="1" width="30" height="14" rx="3" fill="var(--h)" stroke="var(--h-b)"/></svg>homme</div>
    <div class="l"><svg width="30" height="16"><rect x="0" y="1" width="30" height="14" rx="3" fill="var(--f)" stroke="var(--f-b)"/></svg>femme</div>
    <div class="l"><svg width="30" height="16"><rect x="0" y="1" width="30" height="14" rx="3" fill="var(--h)" stroke="var(--h-b)" stroke-dasharray="4 3"/></svg><span><b>pointillé</b> : personne vivante</span></div>
    <div class="l"><svg width="30" height="16"><rect x="1" y="2" width="28" height="12" rx="3" fill="var(--h)" stroke="var(--acc)" stroke-width="2.6"/></svg>toi</div>
    <h4>Liseré de gauche — branche</h4>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="#d9a066"/></svg>Romarin</div>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="#4f9d8c"/></svg>Bourrache</div>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="#8a7bc8"/></svg>Chardon</div>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="#7fa855"/></svg>Tilleul</div>
    <div class="l"><svg width="30" height="16"><rect x="12" y="1" width="4" height="14" fill="var(--mut)"/></svg><span>tronc : toi, ta fratrie, tes parents</span></div>
    <h4>Liens</h4>
    <div class="l"><svg width="30" height="16"><circle cx="15" cy="8" r="5" fill="var(--pan)" stroke="var(--mut)" stroke-width="1.3"/></svg><span>union, suivie de sa date</span></div>
    <div class="l"><svg width="30" height="16"><circle cx="15" cy="8" r="5" fill="var(--pan)" stroke="var(--warn)" stroke-width="1.3"/><path d="M9,14 L21,2" stroke="var(--warn)" stroke-width="1.8"/></svg><span><b>cercle barré</b> : divorce</span></div>
    <div class="l"><svg width="30" height="16"><path d="M0,8 L30,8" stroke="var(--fg)" stroke-width="1.1" stroke-dasharray="2 5" opacity=".3"/></svg><span><b>pointillé pâle</b> : lien traversant — conjoint d’une branche éloignée</span></div>
  </div>
</details>
<div id="loupe"><img alt=""></div>
<aside id="panel">
  <button class="x" id="close" aria-label="Fermer">&times;</button>
  <h2 id="p-nom"></h2><span class="rel" id="p-rel"></span>
  <dl id="p-dl"></dl><div id="p-flag"></div><div id="p-fam"></div>
</aside>
<script>%(vue)s
%(js)s</script>
""" % {"style": PARTS["style"], "focus": PARTS["focus"], "panneau": PANNEAU,
       "svg": PARTS["svg"], "vue": PARTS["vue"], "js": JS.replace("__DATA__", DATA),
       "nb": S["nb"], "ngen": S["ngen"]}

open(RACINE + r"\arbre.html", "w", encoding="utf-8").write(page)
print("ecrit : arbre.html (%d Ko)" % (len(page) // 1024))

# Il n'y a plus qu'une page. Les deux anciens noms y renvoient, pour ne pas
# laisser trainer des copies perimees qu'on rouvrirait par habitude.
REDIR = ('<meta charset="utf-8"><title>Arbre Romarin</title>'
         '<meta http-equiv="refresh" content="0; url=arbre.html">'
         '<p style="font:15px system-ui;padding:2rem">Les deux vues ont fusionné. '
         '<a href="arbre.html">Ouvrir l’arbre</a>.</p>')
for vieux_nom in ("arbre-complet.html", "arbre-interactif.html"):
    open(RACINE + "\\" + vieux_nom, "w", encoding="utf-8").write(REDIR)
print("arbre-complet.html et arbre-interactif.html renvoient desormais vers arbre.html")

import collections
c = collections.Counter(d["parente"].split(",")[0] for d in GENS.values())
print("\nLiens de parente les plus frequents :")
for lib, n in c.most_common(8):
    print("   %3d  %s" % (n, lib))
