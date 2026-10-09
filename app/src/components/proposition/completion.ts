/**
 * **« C'est elle : compléter sa fiche »** — ce que la saisie du formulaire
 * « Ajouter une personne » apporte à une fiche qui existe déjà, champ par champ
 * et lien par lien.
 *
 * Décision du commanditaire (09/10/2026) : « on la complète depuis le formulaire.
 * Le formulaire montre les écarts champ par champ et les liens saisis ; on coche
 * ce qu'on garde, et il dépose une demande `modification_personne` (et
 * `ajout_lien` si des liens sont cochés), sans quitter le formulaire ni l'arbre. »
 *
 * ────────────────────────────────────────────────────────────────────
 * **Quatre natures d'écart, et ce que la case dit par défaut.**
 *
 * | Nature | Quand | Case |
 * |---|---|---|
 * | `complement` | la fiche est vide là où la saisie dit quelque chose | cochée |
 * | `precision` | la date saisie tient **dans** celle de la fiche et la resserre, ou le lieu manque à la fiche | cochée |
 * | `divergence` | la saisie **contredit** la fiche | décochée, en alerte : la valeur de la fiche sera remplacée |
 * | `aveugle` | la naissance d'un vivant que le lecteur ne voit pas en entier | décochée |
 *
 * Une précision ou un complément n'ôte rien à la fiche : les cocher d'office est
 * sans risque. Une divergence remplace ce qu'un autre a établi — souvent sur un
 * acte : c'est au lecteur de le vouloir, la case ne le veut pas pour lui.
 *
 * **Un champ vide dans la saisie ne propose jamais d'effacer.** On ne tape pas un
 * nom d'usage dans un formulaire de création pour dire qu'une fiche existante n'en
 * a pas : le vide veut dire « je ne sais pas », jamais « il n'y en a pas ». Même
 * règle pour une date moins précise que celle de la fiche (« 1904 » contre « 11
 * mars 1904 ») et pour la case « décès attesté » laissée vide.
 *
 * **Les textes se comparent repliés** (`replier`) : « Jean-Marie » et « jean marie »
 * ne sont pas un écart — la graphie de la fiche reste.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Le point de départ est un cliché**, pris au clic (`instantaneDeFiche`) et jamais
 * relu en douce : `oldValue` et `baseVersion` portent ce que le lecteur avait sous
 * les yeux, lus dans le **corps** de la fiche et jamais dans son calque
 * `enAttente`. Si la fiche a bougé entre-temps, le serveur rend `409` — et c'est le
 * lecteur qui la relit, explicitement.
 *
 * **La naissance masquée se propose à l'aveugle** (ADR 0016, révision du
 * 07/10/2026, la même règle que le mode édition de la fiche) : on ne la compare
 * pas — on ne la voit pas —, la case n'est pas cochée, et si on la coche le serveur
 * ignore l'`oldValue` et pose lui-même la valeur courante.
 */
import type {
  CorpsAjoutLien,
  CorpsModificationPersonne,
  Demande,
  EnvoiMedia,
  FichePersonneComplete,
  Media,
  Sexe,
  ValeurDate,
} from '@/lib/api'
import { analyserErreur } from '@/lib/api'
import { dateLisible, dejaLies, parentsApresEdition } from '@/components/personne/edition'
import { descriptionDeChamp, LIBELLES_SEXE, type ChampPersonne } from './champs'
import { cleDuChoix, estFicheProposee, type ChoixDePersonne, type ErreurSaisie, type SaisieBrouillon, type SaisieCreation } from './creation'
import { designeUneDate, memeValeurDate, sansTexte, valeurAEnvoyer } from './dates'
import { corpsAjoutLien, liensVides, type SaisieLiens } from './liens'
import {
  construireOps,
  corpsModificationPersonne,
  MOTIF_NAISSANCE_VERROUILLEE,
  naissanceVerrouillee,
  texteOuNull,
  type InstantaneFiche,
  type ValeursPersonne,
} from './operations'
import { photosAEnvoyer } from './photos'
import type { PhotoAJoindre } from './PhotosAJoindre.vue'
import { replier } from './ressemblances'

