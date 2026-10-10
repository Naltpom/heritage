/**
 * **Compléter sur place une proposition en attente** — § 6.6 ter, ADR 0017.
 *
 * Décision du commanditaire du 09/10/2026 : l'auteur d'une proposition, et
 * l'administrateur, la **complètent sur place** — une date de décès retrouvée, un
 * lien de plus — au lieu de la retirer pour tout ressaisir, ou d'en déposer une
 * seconde. Le formulaire « Ajouter une personne » passe alors en **mode
 * complétion** : il se remplit de la proposition, garde ce que la saisie y
 * ajoute, et repart en `PUT /api/demandes/{id}/brouillons/{ref}` au lieu d'un
 * `POST /api/demandes`. Ce module en est la partie sans écran : tout s'y teste
 * sans monter un formulaire.
 *
 * ────────────────────────────────────────────────────────────────────
 * **D'où vient la proposition.** De la demande entière — `lireDemande` pour son
 * auteur, `lireDemandeAdministration` pour l'administrateur —, et non de
 * `lireFicheProposee` ni du détail d'un nœud : il faut la fiche **et tous ses
 * liens**, et ces deux lectures-là en donnent trop peu. On y prend la fiche dont
 * la référence est demandée (`brouillons.find(localRef)`) — une demande de
 * l'assistant en porte plusieurs, et ce n'est pas toujours `#p1`.
 *
 * **La proposition l'emporte, la saisie remplit les vides** (`fusionnerBrouillons`).
 * C'est la règle de la fusion d'un complément côté serveur (§ 6.7 bis), et pour
 * la même raison : ce qui est déjà proposé a été relu par son auteur, ce qu'on
 * vient de taper ne l'a pas encore été. Un désaccord n'est pas tranché en
 * silence : c'est une **divergence**, que l'écran montre avec « Prendre ma
 * valeur ». Les notes ne se disputent pas, elles s'ajoutent.
 *
 * **Les liens ne font qu'ajouter** (§ 6.6 ter) : ceux de la proposition restent,
 * en lecture seule, et ceux de la saisie qui y sont déjà sont retirés avant
 * l'envoi (`retirerLiensDejaProposes`) — sans quoi le serveur les ajouterait une
 * seconde fois.
 *
 * **Le corps garde la `localRef`** de la fiche (`#p3` reste `#p3`) : c'est elle
 * que les liens à ajouter désignent comme sujet, et le serveur la tolère tant
 * qu'elle désigne la fiche de l'adresse. Et **aucun bloc date n'y porte
 * `texte`** : la fiche reçue en a six clés, celle qui repart en a cinq (§ 14.1).
 * ────────────────────────────────────────────────────────────────────
 */

import type {
  BlocDate,
  BrouillonDemande,
  CorpsMiseAJourBrouillon,
  Demande,
  LienAAjouter,
  LienDemande,
  LienPropose,
  RessemblanceProposition,
  RessemblancePropositionComplete,
  Sexe,
  StatutDemande,
  ValeurDate,
} from '@/lib/api'
import { lienLisible } from '@/components/administration/liens'
import { LIBELLES_SEXE } from './champs'
import { apercuDeValeur, designeUneDate, memeValeurDate, saisieDepuisBloc, valeurAEnvoyer, type SaisieDate } from './dates'
import {
  cleDuChoix,
  construireBrouillon,
  construireLiens,
  type ErreurSaisie,
  type SaisieBrouillon,
  type SaisieCreation,
} from './creation'
import { lireBlocDate, saisieDepuisProposee } from './reprise'

/* ------------------------------------------------------------------ */
/* Ce qu'on demande à compléter                                        */
/* ------------------------------------------------------------------ */

/**
 * « Complète cette fiche-là » : une demande, et la référence d'une de ses fiches.
 *
 * `ref` arrive sous l'une ou l'autre forme que le serveur rend : `p1` (le
 * `brouillonRef` d'un nœud du graphe, l'adresse d'une page) ou `#p1` (la
 * `localRef` d'une ressemblance). `jeton` force une nouvelle demande quand on
 * redemande la même fiche après l'avoir quittée — l'arbre en pose un à chaque clic.
 */
