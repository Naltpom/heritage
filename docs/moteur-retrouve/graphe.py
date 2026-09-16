# -*- coding: utf-8 -*-
"""Construit le graphe familial et calcule une mise en page par generation.

Sortie : graphe.json — noeuds positionnes, aretes, et donnees par personne.
Pas de dependance externe : le rendu SVG est produit par rendu.py.
"""
import json, re
from collections import defaultdict, deque

GED = r"C:\www\myHeritage\arbre-romarin-v5.ged"
OUT = r"C:\www\myHeritage\graphe.json"
EGO = "@I500001@"

# ---------------------------------------------------------------- lecture
raw = open(GED, "rb").read().decode("utf-8")
LINE = re.compile(r"^(\d+) (?:(@[^@]+@) )?([A-Za-z0-9_]+)(?: (.*))?$")
logical, recs, cur = [], {}, None
for ln in raw.replace("\r\n", "\n").split("\n"):
    m = LINE.match(ln)
    if not m:
        if logical:
            logical[-1][3] += "\n" + ln
        continue
    lvl, x, t, v = int(m.group(1)), m.group(2), m.group(3), (m.group(4) or "")
    if t == "CONC" and logical:
        logical[-1][3] += v
    elif t == "CONT" and logical:
        logical[-1][3] += "\n" + v
    else:
        logical.append([lvl, x, t, v])
for lvl, x, t, v in logical:
    if lvl == 0 and x:
        cur = {"id": x, "type": t, "l": []}
        recs[x] = cur
    elif cur is not None:
        cur["l"].append((lvl, t, v))

indi = {k: v for k, v in recs.items() if v["type"] == "INDI"}
fam = {k: v for k, v in recs.items() if v["type"] == "FAM"}


def sub(r, t):
    return [v for _, tg, v in r["l"] if tg == t]


def evt(r, ev, t):
    out, ins = [], False
    for lvl, tg, v in r["l"]:
        if lvl == 1:
            ins = tg == ev
        elif ins and tg == t:
            out.append(v)
    return out


def a_tag(r, t):
    return any(tg == t for lvl, tg, _ in r["l"] if lvl == 1)


MOIS = {"JAN": "01", "FEB": "02", "MAR": "03", "APR": "04", "MAY": "05", "JUN": "06",
        "JUL": "07", "AUG": "08", "SEP": "09", "OCT": "10", "NOV": "11", "DEC": "12"}


def annee(d):
    """Borne basse d'une date : le premier millesime rencontre."""
    m = re.search(r"\b(\d{4})\b", d or "")
    return int(m.group(1)) if m else None


def annee_max(d):
    """Borne haute : le dernier millesime. « BET 1946 AND 1969 » donne 1969.
    Sans elle, une fourchette se lit comme une date certaine."""
    ms = re.findall(r"\b(\d{4})\b", d or "")
    return int(ms[-1]) if ms else None


def jolie(d):
    """'22 MAY 1949' -> '22.5.1949' ; 'BET 1941 AND 1946' -> '1941-1946'."""
    if not d:
        return ""
    d = d.strip()
    m = re.match(r"^BET (.+) AND (.+)$", d)
    if m:
        return str(annee(m.group(1)) or "?") + "-" + str(annee(m.group(2)) or "?")
    m = re.match(r"^(ABT|EST|CAL) (.+)$", d)
    if m:
        return "~" + str(annee(m.group(2)) or "?")
    if d.startswith("BEF "):
        return "<" + str(annee(d) or "?")
    if d.startswith("AFT "):
        return ">" + str(annee(d) or "?")
    m = re.match(r"^(\d{1,2}) ([A-Z]{3}) (\d{4})$", d)
    if m:
        return m.group(1) + "." + str(int(MOIS[m.group(2)])) + "." + m.group(3)
    m = re.match(r"^([A-Z]{3}) (\d{4})$", d)
    if m:
        return str(int(MOIS[m.group(1)])) + "/" + m.group(2)
    return str(annee(d) or d)


def nom_complet(k):
    n = (sub(indi[k], "NAME") or [""])[0]
    m = re.match(r"^(.*?)/([^/]*)/", n)
    pre = (m.group(1) if m else n).strip()
    fam_ = (m.group(2) if m else "").strip()
    return pre, fam_