/* ------------------------------------------------------------------ */
/* Les écarts                                                          */
/* ------------------------------------------------------------------ */

/** Ce qu'une création peut apporter à une fiche : sa surface éditable, moins
 *  les deux réglages de confidentialité, que le formulaire de création n'a pas. */
export type ChampCompletable = Exclude<ChampPersonne, 'forcePrivacy' | 'privacyNote'>

export type NatureEcart = 'complement' | 'precision' | 'divergence' | 'aveugle'

/** Ce qui part en `newValue`. `Sexe` est une chaîne. */
export type ValeurDEcart = string | boolean | ValeurDate

export interface Ecart {
  champ: ChampCompletable
  etiquette: string
  nature: NatureEcart
  /** Ce que la fiche porte, lisible — « (vide) » s'il n'y a rien. */
  avant: string
  /** Ce qui sera proposé, lisible. */
  apres: string
  /** La `newValue` de l'op, si l'écart est retenu. */
  valeur: ValeurDEcart
  /** Coché d'office : un complément ou une précision, jamais le reste. */
  cocheParDefaut: boolean
  /** Ce que l'écran dit sous la ligne, quand il y a quelque chose à dire. */
  motif: string | null
}

const VIDE = '(vide)'

/** Les quatre champs de texte de l'identité, dans l'ordre du formulaire. */
const CHAMPS_TEXTE = ['givenNames', 'surname', 'marriedName', 'nickname'] as const

function ecart(champ: ChampCompletable, nature: NatureEcart, avant: string, apres: string, valeur: ValeurDEcart): Ecart {
  return {
    champ,
    etiquette: descriptionDeChamp(champ).etiquette,
    nature,
    avant,
    apres,
    valeur,
    cocheParDefaut: nature === 'complement' || nature === 'precision',
    motif: nature === 'divergence' ? 'La valeur de la fiche sera remplacée.' : null,
  }
}

/** Une date au sens de `ck_tree_person_deces_coherent` : `raw`, `min` ou `max`. */
function aUneDate(valeur: ValeurDate): boolean {
  return valeur.raw !== null || valeur.min !== null || valeur.max !== null
}

/** Les qualificatifs qui disent le doute. Le reste (`exacte`, `annee`, `mois`)
 *  dit une date établie, à sa précision. */
const APPROCHES = new Set(['environ', 'avant', 'apres', 'entre', 'depuis', 'jusqu_a', 'inconnue'])

/** `a` tient-elle tout entière dans `b` ? Une borne `null` est ouverte. */
function inclus(a: ValeurDate, b: ValeurDate): boolean {
  const bas = b.min === null || (a.min !== null && a.min >= b.min)
  const haut = b.max === null || (a.max !== null && a.max <= b.max)
  return bas && haut
}

type Relation = 'identique' | 'complement' | 'precision' | 'divergence'

/**
 * La date saisie face à celle de la fiche, sans le lieu.
 *
 * - **Précision** : elle tient dans celle de la fiche et la resserre (« 1904 »
 *   dans « entre 1901 et 1909 »), ou elle ôte un doute (« 1904 » pour « vers
 *   1904 »). Ajouter le doute n'est pas préciser : « vers 1904 » pour « 1904 » ne
 *   propose rien.
 * - **Rien** quand la fiche en dit autant ou plus (« 1904 » contre « 11 mars
 *   1904 ») : une date moins précise ne remplace pas une date établie.
 */
