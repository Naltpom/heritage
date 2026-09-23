/**
 * La construction et le pilotage **impératifs** du canevas SVG.
 *
 * ────────────────────────────────────────────────────────────────────
 * **RÈGLE N° 3, PROPRE AU PORTAGE VUE : le canevas vit hors de la réactivité.**
 * Les 377 nœuds sont créés ici par `createElementNS`, insérés une fois, et
 * toutes les interactions ultérieures — zoom, déplacement, surlignage de
 * recherche, sélection, filtres — passent par `classList` et par un seul
 * attribut `viewBox`. Ils ne figurent **jamais** dans un `v-for` réactif : les
 * faire rendre par Vue à chaque frappe dans la recherche détruirait la
 * fluidité. Vue gère le cadre, le panneau latéral et les contrôles.
 *
 * **RÈGLE N° 2 : jamais `opacity` sur un élément SVG.** Ce fichier ne pose
 * aucune propriété de peinture : il ne fait que poser et retirer des classes,
 * et `canevas.css` ne met en retrait qu'avec `fill-opacity` / `stroke-opacity`.
 * `opacity` créerait un contexte d'empilement, donc une couche de composition
 * par arête ; à 377 arêtes, la frappe devient saccadée.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Pourquoi des `Map` et jamais un sélecteur CSS pour retrouver un nœud.** Un
 * identifiant provisoire vaut `#12/p1` : passé à `querySelector`, le `#` en
 * ferait un sélecteur d'identifiant et le `/` une erreur de syntaxe. On indexe
 * donc les éléments à la construction, et on ne recompose jamais de sélecteur
 * depuis un identifiant de nœud.
 */

import type { Graphe, NoeudGraphe } from '@/lib/api'
import type { Boite, BoiteCentree } from './geometrie'
import { boiteVisible, viewBoxEnTexte } from './geometrie'
import { barreDeDivorce, pointsDeLosange, tracerAretes } from './rendu'
import {
  classesDeFamille,
  classesDeFiche,
  datesDeFiche,
  nomDeFiche,
  indexer,
  type IndexGraphe,
  type NoeudPersonneEtendu,
} from './graphe'
import { ENVELOPPES, enveloppesDeBranche, zonesDeBranche } from './zones'

const NS_SVG = 'http://www.w3.org/2000/svg'

/** Rayon des coins d'une fiche, repris de `rendu.py`. */
const RAYON_FICHE = 5
/** Lignes de texte, mesurées depuis le haut de la fiche. */
/*
 * La fiche AFFICHÉE fait 56 px de haut, avec un emplacement photo de 40 px à
 * gauche — le même pour tout le monde, silhouette quand il n'y a pas de portrait
 * (`MesureDeFiche::HAUTEUR_AFFICHEE`, `LARGEUR_PHOTO`, `ESPACE_PHOTO` côté
 * serveur : c'est lui qui a mesuré `w` avec cet emplacement). Le texte se centre
 * sur la zone qui lui reste, à droite de la photo.
 */
const LARGEUR_PHOTO = 40
const ESPACE_PHOTO = 8
const DECALAGE_TEXTE = (LARGEUR_PHOTO + ESPACE_PHOTO) / 2
const LIGNE_NOM = 24
const LIGNE_DATES = 40

/**
 * Le rond des portraits : un `<clipPath>` SVG **défini une fois** dans le
 * canevas, en unités de boîte englobante, et référencé par chaque `<image>` —
 * la forme que tout moteur SVG sait rogner, là où `clip-path="inset(0 round
 * 50%)"` est une forme CSS posée sur un élément SVG.
 */
const ID_ROND_PORTRAIT = 'rond-portrait'