# ---------------------------------------------------------------- personnes
gens_data = {}
for k in indi:
    r = indi[k]
    pre, nm = nom_complet(k)
    nick = (sub(r, "NICK") or [""])[0] or next(
        (v for lvl, tg, v in r["l"] if tg == "NICK"), "")
    b = (evt(r, "BIRT", "DATE") or [""])[0]
    bp = (evt(r, "BIRT", "PLAC") or [""])[0]
    d = (evt(r, "DEAT", "DATE") or [""])[0]
    dp = (evt(r, "DEAT", "PLAC") or [""])[0]
    prive = a_tag(r, "RESN")
    gens_data[k] = {
        "prenom": pre or "?", "nom": nm, "usage": nick,
        "sexe": (sub(r, "SEX") or [""])[0],
        "nais": jolie(b), "an_nais": annee(b), "an_nais_max": annee_max(b),
        "lieu_nais": bp,
        "deces": jolie(d), "an_deces": annee(d), "an_deces_max": annee_max(d),
        "lieu_deces": dp,
        "mort": a_tag(r, "DEAT"),
        "prive": prive,
        "sources": [],
        "fiable": False,
    }
    # fiabilite : le plus haut QUAY porte par une citation
    q, ins = [], False
    for lvl, tg, v in r["l"]:
        if lvl == 1:
            ins = tg == "SOUR"
        elif ins and tg == "QUAY" and v.strip().isdigit():
            q.append(int(v))
    gens_data[k]["quay"] = max(q) if q else None
    gens_data[k]["fiable"] = bool(q) and max(q) >= 2
    gens_data[k]["sans_source"] = not any(tg == "SOUR" for lvl, tg, _ in r["l"] if lvl == 1)

# ---------------------------------------------------------------- familles
fams_data = {}
enfants, parents, conjoints = defaultdict(list), {}, defaultdict(list)
for fk, fr in fam.items():
    h = (sub(fr, "HUSB") or [None])[0]
    w = (sub(fr, "WIFE") or [None])[0]
    ch = sub(fr, "CHIL")
    mar = (evt(fr, "MARR", "DATE") or [""])[0]
    div = (evt(fr, "DIV", "DATE") or [""])[0]
    a_div = a_tag(fr, "DIV")
    fams_data[fk] = {
        "epoux": [p for p in (h, w) if p],
        "enfants": ch,
        "mariage": jolie(mar), "an_mariage": annee(mar),
        "lieu_mariage": (evt(fr, "MARR", "PLAC") or [""])[0],
        "divorce": jolie(div), "a_divorce": a_div,
    }
    for c in ch:
        parents[c] = [p for p in (h, w) if p]
        for p in (h, w):
            if p:
                enfants[p].append(c)
    if h and w:
        conjoints[h].append(w)
        conjoints[w].append(h)

# remariages : une personne rattachee a plusieurs FAM comme epoux
unions = defaultdict(list)
for fk, fd in fams_data.items():
    for p in fd["epoux"]:
        unions[p].append(fk)
for k in gens_data:
    gens_data[k]["unions"] = len(unions.get(k, []))

# ---------------------------------------------------------------- generations
# Propagation de contraintes : enfant = parent + 1, epoux de meme rang.
gen = {EGO: 0}
file = deque([EGO])
while file:
    x = file.popleft()
    g = gen[x]
    for p in parents.get(x, []):
        if p not in gen:
            gen[p] = g - 1
            file.append(p)
    for c in enfants.get(x, []):
        if c not in gen:
            gen[c] = g + 1
            file.append(c)
    for s in conjoints.get(x, []):
        if s not in gen:
            gen[s] = g
            file.append(s)
for k in indi:
    gen.setdefault(k, 0)

# un couple doit partager son rang : on aligne sur le plus ancien
for _ in range(6):
    bouge = False
    for fk, fd in fams_data.items():
        ep = fd["epoux"]
        if len(ep) == 2 and gen[ep[0]] != gen[ep[1]]:
            g = min(gen[ep[0]], gen[ep[1]])
            for p in ep:
                if gen[p] != g:
                    gen[p] = g
                    bouge = True
        # un enfant doit etre sous ses parents
        if ep:
            gp = min(gen[p] for p in ep)
            for c in fd["enfants"]:
                if gen[c] <= gp:
                    gen[c] = gp + 1
                    bouge = True
    if not bouge:
        break