export interface DemandeDeCompletion {
  demandeId: number
  ref: string
  jeton?: number
}

/** `#p1` → `p1`. Le seul dièse de tête : c'est la forme d'adresse du § 6.6. */
export function referenceSansDiese(ref: string): string {
  return ref.startsWith('#') ? ref.slice(1) : ref
}

/** `p1` → `#p1`, la forme qu'une demande donne à ses fiches. */
export function referenceAvecDiese(ref: string): string {
  return ref.startsWith('#') ? ref : `#${ref}`
}

/**
 * `?completer=12&ref=p1` → la fiche à compléter, ou `null`.
 *
 * Une adresse se tape et se bricole : un numéro qui n'est pas un entier positif,
 * ou une référence qui n'a pas la forme `p<n>` du contrat (§ 6.3), n'ouvre rien —
 * plutôt qu'une lecture vouée au `404`.
 */
export function completionDepuisRequete(completer: string | undefined, ref: string | undefined): DemandeDeCompletion | null {
  const demandeId = Number(completer)
  if (completer === undefined || !Number.isInteger(demandeId) || demandeId <= 0 || ref === undefined) return null
  const propre = referenceSansDiese(ref.trim())
  return /^p\d+$/.test(propre) ? { demandeId, ref: propre } : null
}

/** Deux demandes désignent-elles la même fiche ? Le jeton n'y entre pas. */
export function memeCible(a: DemandeDeCompletion | null, b: DemandeDeCompletion | null): boolean {
  if (a === null || b === null) return a === b
  return a.demandeId === b.demandeId && referenceSansDiese(a.ref) === referenceSansDiese(b.ref)
}

/**
 * La proposition d'un **autre membre** que la saisie va compléter par un
 * complément (§ 6.3 ter) — action `complement` d'une ressemblance.
 *
 * `libelle` et `auteur` ne sont là que si la ressemblance les a rendus en clair
 * (forme `complete` : un défunt, par exemple). Une forme **discrète** n'en porte
 * pas, et on ne les reconstruit pas : seul `draftPersonId` voyage.
 */
export interface ComplementChoisi {
  draftPersonId: number
  libelle: string | null
  auteur: string | null
}

export function complementDepuisRessemblance(r: RessemblanceProposition): ComplementChoisi {
  return r.forme === 'discrete'
    ? { draftPersonId: r.draftPersonId, libelle: null, auteur: null }
    : { draftPersonId: r.draftPersonId, libelle: r.displayName, auteur: r.authorLabel }
}

/** « Compléter cette proposition » depuis une ressemblance en clair. */
export function completionDepuisRessemblance(r: RessemblancePropositionComplete): DemandeDeCompletion {
  return { demandeId: r.demandeId, ref: r.localRef }
}

/* ------------------------------------------------------------------ */
/* La proposition, telle que la demande la porte                       */
/* ------------------------------------------------------------------ */

export interface PropositionACompleter {
  demandeId: number
  /** `#p3` — telle que la demande la nomme : elle repart dans la fiche et comme sujet des liens. */
  localRef: string
  /** `p3` — pour l'adresse du `PUT` et pour les photos (§ 7.1). */
  brouillonRef: string
  statut: StatutDemande
  /** `null` quand la charge ne le porte pas : on ne suppose alors rien. */
  authorUserId: number | null
  auteur: string
  /** « Prénoms Nom », ou « la personne proposée ». */
  libelle: string
  /** Celle de la demande lue — `null` si la charge n'en porte pas : on écrit alors comme avant. */
  empreinte: string | null
  fiche: BrouillonDemande
  /** Les liens de la demande **qui touchent cette fiche**, sujet ou autre extrémité. */
  liens: LienDemande[]
}

/** Un lien désigne-t-il cette fiche, à l'une de ses extrémités ? */
export function touche(lien: LienDemande, localRef: string): boolean {
  return lien.subjectDraftRef === localRef || lien.otherDraftRef === localRef || lien.other2DraftRef === localRef
}

