# -*- coding: utf-8 -*-
"""Assemble le visualiseur interactif : SVG graphviz + donnees GEDCOM."""
import re

SVG = open(r"C:\www\myHeritage\arbre-complet.svg", encoding="utf-8").read()
DATA = open(r"C:\www\myHeritage\arbre-data.json", encoding="utf-8").read()
OUT = r"C:\www\myHeritage\arbre-interactif.html"

svg = re.sub(r"^.*?(?=<svg)", "", SVG, flags=re.S)
m = re.search(r'viewBox="([-\d.]+) ([-\d.]+) ([-\d.]+) ([-\d.]+)"', svg)
if not m:
    raise SystemExit("viewBox introuvable dans le SVG")
VBX, VBY, VBW, VBH = (float(v) for v in m.groups())
svg = re.sub(r'<svg[^>]*?width="[^"]*"', "<svg", svg, count=1)
svg = re.sub(r'(<svg[^>]*?)height="[^"]*"', r"\1", svg, count=1)
svg = svg.replace("<svg", '<svg id="g" preserveAspectRatio="xMidYMid meet"', 1)

TPL = r"""<meta charset="utf-8">
<title>Arbre Romarin — vue interactive</title>
<style>
  :root{
    --bg:#f4f2ef; --fg:#1b1b1b; --pan:#fffefc; --line:#dcd7ce; --mut:#6b6560;
    --acc:#8a5a2b; --acc-soft:#f0e3d4; --warn:#a8501e; --ok:#3f6b4a;
  }
  @media (prefers-color-scheme:dark){
    :root{--bg:#141517; --fg:#e8e6e2; --pan:#1d1f22; --line:#33363b; --mut:#9a948c;
          --acc:#d9a066; --acc-soft:#3a2c1d; --warn:#e08a5a; --ok:#7db38c;}
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{margin:0;background:var(--bg);color:var(--fg);overflow:hidden;
    font:14px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}

  header{position:fixed;inset:0 0 auto 0;z-index:10;display:flex;gap:12px;align-items:center;
    flex-wrap:wrap;padding:9px 14px;background:var(--pan);border-bottom:1px solid var(--line)}
  h1{margin:0;font-size:14px;font-weight:640;letter-spacing:-.01em;white-space:nowrap}
  .sep{width:1px;height:20px;background:var(--line)}
  #q{flex:0 1 260px;min-width:150px;padding:5px 9px;border:1px solid var(--line);border-radius:7px;
    background:var(--bg);color:var(--fg);font:inherit;font-size:13px}
  #q:focus{outline:2px solid var(--acc);outline-offset:-1px}
  .chip{font:inherit;font-size:12px;padding:4px 10px;border:1px solid var(--line);border-radius:99px;
    background:var(--bg);color:var(--mut);cursor:pointer;white-space:nowrap}
  .chip:hover{border-color:var(--acc)}
  .chip[aria-pressed="true"]{background:var(--acc-soft);border-color:var(--acc);color:var(--fg);font-weight:600}
  .grow{flex:1 1 auto}
  .hint{font-size:12px;color:var(--mut);white-space:nowrap}
  kbd{font:inherit;font-size:11px;border:1px solid var(--line);border-radius:4px;padding:0 4px}

  #wrap{position:absolute;inset:46px 0 0 0;overflow:hidden;cursor:grab;touch-action:none}
  #wrap.drag{cursor:grabbing}
  #g{display:block;width:100%;height:100%}
  /* JAMAIS de `opacity` sur un element SVG ici : la propriete cree un contexte
     d'empilement, donc une couche de composition par arete. Avec 228 aretes
     dont certaines traversent les 7800px du graphe, Chrome allouait autant de
     grandes textures et le moindre changement de style gelait l'onglet
     plusieurs dizaines de secondes. `stroke-opacity` / `fill-opacity` sont des
     proprietes de peinture : aucune couche, un simple repaint. */
  #g .edge path{stroke:var(--fg);stroke-opacity:.45}
  #g .edge polygon{fill:var(--fg);stroke:var(--fg);fill-opacity:.45;stroke-opacity:.45}
  #g .node{cursor:pointer}
  /* mise en retrait quand une selection ou un filtre est actif */
  body.focus #g .node polygon,body.focus #g .node ellipse{fill-opacity:.13;stroke-opacity:.13}
  body.focus #g .node text{fill-opacity:.15}
  body.focus #g .edge path{stroke-opacity:.07}
  body.focus #g .edge polygon{fill-opacity:.07;stroke-opacity:.07}
  body.focus #g .on polygon,body.focus #g .on ellipse{fill-opacity:1;stroke-opacity:1}
  body.focus #g .on text{fill-opacity:1}
  body.focus #g .edge.on path{stroke-opacity:.8}
  body.focus #g .edge.on polygon{fill-opacity:.8;stroke-opacity:.8}
  #g .sel polygon,#g .sel ellipse{stroke:var(--acc)!important;stroke-width:3.5!important}
  #g .lien polygon,#g .lien ellipse{stroke:var(--acc)!important;stroke-width:2!important}

  aside{position:fixed;top:46px;right:0;bottom:0;width:330px;max-width:86vw;z-index:9;
    background:var(--pan);border-left:1px solid var(--line);padding:18px 18px 28px;
    overflow-y:auto;transform:translateX(102%);transition:transform .2s ease}
  aside.open{transform:none}
  aside h2{margin:0 0 2px;font-size:17px;line-height:1.25;letter-spacing:-.015em}
  .rel{display:inline-block;margin:6px 0 14px;padding:3px 9px;border-radius:99px;
    background:var(--acc-soft);color:var(--acc);font-size:12px;font-weight:640}
  dl{margin:0 0 16px;display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:13px}
  dt{color:var(--mut)}
  dd{margin:0}
  h3{margin:16px 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--mut)}
  ul{margin:0;padding:0;list-style:none;font-size:13px}
  li{padding:2px 0}
  .lk{background:none;border:0;padding:0;font:inherit;color:var(--acc);cursor:pointer;text-align:left}
  .lk:hover{text-decoration:underline}
  .flag{margin:14px 0 0;padding:9px 11px;border-radius:8px;font-size:12px;line-height:1.45}
  .flag.bad{background:color-mix(in srgb,var(--warn) 13%,transparent);border:1px solid color-mix(in srgb,var(--warn) 35%,transparent)}
  .flag.good{background:color-mix(in srgb,var(--ok) 13%,transparent);border:1px solid color-mix(in srgb,var(--ok) 35%,transparent)}
  .src{font-size:12px;color:var(--mut);margin-top:5px}
  .x{position:absolute;top:12px;right:14px;border:0;background:none;color:var(--mut);
    font-size:20px;line-height:1;cursor:pointer;padding:2px 6px}
  .x:hover{color:var(--fg)}
  #res{position:fixed;top:44px;z-index:11;background:var(--pan);border:1px solid var(--line);
    border-radius:9px;box-shadow:0 8px 26px rgba(0,0,0,.16);max-height:52vh;overflow-y:auto;
    min-width:250px;padding:5px;display:none}
  #res button{display:block;width:100%;text-align:left;padding:6px 9px;border:0;border-radius:6px;
    background:none;color:var(--fg);font:inherit;font-size:13px;cursor:pointer}
  #res button:hover,#res button.k{background:var(--acc-soft)}
  #res small{color:var(--mut)}
  @media (max-width:720px){ .hint{display:none} aside{width:100%} }
</style>

<header>
  <h1>Arbre Romarin</h1>
  <div class="sep"></div>
  <input id="q" type="search" placeholder="Chercher un nom…" autocomplete="off" spellcheck="false">
  <button class="chip" id="f-doute" aria-pressed="false" title="Personnes dont aucune information ne repose sur un acte">Non vérifiés</button>
  <button class="chip" id="f-nosrc" aria-pressed="false" title="Personnes sans la moindre citation de source">Sans source</button>
  <button class="chip" id="reset">Tout afficher</button>
  <span class="grow"></span>
  <span class="hint">molette&nbsp;: zoom · glisser&nbsp;: déplacer · <kbd>0</kbd>&nbsp;: recadrer · <kbd>Échap</kbd>&nbsp;: fermer</span>
</header>

<div id="wrap">__SVG__</div>
<div id="res"></div>

<aside id="panel">
  <button class="x" id="close" aria-label="Fermer">&times;</button>
  <h2 id="p-nom"></h2>
  <span class="rel" id="p-rel"></span>
  <dl id="p-dl"></dl>
  <div id="p-flag"></div>
  <div id="p-fam"></div>
</aside>

<script>
const D = __DATA__;
const GENS = D.gens, FAMS = D.familles, EGO = D.ego;

/* ---------- index SVG <-> GEDCOM ---------- */
const wrap = document.getElementById('wrap'), g = document.getElementById('g');
const nodeEl = {}, edgeEls = [];
for (const el of g.querySelectorAll('g.node')) {
  const t = el.querySelector('title');
  if (t) { nodeEl[t.textContent.trim()] = el; el.dataset.id = t.textContent.trim(); }
}
for (const el of g.querySelectorAll('g.edge')) {
  const t = el.querySelector('title');
  if (!t) continue;
  const [a, b] = t.textContent.trim().split('->');
  edgeEls.push({ el, a, b });
}

/* ---------- graphe de parente (personne <-> famille) ---------- */
const voisins = id => {
  const p = GENS[id]; if (!p) return [];
  return [...p.parents, ...p.enfants, ...p.conjoints];
};
function chemin(de, vers) {                       // BFS, pour relier a l'ego
  if (de === vers) return [de];
  const prec = { [de]: null }, file = [de];
  while (file.length) {
    const x = file.shift();
    for (const y of voisins(x)) {
      if (y in prec) continue;
      prec[y] = x;
      if (y === vers) { const out = []; let c = y; while (c != null) { out.push(c); c = prec[c]; } return out.reverse(); }
      file.push(y);
    }
  }
  return [];
}

/* ---------- vue : zoom/deplacement par viewBox ----------
   Surtout PAS de `transform: scale()` sur le SVG : Chrome rasterise alors
   l'integralite des 7815x1854 px a l'echelle demandee, ce qui gelait le rendu
   pendant des dizaines de secondes. En bougeant le viewBox, le navigateur ne
   dessine que ce qui est reellement visible. */
const VB0 = { x: __VBX__, y: __VBY__, w: __VBW__, h: __VBH__ };
let vb = { ...VB0 };
const applyVB = () => g.setAttribute('viewBox', `${vb.x} ${vb.y} ${vb.w} ${vb.h}`);

function echelle() {                       // px ecran par unite de contenu
  const r = g.getBoundingClientRect();
  return Math.min(r.width / vb.w, r.height / vb.h);
}
function versContenu(clientX, clientY) {
  const r = g.getBoundingClientRect(), k = echelle();
  const offX = (r.width - vb.w * k) / 2, offY = (r.height - vb.h * k) / 2;
  return { x: vb.x + (clientX - r.left - offX) / k, y: vb.y + (clientY - r.top - offY) / k };
}
function fit() { vb = { ...VB0 }; applyVB(); }

function centrer(id, largeurCible) {
  const el = nodeEl[id]; if (!el) return;
  const b = el.getBoundingClientRect();
  const c = versContenu(b.left + b.width / 2, b.top + b.height / 2);
  const r = wrap.getBoundingClientRect();
  const w = Math.min(largeurCible || 1500, VB0.w);
  const h = w * (r.height / Math.max(r.width, 1));
  // le panneau lateral masque la droite : on decale le centre vers la gauche
  const biais = r.width > 720 ? (330 / r.width) * w / 2 : 0;
  vb = { x: c.x - w / 2 + biais, y: c.y - h / 2, w, h };
  applyVB();
}

wrap.addEventListener('wheel', e => {
  e.preventDefault();
  const p = versContenu(e.clientX, e.clientY);
  const f = Math.exp(e.deltaY * 0.0016);
  const nw = Math.min(VB0.w * 3, Math.max(VB0.w / 400, vb.w * f));
  const ratio = nw / vb.w;
  vb = { x: p.x - (p.x - vb.x) * ratio, y: p.y - (p.y - vb.y) * ratio,
         w: nw, h: vb.h * ratio };
  applyVB();
}, { passive: false });

let px = 0, py = 0, down = false, bouge = false;
wrap.addEventListener('pointerdown', e => {
  down = true; bouge = false; px = e.clientX; py = e.clientY;
  wrap.classList.add('drag'); wrap.setPointerCapture(e.pointerId);
});
wrap.addEventListener('pointermove', e => {
  if (!down) return;
  if (Math.abs(e.clientX - px) + Math.abs(e.clientY - py) > 3) bouge = true;
  const k = echelle();
  vb.x -= (e.clientX - px) / k; vb.y -= (e.clientY - py) / k;
  px = e.clientX; py = e.clientY; applyVB();
});
wrap.addEventListener('pointerup', () => { down = false; wrap.classList.remove('drag'); });

/* ---------- mise en evidence ---------- */
function eclairer(garder, selId, cheminIds) {
  const keep = new Set(garder);
  document.body.classList.add('focus');
  for (const [id, el] of Object.entries(nodeEl)) {
    el.classList.toggle('on', keep.has(id));
    el.classList.toggle('sel', id === selId);
    el.classList.toggle('lien', !!cheminIds && cheminIds.has(id) && id !== selId);
  }
  for (const { el, a, b } of edgeEls) el.classList.toggle('on', keep.has(a) && keep.has(b));
}
function toutAfficher() {
  document.body.classList.remove('focus');
  for (const el of Object.values(nodeEl)) el.classList.remove('on', 'sel', 'lien');
  for (const { el } of edgeEls) el.classList.remove('on');
  panel.classList.remove('open');
  for (const b of [fDoute, fNosrc]) b.setAttribute('aria-pressed', 'false');
}

/* ---------- panneau ---------- */
const panel = document.getElementById('panel');
const nom = p => ((p.prenom === '?' ? '' : p.prenom) + ' ' + p.nom).trim() || 'Sans nom';
const esc = t => String(t).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));

function lignes(p) {
  const L = [];
  if (p.nais) L.push(['Naissance', p.nais + (p.lieu_nais ? ' — ' + p.lieu_nais : '')]);
  else if (p.lieu_nais) L.push(['Naissance', p.lieu_nais]);
  if (p.deces) L.push(['Décès', p.deces + (p.lieu_deces ? ' — ' + p.lieu_deces : '')]);
  else if (p.lieu_deces) L.push(['Décès', p.lieu_deces]);
  L.push(['Sexe', p.sexe === 'M' ? 'Homme' : p.sexe === 'F' ? 'Femme' : 'Inconnu']);
  return L;
}
function listeLiens(titre, ids) {
  if (!ids.length) return '';
  return '<h3>' + titre + '</h3><ul>' + ids.map(i => {
    const q = GENS[i]; if (!q) return '';
    const an = q.an ? ' <small style="color:var(--mut)">' + q.an + '</small>' : '';
    return '<li><button class="lk" data-go="' + i + '">' + esc(nom(q)) + '</button>' + an + '</li>';
  }).join('') + '</ul>';
}
function ouvrir(id) {
  const p = GENS[id]; if (!p) return;
  document.getElementById('p-nom').textContent = nom(p);
  document.getElementById('p-rel').textContent = p.parente;
  document.getElementById('p-dl').innerHTML =
    lignes(p).map(([k, v]) => '<dt>' + k + '</dt><dd>' + esc(v) + '</dd>').join('');

  let f = '';
  if (p.sans_source) {
    f = '<div class="flag bad"><strong>Aucune source.</strong> Rien ne vient appuyer ces informations.</div>';
  } else if (!p.fiable) {
    f = '<div class="flag bad"><strong>Non vérifié.</strong> Uniquement des recopies d’arbres MyHeritage, ' +
        'pas d’acte.<div class="src">' + p.sources.map(esc).join(' · ') + '</div></div>';
  } else {
    f = '<div class="flag good"><strong>Appuyé par un document.</strong>' +
        '<div class="src">' + p.sources.map(esc).join(' · ') + '</div></div>';
  }
  document.getElementById('p-flag').innerHTML = f;
  document.getElementById('p-fam').innerHTML =
    listeLiens('Parents', p.parents) + listeLiens('Conjoint(s)', p.conjoints) +
    listeLiens('Enfants', p.enfants) + listeLiens('Frères et sœurs', p.fratrie);
  panel.classList.add('open');

  const ch = chemin(id, EGO);
  const garder = new Set([id, ...voisins(id), ...p.fratrie, ...ch]);
  for (const c of ch) for (const v of voisins(c)) if (ch.includes(v)) garder.add(v);
  eclairer(garder, id, new Set(ch));
  centrer(id);
}
document.getElementById('p-fam').addEventListener('click', e => {
  const b = e.target.closest('[data-go]'); if (b) ouvrir(b.dataset.go);
});
document.getElementById('close').addEventListener('click', toutAfficher);

g.addEventListener('click', e => {
  if (bouge) return;
  const n = e.target.closest('g.node'); if (!n) return;
  const id = n.dataset.id;
  if (id && id[0] === 'I') ouvrir(id);
});

/* ---------- filtres ---------- */
const fDoute = document.getElementById('f-doute'), fNosrc = document.getElementById('f-nosrc');
function filtrer(btn, test) {
  const actif = btn.getAttribute('aria-pressed') !== 'true';
  for (const b of [fDoute, fNosrc]) b.setAttribute('aria-pressed', 'false');
  panel.classList.remove('open');
  if (!actif) { toutAfficher(); return; }
  btn.setAttribute('aria-pressed', 'true');
  const ids = Object.keys(GENS).filter(i => test(GENS[i]));
  eclairer(ids, null, null);
}
fDoute.addEventListener('click', () => filtrer(fDoute, p => !p.fiable && !p.sans_source));
fNosrc.addEventListener('click', () => filtrer(fNosrc, p => p.sans_source));
document.getElementById('reset').addEventListener('click', () => { toutAfficher(); fit(); });

/* ---------- recherche ---------- */
const q = document.getElementById('q'), res = document.getElementById('res');
let k = -1, hits = [];
const sansAccent = t => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
function chercher() {
  const v = sansAccent(q.value.trim());
  if (v.length < 2) { res.style.display = 'none'; hits = []; return; }
  hits = Object.keys(GENS)
    .filter(i => sansAccent(nom(GENS[i]) + ' ' + GENS[i].parente).includes(v))
    .sort((a, b) => nom(GENS[a]).localeCompare(nom(GENS[b])))
    .slice(0, 40);
  if (!hits.length) { res.style.display = 'none'; return; }
  k = -1;
  res.innerHTML = hits.map(i => {
    const p = GENS[i];
    return '<button data-go="' + i + '">' + esc(nom(p)) +
      (p.an ? ' <small>' + p.an + '</small>' : '') +
      '<br><small>' + esc(p.parente) + '</small></button>';
  }).join('');
  const r = q.getBoundingClientRect();
  res.style.left = r.left + 'px';
  res.style.display = 'block';
}
q.addEventListener('input', chercher);
res.addEventListener('click', e => {
  const b = e.target.closest('[data-go]'); if (!b) return;
  res.style.display = 'none'; q.value = ''; ouvrir(b.dataset.go);
});
q.addEventListener('keydown', e => {
  if (!hits.length) return;
  const btns = [...res.querySelectorAll('button')];
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    k = (k + (e.key === 'ArrowDown' ? 1 : -1) + btns.length) % btns.length;
    btns.forEach((b, i) => b.classList.toggle('k', i === k));
    btns[k].scrollIntoView({ block: 'nearest' });
  } else if (e.key === 'Enter') {
    e.preventDefault();
    const b = btns[k < 0 ? 0 : k];
    if (b) { res.style.display = 'none'; q.value = ''; ouvrir(b.dataset.go); }
  } else if (e.key === 'Escape') { res.style.display = 'none'; q.blur(); }
});
document.addEventListener('click', e => {
  if (!res.contains(e.target) && e.target !== q) res.style.display = 'none';
});

addEventListener('keydown', e => {
  if (e.target === q) return;
  if (e.key === '0') fit();
  if (e.key === 'Escape') toutAfficher();
  if (e.key === '/') { e.preventDefault(); q.focus(); }
});
addEventListener('resize', fit);
fit();
</script>
"""

html = (TPL.replace("__SVG__", svg)
           .replace("__DATA__", DATA)
           .replace("__VBX__", "%.2f" % VBX)
           .replace("__VBY__", "%.2f" % VBY)
           .replace("__VBW__", "%.2f" % VBW)
           .replace("__VBH__", "%.2f" % VBH))
for marqueur in ("__SVG__", "__DATA__", "__VBX__", "__VBY__", "__VBW__", "__VBH__", "__W__", "__H__"):
    assert marqueur not in html, "marqueur non substitue : " + marqueur
open(OUT, "w", encoding="utf-8").write(html)
print("ecrit : " + OUT)
print("taille : " + str(round(len(html) / 1024)) + " Ko")
print("viewBox : %.0f %.0f %.0f %.0f" % (VBX, VBY, VBW, VBH))