# Rangs entrelaces : les personnes occupent les rangs pairs, les noeuds famille
# les rangs impairs. Sans cela, un noeud famille partage sa rangee avec les epoux
# et le barycentre — qui ne regarde que les rangees voisines — ne les rapproche
# jamais : les conjoints finissaient aux deux bouts de leur generation.
for fk, fd in fams_data.items():
    ep = fd["epoux"]
    fd["gen"] = (min(gen[p] for p in ep) if ep
                 else (min(gen[c] for c in fd["enfants"]) - 1 if fd["enfants"] else 0))
    fd["rang"] = 2 * fd["gen"] + 1

rang = {k: 2 * gen[k] for k in indi}
for fk, fd in fams_data.items():
    rang[fk] = fd["rang"]


# --- Branche d'origine : de laquelle des quatre lignes de grands-parents
#     chaque personne releve. Sert a poser un liseré de couleur sur la fiche,
#     pour qu'on sache d'un coup d'oeil dans quelle moitie de l'arbre on est.
SOUCHES = [("pp", "@I500007@"), ("pm", "@I500006@"),
           ("mp", "@I500005@"), ("mm", "@I500004@")]


def remonte(dep):
    vus, pile = set(), [dep]
    while pile:
        x = pile.pop()
        if x in vus:
            continue
        vus.add(x)
        pile.extend(parents.get(x, []))
    return vus


branche = {}
for code, souche in SOUCHES:
    if souche in indi:
        for p in remonte(souche):
            branche.setdefault(p, code)

# Le tronc : l'ego, sa fratrie et ses parents n'appartiennent a aucune des
# quatre branches — ils sont le point ou elles se rejoignent.
TRONC = ({EGO} | set(parents.get(EGO, []))
         | {c for q in parents.get(EGO, []) for c in enfants.get(q, [])})
for p in TRONC:
    branche[p] = "tr"

# Heritage : le SANG d'abord (parents, puis enfants), l'alliance en dernier.
# Dans l'autre ordre, un homme heritait de la branche de sa femme — c'est ainsi
# que le pere de l'ego se retrouvait etiquete du cote maternel.
for _ in range(8):
    for k in indi:
        if k in branche:
            continue
        for v in parents.get(k, []) + enfants.get(k, []) + conjoints.get(k, []):
            if v in branche and branche[v] != "tr":
                branche[k] = branche[v]
                break

for k in gens_data:
    gens_data[k]["branche"] = branche.get(k, "")


# --- Citations : titre de la source, reference exacte, niveau de preuve, et
#     l'image d'archive quand un agent l'a deposee. Sans ca, la fiche affirme
#     « appuye sur un acte » sans jamais dire lequel.
import os

RACINE_WEB = r"C:\www\myHeritage"
DOSSIER_IMG = os.path.join(RACINE_WEB, "recherche")

src_meta = {}
for k, r in recs.items():
    if r["type"] != "SOUR":
        continue
    src_meta[k] = {
        "titre": (sub(r, "TITL") or sub(r, "ABBR") or ["(sans titre)"])[0],
        "auteur": (sub(r, "AUTH") or [""])[0],
        "publi": (sub(r, "PUBL") or [""])[0],
    }

QUAY_LBL = {0: "recopie non vérifiée", 1: "index ou déduction",
            2: "acte ou registre", 3: "preuve directe"}


def jetons(t):
    """Cotes d'archives normalisees, sous-serie comprise.

    '7 R 2044', '7R2044' et '07R2044' donnent le meme jeton ; '9 E 118/30/13'
    donne '9E118-30-13', distinct de '9E118-25-9' — sans quoi toutes les images
    d'une meme commune se collent a toutes ses citations. Pas de `\b` en tete :
    le souligne est un caractere de mot, donc il n'y a pas de frontiere entre
    `_` et le chiffre d'une cote dans un nom de fichier.
    """
    out = set()
    for m in re.finditer(r"(?<![0-9])(\d{1,3})\s*(MI|[EWRM])\s*(\d{1,4})"
                         r"((?:\s*[/-]\s*\d{1,4})*)", (t or "").upper()):
        base = str(int(m.group(1))) + m.group(2) + str(int(m.group(3)))
        for s in re.findall(r"\d{1,4}", m.group(4) or ""):
            base += "-" + str(int(s))
        out.add(base)
    return out


def compatibles(a, b):
    """Une citation qui ne cite que '9 E 118' accepte une image '9E118-30-13',
    mais '9E118-30-13' et '9E118-25-9' ne se rapprochent pas."""
    return any(x == y or x.startswith(y + "-") or y.startswith(x + "-")
               for x in a for y in b)