/**
 * Les vignettes se chargent **quand leur fiche entre dans le `viewBox`**, pas
 * toutes à la construction : 274 fiches font 274 requêtes, et sur un téléphone
 * qui n'en montre que dix, c'est 264 de trop (ADR 0007).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pas d'`IntersectionObserver`.** La première version confiait le moment à
 * l'observateur du navigateur, et Safari — Mac comme iPhone — ne signale jamais
 * l'apparition d'une `<image>` SVG : `data-href` posé, `href` jamais, et un
 * rond vide sur toutes les fiches à photo — vu dans son inspecteur. Le
 * `viewBox` est la seule vérité de ce que le lecteur voit ; c'est lui qui
 * décide, à chaque fois qu'il change ({@link boiteVisible}), et il ne dépend
 * d'aucun moteur. Une image chargée le reste.
 * ────────────────────────────────────────────────────────────────────
 */
interface VignetteEnAttente {
  image: SVGImageElement
  noeud: BoiteCentree
  href: string
}

function creer<K extends keyof SVGElementTagNameMap>(
  nom: K,
  attributs: Record<string, string | number>,
): SVGElementTagNameMap[K] {
  const element = document.createElementNS(NS_SVG, nom)
  for (const [cle, valeur] of Object.entries(attributs)) {
    element.setAttribute(cle, String(valeur))
  }
  return element
}

/** L'interface impérative offerte au composant Vue. */
export interface PiloteCanevas {
  readonly index: IndexGraphe
  /** Le seul endroit du projet qui écrit l'attribut `viewBox`. */
  appliquerViewBox: (vb: Boite) => void
  /** Met en retrait tout ce qui n'est pas dans `garder`. */
  eclairer: (garder: ReadonlySet<string>, selection: string | null, chaine: ReadonlySet<string> | null) => void
  /** Rend tout le graphe à sa peinture pleine. */
  toutAfficher: () => void
  marquerTrouves: (ids: ReadonlySet<string>) => void
  effacerRecherche: () => void
  /**
   * **Déplace ce qui est déjà dessiné, sans rien reconstruire.**
   *
   * Le canevas est bâti une fois : les 460 fiches, les 178 losanges et les 641
   * tracés sont créés par `createElementNS` et insérés une seule fois. Rejouer
   * `construireCanevas` à chaque image d'une animation recréerait mille trois
   * cents éléments soixante fois par seconde — ce n'est pas une option.
   *
   * Cette méthode écrit donc **un `transform` par groupe** et **un `d` par
   * tracé**. Les deux sont nécessaires et le second est le piège : une fiche est
   * un `<g>` qu'une translation suffit à bouger, mais une arête est un chemin
   * dont la forme dépend des DEUX extrémités. Ne translater que les cartes
   * laisserait les traits sur place.
   *
   * **Le graphe passé ne change pas l'état construit.** Rappeler la méthode avec
   * le graphe d'origine remet tout exactement où c'était, et `figerLesPositions`
   * efface les `transform` : la position finale reste celle de la construction,
   * au pixel près.
   */
  appliquerPositions: (deplace: Graphe) => void
  /** Retire les `transform` posés par {@link appliquerPositions}. */
  figerLesPositions: () => void
  /** Le nœud sous un événement de souris, ou `null` hors d'une fiche. */
  noeudSousLeCurseur: (cible: EventTarget | null) => NoeudGraphe | null
  boiteDe: (id: string) => BoiteCentree | null
  boitesDe: (ids: Iterable<string>) => BoiteCentree[]
  detruire: () => void
}

