/* ------------------------------------------------------------------------ */
/* La famille fictive de la vidéo : ≈ 2 300 personnes sur 14 générations.    */
/*                                                                           */
/* Construite comme l'arbre réel est servi : un lecteur (l'ego), son         */
/* ascendance par numéros de Sosa sur 12 générations, et autour, la          */
/* descendance des frères et sœurs de chaque ancêtre (les cousins à tous     */
/* les degrés) avec leurs conjoints. Les branches suivent la règle du        */
/* serveur (api/src/Module/Tree/Service/CalculAscendance.php) : les quatre   */
/* quarts depuis les grands-parents, le tronc (ego, fratrie, parents), puis  */
/* le sang (parents d'abord), l'alliance en dernier.                         */
/*                                                                           */
/* Déterministe (graine fixe) : la fausse API, les captures et la            */
/* composition voient exactement la même famille.                           */
/* ------------------------------------------------------------------------ */
;(function (racine) {
  const V = typeof module !== 'undefined' ? require('./vocabulaire.js') : racine.VOCABULAIRE

  function rnd(seed) { let s = seed >>> 0; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296) }

  // `cible` règle le budget des cousins ; 3400 donne 2 339 personnes (« plus de 2 300 »)
  function construire({ graine = 1887, cible = 3400 } = {}) {
    const r = rnd(graine)
    const choisir = (l) => l[Math.floor(r() * l.length)]
    const P = [] // personnes
    const F = [] // familles (couples) : {id, pere, mere, enfants, mariage}
    const parId = new Map()

    const prenomPour = (sexe, annee) => {
      const ep = V.PRENOMS.epoques.find(([fin]) => annee <= fin)
      const liste = sexe === 'masculin' ? ep[1] : ep[2]
      // les premiers prénoms de la liste reviennent plus souvent (Jean, Marie…)
      return liste[Math.floor(Math.pow(r(), 1.8) * liste.length)]
    }
    const metierPour = (sexe, annee) => {
      const ep = V.METIERS.find(([fin]) => annee <= fin)
      const liste = sexe === 'masculin' ? ep[1] : ep[2]
      return liste[Math.floor(Math.pow(r(), 1.5) * liste.length)]
    }
    // la famille vit dans un petit pays (les 8 premières communes), puis s'éloigne avec le temps
    const lieuPour = (annee, proche) => {
      const rayon = annee < 1820 ? 8 : annee < 1900 ? 14 : annee < 1950 ? 22 : V.LIEUX.length
      if (proche && r() < (annee < 1900 ? 0.7 : 0.4)) return proche
      return V.LIEUX[Math.floor(Math.pow(r(), annee < 1900 ? 1.4 : 1) * rayon)][0]
    }

    function personne(o) {
      const id = P.length + 1
      const naissance = o.naissance
      const vivant = o.vivant ?? (naissance > 1935 && r() > (2026 - naissance) / 110)
      let deces = null
      if (!vivant) {
        // l'espérance de vie suit l'époque, avec une mortalité infantile ancienne
        const enfance = naissance < 1900 && r() < 0.14
        const age = enfance ? Math.floor(r() * 6) : Math.round((naissance < 1800 ? 48 : naissance < 1900 ? 58 : 72) + (r() - 0.4) * 34)
        deces = Math.min(2025, naissance + Math.max(0, age))
      }
      const p = {
        id, personId: id, noeud: `p${id}`, sexe: o.sexe, prenom: o.prenom ?? prenomPour(o.sexe, naissance), nom: o.nom,
        naissance, deces, vivant, lieu: o.lieu ?? lieuPour(naissance), generation: o.generation, sosa: o.sosa ?? null,
        metier: naissance < 2004 ? metierPour(o.sexe, naissance) : null,
        parents: null, unions: [], branch: null, parente: 'famille', mois: 1 + Math.floor(r() * 12), jour: 1 + Math.floor(r() * 28),
      }
      if (deces !== null) { p.lieuDeces = r() < 0.75 ? p.lieu : lieuPour(deces, p.lieu); p.moisDeces = 1 + Math.floor(r() * 12); p.jourDeces = 1 + Math.floor(r() * 28) }
      P.push(p); parId.set(id, p)
      return p
    }
    function famille(pere, mere, enfants = []) {
      const f = { id: F.length + 1, pere: pere?.id ?? null, mere: mere?.id ?? null, enfants: enfants.map((e) => e.id) }
      const ref = pere ?? mere
      f.mariage = ref ? { annee: Math.max(pere?.naissance ?? 0, mere?.naissance ?? 0) + 21 + Math.floor(r() * 8), lieu: (mere ?? pere).lieu } : null
      F.push(f)
      for (const e of [pere, mere]) if (e) e.unions.push(f.id)
      for (const e of enfants) e.parents = f.id
      return f
    }

    /* ---- l'ego et sa famille proche (le tronc) ---- */
    const EGO_NE = 1994
    const ego = personne({ sexe: 'masculin', prenom: 'Thomas', nom: 'Martin', naissance: EGO_NE, generation: 0, sosa: 1, vivant: true, lieu: 'Rennes' })

    /* ---- l'ascendance : Sosa 2..2^12, de moins en moins connue en remontant ---- */
    const sosa = new Map([[1, ego]])
    const PROFONDEUR = 12
    for (let g = 1; g <= PROFONDEUR; g++) {
      const connu = g <= 4 ? 1 : g === 5 ? 0.9 : g === 6 ? 0.8 : g === 7 ? 0.72 : 0.56
      for (let n = 2 ** (g - 1); n < 2 ** g; n++) {
        const enfant = sosa.get(n)
        if (!enfant || (g > 4 && r() > connu)) continue
        const ne = enfant.naissance - (25 + Math.floor(r() * 12))
        const pere = personne({ sexe: 'masculin', nom: enfant.sexe === 'masculin' || n % 2 === 0 ? enfant.nom : enfant.nom, naissance: ne - Math.floor(r() * 5), generation: -g, sosa: 2 * n })
        // le nom du père : celui de l'enfant (transmission patrilinéaire)
        pere.nom = enfant.nom
        const mere = personne({ sexe: 'feminin', nom: g === 2 && n === 3 ? 'Dubois' : choisir(V.NOMS), naissance: ne + Math.floor(r() * 4), generation: -g, sosa: 2 * n + 1, lieu: lieuPour(ne, pere.lieu) })
        sosa.set(2 * n, pere); sosa.set(2 * n + 1, mere)
        famille(pere, mere, [enfant])
      }
    }
    // les quatre souches ont les noms des chapitres (Martin, Bernard, Dubois, Lefèvre)
    const renommerLignee = (n, nom) => { let k = n; while (sosa.get(k)) { sosa.get(k).nom = nom; k *= 2 } }
    renommerLignee(2, 'Martin'); renommerLignee(5, 'Bernard'); renommerLignee(3, 'Dubois'); renommerLignee(7, 'Lefèvre')
    // la lignée de Joséphine (Sosa 25), avant les collatéraux : ses frères et sœurs porteront son nom
    renommerLignee(50, 'Perrin'); sosa.get(25).nom = 'Perrin'

    /* ---- la descendance des frères et sœurs des ancêtres : les cousins ---- */
    const fecondite = (annee) => (annee < 1800 ? 5.2 : annee < 1900 ? 4.4 : annee < 1950 ? 2.9 : 2.1)
    // la descendance d'un collatéral, en largeur (génération après génération), dans un budget
    function descendance(graine, budget) {
      const file = [graine]
      while (file.length && budget.reste > 0) {
        const parent = file.shift()
        if (parent.naissance > 1998 || parent.generation >= 1) continue
        if (r() > (parent.naissance < 1900 ? 0.72 : 0.8)) continue
        const conj = personne({ sexe: parent.sexe === 'masculin' ? 'feminin' : 'masculin', nom: choisir(V.NOMS), naissance: parent.naissance + Math.floor((r() - 0.5) * 8), generation: parent.generation, lieu: lieuPour(parent.naissance, parent.lieu) })
        budget.reste--
        const pere = parent.sexe === 'masculin' ? parent : conj, mere = parent.sexe === 'masculin' ? conj : parent
        const n = Math.max(0, Math.round(fecondite(parent.naissance) * (0.4 + r() * 0.75)))
        const enfants = []
        for (let i = 0; i < n && budget.reste > 0; i++) {
          const ne = parent.naissance + 23 + Math.floor(r() * 14) + i * 2
          if (ne > 2023) break
          const e = personne({ sexe: r() < 0.5 ? 'masculin' : 'feminin', nom: pere.nom, naissance: ne, generation: parent.generation + 1, lieu: lieuPour(ne, mere.lieu) })
          enfants.push(e); budget.reste--
        }
        famille(pere, mere, enfants)
        file.push(...enfants)
      }
    }
    // pour chaque couple d'ancêtres : quelques frères et sœurs de l'ancêtre direct, et leur descendance.
    // Le budget des cousins est réparti par génération (les cousins proches et moyens d'abord).
    const POIDS = { '-1': 0.05, '-2': 0.08, '-3': 0.11, '-4': 0.13, '-5': 0.13, '-6': 0.12, '-7': 0.1, '-8': 0.08, '-9': 0.07, '-10': 0.05, '-11': 0.04, '-12': 0.04 }
    const total = cible - P.length - 3
    const parGen = new Map()
    for (const f of F.slice()) { const g = parId.get(f.enfants[0]).generation; parGen.set(g, [...(parGen.get(g) ?? []), f]) }
    for (const [g, liste] of [...parGen.entries()].sort((a, b) => b[0] - a[0])) {
      const part = Math.round(total * (POIDS[g] ?? 0.03))
      const graines = []
      for (const f of liste) {
        const direct = parId.get(f.enfants[0]), pere = parId.get(f.pere)
        const n = Math.max(0, Math.round(fecondite(pere.naissance) * (0.35 + r() * 0.7)) - 1)
        for (let i = 0; i < n; i++) {
          const ne = direct.naissance + (i % 2 ? 1 : -1) * (2 + Math.floor(r() * 5)) * Math.ceil((i + 1) / 2)
          if (ne > 2022) continue
          const c = personne({ sexe: r() < 0.5 ? 'masculin' : 'feminin', nom: pere.nom, naissance: ne, generation: direct.generation, lieu: lieuPour(ne, direct.lieu) })
          f.enfants.push(c.id); c.parents = f.id
          graines.push(c)
        }
      }
      const reste = part - graines.length
      for (const c of graines) descendance(c, { reste: Math.max(0, Math.floor(reste / Math.max(1, graines.length))) })
    }
    // l'ego : une compagne, deux enfants (la 14e génération : de −12 à +1)
    const compagne = personne({ sexe: 'feminin', prenom: 'Claire', nom: 'Girard', naissance: 1995, generation: 0, vivant: true, lieu: 'Rennes' })
    const e1 = personne({ sexe: 'feminin', prenom: 'Louise', nom: 'Martin', naissance: 2021, generation: 1, vivant: true, lieu: 'Rennes' })
    const e2 = personne({ sexe: 'masculin', prenom: 'Jules', nom: 'Martin', naissance: 2024, generation: 1, vivant: true, lieu: 'Rennes' })
    famille(ego, compagne, [e1, e2])

    /* ---- les branches, comme le serveur ---- */
    const parentsDe = (p) => { const f = p.parents ? F[p.parents - 1] : null; return f ? [f.pere, f.mere].filter(Boolean).map((i) => parId.get(i)) : [] }
    const enfantsDe = (p) => p.unions.flatMap((u) => F[u - 1].enfants.map((i) => parId.get(i)))
    const conjointsDe = (p) => p.unions.map((u) => F[u - 1]).map((f) => parId.get(f.pere === p.id ? f.mere : f.pere)).filter(Boolean)
    const remonte = (p, code) => { if (!p || p.branch) return; p.branch = code; for (const a of parentsDe(p)) remonte(a, code) }
    ;[[4, 'pp'], [5, 'pm'], [6, 'mp'], [7, 'mm']].forEach(([s, c]) => remonte(sosa.get(s), c))
    const pere = sosa.get(2), mere = sosa.get(3)
    for (const p of [ego, pere, mere, ...enfantsDe(pere)]) p.branch = 'tr'
    for (let passe = 0; passe < 40; passe++) {
      let change = false
      for (const p of P) {
        if (p.branch) continue
        for (const v of [...parentsDe(p), ...enfantsDe(p)]) if (v.branch && v.branch !== 'tr') { p.branch = v.branch; change = true; break }
      }
      for (const p of P) {
        if (p.branch) continue
        for (const v of conjointsDe(p)) if (v.branch) { p.branch = v.branch; change = true; break }
      }
      if (!change) break
    }
    for (const p of P) p.branch ??= 'tr'

    /* ---- les personnes mises en avant dans la vidéo ---- */
    // l'arrière-arrière-grand-mère dont on ouvre la fiche : Sosa 25, née en 1868 (côté Dubois),
    // choisie parce que la date de l'acte tombe sur sa naissance générée (ses enfants naissent de 1896 à 1906)
    const vedette = sosa.get(25)
    Object.assign(vedette, { prenom: 'Joséphine', nom: 'Perrin', naissance: 1868, mois: 3, jour: 12, deces: 1949, moisDeces: 11, jourDeces: 4, vivant: false, lieu: 'Josselin', lieuDeces: 'Ploërmel', metier: 'couturière' })
    const parentsVedette = parentsDe(vedette)
    if (parentsVedette.length === 2) {
      Object.assign(parentsVedette[0], { prenom: 'Mathurin', naissance: 1834, deces: 1901, vivant: false, metier: 'tisserand', lieu: 'Josselin' })
      Object.assign(parentsVedette[1], { prenom: 'Perrine', nom: 'Le Gall', naissance: 1839, deces: 1912, vivant: false, metier: 'fileuse', lieu: 'Guilliers' })
    }

    // les dates de mariage suivent les naissances retouchées ; pas deux prénoms identiques dans une fratrie
    for (const f of F) {
      const ep = [f.pere, f.mere].filter(Boolean).map((i) => parId.get(i))
      const enfants = f.enfants.map((i) => parId.get(i))
      if (ep.length && enfants.length) f.mariage = { annee: Math.min(Math.max(...ep.map((p) => p.naissance)) + 22, Math.min(...enfants.map((e) => e.naissance)) - 1), lieu: (parId.get(f.mere) ?? ep[0]).lieu }
      const pris = new Set()
      for (const e of enfants) {
        if (pris.has(e.prenom)) { for (let k = 0; k < 20 && pris.has(e.prenom); k++) e.prenom = prenomPour(e.sexe, e.naissance) }
        pris.add(e.prenom)
      }
    }
    const tous = P
    const stats = {
      personnes: tous.length,
      generations: new Set(tous.map((p) => p.generation)).size,
      genMin: Math.min(...tous.map((p) => p.generation)), genMax: Math.max(...tous.map((p) => p.generation)),
      plusAncien: Math.min(...tous.map((p) => p.naissance)),
    }
    return { personnes: P, familles: F, sosa, ego, vedette, parentsVedette, parId, parentsDe, enfantsDe, conjointsDe, stats }
  }

  const exp = { construireFamille: construire }
  if (typeof module !== 'undefined') module.exports = exp
  else Object.assign(racine, exp)
})(typeof window !== 'undefined' ? window : globalThis)