/** « Prénoms Nom », sans blanc ni morceau vide. */
export function nomDeLaFiche(fiche: Pick<BrouillonDemande, 'givenNames' | 'surname'>): string {
  const nom = [fiche.givenNames, fiche.surname]
    .map((morceau) => morceau?.trim() ?? '')
    .filter((morceau) => morceau !== '')
    .join(' ')
  return nom !== '' ? nom : 'la personne proposée'
}

/**
 * La fiche `ref` de cette demande, et ce qu'il faut pour la compléter — ou `null`
 * si la demande ne la porte pas (une autre nature, une référence disparue).
 */
export function propositionDepuisDemande(demande: Demande, ref: string): PropositionACompleter | null {
  const localRef = referenceAvecDiese(ref)
  const fiche = demande.brouillons.find((brouillon) => brouillon.localRef === localRef)
  if (fiche === undefined) return null

  return {
    demandeId: demande.id,
    localRef,
    brouillonRef: referenceSansDiese(localRef),
    statut: demande.status,
    authorUserId: demande.authorUserId ?? null,
    auteur: demande.authorLabel,
    libelle: nomDeLaFiche(fiche),
    empreinte: demande.empreinte ?? null,
    fiche,
    liens: demande.liens.filter((lien) => touche(lien, localRef)),
  }
}

/**
 * Pourquoi cette proposition ne se complète pas d'ici, ou `null` si elle le peut.
 *
 * C'est le serveur qui décide (`403`, `409`) ; ceci évite seulement d'ouvrir un
 * formulaire dont l'envoi est déjà perdu. Deux cas se lisent dans la charge : une
 * demande tranchée, et le **brouillon d'un autre** — un brouillon est le travail
 * en cours de son auteur, même l'administrateur n'y écrit pas.
 */
export function raisonDeNePasCompleter(proposition: PropositionACompleter, lecteurId: number | null): string | null {
  if (proposition.statut !== 'brouillon' && proposition.statut !== 'en_attente') {
    return 'Cette proposition a déjà été tranchée : elle ne se complète plus.'
  }
  if (
    proposition.statut === 'brouillon' &&
    proposition.authorUserId !== null &&
    lecteurId !== null &&
    proposition.authorUserId !== lecteurId
  ) {
    return 'Ce brouillon n’est pas encore envoyé : seul son auteur peut le compléter.'
  }
  return null
}

/* ------------------------------------------------------------------ */
/* La saisie depuis la proposition                                     */
/* ------------------------------------------------------------------ */

/**
 * La fiche proposée, remise dans les champs du formulaire.
 *
 * Les dates passent par `saisieDepuisProposee` (`reprise.ts`) : le bloc reçu y est
 * relu à **cinq clés** — `texte` tombe —, et un bloc qui ne désigne rien (le
 * `inconnue` tout nul) ouvre un champ vide et modifiable plutôt que verrouillé.
 * C'est le chemin de la reprise d'une demande, qui a payé ces deux pièges.
 */
export function saisieDepuisBrouillonDemande(fiche: BrouillonDemande): SaisieBrouillon {
  return {
    givenNames: fiche.givenNames ?? '',
    surname: fiche.surname ?? '',
    marriedName: fiche.marriedName ?? '',
    nickname: fiche.nickname ?? '',
    sex: fiche.sex,
    deceased: fiche.deceased,
    naissance: saisieDepuisProposee(fiche.birth ?? null),
    deces: saisieDepuisProposee(fiche.death ?? null),
    note: fiche.note ?? '',
  }
}

/* ------------------------------------------------------------------ */
/* La fusion : la proposition l'emporte, la saisie remplit les vides   */
/* ------------------------------------------------------------------ */

/** Les champs où une saisie peut contredire une proposition. `deceased` et `note` n'en sont pas. */
export type ChampFusionne = 'givenNames' | 'surname' | 'marriedName' | 'nickname' | 'sex' | 'naissance' | 'deces'

const ETIQUETTES: Record<ChampFusionne, string> = {
  givenNames: 'Prénoms',
  surname: 'Nom',
  marriedName: 'Nom d’usage',
  nickname: 'Surnom',
  sex: 'Sexe',
  naissance: 'Naissance',
  deces: 'Décès',
}