images = []
for dossier, _, fichiers in os.walk(DOSSIER_IMG):
    for f in fichiers:
        if f.lower().endswith((".jpg", ".jpeg", ".png")):
            rel = os.path.relpath(os.path.join(dossier, f), RACINE_WEB)
            images.append({"url": rel.replace("\\", "/"), "jetons": jetons(f)})


def citations(r):
    out, cur_c = [], None
    for lvl, t, v in r["l"]:
        if lvl == 1 and t == "SOUR":
            meta = src_meta.get(v, {})
            cur_c = {"titre": meta.get("titre", v), "auteur": meta.get("auteur", ""),
                     "publi": meta.get("publi", ""), "page": "", "quay": None,
                     "texte": "", "url": "", "images": []}
            out.append(cur_c)
        elif lvl == 1:
            cur_c = None
        elif cur_c is not None:
            if t == "PAGE":
                cur_c["page"] = v
                m = re.search(r"https?://\S+", v)
                if m:
                    cur_c["url"] = m.group(0).rstrip(".,;")
                    cur_c["page"] = v.replace(m.group(0), "").strip(" —-")
            elif t == "QUAY" and v.strip().isdigit():
                cur_c["quay"] = int(v)
            elif t == "TEXT" and not cur_c["texte"]:
                cur_c["texte"] = v.split("\n")[0][:160]
    for c in out:
        c["quay_lbl"] = QUAY_LBL.get(c["quay"], "")
        j = jetons(c["page"])
        if j:
            c["images"] = sorted({im["url"] for im in images
                                  if compatibles(im["jetons"], j)})
    return out


for k in gens_data:
    gens_data[k]["citations"] = citations(indi[k])

_ncit = sum(len(d["citations"]) for d in gens_data.values())
_nurl = sum(1 for d in gens_data.values() for c in d["citations"] if c["url"])
_nimg = sum(1 for d in gens_data.values() for c in d["citations"] if c["images"])
print("citations : %d | avec lien : %d | avec image d'archive : %d" % (_ncit, _nurl, _nimg))


# ---------------------------------------------------------------- ordre en X
# Mise en page en ASCENDANCE RECURSIVE, et non en graphe generique.
#
# L'ego est en bas au centre. Au-dessus, le bloc du pere occupe toute la moitie
# gauche, celui de la mere toute la moitie droite, et ainsi de suite a chaque
# generation. Une personne ne peut donc jamais se retrouver du mauvais cote de
# l'arbre : son cote est decide par la recursion, pas par un barycentre.
#
# Chaque bloc est aussi large qu'il faut pour contenir, au choix, les deux blocs
# parentaux cote a cote, ou la fratrie de la personne. La fratrie est centree
# sous le couple parental — c'est ce qui raccourcit les liens.

LARG_CAR = 6.6
PAD = 14
H_FICHE = 40
H_RANG = 150
ESPACE = 20            # entre deux fiches d'une meme fratrie
GAP_BLOC = 70          # entre le bloc paternel et le bloc maternel


def ligne_dates(d):
    """Forme complete du § 14.4 : '1901-1909' et '1987' donnent '1901-1909 – 1987'.
    Naissance seule : 'n. 26.9.2012'. Deces seul : '† 1976'. Ni l'un ni l'autre : '—'.

    Meme raison que pour jolie_date() dans build_data.py : la ligne est assemblee
    a l'execution, et l'exemple litteral ci-dessus est le seul endroit du moteur
    ou la forme complete s'ecrit en clair. C'est par lui que le controle verifie
    qu'une naissance n'a pas ete decalee sans son deces.
    """
    if d["prive"]:
        return "vivant·e"
    n, m = d["nais"], d["deces"]
    if n and m:
        return n + " – " + m
    if n:
        return "n. " + n
    if m:
        return "† " + m
    return "—"


def largeur(k):
    d = gens_data[k]
    t1 = len((d["usage"] or d["prenom"]).split(" ")[0] + " " + d["nom"])
    t2 = len(ligne_dates(d))
    return max(104, int(max(t1 * LARG_CAR, t2 * 5.8) + 2 * PAD))


def pere_mere(p):
    ps = parents.get(p, [])
    pe = next((x for x in ps if gens_data[x]["sexe"] == "M"), None)
    me = next((x for x in ps if gens_data[x]["sexe"] == "F"), None)
    if pe is None and me is None and ps:
        pe = ps[0]
        me = ps[1] if len(ps) > 1 else None
    return pe, me


