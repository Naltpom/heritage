# -*- coding: utf-8 -*-
"""Extrait du GEDCOM les donnees necessaires au visualiseur interactif."""
import re, json, collections

GED = r"C:\www\myHeritage\arbre-romarin-source.ged"
EGO = "@I500001@"

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
sour = {k: v for k, v in recs.items() if v["type"] == "SOUR"}


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


MOIS = {"JAN": "janv.", "FEB": "fevr.", "MAR": "mars", "APR": "avr.", "MAY": "mai",
        "JUN": "juin", "JUL": "juil.", "AUG": "aout", "SEP": "sept.", "OCT": "oct.",
        "NOV": "nov.", "DEC": "dec."}


def jolie_date(d):
    """'BET 1901 AND 1909' -> 'entre 1901 et 1909' ; 'ABT 1889' -> 'vers 1889'.

    Les deux bornes sont recopiees telles quelles, dans l'ordre. L'exemple
    litteral ci-dessus n'est pas decoratif : la fourchette etant composee a
    l'execution, il est la seule prise du controle d'ordre
    (CoherenceDesFourchettesTest) sur cette fonction. Le retirer rend la
    composition invisible au test sans rien casser d'apparent.
    """
    if not d:
        return ""
    s = d.strip()
    s = re.sub(r"^BET (.+) AND (.+)$", r"entre \1 et \2", s)
    s = re.sub(r"^ABT ", "vers ", s)
    s = re.sub(r"^BEF ", "avant ", s)
    s = re.sub(r"^AFT ", "apres ", s)
    for k, v in MOIS.items():
        s = re.sub(r"\b" + k + r"\b", v, s)
    return s


def annee(d):
    m = re.search(r"\b(\d{4})\b", d or "")
    return int(m.group(1)) if m else None


parents = {}
enfants = collections.defaultdict(list)
conjoints = collections.defaultdict(list)
fratrie = collections.defaultdict(list)
for fk, fr in fam.items():
    h = (sub(fr, "HUSB") or [None])[0]
    w = (sub(fr, "WIFE") or [None])[0]
    ch = sub(fr, "CHIL")
    for c in ch:
        parents[c] = [p for p in (h, w) if p]
        for p in (h, w):
            if p:
                enfants[p].append(c)
    if h and w:
        conjoints[h].append(w)
        conjoints[w].append(h)
    for a in ch:
        for b in ch:
            if a != b:
                fratrie[a].append(b)


def ascendants(p):
    dist, file = {p: 0}, [p]
    while file:
        x = file.pop(0)
        for q in parents.get(x, []):
            if q not in dist or dist[x] + 1 < dist[q]:
                dist[q] = dist[x] + 1
                file.append(q)
    return dist


ASC_EGO = ascendants(EGO)
ORD = {1: "", 2: "arriere-", 3: "arriere-arriere-", 4: "arriere-arriere-arriere-"}


def prefixe(n):
    v = ORD.get(n)
    return v if v is not None else "arriere-" * (n - 1)


def parente_sang(p, sexe):
    """Lien par le sang uniquement. None s'il n'y a pas d'ancetre commun."""
    if p == EGO:
        return "toi"
    asc_p = ascendants(p)
    communs = [(ASC_EGO[a] + asc_p[a], ASC_EGO[a], asc_p[a]) for a in ASC_EGO if a in asc_p]
    if not communs:
        return None
    _, dE, dP = min(communs)
    H = sexe == "M"
    e = "" if H else "e"
    if dP == 0:
        if dE == 1:
            return "pere" if H else "mere"
        if dE == 2:
            return "grand-pere" if H else "grand-mere"
        return prefixe(dE - 1) + ("grand-pere" if H else "grand-mere")
    if dE == 0:
        if dP == 1:
            return "fils" if H else "fille"
        if dP == 2:
            return "petit-fils" if H else "petite-fille"
        return prefixe(dP - 1) + ("petit-fils" if H else "petite-fille")
    if dE == 1 and dP == 1:
        return "frere" if H else "soeur"
    if dE == 1:
        if dP == 2:
            return "neveu" if H else "niece"
        return prefixe(dP - 2) + ("petit-neveu" if H else "petite-niece")
    if dP == 1:
        if dE == 2:
            return "oncle" if H else "tante"
        return prefixe(dE - 2) + ("grand-oncle" if H else "grand-tante")
    k = min(dE, dP)
    ecart = abs(dE - dP)
    base = "cousin" if H else "cousine"
    if k == 2:
        lib = base + " germain" + e
    elif k == 3:
        lib = base + " issu" + e + " de germain"
    else:
        lib = base + " au " + str(k - 1) + "e degre"
    if ecart:
        lib += ", eloigne" + e + " de " + str(ecart) + " generation" + ("s" if ecart > 1 else "")
    return lib