function relationDesDates(fiche: ValeurDate, saisie: ValeurDate): Relation {
  if (!aUneDate(saisie)) return 'identique'
  if (!aUneDate(fiche)) return 'complement'
  if (fiche.qualifier === saisie.qualifier && fiche.min === saisie.min && fiche.max === saisie.max) return 'identique'

  const ficheEtablie = !APPROCHES.has(fiche.qualifier)
  const saisieEtablie = !APPROCHES.has(saisie.qualifier)
  if (inclus(saisie, fiche) && !(ficheEtablie && !saisieEtablie)) {
    const plusEtroite = !inclus(fiche, saisie)
    if (plusEtroite || (!ficheEtablie && saisieEtablie)) return 'precision'
  }
  if (inclus(fiche, saisie)) return 'identique'
  return 'divergence'
}

/** « Saule » est le début de « Saule, Ombrage » — mot à mot, pas lettre à lettre. */
function commencePar(long: string, court: string): boolean {
  return long === court || long.startsWith(`${court} `)
}

/**
 * Le lieu saisi face à celui de la fiche. Comparés repliés ; « Saule » contre
 * « Saule, Ombrage, Sylvanie » n'est pas un écart (la fiche en dit plus), et
 * « Saule, Ombrage » contre « Saule » est une précision.
 */
function relationDesLieux(fiche: string | null, saisie: string | null): Relation {
  const s = replier(saisie ?? '')
  if (s === '') return 'identique'
  const f = replier(fiche ?? '')
  if (f === '') return 'complement'
  if (commencePar(f, s)) return 'identique'
  if (commencePar(s, f)) return 'precision'
  return 'divergence'
}

function ecartDeDate(
  champ: 'birth' | 'death',
  bloc: FichePersonneComplete['birth'],
  propose: ValeurDate,
  aveugle: boolean,
): Ecart | null {
  if (aveugle) {
    const vu = bloc?.texte.trim() ?? ''
    // Le lieu reste visible sur un bloc masqué : un lieu laissé vide ne l'efface pas.
    const valeur: ValeurDate = { ...propose, place: propose.place ?? bloc?.place ?? null }
    return {
      ...ecart(champ, 'aveugle', vu === '' ? 'non visible' : `${vu} (en partie masquée)`, dateLisible(valeur), valeur),
      motif: MOTIF_NAISSANCE_VERROUILLEE,
    }
  }

  const avant = bloc === null ? null : sansTexte(bloc)
  if (avant === null || !designeUneDate(avant)) {
    return ecart(champ, 'complement', VIDE, dateLisible(propose), propose)
  }

  const date = relationDesDates(avant, propose)
  const lieu = relationDesLieux(avant.place, propose.place)
  if (date === 'identique' && lieu === 'identique') return null

  // Un seul bloc, un seul op : la date et le lieu partent ensemble. Ce que la
  // saisie ne change pas reste ce que la fiche porte — `raw` compris, la
  // provenance qu'on ne réécrit jamais.
  const source = date === 'identique' ? avant : propose
  const valeur: ValeurDate = {
    raw: source.raw,
    qualifier: source.qualifier,
    min: source.min,
    max: source.max,
    place: lieu === 'identique' ? avant.place : propose.place,
  }
  if (memeValeurDate(avant, valeur)) return null

  const nature: NatureEcart =
    date === 'divergence' || lieu === 'divergence'
      ? 'divergence'
      : date === 'complement' && lieu === 'identique'
        ? 'complement'
        : 'precision'
  return ecart(champ, nature, dateLisible(bloc), dateLisible(valeur), valeur)
}

/** La date saisie, ou `null` si rien ne se compose — une date mal écrite ne
 *  propose rien : elle a son message sous son champ. */
function dateSaisie(saisie: SaisieBrouillon['naissance']): ValeurDate | null {
  const resultat = valeurAEnvoyer(saisie)
  return resultat.valide && resultat.valeur !== null ? sansTexte(resultat.valeur) : null
}

/**
 * Les écarts entre la fiche et la saisie, **dans l'ordre du formulaire**.
 *
 * Les valeurs de la fiche sont lues dans son **corps** — jamais dans le calque
 * `enAttente`, qui porte ce qu'une autre demande propose et non ce qui est validé.
 */