/**
 * Un champ où la saisie dit autre chose que la proposition. Les deux valeurs sont
 * déjà écrites pour l'écran ; la proposition est gardée tant que le lecteur ne
 * choisit pas la sienne.
 */
export interface Divergence {
  champ: ChampFusionne
  etiquette: string
  proposition: string
  saisie: string
}

export interface Fusion {
  /** Ce que le formulaire affiche : la proposition, complétée de la saisie. */
  fusion: SaisieBrouillon
  /** La proposition seule, pour « Garder celle de la proposition ». */
  proposee: SaisieBrouillon
  divergences: Divergence[]
  /** Les champs vides de la proposition que la saisie a remplis. */
  remplis: ChampFusionne[]
}

/**
 * Des notes, mises bout à bout **sans se répéter**, paragraphe par paragraphe :
 * un paragraphe déjà présent — ou contenu dans un précédent — ne s'ajoute pas.
 * Deux notes ne se disputent pas, elles s'ajoutent : c'est la règle de la fusion
 * côté serveur.
 *
 * Le découpage en paragraphes compte à la **relecture** d'une proposition qui a
 * bougé : la note relue (« A », puis ce que l'administrateur a ajouté) et celle
 * du formulaire (« A », puis ce que le lecteur a ajouté) partagent leur début, et
 * le recopier deux fois serait le pire service à rendre à qui la relira.
 */
export function joindreNotes(...morceaux: readonly string[]): string {
  const gardes: string[] = []
  for (const brut of morceaux) {
    for (const paragraphe of brut.split(/\n\s*\n/)) {
      const propre = paragraphe.trim()
      if (propre === '' || gardes.some((garde) => garde.includes(propre))) continue
      gardes.push(propre)
    }
  }
  return gardes.join('\n\n')
}

function fusionnerTexte(propose: string, saisi: string): { valeur: string; rempli: boolean; divergence: boolean } {
  const p = propose.trim()
  const s = saisi.trim()
  if (s === '' || s === p) return { valeur: propose, rempli: false, divergence: false }
  if (p === '') return { valeur: saisi, rempli: true, divergence: false }
  return { valeur: propose, rempli: false, divergence: true }
}

function fusionnerSexe(propose: Sexe, saisi: Sexe): { valeur: Sexe; rempli: boolean; divergence: boolean } {
  if (saisi === 'inconnu' || saisi === propose) return { valeur: propose, rempli: false, divergence: false }
  if (propose === 'inconnu') return { valeur: saisi, rempli: true, divergence: false }
  return { valeur: propose, rempli: false, divergence: true }
}

/** La valeur que porte un bloc reçu, à cinq clés — ou `null` s'il ne désigne rien. */
function valeurProposee(bloc: BlocDate | null | undefined): ValeurDate | null {
  const lue = lireBlocDate(bloc ?? null)
  return designeUneDate(lue) ? lue : null
}

/** Même date : qualificatif et bornes, **jamais `raw`** — « 1901 » et « en 1901 » ne divergent pas. */
function memeDate(a: ValeurDate, b: ValeurDate): boolean {
  return a.qualifier === b.qualifier && a.min === b.min && a.max === b.max
}

/**
 * Deux dates : la **date** (qualificatif et bornes) et le **lieu** se fusionnent
 * chacun de leur côté, et le moindre désaccord fait du bloc entier une
 * divergence — la règle du serveur (`FusionDeComplement::fusionnerLaDate`) :
 * une date inconnue reçoit l'autre, une date connue sans lieu reçoit le lieu seul.
 *
 * Une saisie **illisible** (« 31/02/1890 ») ne se compare pas : elle reste dans le
 * champ si la proposition n'a rien — son erreur s'affichera à l'envoi —, et
 * diverge sinon, avec le texte tapé tel quel.
 */