def rang_fratrie(p):
    """La fratrie de p, dans l'ordre des naissances, conjoints accoles.

    Le conjoint de p lui-meme est exclu : c'est l'autre parent de la generation
    suivante, il a son propre bloc d'ascendance de l'autre cote.
    """
    freres = {c for q in parents.get(p, []) for c in enfants.get(q, []) if c != p}
    fr = sorted({p} | freres,
                key=lambda c: (gens_data[c]["an_nais"] or 9999, gens_data[c]["nom"], c))
    out = []
    for s in fr:
        out.append(s)
        if s == p:
            continue
        for c in sorted(set(conjoints.get(s, []))):
            if c not in out and c not in fr and gen.get(c) == gen.get(s):
                out.append(c)
    return out


_lb, _lf = {}, {}


def larg_fratrie(p):
    if p not in _lf:
        fr = rang_fratrie(p)
        _lf[p] = sum(largeur(m) for m in fr) + ESPACE * (len(fr) - 1)
    return _lf[p]


def larg_bloc(p, vus=None):
    if p in _lb:
        return _lb[p]
    vus = vus or set()
    if p in vus:                       # garde-fou contre la consanguinite bouclee
        return larg_fratrie(p)
    vus = vus | {p}
    pe, me = pere_mere(p)
    wp = larg_bloc(pe, vus) if pe else 0
    wm = larg_bloc(me, vus) if me else 0
    wa = wp + wm + (GAP_BLOC if pe and me else 0)
    _lb[p] = max(wa, larg_fratrie(p))
    return _lb[p]


posx = {}
place_faite = set()


def pose_bloc(p, x0):
    if p in place_faite:
        return
    place_faite.add(p)
    w = larg_bloc(p)
    pe, me = pere_mere(p)
    if pe or me:
        wp = larg_bloc(pe) if pe else 0
        wm = larg_bloc(me) if me else 0
        tot = wp + wm + (GAP_BLOC if pe and me else 0)
        x = x0 + (w - tot) / 2
        if pe:
            pose_bloc(pe, x)
            x += wp + GAP_BLOC
        if me:
            pose_bloc(me, x)
    # la fratrie se centre sous le couple parental : c'est ce qui fait les
    # liens courts. A defaut de parents places, on la centre sur le bloc.
    anc = [q for q in (pe, me) if q and q in posx]
    cx = (sum(posx[q] for q in anc) / len(anc)) if anc else (x0 + w / 2)
    fr = rang_fratrie(p)
    x = cx - larg_fratrie(p) / 2
    for m in fr:
        if m not in posx:
            posx[m] = x + largeur(m) / 2
        x += largeur(m) + ESPACE


pose_bloc(EGO, 0.0)

# --- Phase 2 : tout ce qui n'est ni ancetre ni frere d'ancetre — les cousins,
#     leurs conjoints, leurs enfants — se range sous ses propres parents.
for _ in range(12):
    reste = [k for k in indi if k not in posx]
    if not reste:
        break
    for k in sorted(reste, key=lambda k: gen[k]):
        anc = [q for q in parents.get(k, []) if q in posx]
        if not anc:
            # Pas de parents places : on se raccroche a un conjoint, sinon a ses
            # enfants. Sans ce repli, une personne dont le seul lien est vers le
            # bas tombait a x=0 — d'ou des peres a 7000 px de leur fils.
            cj = [c for c in conjoints.get(k, []) if c in posx and gen.get(c) == gen[k]]
            if cj:
                a = cj[0]
                posx[k] = posx[a] + largeur(a) / 2 + ESPACE + largeur(k) / 2
                continue
            enf = [c for c in enfants.get(k, []) if c in posx]
            if enf:
                posx[k] = sum(posx[c] for c in enf) / len(enf)
            continue
        cx = sum(posx[q] for q in anc) / len(anc)
        fr = [c for c in sorted(set(enfants.get(anc[0], [])),
                                key=lambda c: (gens_data[c]["an_nais"] or 9999, c))]
        wf = sum(largeur(m) for m in fr) + ESPACE * (len(fr) - 1)
        x = cx - wf / 2
        for m in fr:
            if m not in posx:
                posx[m] = x + largeur(m) / 2
            x += largeur(m) + ESPACE
    # les conjoints des nouveaux venus se posent a cote d'eux
    for k in list(posx):
        for c in sorted(set(conjoints.get(k, []))):
            if c not in posx and gen.get(c) == gen.get(k):
                posx[c] = posx[k] + largeur(k) / 2 + ESPACE + largeur(c) / 2