export function ecartsAvecFiche(fiche: FichePersonneComplete, saisie: SaisieBrouillon): Ecart[] {
  const ecarts: Ecart[] = []

  for (const champ of CHAMPS_TEXTE) {
    const propose = texteOuNull(saisie[champ])
    if (propose === null || replier(propose) === '') continue
    const actuel = fiche[champ]
    if (actuel === null || replier(actuel) === '') {
      ecarts.push(ecart(champ, 'complement', VIDE, propose, propose))
    } else if (replier(actuel) !== replier(propose)) {
      ecarts.push(ecart(champ, 'divergence', actuel, propose, propose))
    }
  }

  if (saisie.sex !== 'inconnu' && saisie.sex !== fiche.sex) {
    const nature: NatureEcart = fiche.sex === 'inconnu' ? 'complement' : 'divergence'
    ecarts.push(ecart('sex', nature, LIBELLES_SEXE[fiche.sex], LIBELLES_SEXE[saisie.sex], saisie.sex satisfies Sexe))
  }

  const deces = dateSaisie(saisie.deces)
  // Une date de décès vaut décès attesté : la base refuse l'une sans l'autre.
  if (!fiche.deceased && (saisie.deceased || (deces !== null && aUneDate(deces)))) {
    ecarts.push(ecart('deceased', 'complement', 'non', 'oui', true))
  }

  const naissance = dateSaisie(saisie.naissance)
  if (naissance !== null) {
    const e = ecartDeDate('birth', fiche.birth, naissance, naissanceVerrouillee(fiche))
    if (e !== null) ecarts.push(e)
  }
  if (deces !== null) {
    const e = ecartDeDate('death', fiche.death, deces, false)
    if (e !== null) ecarts.push(e)
  }

  return ecarts
}

/** La clé d'un écart : son champ **et** sa valeur. Une saisie qui change la
 *  valeur proposée rend une autre clé — et la case revient à son défaut. */
export function cleDEcart(e: Ecart): string {
  return `${e.champ}=${JSON.stringify(e.valeur)}`
}

export function ecartsCochesParDefaut(ecarts: readonly Ecart[]): string[] {
  return ecarts.filter((e) => e.cocheParDefaut).map(cleDEcart)
}

function porteUneDateDeDeces(e: Ecart | undefined): boolean {
  return e !== undefined && typeof e.valeur === 'object' && aUneDate(e.valeur)
}

/**
 * Coche ou décoche un écart. **Le décès et sa date vont ensemble** : cocher une
 * date de décès coche « décès attesté », décocher « décès attesté » décoche la
 * date — `ck_tree_person_deces_coherent` refuserait l'une sans l'autre.
 */
export function basculerEcart(ecarts: readonly Ecart[], retenus: readonly string[], cle: string): string[] {
  const visee = ecarts.find((e) => cleDEcart(e) === cle)
  if (visee === undefined) return [...retenus]
  const deces = ecarts.find((e) => e.champ === 'death')
  const decede = ecarts.find((e) => e.champ === 'deceased')

  if (retenus.includes(cle)) {
    const restants = retenus.filter((c) => c !== cle)
    if (visee.champ === 'deceased' && porteUneDateDeDeces(deces)) return restants.filter((c) => c !== cleDEcart(deces!))
    return restants
  }
  const ajoutes = [...retenus, cle]
  if (visee.champ === 'death' && porteUneDateDeDeces(visee) && decede !== undefined && !ajoutes.includes(cleDEcart(decede))) {
    ajoutes.push(cleDEcart(decede))
  }
  return ajoutes
}

/**
 * Les valeurs proposées : le cliché, et par-dessus les écarts retenus. Une date
 * de décès retenue rend la personne décédée, quoi qu'on ait coché à côté.
 */