function fusionnerDate(
  bloc: BlocDate | null | undefined,
  proposee: SaisieDate,
  saisie: SaisieDate,
): { valeur: SaisieDate; rempli: boolean; divergence: boolean } {
  const p = valeurProposee(bloc)
  const lue = valeurAEnvoyer(saisie)
  const garder = { valeur: proposee, rempli: false, divergence: false }

  if (!lue.valide) return p === null ? { valeur: saisie, rempli: false, divergence: false } : { ...garder, divergence: true }

  const s = lue.valeur
  if (s === null || !designeUneDate(s)) return garder
  if (p === null) return { valeur: saisie, rempli: true, divergence: false }

  const date = s.qualifier === 'inconnue' || memeDate(p, s) ? p : p.qualifier === 'inconnue' ? s : null

  const lieuPropose = (p.place ?? '').trim()
  const lieuSaisi = (s.place ?? '').trim()
  const lieu =
    lieuSaisi === '' || lieuSaisi === lieuPropose ? p.place : lieuPropose === '' ? s.place : undefined

  if (date === null || lieu === undefined) return { ...garder, divergence: true }

  const fusionnee: ValeurDate = { raw: date.raw, qualifier: date.qualifier, min: date.min, max: date.max, place: lieu }
  if (memeValeurDate(fusionnee, p)) return garder
  // Relue depuis le bloc fusionné, la saisie le prend pour origine : sa provenance
  // (`raw`) repart intacte tant que la date ne bouge pas, et une date que le
  // formulaire ne sait pas rééditer (`FROM`, `TO`) repart telle quelle, lieu compris.
  return { valeur: saisieDepuisBloc(fusionnee), rempli: true, divergence: false }
}

/** Le bloc proposé, en mots : le `texte` du serveur (§ 14.4), puis le lieu. */
export function texteDuBloc(bloc: BlocDate | null | undefined): string {
  if (bloc === null || bloc === undefined) return 'aucune date'
  const texte = (bloc.texte ?? '').trim() !== '' ? bloc.texte!.trim() : (bloc.raw ?? '').trim()
  const lieu = (bloc.place ?? '').trim()
  const morceaux = [texte, lieu !== '' && !texte.includes(lieu) ? lieu : ''].filter((morceau) => morceau !== '')
  return morceaux.length > 0 ? morceaux.join(', ') : 'aucune date'
}

/** La date saisie, en mots — ou le texte tapé tel quel quand il ne se lit pas. */
export function texteDeLaSaisie(saisie: SaisieDate): string {
  const lue = valeurAEnvoyer(saisie)
  if (!lue.valide) {
    const tape = [saisie.debut.trim(), saisie.fin.trim()].filter((morceau) => morceau !== '').join(' – ')
    return tape !== '' ? `« ${tape} » (illisible)` : 'date illisible'
  }
  return apercuDeValeur(lue.valeur).replace(/\.$/, '')
}

/**
 * **La proposition l'emporte, la saisie remplit les vides.**
 *
 * - un texte, un sexe ou une date **vides** dans la proposition prennent la
 *   valeur saisie ;
 * - une valeur **égale**, ou une saisie vide, laisse la proposition ;
 * - deux valeurs **différentes** : la proposition reste, et c'est une divergence ;
 * - « décès attesté » ne se contredit pas : coché d'un côté, il l'est ;
 * - les notes s'ajoutent, sans se répéter.
 *
 * Un champ vide dans la saisie ne propose jamais d'effacer la proposition.
 */
export function fusionnerBrouillons(fiche: BrouillonDemande, saisie: SaisieBrouillon): Fusion {
  const proposee = saisieDepuisBrouillonDemande(fiche)
  const fusion: SaisieBrouillon = copieDuBrouillon(proposee)
  const divergences: Divergence[] = []
  const remplis: ChampFusionne[] = []

  const retenir = (champ: ChampFusionne, issue: { rempli: boolean; divergence: boolean }, proposition: string, saisi: string): void => {
    if (issue.rempli) remplis.push(champ)
    if (issue.divergence) divergences.push({ champ, etiquette: ETIQUETTES[champ], proposition, saisie: saisi })
  }

  for (const champ of ['givenNames', 'surname', 'marriedName', 'nickname'] as const) {
    const issue = fusionnerTexte(proposee[champ], saisie[champ])
    fusion[champ] = issue.valeur
    retenir(champ, issue, proposee[champ].trim(), saisie[champ].trim())
  }

  const sexe = fusionnerSexe(proposee.sex, saisie.sex)
  fusion.sex = sexe.valeur
  retenir('sex', sexe, LIBELLES_SEXE[proposee.sex], LIBELLES_SEXE[saisie.sex])

  const naissance = fusionnerDate(fiche.birth, proposee.naissance, saisie.naissance)
  fusion.naissance = copieDeLaDate(naissance.valeur)
  retenir('naissance', naissance, texteDuBloc(fiche.birth), texteDeLaSaisie(saisie.naissance))

  const deces = fusionnerDate(fiche.death, proposee.deces, saisie.deces)
  fusion.deces = copieDeLaDate(deces.valeur)
  retenir('deces', deces, texteDuBloc(fiche.death), texteDeLaSaisie(saisie.deces))

  fusion.deceased = proposee.deceased || saisie.deceased
  fusion.note = joindreNotes(proposee.note, saisie.note)

  return { fusion, proposee, divergences, remplis }
}