for k in indi:                          # filet de securite
    posx.setdefault(k, 0.0)

# --- Phase 3 : on ecarte les chevauchements, rangee par rangee, sans changer
#     l'ordre — l'ordre vient de la recursion et il porte la structure.
par_gen = defaultdict(list)
for k in indi:
    par_gen[gen[k]].append(k)
for g in sorted(par_gen):
    ks = sorted(par_gen[g], key=lambda k: posx[k])
    par_gen[g] = ks
    for i in range(1, len(ks)):
        a, b = ks[i - 1], ks[i]
        mini = posx[a] + largeur(a) / 2 + ESPACE + largeur(b) / 2
        if posx[b] < mini:
            posx[b] = mini

# --- Coordonnees finales
pos = {}
for g in sorted(par_gen):
    y = g * H_RANG
    for k in par_gen[g]:
        pos[k] = {"x": posx[k], "y": y, "w": largeur(k), "h": H_FICHE}

for fk, fd in fams_data.items():
    ep = [p for p in fd["epoux"] if p in pos]
    enf = [c for c in fd["enfants"] if c in pos]
    if ep:
        gx = sum(pos[p]["x"] for p in ep) / len(ep)
        gy = pos[ep[0]]["y"] + H_FICHE / 2 + 26
    elif enf:
        gx = sum(pos[c]["x"] for c in enf) / len(enf)
        gy = min(pos[c]["y"] for c in enf) - H_RANG + H_FICHE / 2 + 26
    else:
        continue
    pos[fk] = {"x": gx, "y": gy, "w": 22, "h": 14}
    fd["gen"] = round((gy - H_FICHE / 2 - 26) / H_RANG)

# l'ego est le point de lecture : c'est lui qu'on centre, pas la boite
dx = -pos[EGO]["x"] if EGO in pos else 0
for k in pos:
    pos[k]["x"] += dx

# ---------------------------------------------------------------- sortie
aretes = []
for fk, fd in fams_data.items():
    for p in fd["epoux"]:
        aretes.append({"de": p, "vers": fk, "genre": "union",
                       "divorce": fd["a_divorce"]})
    for c in fd["enfants"]:
        aretes.append({"de": fk, "vers": c, "genre": "filiation"})

for k in gens_data:
    gens_data[k]["parents"] = [p for p in parents.get(k, [])]
    gens_data[k]["enfants"] = sorted(set(enfants.get(k, [])))
    gens_data[k]["conjoints"] = sorted(set(conjoints.get(k, [])))
    fr = [c for p in parents.get(k, []) for c in enfants.get(p, []) if c != k]
    gens_data[k]["fratrie"] = sorted(set(fr))
    gens_data[k]["ligne_dates"] = ligne_dates(gens_data[k])
    gens_data[k]["gen"] = gen[k]

xs = [pos[k]["x"] - pos[k]["w"] / 2 for k in pos] + [pos[k]["x"] + pos[k]["w"] / 2 for k in pos]
ys = [pos[k]["y"] - pos[k]["h"] / 2 for k in pos] + [pos[k]["y"] + pos[k]["h"] / 2 for k in pos]
M = 60
boite = {"x": min(xs) - M, "y": min(ys) - M,
         "w": max(xs) - min(xs) + 2 * M, "h": max(ys) - min(ys) + 2 * M}

json.dump({"gens": gens_data, "familles": fams_data, "pos": pos,
           "aretes": aretes, "boite": boite, "ego": EGO},
          open(OUT, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))

print("personnes  : " + str(len(gens_data)))
print("familles   : " + str(len(fams_data)))
print("aretes     : " + str(len(aretes)))
gmin = min(d["gen"] for d in gens_data.values())
gmax = max(d["gen"] for d in gens_data.values())
print("generations: " + str(gmax - gmin + 1) + "  (rangs " + str(min(par_gen)) + " a " + str(max(par_gen)) + ")")
print("boite      : " + str(int(boite["w"])) + " x " + str(int(boite["h"])))
print("divorces   : " + str(sum(1 for f in fams_data.values() if f["a_divorce"])))
print("remariages : " + str(sum(1 for k, u in unions.items() if len(u) > 1)))
print("ecrit      : " + OUT)