export function valeursRetenues(instantane: InstantaneFiche, ecarts: readonly Ecart[], retenus: readonly string[]): ValeursPersonne {
  const valeurs: ValeursPersonne = { ...instantane.valeurs }
  for (const e of ecarts) {
    if (!retenus.includes(cleDEcart(e))) continue
    switch (e.champ) {
      case 'givenNames':
      case 'surname':
      case 'marriedName':
      case 'nickname':
        valeurs[e.champ] = e.valeur as string
        break
      case 'sex':
        valeurs.sex = e.valeur as Sexe
        break
      case 'deceased':
        valeurs.deceased = e.valeur as boolean
        break
      case 'birth':
      case 'death':
        valeurs[e.champ] = e.valeur as ValeurDate
        break
    }
  }
  if (valeurs.death !== null && aUneDate(valeurs.death) && !instantane.valeurs.deceased) valeurs.deceased = true
  return valeurs
}

/* ------------------------------------------------------------------ */
/* Les liens                                                           */
/* ------------------------------------------------------------------ */

export type NatureLienDeFiche = 'parent' | 'famille' | 'conjoint' | 'enfant'

/**
 * Un lien de la saisie, vu depuis la fiche existante :
 *  - `proposable` — il s'ajoutera, coché d'office ;
 *  - `present` — la fiche le porte déjà : grisé, rien ne part ;
 *  - `impossible` — il contredirait la fiche (un troisième parent, une personne
 *    déjà liée autrement) : grisé, avec sa raison.
 */
export type StatutLien = 'proposable' | 'present' | 'impossible'

export interface LienPourFiche {
  cle: string
  nature: NatureLienDeFiche
  libelle: string
  statut: StatutLien
  raison: string | null
}

type Role = 'elle' | 'parent' | 'conjoint' | 'enfant'

/** La place qu'une personne de l'arbre occupe déjà autour de la fiche, ou `null`. */
function roleDans(fiche: FichePersonneComplete, choix: ChoixDePersonne): Role | null {
  if (estFicheProposee(choix)) return null
  const id = choix.personId
  if (id === fiche.id) return 'elle'
  if (fiche.parents.some((p) => p.personId === id)) return 'parent'
  if (fiche.unions.some((u) => u.conjoint?.personId === id)) return 'conjoint'
  if (fiche.unions.some((u) => u.enfants.some((e) => e.personId === id))) return 'enfant'
  return null
}

/** `dejaLies` sans édition en cours : la fiche telle qu'elle est. */
const SANS_CHANGEMENT = { retraits: [], ajouts: { parents: [], conjoints: [], enfants: [] } }

const PROPOSABLE: Pick<LienPourFiche, 'statut' | 'raison'> = { statut: 'proposable', raison: null }

/**
 * Les liens de la saisie, **dans l'ordre du bloc « Liens »** : la filiation, les
 * unions, les enfants.
 *
 * Une personne **déjà liée** à la fiche (`dejaLies`, la règle du mode édition) ne
 * s'ajoute jamais : à sa place, le lien est `present` ; ailleurs, il la
 * contredirait — on ne devient pas le parent de son propre enfant.
 *
 * `retenus` compte pour les parents : la fiche n'en a que deux places, et un
 * parent qu'on n'a pas retenu devient impossible dès que les deux sont prises —
 * par la fiche ou par ceux qu'on a cochés (`parentsApresEdition`).
 */