/**
 * Le champ `champ` repris de `source` — « Prendre ma valeur » (la saisie d'avant)
 * ou « Garder celle de la proposition ». Une copie : les deux saisies restent
 * intactes, et le lecteur peut changer d'avis.
 */
export function prendreLaValeur(brouillon: SaisieBrouillon, source: SaisieBrouillon, champ: ChampFusionne): SaisieBrouillon {
  const copie: SaisieBrouillon = { ...brouillon }
  switch (champ) {
    case 'naissance':
    case 'deces':
      copie[champ] = copieDeLaDate(source[champ])
      // Prendre une date de décès, c'est prendre le décès : la fiche ne doit pas
      // se dire vivante avec une date de mort.
      if (champ === 'deces' && designeUneDate(valeurOuNull(source.deces))) copie.deceased = copie.deceased || source.deceased
      return copie
    case 'sex':
      copie.sex = source.sex
      return copie
    default:
      copie[champ] = source[champ]
      return copie
  }
}

function valeurOuNull(saisie: SaisieDate): ValeurDate | null {
  const lue = valeurAEnvoyer(saisie)
  return lue.valide ? lue.valeur : null
}

/**
 * Une date **à soi** : le champ de saisie l'édite en place, et la proposition
 * d'origine — gardée pour « Garder celle de la proposition » — ne doit pas
 * changer sous lui.
 */
function copieDeLaDate(date: SaisieDate): SaisieDate {
  return { ...date, origine: date.origine === null ? null : { ...date.origine } }
}

/** Le brouillon entier, dates comprises, sans rien partager avec l'original. */
export function copieDuBrouillon(brouillon: SaisieBrouillon): SaisieBrouillon {
  return { ...brouillon, naissance: copieDeLaDate(brouillon.naissance), deces: copieDeLaDate(brouillon.deces) }
}

/* ------------------------------------------------------------------ */
/* Les liens : ceux qui y sont, ceux qu'on ajoute                       */
/* ------------------------------------------------------------------ */

/** La clé d'une extrémité, au format de `cleDuChoix` — `ref:#p2` pour une fiche de la même demande. */
function cleDExtremite(personId: number | null | undefined, draftPersonId: number | null | undefined, draftRef: string | null | undefined): string | null {
  if (personId !== null && personId !== undefined) return `personne:${personId}`
  if (draftPersonId !== null && draftPersonId !== undefined) return `brouillon:${draftPersonId}`
  if (draftRef !== null && draftRef !== undefined && draftRef !== '') return `ref:${draftRef}`
  return null
}

/**
 * Les proches de la fiche que la proposition **nomme déjà**, rangés comme le
 * formulaire les range : parents, conjoints, enfants.
 *
 * Un lien se lit du sujet vers l'autre (`creation.ts`) : la fiche peut être le
 * sujet (`#p1 enfant_de 12`) ou l'autre extrémité (`#p2 parent_de #p1`, que
 * l'assistant écrit volontiers). Les deux sens comptent.
 */
export interface ProchesProposes {
  parents: string[]
  /** Un couple désigné d'un bloc (`familyId`) : il occupe les deux places de parent. */
  familles: number[]
  conjoints: string[]
  enfants: string[]
}