titres = {k: (sub(r, "TITL") or sub(r, "ABBR") or ["(sans titre)"])[0] for k, r in sour.items()}


def sources_de(r):
    out, ins, cur_s = [], False, None
    for lvl, t, v in r["l"]:
        if lvl == 1 and t == "SOUR":
            cur_s = {"titre": titres.get(v, v), "quay": None}
            out.append(cur_s)
            ins = True
        elif lvl == 1:
            ins = False
        elif ins and t == "QUAY" and cur_s is not None:
            cur_s["quay"] = int(v) if v.isdigit() else None
    return out


people = {}
for k, r in indi.items():
    n = (sub(r, "NAME") or [""])[0]
    m = re.match(r"^(.*?)/([^/]*)/(.*)$", n)
    prenom = (m.group(1) if m else n).strip()
    nom = (m.group(2) if m else "").strip()
    b = (evt(r, "BIRT", "DATE") or [""])[0]
    bp = (evt(r, "BIRT", "PLAC") or [""])[0]
    d = (evt(r, "DEAT", "DATE") or [""])[0]
    dp = (evt(r, "DEAT", "PLAC") or [""])[0]
    srcs = sources_de(r)
    quays = [s["quay"] for s in srcs if s["quay"] is not None]
    people[k.strip("@")] = {
        "prenom": prenom or "?",
        "nom": nom,
        "sexe": (sub(r, "SEX") or [""])[0],
        "nais": jolie_date(b), "lieu_nais": bp,
        "deces": jolie_date(d), "lieu_deces": dp,
        "an": annee(b) or annee(d),
        "parents": [p.strip("@") for p in parents.get(k, [])],
        "enfants": sorted({c.strip("@") for c in enfants.get(k, [])}),
        "conjoints": sorted({c.strip("@") for c in conjoints.get(k, [])}),
        "fratrie": sorted({c.strip("@") for c in fratrie.get(k, [])}),
        "parente": parente_sang(k, (sub(r, "SEX") or [""])[0]),
        "sources": sorted({s["titre"] for s in srcs}),
        "fiable": bool(quays) and max(quays) >= 2,
        "sans_source": not srcs,
    }

# 2e passe : les pieces rapportees (aucun ancetre commun) sont nommees par le
# conjoint qui, lui, est du sang. On fige la 1re passe pour ne pas relire
# des libelles qu'on vient soi-meme d'ecrire.
sang = {pid: p["parente"] for pid, p in people.items()}
for pid, p in people.items():
    if p["parente"]:
        continue
    for c in p["conjoints"]:
        lien = sang.get(c)
        if lien and lien != "toi":
            p["parente"] = ("epoux" if p["sexe"] == "M" else "epouse") + " de ton " + lien
            break
    else:
        p["parente"] = "relie par alliance"

fams = {}
for k, v in fam.items():
    fams[k.strip("@")] = {
        "epoux": [p.strip("@") for p in (sub(v, "HUSB") + sub(v, "WIFE"))],
        "enfants": [c.strip("@") for c in sub(v, "CHIL")],
        "mariage": jolie_date((evt(v, "MARR", "DATE") or [""])[0]),
        "lieu": (evt(v, "MARR", "PLAC") or [""])[0],
    }

json.dump({"gens": people, "familles": fams, "ego": EGO.strip("@")},
          open(r"C:\www\myHeritage\arbre-data.json", "w", encoding="utf-8"),
          ensure_ascii=False, separators=(",", ":"))

print(str(len(people)) + " personnes, " + str(len(fams)) + " familles")
print("fiables (au moins une source QUAY>=2) : " + str(sum(1 for p in people.values() if p["fiable"])))
print("sans aucune source                    : " + str(sum(1 for p in people.values() if p["sans_source"])))
print("")
print("Exemples de parente calculee :")
for pid in ("I500003", "I500013", "I500017", "I500066", "I500160", "I500002", "I500005"):
    p = people.get(pid)
    if p:
        print("   " + (p["prenom"] + " " + p["nom"]).ljust(34) + " -> " + p["parente"])
c = collections.Counter(p["parente"].split(",")[0] for p in people.values())
print("")
print("Repartition des liens :")
for k, v in c.most_common(14):
    print("   " + str(v).rjust(4) + "  " + k)