export function liensPourFiche(
  fiche: FichePersonneComplete,
  saisie: SaisieCreation,
  retenus: readonly string[],
): LienPourFiche[] {
  const lies = new Set(dejaLies(fiche, SANS_CHANGEMENT))
  const liens: LienPourFiche[] = []

  /** Le statut d'une personne déjà liée, ou `null` pour une personne nouvelle ici. */
  const dejaLa = (choix: ChoixDePersonne, attendu: Role, present: string): Pick<LienPourFiche, 'statut' | 'raison'> | null => {
    if (!lies.has(cleDuChoix(choix))) return null
    const role = roleDans(fiche, choix)
    if (role === attendu) return { statut: 'present', raison: present }
    return {
      statut: 'impossible',
      raison:
        role === 'elle' || role === null
          ? 'C’est la fiche elle-même.'
          : `Déjà son ${role} dans l’arbre : un second lien le contredirait.`,
    }
  }

  /* --- Le couple de parents, quand une reprise en rapporte un ----------- */
  const famille = saisie.filiation.famille
  if (famille !== null) {
    const base = { cle: `famille:${famille.familyId}`, nature: 'famille' as const, libelle: `Enfant du couple ${famille.libelle}` }
    if (saisie.filiation.parents.length > 0) {
      liens.push({ ...base, statut: 'impossible', raison: 'Désignez soit le couple, soit les parents un par un — pas les deux.' })
    } else if (fiche.parents.some((p) => p.familyId === famille.familyId)) {
      liens.push({ ...base, statut: 'present', raison: 'Elle est déjà l’enfant de ce couple dans l’arbre.' })
    } else if (fiche.parents.length > 0) {
      liens.push({ ...base, statut: 'impossible', raison: 'Elle a déjà des parents dans l’arbre : ce couple lui en donnerait d’autres.' })
    } else {
      liens.push({ ...base, ...PROPOSABLE })
    }
  }

  /* --- Les parents -------------------------------------------------------- */
  const cleDeParent = (choix: ChoixDePersonne) => `parent:${cleDuChoix(choix)}`
  // Les parents nouveaux qu'on a cochés, **dans la limite des places** : au-delà,
  // même coché, un parent ne part pas — la fiche en aurait trois.
  const libres = Math.max(0, 2 - parentsApresEdition(fiche, SANS_CHANGEMENT))
  const gardes = saisie.filiation.parents
    .filter((choix) => !lies.has(cleDuChoix(choix)) && retenus.includes(cleDeParent(choix)))
    .slice(0, libres)
  const parents = parentsApresEdition(fiche, { retraits: [], ajouts: { parents: gardes, conjoints: [], enfants: [] } })

  for (const choix of saisie.filiation.parents) {
    const base = { cle: cleDeParent(choix), nature: 'parent' as const, libelle: `Parent : ${choix.libelle}` }
    const deja = dejaLa(choix, 'parent', 'Déjà son parent dans l’arbre.')
    if (deja !== null) liens.push({ ...base, ...deja })
    else if (gardes.includes(choix) || parents < 2) liens.push({ ...base, ...PROPOSABLE })
    else {
      liens.push({
        ...base,
        statut: 'impossible',
        raison:
          fiche.parents.length >= 2
            ? 'Elle a déjà deux parents dans l’arbre : un troisième ne s’ajoute pas.'
            : 'Elle aurait trois parents avec ceux que vous gardez : décochez l’autre pour retenir celui-ci.',
      })
    }
  }

  /* --- Les unions ---------------------------------------------------------- */
  for (const union of saisie.unions) {
    const base = { cle: `conjoint:${cleDuChoix(union.personne)}`, nature: 'conjoint' as const, libelle: `Conjoint : ${union.personne.libelle}` }
    liens.push({ ...base, ...(dejaLa(union.personne, 'conjoint', 'Déjà son conjoint dans l’arbre.') ?? PROPOSABLE) })
  }

  /* --- Les enfants ---------------------------------------------------------- */
  for (const enfant of saisie.enfants) {
    const base = { cle: `enfant:${cleDuChoix(enfant.personne)}`, nature: 'enfant' as const, libelle: `Enfant : ${enfant.personne.libelle}` }
    liens.push({ ...base, ...(dejaLa(enfant.personne, 'enfant', 'Déjà son enfant dans l’arbre.') ?? PROPOSABLE) })
  }

  return liens
}

/** Les liens cochés d'office : tous les proposables, les parents dans la limite
 *  des deux places — le premier saisi d'abord. */