export function prochesProposes(proposition: Pick<PropositionACompleter, 'localRef' | 'liens'>): ProchesProposes {
  const ref = proposition.localRef
  const proches: ProchesProposes = { parents: [], familles: [], conjoints: [], enfants: [] }
  const ajouter = (liste: string[], cle: string | null): void => {
    if (cle !== null && !liste.includes(cle)) liste.push(cle)
  }

  for (const lien of proposition.liens) {
    const sujet = cleDExtremite(lien.subjectPersonId, lien.subjectDraftPersonId, lien.subjectDraftRef)
    const autre = cleDExtremite(lien.otherPersonId, lien.otherDraftPersonId, lien.otherDraftRef)
    const autre2 = cleDExtremite(lien.other2PersonId, lien.other2DraftPersonId, lien.other2DraftRef)
    const estSujet = lien.subjectDraftRef === ref

    switch (lien.kind) {
      case 'enfant_de':
        if (estSujet) {
          if (lien.familyId !== null && lien.familyId !== undefined) proches.familles.push(lien.familyId)
          ajouter(proches.parents, autre)
          ajouter(proches.parents, autre2)
        } else if (lien.otherDraftRef === ref || lien.other2DraftRef === ref) {
          ajouter(proches.enfants, sujet)
        }
        break
      case 'parent_de':
        if (estSujet) ajouter(proches.enfants, autre)
        else if (lien.otherDraftRef === ref) ajouter(proches.parents, sujet)
        break
      case 'conjoint_de':
        if (estSujet) ajouter(proches.conjoints, autre)
        else if (lien.otherDraftRef === ref) ajouter(proches.conjoints, sujet)
        break
    }
  }
  return proches
}

/**
 * Un lien de la proposition, en une phrase — pour le bloc « Déjà dans la
 * proposition », en lecture seule.
 *
 * Les mots viennent de `lienLisible` (l'écran d'administration) : la nature lue
 * du sujet vers l'autre, les noms tels que le serveur les rend, les dates par
 * leur `texte`. Quand la fiche n'est pas le **sujet** du lien (`#p2 parent_de
 * #p1`), on dit de qui il parle — sans quoi « Parent de Marie » se lirait à
 * l'envers.
 */
export function phraseDuLienPropose(lien: LienDemande, localRef: string): string {
  const lisible = lienLisible(lien)
  const qui = lisible.secondParent === null ? lisible.autre : `${lisible.autre} et ${lisible.secondParent}`
  const details = [lisible.union, lisible.rupture].filter((detail): detail is string => detail !== null)
  const phrase = `${lisible.nature} ${qui}${details.length > 0 ? ` (${details.join(', ')})` : ''}`
  if (lien.subjectDraftRef === localRef) return phrase
  return `${lisible.sujet} : ${phrase.charAt(0).toLowerCase()}${phrase.slice(1)}`
}

/** Toutes les personnes déjà nommées : le sélecteur les montre, bouton éteint. */
export function designesParLaProposition(proposition: Pick<PropositionACompleter, 'localRef' | 'liens'>): string[] {
  const proches = prochesProposes(proposition)
  return [...new Set([...proches.parents, ...proches.conjoints, ...proches.enfants])]
}

/** Les places de parent encore libres : deux au plus, un couple désigné les prend toutes. */
export function placesDeParent(proposition: Pick<PropositionACompleter, 'localRef' | 'liens'>): number {
  const proches = prochesProposes(proposition)
  if (proches.familles.length > 0) return 0
  return Math.max(0, 2 - proches.parents.length)
}

/**
 * Ce que la saisie désigne **et que la proposition porte déjà** :
 *
 * - la filiation, si chacun de ses parents est déjà un parent proposé — ou si
 *   elle désigne le même couple ;
 * - chaque conjoint, chaque enfant déjà nommé.
 *
 * Une filiation dont un seul parent est nouveau n'est **pas** un doublon : elle
 * dit « enfant de A et C » là où la proposition dit « enfant de A », et c'est à
 * l'administrateur d'en juger.
 */
