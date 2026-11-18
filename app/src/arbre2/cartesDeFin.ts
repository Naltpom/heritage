/**
 * Les **cartes « Recherche close »** de `/arbre` (révision du 18/11/2026 de l'ADR 0023).
 *
 * Un côté clos faute d'archives se voyait par un bord brique et une icône « mur » de 14 px
 * dans le coin de la carte de l'enfant — trop petite pour être vue, et muette sur ce qu'elle
 * voulait dire. Le commanditaire, le 18/11/2026 : *« je voudrais que ça soit une card à part,
 * sur l'emplacement du parent qu'on ne peut pas trouver, et pas une icône qu'on ne voit pas »*.
 *
 * La carte est donc un **nœud de plus dans le graphe**, posé **avant** la disposition : le
 * moteur la place exactement où il placerait le parent — au-dessus de l'enfant, à côté de
 * l'autre parent s'il est connu, dans le rectangle de la branche qu'aurait ce parent. Aucun
 * moteur n'a à la connaître pour la poser ; seuls le rendu (`apparence.ts`, `libelles.ts`) et
 * les gestes de l'écran la reconnaissent, par `NoeudPersonne.carteDeFin`.
 *
 * ## Les règles
 *
 * - **Une carte par côté clos**, selon `etatDuSommet()` : une fin de recherche **et** aucun
 *   parent lié de ce côté. Un parent connu l'emporte, la carte n'est pas posée.
 * - **Elle rejoint la famille des parents qui existe** — la mère est connue, le père est
 *   « inconnu » : la carte devient l'époux de la mère, et l'enfant reste sous le couple. Sans
 *   famille de parents, une **famille virtuelle** la porte (les deux cartes d'un enfant clos
 *   des deux côtés la partagent).
 * - **La branche est celle qu'aurait le parent** (`CalculAscendance::branches()` côté
 *   serveur) : le père d'un parent de l'ego ouvre `pp` ou `mp`, celui du conjoint `cpp`… ;
 *   au-delà, la carte reste dans la branche de l'enfant.
 * - **Ce n'est pas une personne** : `personId` à `null`, ni dates (`anneeDe()` lirait
 *   l'année du motif comme une naissance) ni nom de famille (le nom d'un foyer prend celui
 *   de l'autre époux). L'écran l'exclut des effectifs et renvoie un clic vers l'enfant.
 *
 * Pur, sans DOM.
 */
import type {
  AreteGraphe,
  Branche,
  CarteDeFinDeRecherche,
  CoteDeRecherche,
  Graphe,
  IdentifiantNoeud,
  NoeudFamille,
  NoeudGraphe,
  NoeudPersonne,
} from '@/lib/api'
import { COTES_DE_RECHERCHE, cotesDUneFamille, finDuCote, libelleDuCote, libelleDuMotif } from '@/lib/finDeRecherche'

/** Ce que la carte écrit en titre. */
export const TITRE_DE_LA_CARTE = 'Recherche close'

/**
 * La largeur minimale d'une carte (unités du monde) : sans photo, le titre et un motif comme
 * « Registres inexistants avant 1668 » tiennent sur deux lignes. Une carte plus étroite que
 * celle de l'enfant prend la sienne.
 */
export const LARGEUR_MIN_DE_LA_CARTE = 168

/** Le nœud est-il une carte « Recherche close » ? */
export function estCarteDeFin(n: NoeudGraphe): n is NoeudPersonne & { carteDeFin: CarteDeFinDeRecherche } {
  return n.type === 'personne' && n.carteDeFin !== undefined
}

/** Le motif de la carte, en clair : « Registres inexistants avant 1668 », « Père inconnu ». */
export function motifDeLaCarte(carte: CarteDeFinDeRecherche): string {
  return libelleDuMotif(carte.reason, carte.beforeYear)
}