export function liensCochesParDefaut(fiche: FichePersonneComplete, saisie: SaisieCreation): string[] {
  const retenus: string[] = []
  for (const lien of liensPourFiche(fiche, saisie, [])) {
    if (lien.statut !== 'proposable') continue
    // Chaque parent retenu prend une place : on relit après chaque ajout.
    const relu = liensPourFiche(fiche, saisie, retenus).find((l) => l.cle === lien.cle)
    if (relu?.statut === 'proposable') retenus.push(lien.cle)
  }
  return retenus
}

/** Les unions de la fiche où un enfant peut entrer. */
export function unionsPourEnfant(fiche: FichePersonneComplete): { familleId: number; libelle: string }[] {
  return fiche.unions.map((u) => ({
    familleId: u.familyId,
    libelle: u.conjoint === null ? 'Dans son union sans conjoint connu' : `Avec ${u.conjoint.displayName}`,
  }))
}

/**
 * L'union d'un enfant, par défaut : **la seule**, quand la fiche n'en a qu'une —
 * sans quoi l'enfant rejoindrait « la fiche seule » au lieu du couple, et le
 * graphe montrerait un second losange pour un couple qui existe (`liens.ts`).
 * Plusieurs : aucune, et l'écran demande laquelle.
 */
export function unionParDefaut(fiche: FichePersonneComplete): number | null {
  return fiche.unions.length === 1 ? fiche.unions[0]!.familyId : null
}

/**
 * Les liens retenus, **dans la forme de `liens.ts`**, qui sait composer
 * `ajout_lien` : la fiche est le sujet, et `targetId` la désigne.
 *
 * Le couple de parents d'une reprise s'écrit comme une fratrie dont la fiche est
 * le membre : « enfant de ce couple », `enfant_de` + `familyId` — exactement le
 * lien qu'elle aurait porté à sa création.
 */
export function saisieLiensRetenus(
  fiche: FichePersonneComplete,
  saisie: SaisieCreation,
  retenus: readonly string[],
  unionsDesEnfants: Readonly<Record<string, number | null>>,
): SaisieLiens {
  const garde = new Set(
    liensPourFiche(fiche, saisie, retenus)
      .filter((lien) => lien.statut === 'proposable' && retenus.includes(lien.cle))
      .map((lien) => lien.cle),
  )
  const sortie = liensVides(fiche.id)
  const famille = saisie.filiation.famille
  if (famille !== null && garde.has(`famille:${famille.familyId}`)) {
    sortie.fratrie.push({ personne: { personId: fiche.id, libelle: fiche.displayName }, familleId: famille.familyId, rangFratrie: null })
  }
  sortie.parents = saisie.filiation.parents.filter((choix) => garde.has(`parent:${cleDuChoix(choix)}`))
  sortie.unions = saisie.unions.filter((union) => garde.has(`conjoint:${cleDuChoix(union.personne)}`))
  sortie.enfants = saisie.enfants
    .filter((enfant) => garde.has(`enfant:${cleDuChoix(enfant.personne)}`))
    .map((enfant) => {
      const cle = `enfant:${cleDuChoix(enfant.personne)}`
      const choisie = Object.hasOwn(unionsDesEnfants, cle) ? unionsDesEnfants[cle]! : unionParDefaut(fiche)
      // Une union qui n'est plus celle de la fiche (relue entre-temps) : aucune.
      const familleId = choisie !== null && fiche.unions.some((u) => u.familyId === choisie) ? choisie : null
      return { personne: enfant.personne, familleId, rangFratrie: enfant.rangFratrie }
    })
  return sortie
}

/* ------------------------------------------------------------------ */
/* Les demandes                                                        */
/* ------------------------------------------------------------------ */

/**
 * Le mot qui accompagne les demandes : la note du formulaire — « sur quoi vous
 * appuyez-vous ? », la source — puis le message à l'administrateur. Une création
 * garde sa note sur la fiche proposée ; une modification n'a pas de note, et la
 * source ne doit pas se perdre en chemin.
 */
export function messageDeCompletion(note: string, message: string): string {
  return [note, message]
    .map((morceau) => morceau.trim())
    .filter((morceau) => morceau !== '')
    .join('\n\n')
}