export function liensDejaProposes(
  proposition: Pick<PropositionACompleter, 'localRef' | 'liens'>,
  saisie: SaisieCreation,
): { filiation: boolean; unions: string[]; enfants: string[] } {
  const proches = prochesProposes(proposition)
  const { famille, parents } = saisie.filiation

  const filiation =
    famille !== null
      ? proches.familles.includes(famille.familyId)
      : parents.length > 0 && parents.every((parent) => proches.parents.includes(cleDuChoix(parent)))

  return {
    filiation,
    unions: saisie.unions.map((union) => cleDuChoix(union.personne)).filter((cle) => proches.conjoints.includes(cle)),
    enfants: saisie.enfants.map((enfant) => cleDuChoix(enfant.personne)).filter((cle) => proches.enfants.includes(cle)),
  }
}

/**
 * La saisie **sans** ce que la proposition porte déjà : il ne reste que des liens
 * à ajouter. Une copie ; la saisie d'origine reste telle quelle, pour « Revenir à
 * une nouvelle personne ».
 */
export function retirerLiensDejaProposes(
  saisie: SaisieCreation,
  proposition: Pick<PropositionACompleter, 'localRef' | 'liens'>,
): SaisieCreation {
  const deja = liensDejaProposes(proposition, saisie)
  return {
    ...saisie,
    filiation: deja.filiation
      ? { famille: null, parents: [] }
      : { famille: saisie.filiation.famille, parents: [...saisie.filiation.parents] },
    unions: saisie.unions.filter((union) => !deja.unions.includes(cleDuChoix(union.personne))),
    enfants: saisie.enfants.filter((enfant) => !deja.enfants.includes(cleDuChoix(enfant.personne))),
  }
}

/* ------------------------------------------------------------------ */
/* Le corps du PUT                                                      */
/* ------------------------------------------------------------------ */

export type ResultatMiseAJour =
  | { valide: true; corps: CorpsMiseAJourBrouillon }
  | { valide: false; erreurs: ErreurSaisie[] }

/** Un lien **sans son rang** : le serveur l'attribue, et un `seq` envoyé rend `422`. */
function sansRang(lien: LienPropose): LienAAjouter {
  const copie: Partial<LienPropose> = { ...lien }
  delete copie.seq
  return copie as LienAAjouter
}

/**
 * Le corps de `PUT /api/demandes/{id}/brouillons/{ref}`, ou ce qui manque.
 *
 * Mêmes règles qu'au dépôt, et par les mêmes fonctions (`construireBrouillon`,
 * `construireLiens`) : un prénom ou un nom, des dates lisibles, pas de décès daté
 * sans « décès attesté », un couple **ou** des parents. Deux différences :
 *
 * - **« Au moins un lien »** se compte sur la proposition entière — les liens
 *   qu'elle porte et ceux qu'on ajoute —, et seulement si l'on **envoie** un
 *   brouillon : une proposition en attente en a déjà un, un brouillon peut
 *   attendre ;
 * - **les liens partent sans `seq`**, et `liens` est omis quand il n'y a rien à
 *   ajouter — la complétion d'un champ seul n'envoie pas de tableau vide.
 */
export function corpsMiseAJour(
  saisie: SaisieCreation,
  options: { empreinte: string | null; liensExistants: number; soumettre: boolean },
): ResultatMiseAJour {
  const fiche = construireBrouillon(saisie)
  const liens = construireLiens(saisie)
  const erreurs = [...fiche.erreurs, ...liens.erreurs]

  if (options.soumettre && options.liensExistants + liens.liens.length === 0) {
    erreurs.push({
      champ: 'liens',
      message:
        'Rattachez la personne à au moins un parent, un enfant ou un conjoint avant d’envoyer : sans lien, elle flotterait hors de l’arbre.',
    })
  }

  if (erreurs.length > 0 || fiche.brouillon === null) return { valide: false, erreurs }

  const corps: CorpsMiseAJourBrouillon = { fiche: { ...fiche.brouillon } }
  if (liens.liens.length > 0) corps.liens = liens.liens.map(sansRang)
  if (options.empreinte !== null) corps.empreinte = options.empreinte
  return { valide: true, corps }
}