export function construireCanevas(svg: SVGSVGElement, graphe: Graphe): PiloteCanevas {
  const index = indexer(graphe)

  while (svg.firstChild !== null) svg.removeChild(svg.firstChild)

  const gNuages = creer('g', { class: 'nuages' })
  const gZones = creer('g', { class: 'zones' })
  const gAretes = creer('g', { class: 'aretes' })
  const gFamilles = creer('g', { class: 'familles' })
  const gFiches = creer('g', { class: 'fiches' })

  // Le rond partagé des portraits — voir `ID_ROND_PORTRAIT`.
  const defs = creer('defs', {})
  const rond = creer('clipPath', { id: ID_ROND_PORTRAIT, clipPathUnits: 'objectBoundingBox' })
  rond.appendChild(creer('circle', { cx: 0.5, cy: 0.5, r: 0.5 }))
  defs.appendChild(rond)

  /*
   * **L'estompe des bords vient d'un DÉGRADÉ, et c'est une contrainte, pas un goût.**
   *
   * Un nuage à bord flou se fait d'instinct avec `feGaussianBlur`. L'ADR 0007 l'interdit
   * ici, et il nomme `filter` au même titre qu'`opacity` : chacun crée un contexte
   * d'empilement, donc une couche de composition allouée **par élément et par image**, sur
   * un canevas de 750. Un `<radialGradient>` est un *paint server* : il s'applique pendant
   * la peinture, dans la même passe que le reste, et n'alloue rien.
   *
   * `gradientUnits` reste à son défaut — `objectBoundingBox` —, si bien qu'un seul dégradé
   * par enveloppe suit la forme de son chemin quelles que soient sa taille et sa position,
   * y compris quand `ressorts` la redessine à chaque image de l'animation d'arrivée.
   *
   * Les teintes ne sont pas posées ici mais dans `canevas.css`, sur les `stop` : une couleur
   * écrite en JavaScript échapperait au thème et aux jetons de la palette.
   */
  for (const { cle } of ENVELOPPES) {
    const degrade = creer('radialGradient', { id: `nuage-${cle}`, class: `n-${cle}` })
    /*
     * **L'ARRÊT MÉDIAN EST À 0,85, ET CE N'EST PAS UN RÉGLAGE AU GOÛT.**
     *
     * Le dégradé est en `objectBoundingBox` : ses arrêts sont posés en FRACTION de la boîte
     * du chemin, pas en unités. Élargir une enveloppe étire donc le dégradé au lieu
     * d'ajouter de la surface peinte, et la marge gagnée atterrit là où l'alpha vaut zéro.
     * Mesuré : l'élargissement latéral seul ne changeait que **2,4 niveaux sur 255**, sur
     * 7,11 % des pixels — la surface était réelle, son alpha était nul. **On peignait du
     * transparent.**
     *
     * Reculer l'arrêt médian de 0,62 à 0,85 resserre l'estompe sans jamais donner de bord :
     * l'alpha tombe toujours à zéro au bord de la boîte, il y descend seulement plus vite.
     * Mesuré au pixel, sur le graphe servi, lecteur `p3`, même cadrage :
     *
     *     arrêt 0,50   12,09 % des pixels changent · écart moyen  2,4 niveaux
     *     arrêt 0,70   15,65 %                      ·              6,7
     *     arrêt 0,85   15,71 %                      ·             10,1   ← retenu
     *
     * **Le repère qui rend ce 10,1 lisible** : les régions elles-mêmes écartent le fond de
     * **10,0 niveaux** en médiane. À 0,85, un gros nuage a donc la même présence que les
     * deux petits qu'il contient — ni plus, ce qui les écraserait, ni moins, ce qui le
     * rendait invisible.
     *
     * ⚠ **NE PAS MONTER PLUS HAUT SANS REGARDER L'ÉCRAN.** Plus l'arrêt monte, plus la
     * transition se fait sur une frange étroite, et plus le nuage tend vers un aplat à bord
     * franc — c'est-à-dire vers le rendu que le commanditaire a rejeté en premier. Aucune
     * mesure ne dit où est cette limite ; à 0,85 il a dit oui sur image.
     */
    degrade.appendChild(creer('stop', { offset: '0', 'stop-opacity': '0.5' }))
    degrade.appendChild(creer('stop', { offset: '0.85', 'stop-opacity': '0.44' }))
    degrade.appendChild(creer('stop', { offset: '1', 'stop-opacity': '0' }))
    defs.appendChild(degrade)
  }

  /* ---- zones de branche, SOUS les arêtes ---- */

  /*
   * Les aplats de région. Posés en premier, donc peints en premier : au-dessus,
   * ils masqueraient les traits qu'ils sont censés accompagner.
   *
   * Aucune propriété de peinture ici — règle n° 2. La classe `b-…` est celle du
   * liseré de fiche, réutilisée exprès : la teinte d'une région et celle de son
   * liseré ne peuvent pas diverger si elles viennent de la même règle. La mise en
   * retrait, elle, est dans `canevas.css` et passe par `fill-opacity`.
   */
  const zones = new Map<string, SVGPathElement>()
  for (const zone of zonesDeBranche(graphe.noeuds)) {
    const path = creer('path', { class: `zone b-${zone.cle}`, d: zone.d })
    gZones.appendChild(path)
    zones.set(zone.cle, path)
  }

  /*
   * Les gros nuages, SOUS les régions qu'ils contiennent — `gNuages` est ajouté au `<svg>`
   * avant `gZones`, donc peint avant. C'est l'imbrication du croquis : *« dans les gros
   * blocs on retrouve 2 blocs nuage »*.
   */
  const nuages = new Map<string, SVGPathElement>()
  for (const enveloppe of enveloppesDeBranche(graphe.noeuds)) {
    const path = creer('path', {
      class: `nuage n-${enveloppe.cle}`,
      d: enveloppe.d,
      fill: `url(#nuage-${enveloppe.cle})`,
    })
    gNuages.appendChild(path)
    nuages.set(enveloppe.cle, path)
  }

  /**
   * Les aplats suivent les fiches quand elles bougent.
   *
   * Sans cela, l'animation d'arrivée de `ressorts` traînerait les régions
   * derrière les fiches pendant toute sa durée, pour retomber juste à la fin —
   * le genre de défaut qui ne se voit qu'en mouvement. Un aplat n'a pas de
   * `transform` à recevoir : sa forme dépend de TOUS les nœuds de sa rangée, pas
   * d'un seul, exactement comme une arête dépend de ses deux extrémités. On
   * recalcule donc les cinq ou six `d`, ce qui coûte un tri des nœuds — à côté
   * des 377 arêtes retracées dans la même passe, ce n'est pas ce qui pèse.
   */
  const redessinerLesZones = (noeuds: readonly NoeudGraphe[]): void => {
    const aJour = new Set<string>()
    for (const zone of zonesDeBranche(noeuds)) {
      aJour.add(zone.cle)
      zones.get(zone.cle)?.setAttribute('d', zone.d)
    }
    // Une région que le graphe déplacé ne porte plus : on la vide plutôt que de
    // la laisser sur sa dernière forme connue.
    for (const [cle, path] of zones) if (!aJour.has(cle)) path.setAttribute('d', '')

    const nuagesAJour = new Set<string>()
    for (const enveloppe of enveloppesDeBranche(noeuds)) {
      nuagesAJour.add(enveloppe.cle)
      nuages.get(enveloppe.cle)?.setAttribute('d', enveloppe.d)
    }
    for (const [cle, path] of nuages) if (!nuagesAJour.has(cle)) path.setAttribute('d', '')
  }

  /* ---- arêtes, sous les fiches ---- */

  const aretes: { el: SVGPathElement; de: string; vers: string }[] = []
  for (const tracee of tracerAretes(index, graphe.aretes)) {
    const path = creer('path', {
      class: tracee.classes.join(' '),
      'data-de': tracee.de,
      'data-vers': tracee.vers,
      d: tracee.d,
    })
    gAretes.appendChild(path)
    aretes.push({ el: path, de: tracee.de, vers: tracee.vers })
  }

  /* ---- nœuds de famille : losange 22 × 14, pas un cercle ---- */

  const familles = new Map<string, SVGGElement>()
  for (const famille of index.familles) {
    const g = creer('g', {
      class: classesDeFamille(famille).join(' '),
      'data-id': famille.id,
    })
    g.appendChild(creer('polygon', { points: pointsDeLosange(famille) }))
    if (famille.divorced) {
      g.appendChild(creer('path', { class: 'barre', d: barreDeDivorce(famille) }))
    }
    gFamilles.appendChild(g)
    familles.set(famille.id, g)
  }

  /* ---- fiches ---- */

  const fiches = new Map<string, SVGGElement>()
  let vignettesEnAttente: VignetteEnAttente[] = []
  for (const personne of index.personnes) {
    fiches.set(
      personne.id,
      construireFiche(personne, index, gFiches, (image, href) => {
        vignettesEnAttente.push({ image, noeud: personne, href })
      }),
    )
  }

  const fragment = document.createDocumentFragment()
  fragment.appendChild(defs)
  // Les gros nuages d'abord : ils portent les régions, donc ils se peignent dessous.
  fragment.appendChild(gNuages)
  fragment.appendChild(gZones)
  fragment.appendChild(gAretes)
  fragment.appendChild(gFamilles)
  fragment.appendChild(gFiches)
  svg.appendChild(fragment)

  /* ---- pilotage ---- */

  const boiteDe = (id: string): BoiteCentree | null => index.parId.get(id) ?? null

  /** Les abscisses **de construction**, seule référence des translations. */
  const abscisseDOrigine = new Map<string, number>(graphe.noeuds.map((n) => [n.id, n.x]))

  return {
    index,

    appliquerPositions(deplace) {
      const idx = indexer(deplace)

      const translater = (groupes: Map<string, SVGGElement>): void => {
        for (const [id, el] of groupes) {
          const arrivee = idx.parId.get(id)
          const depart = abscisseDOrigine.get(id)
          if (arrivee === undefined || depart === undefined) continue

          const dx = arrivee.x - depart
          if (dx === 0) el.removeAttribute('transform')
          else el.setAttribute('transform', `translate(${dx} 0)`)
        }
      }

      translater(fiches)
      translater(familles)

      const traces = new Map<string, string>()
      for (const tracee of tracerAretes(idx, deplace.aretes)) {
        traces.set(`${tracee.de}\u0000${tracee.vers}`, tracee.d)
      }
      for (const arete of aretes) {
        const d = traces.get(`${arete.de}\u0000${arete.vers}`)
        if (d !== undefined) arete.el.setAttribute('d', d)
      }

      redessinerLesZones(deplace.noeuds)
    },

    figerLesPositions() {
      for (const el of fiches.values()) el.removeAttribute('transform')
      for (const el of familles.values()) el.removeAttribute('transform')
      for (const tracee of tracerAretes(index, graphe.aretes)) {
        const arete = aretes.find((a) => a.de === tracee.de && a.vers === tracee.vers)
        if (arete !== undefined) arete.el.setAttribute('d', tracee.d)
      }

      redessinerLesZones(graphe.noeuds)
    },

    appliquerViewBox(vb) {
      svg.setAttribute('viewBox', viewBoxEnTexte(vb))
      if (vignettesEnAttente.length === 0) return
      vignettesEnAttente = vignettesEnAttente.filter(({ image, noeud, href }) => {
        if (!boiteVisible(noeud, vb)) return true
        image.setAttribute('href', href)
        return false
      })
    },

    eclairer(garder, selection, chaine) {
      svg.classList.add('focus')
      for (const [id, el] of fiches) {
        el.classList.toggle('on', garder.has(id))
        el.classList.toggle('sel', id === selection)
        el.classList.toggle('lien', chaine !== null && chaine.has(id) && id !== selection)
      }
      for (const [id, el] of familles) el.classList.toggle('on', garder.has(id))
      for (const a of aretes) a.el.classList.toggle('on', garder.has(a.de) && garder.has(a.vers))
    },

    toutAfficher() {
      svg.classList.remove('focus')
      for (const el of fiches.values()) el.classList.remove('on', 'sel', 'lien')
      for (const el of familles.values()) el.classList.remove('on')
      for (const a of aretes) a.el.classList.remove('on')
    },

    marquerTrouves(ids) {
      for (const [id, el] of fiches) el.classList.toggle('trouve', ids.has(id))
      svg.classList.add('recherche')
    },

    effacerRecherche() {
      svg.classList.remove('recherche')
      for (const el of fiches.values()) el.classList.remove('trouve')
    },

    noeudSousLeCurseur(cible) {
      if (!(cible instanceof Element)) return null
      const groupe = cible.closest('.fiche, .fam')
      if (groupe === null) return null
      const id = (groupe as SVGGElement).dataset.id
      if (id === undefined) return null
      return index.parId.get(id) ?? null
    },

    boiteDe,

    boitesDe(ids) {
      const boites: BoiteCentree[] = []
      for (const id of ids) {
        const boite = boiteDe(id)
        if (boite !== null) boites.push(boite)
      }
      return boites
    },

    detruire() {
      while (svg.firstChild !== null) svg.removeChild(svg.firstChild)
      svg.classList.remove('focus', 'recherche')
      svg.removeAttribute('viewBox')
    },
  }
}