export type CorpsDeCompletion = CorpsModificationPersonne | CorpsAjoutLien

export type DemandesDeCompletion =
  | { valide: true; corps: CorpsDeCompletion[] }
  | { valide: false; erreurs: ErreurSaisie[] }

export interface ChoixDeCompletion {
  ecarts: readonly Ecart[]
  /** Les écarts cochés ({@link cleDEcart}). */
  retenus: readonly string[]
  /** Les liens cochés ({@link LienPourFiche.cle}). */
  liensRetenus: readonly string[]
  unionsDesEnfants: Readonly<Record<string, number | null>>
  message: string
}

/**
 * Les corps à poster, **dans cet ordre** : les champs (`modification_personne`),
 * puis les liens (`ajout_lien`). Deux natures, deux demandes : une demande porte
 * une nature (`liens.ts`), et l'administrateur peut valider l'une sans l'autre.
 * Rien de retenu : une liste vide — l'écran le dit, et rien ne part.
 */
export function demandesDeCompletion(
  fiche: FichePersonneComplete,
  instantane: InstantaneFiche,
  saisie: SaisieCreation,
  choix: ChoixDeCompletion,
): DemandesDeCompletion {
  const options = { message: choix.message, soumettre: true }
  const corps: CorpsDeCompletion[] = []

  const valeurs = valeursRetenues(instantane, choix.ecarts, choix.retenus)
  if (construireOps(instantane, valeurs).length > 0) corps.push(corpsModificationPersonne(instantane, valeurs, options))

  const liens = saisieLiensRetenus(fiche, saisie, choix.liensRetenus, choix.unionsDesEnfants)
  const nombre = liens.parents.length + liens.fratrie.length + liens.unions.length + liens.enfants.length
  if (nombre > 0) {
    const ajout = corpsAjoutLien(liens, options)
    if (!ajout.valide) return { valide: false, erreurs: ajout.erreurs }
    corps.push(ajout.corps)
  }

  return { valide: true, corps }
}

/* ------------------------------------------------------------------ */
/* Les photos                                                          */
/* ------------------------------------------------------------------ */

/**
 * Les photos de la saisie, **sur la fiche existante** : téléversées sur la
 * personne (`subjectId`), puis portées chacune par sa demande `ajout_media` —
 * sans elle, un média déposé par un membre reste orphelin, en attente pour
 * toujours (signalé le 03/10/2026).
 *
 * En série, comme partout ailleurs (un limiteur à vingt par heure). **Rien ne part
 * deux fois** : `televersees` garde, photo par photo, le média déjà déposé — si la
 * demande seule a échoué, le second clic ne renvoie que la demande.
 */
export async function envoyerLesPhotosSurLaFiche(
  photos: readonly PhotoAJoindre[],
  personId: number,
  televersees: Map<number, Media>,
  televerser: (envoi: EnvoiMedia) => Promise<Media>,
  proposer: (media: Media, personId: number) => Promise<Demande | null>,
): Promise<{ echecs: number; demandeIds: number[] }> {
  let echecs = 0
  const demandeIds: number[] = []
  for (const photo of photosAEnvoyer(photos)) {
    photo.etat = 'en_cours'
    photo.message = null
    try {
      let media = televersees.get(photo.cle)
      if (media === undefined) {
        const description = photo.description.trim()
        media = await televerser({
          fichier: photo.fichier,
          kind: 'preuve',
          subjectKind: 'personne',
          subjectId: personId,
          ...(description.length > 0 ? { caption: description } : {}),
        })
        televersees.set(photo.cle, media)
      }
      const demande = await proposer(media, personId)
      if (demande !== null) demandeIds.push(demande.id)
      photo.etat = 'reussie'
    } catch (cause) {
      photo.etat = 'echec'
      photo.message = analyserErreur(cause).message
      echecs++
    }
  }
  return { echecs, demandeIds }
}