/** L'identifiant de la carte d'un côté — opaque comme tout identifiant de nœud. */
const idDeCarte = (enfant: IdentifiantNoeud, cote: CoteDeRecherche): IdentifiantNoeud => `fin:${enfant}:${cote}`
const idDeFamille = (enfant: IdentifiantNoeud): IdentifiantNoeud => `fin:${enfant}:famille`

/**
 * Le graphe, augmenté d'une carte à la place de chaque parent introuvable. Le graphe reçu
 * n'est pas modifié ; sans aucun côté clos, il est rendu tel quel (même objet).
 */
export function avecCartesDeFinDeRecherche(graphe: Graphe): Graphe {
  const personnes = new Map<IdentifiantNoeud, NoeudPersonne>()
  for (const n of graphe.noeuds) if (n.type === 'personne') personnes.set(n.id, n)

  const epoux = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const famillesDeParents = new Map<IdentifiantNoeud, IdentifiantNoeud[]>()
  const ajouter = (table: Map<IdentifiantNoeud, IdentifiantNoeud[]>, cle: IdentifiantNoeud, valeur: IdentifiantNoeud): void => {
    const liste = table.get(cle)
    if (liste) liste.push(valeur)
    else table.set(cle, [valeur])
  }
  for (const a of graphe.aretes) {
    if (a.genre === 'union') ajouter(epoux, a.vers, a.de)
    else ajouter(famillesDeParents, a.vers, a.de)
  }

  /** Les époux d'une famille, sans l'enfant (une arête mal formée ne fait pas de lui son parent). */
  const parentsDans = (famille: IdentifiantNoeud, enfant: IdentifiantNoeud): NoeudPersonne[] =>
    (epoux.get(famille) ?? []).filter((id) => id !== enfant).flatMap((id) => personnes.get(id) ?? [])
  /** Les côtés connus d'une famille, par la règle de `Tree` (`cotesDUneFamille()`). */
  const cotesDe = (famille: IdentifiantNoeud, enfant: IdentifiantNoeud) => cotesDUneFamille(parentsDans(famille, enfant).map((p) => p.sex))
  /** Le parent d'un côté dans la première famille qui le porte — pour reconnaître le père de l'ego. */
  const parentDuCote = (enfant: IdentifiantNoeud, cote: CoteDeRecherche): IdentifiantNoeud | null => {
    for (const famille of famillesDeParents.get(enfant) ?? []) {
      const parents = parentsDans(famille, enfant)
      const pere = parents.find((p) => p.sex === 'masculin') ?? parents.find((p) => p.sex !== 'feminin') ?? null
      const mere = parents.find((p) => p.sex === 'feminin' && p !== pere) ?? parents.find((p) => p !== pere) ?? null
      const choisi = cote === 'pere' ? pere : mere
      if (choisi) return choisi.id
    }
    return null
  }

  // Les quarts qu'ouvre un parent introuvable du tronc : celui de l'ego, celui de son conjoint.
  const quartsOuverts = new Map<IdentifiantNoeud, Record<CoteDeRecherche, Branche>>()
  const ego = graphe.ego
  const pereDeLEgo = parentDuCote(ego, 'pere')
  const mereDeLEgo = parentDuCote(ego, 'mere')
  if (pereDeLEgo) quartsOuverts.set(pereDeLEgo, { pere: 'pp', mere: 'pm' })
  if (mereDeLEgo) quartsOuverts.set(mereDeLEgo, { pere: 'mp', mere: 'mm' })
  // Le conjoint de l'ego (le premier qui a des parents, comme au serveur ; sinon le premier) :
  // ses parents ouvrent `cpp` et `cmp`, leurs propres parents les quatre quarts du conjoint.
  const conjoints = graphe.aretes
    .filter((a) => a.genre === 'union' && a.de === ego)
    .flatMap((a) => (epoux.get(a.vers) ?? []).filter((id) => id !== ego && personnes.has(id)))
  const conjoint = conjoints.find((c) => (famillesDeParents.get(c) ?? []).length > 0) ?? conjoints[0]
  if (conjoint !== undefined) {
    quartsOuverts.set(conjoint, { pere: 'cpp', mere: 'cmp' })
    const pereDuConjoint = parentDuCote(conjoint, 'pere')
    const mereDuConjoint = parentDuCote(conjoint, 'mere')
    if (pereDuConjoint && !quartsOuverts.has(pereDuConjoint)) quartsOuverts.set(pereDuConjoint, { pere: 'cpp', mere: 'cpm' })
    if (mereDuConjoint && !quartsOuverts.has(mereDuConjoint)) quartsOuverts.set(mereDuConjoint, { pere: 'cmp', mere: 'cmm' })
  }
  const brancheDeLaCarte = (enfant: NoeudPersonne, cote: CoteDeRecherche): Branche =>
    enfant.id === ego ? enfant.branch : (quartsOuverts.get(enfant.id)?.[cote] ?? enfant.branch)

  const gabaritDeFamille = graphe.noeuds.find((n): n is NoeudFamille => n.type === 'famille')
  const noeuds: NoeudGraphe[] = []
  const aretes: AreteGraphe[] = []

  for (const enfant of personnes.values()) {
    if (enfant.visibilite === 'restreinte' || enfant.carteDeFin !== undefined) continue
    if (enfant.finDeRecherchePere === undefined && enfant.finDeRechercheMere === undefined) continue
    const familles = famillesDeParents.get(enfant.id) ?? []
    // Les côtés connus, toutes familles de parents confondues (une naissance, une adoption).
    const connus = { pere: false, mere: false }
    for (const f of familles) {
      const c = cotesDe(f, enfant.id)
      connus.pere ||= c.pere
      connus.mere ||= c.mere
    }
    let familleVirtuelle: IdentifiantNoeud | null = null
    for (const cote of COTES_DE_RECHERCHE) {
      const fin = finDuCote(enfant, cote)
      if (fin === null || connus[cote]) continue
      const carte: NoeudPersonne = {
        id: idDeCarte(enfant.id, cote),
        type: 'personne',
        personId: null,
        x: 0,
        y: 0,
        w: Math.max(enfant.w || 0, LARGEUR_MIN_DE_LA_CARTE),
        h: enfant.h,
        displayName: `${TITRE_DE_LA_CARTE} ${libelleDuCote(cote)} : ${libelleDuMotif(fin.reason, fin.beforeYear)}`,
        // Sans espace ordinaire : `nomDeFamilleDe()` n'y lit aucun nom, le foyer prend celui de l'autre époux.
        etiquetteNom: TITRE_DE_LA_CARTE.replace(' ', ' '),
        sex: cote === 'pere' ? 'masculin' : 'feminin',
        generation: enfant.generation - 1,
        branch: brancheDeLaCarte(enfant, cote),
        vivant: false,
        visibilite: 'complete',
        ...(enfant.parente !== undefined ? { parente: enfant.parente } : {}),
        ...(enfant.pont !== undefined ? { pont: enfant.pont } : {}),
        carteDeFin: { enfant: enfant.id, cote, reason: fin.reason, beforeYear: fin.beforeYear ?? null },
      }
      noeuds.push(carte)
      // La famille des parents qui existe ; sinon la famille virtuelle, partagée par les deux côtés.
      let famille = familles[0]
      if (famille === undefined) {
        if (familleVirtuelle === null) {
          familleVirtuelle = idDeFamille(enfant.id)
          noeuds.push({
            id: familleVirtuelle,
            type: 'famille',
            familyId: null,
            x: 0,
            y: 0,
            w: gabaritDeFamille?.w ?? 0,
            h: gabaritDeFamille?.h ?? 0,
            divorced: false,
            married: null,
          })
          aretes.push({ de: familleVirtuelle, vers: enfant.id, genre: 'filiation' })
        }
        famille = familleVirtuelle
      }
      aretes.push({ de: carte.id, vers: famille, genre: 'union' })
    }
  }

  if (noeuds.length === 0) return graphe
  return { ...graphe, noeuds: [...graphe.noeuds, ...noeuds], aretes: [...graphe.aretes, ...aretes] }
}