function construireFiche(
  personne: NoeudPersonneEtendu,
  index: IndexGraphe,
  parent: SVGGElement,
  enregistrerVignette: (image: SVGImageElement, href: string) => void,
): SVGGElement {
  const x = personne.x - personne.w / 2
  const y = personne.y - personne.h / 2
  const unions = index.unionsParPersonne.get(personne.id) ?? 0

  const g = creer('g', {
    class: classesDeFiche(personne, index.ego, unions).join(' '),
    'data-id': personne.id,
  })

  // `<title>` : le nom complet au survol et pour les lecteurs d'écran, alors que
  // la fiche ne porte que l'étiquette courte, mesurée par le moteur.
  const titre = document.createElementNS(NS_SVG, 'title')
  titre.textContent = personne.displayName
  g.appendChild(titre)

  g.appendChild(
    creer('rect', { x, y, width: personne.w, height: personne.h, rx: RAYON_FICHE }),
  )

  /*
   * ⚠ **LE LISERÉ DE BRANCHE A ÉTÉ RETIRÉ D'ICI LE 23/09/2026, ET LES RÈGLES CSS RESTENT.**
   *
   * Une fiche portait deux couleurs : le fond et la bordure disent le **sexe**, un liseré de
   * 4 unités disait la **branche**. Le commanditaire a tranché : *seul le liseré disparaît, le
   * fond de sexe reste.* La branche se lit désormais au nuage, le sexe à la carte — et le
   * sexe est une convention généalogique indépendante des blocs.
   *
   * **LE GESTE EST DE CESSER DE POSER LA CLASSE, JAMAIS DE SUPPRIMER `.br` NI LES `.b-*`.**
   * Ces règles servent 22 autres émissions dans cinq fichiers, relevées à la commande et non
   * de mémoire : les aplats de région juste au-dessus dans ce fichier, la légende de l'arbre,
   * les deux sites de `chronologie/canevas.ts`, la pastille de filtre, et la légende de la
   * chronologie. **Cette dernière est la plus silencieuse** : des pastilles qui perdent leur
   * couleur ne cassent rien, ne rougissent nulle part, n'ont aucun test — personne ne s'en
   * apercevrait avant des semaines.
   *
   * La recette de vérification est un inventaire relancé, pas une liste recopiée :
   *
   *     git grep -nE 'b-(pp|pm|mp|mm|tr|hors|cpp|cpm|cmp|cmm|\$\{)' origin/main    *         -- 'app/src/**\/*.ts' 'app/src/**\/*.vue' | grep -v '\.test\.ts'
   *
   * Guillemets **simples** : entre doubles, le shell mange l'antislash, `$` redevient
   * l'ancre de fin de ligne, et la commande ne rend plus que les classes littérales — en
   * silence, avec un code de sortie 0. Elle rendait 25 lignes dont 2 docblocs, soit 23
   * émissions, avant ce retrait ; 24 et 22 après. **Si elle en rend moins, c'est qu'on a
   * touché à autre chose que cette ligne.**
   */

  // L'emplacement photo, identique pour toutes les fiches : le portrait s'il y en
  // a un et si la fiche est visible du lecteur — la clé est alors présente —,
  // la silhouette sinon. Une image rognée en rond, jamais déformée
  // (`preserveAspectRatio` par défaut : `xMidYMid meet`, complété par `slice`
  // pour remplir le rond).
  const xPhoto = x + ESPACE_PHOTO
  const yPhoto = y + (personne.h - LARGEUR_PHOTO) / 2
  if (personne.portrait !== undefined) {
    const image = creer('image', {
      class: 'photo',
      x: xPhoto,
      y: yPhoto,
      width: LARGEUR_PHOTO,
      height: LARGEUR_PHOTO,
      preserveAspectRatio: 'xMidYMid slice',
      'clip-path': `url(#${ID_ROND_PORTRAIT})`,
    })
    // `data-href` reste lisible dans l'inspecteur ; `href` vient avec le viewBox.
    image.setAttribute('data-href', personne.portrait)
    enregistrerVignette(image as unknown as SVGImageElement, personne.portrait)
    g.appendChild(image)
  } else {
    // La silhouette : une tête et des épaules, en deux formes, dans la teinte de
    // la fiche. Pas d'icône externe — rien à charger pour dire « pas de photo ».
    const cx = xPhoto + LARGEUR_PHOTO / 2
    g.appendChild(
      creer('circle', { class: 'photo-vide', cx, cy: yPhoto + LARGEUR_PHOTO * 0.34, r: LARGEUR_PHOTO * 0.17 }),
    )
    g.appendChild(
      creer('path', {
        class: 'photo-vide',
        d:
          `M${cx - LARGEUR_PHOTO * 0.33},${yPhoto + LARGEUR_PHOTO * 0.92} ` +
          `a${LARGEUR_PHOTO * 0.33},${LARGEUR_PHOTO * 0.33} 0 0 1 ${LARGEUR_PHOTO * 0.66},0 Z`,
      }),
    )
  }

  // `libelle` est le NOM ; `personne.etiquette`, vingt lignes plus bas, est la
  // ligne de DATES. Deux notions que le contrat a fini par séparer justement
  // parce qu'un seul mot les désignait — on ne les rapproche pas ici.
  const libelle = nomDeFiche(personne)
  const nom = creer('text', { class: 'nm', x: personne.x + DECALAGE_TEXTE, y: y + LIGNE_NOM })
  if (libelle.largeurImposee !== null) {
    // Le nom complet dépasse la boîte mesurée par le moteur : on le condense
    // pour qu'il y tienne, plutôt que de le laisser chevaucher les fiches
    // voisines. Aucun nom n'est coupé ni récrit — voir `nomDeFiche`.
    nom.setAttribute('textLength', String(libelle.largeurImposee))
    nom.setAttribute('lengthAdjust', 'spacingAndGlyphs')
  }
  nom.textContent = libelle.texte
  g.appendChild(nom)

  // Absente sur un nœud restreint — on ne dessine alors aucune ligne, et on ne
  // la reconstitue jamais. Voir `datesDeFiche` pour laquelle des trois formes
  // de date c'est, et pourquoi elle est à part.
  const dates = datesDeFiche(personne)
  if (dates !== null) {
    const ligne = creer('text', { class: 'dt', x: personne.x + DECALAGE_TEXTE, y: y + LIGNE_DATES })
    ligne.textContent = dates
    g.appendChild(ligne)
  }

  parent.appendChild(g)
  return g
}
