/**
 * Le tableau de suivi — tout ce qui se calcule sans navigateur, testé par
 * `suivi.test.ts`. La vue (`SuiviView.vue`) ne fait que lire, sonder et dessiner.
 *
 * ────────────────────────────────────────────────────────────────────
 * **D'où viennent les chiffres.** Le journal d'audit est la seule trace datée de
 * ce que fait l'application : connexions, demandes, téléversements, appels au
 * modèle de langage. Il n'y a pas de file de tâches à suivre — l'API répond en
 * synchrone — : les « tâches » de cet écran sont donc les **lignes du journal**,
 * et la file d'attente, ce qu'« À traiter » attend d'une décision humaine.
 *
 * **Ce qu'on ne calcule pas ici.** L'activité par jour et la répartition par
 * famille portent sur la **fenêtre chargée** (les 100 dernières lignes au plus,
 * plafond du § 1.5), pas sur tout le journal : l'écran le dit à côté du
 * graphique. Les totaux, eux, viennent du serveur.
 * ────────────────────────────────────────────────────────────────────
 */

import type { CompteARattacher, Demande, DemandeRgpdAdministration, LigneDuJournal, MesureDeSante } from '@/lib/api'
import { LIBELLES_NATURE } from '@/components/proposition/mes-demandes'
import { libelleNature as libelleNatureRgpd } from '@/components/compte/demandesRgpd'

/* ───────────────── Les actions du journal ───────────────── */

/** Une famille d'actions : ce qui donne sa couleur à une ligne. Cinq, pas une par action. */
export type Famille = 'compte' | 'demande' | 'arbre' | 'ia' | 'securite'

export const FAMILLES: readonly { cle: Famille; libelle: string; couleur: string }[] = [
  { cle: 'demande', libelle: 'Demandes', couleur: 'var(--h-accent)' },
  { cle: 'arbre', libelle: 'Arbre et médias', couleur: 'var(--h-ok)' },
  { cle: 'compte', libelle: 'Comptes', couleur: 'var(--h-homme-bord)' },
  { cle: 'ia', libelle: 'Modèle de langage', couleur: 'var(--h-edition)' },
  { cle: 'securite', libelle: 'Sécurité', couleur: 'var(--h-alerte)' },
]

const ACTIONS: Record<string, { libelle: string; famille: Famille }> = {
  creation: { libelle: 'Création', famille: 'arbre' },
  modification: { libelle: 'Modification', famille: 'arbre' },
  suppression: { libelle: 'Suppression', famille: 'arbre' },
  connexion: { libelle: 'Connexion', famille: 'compte' },
  connexion_echouee: { libelle: 'Connexion échouée', famille: 'securite' },
  deconnexion: { libelle: 'Déconnexion', famille: 'compte' },
  invitation_creee: { libelle: 'Invitation créée', famille: 'compte' },
  invitation_revoquee: { libelle: 'Invitation révoquée', famille: 'compte' },
  invitation_utilisee: { libelle: 'Invitation utilisée', famille: 'compte' },
  rattachement_tente: { libelle: 'Rattachement tenté', famille: 'compte' },
  demande_soumise: { libelle: 'Demande soumise', famille: 'demande' },
  demande_validee: { libelle: 'Demande validée', famille: 'demande' },
  demande_refusee: { libelle: 'Demande refusée', famille: 'demande' },
  demande_rejouee: { libelle: 'Demande rejouée', famille: 'demande' },
  demande_caduque: { libelle: 'Demande caduque', famille: 'demande' },
  role_modifie: { libelle: 'Rôle modifié', famille: 'securite' },
  compte_suspendu: { libelle: 'Compte suspendu', famille: 'securite' },
  mot_de_passe_reinitialise: { libelle: 'Mot de passe réinitialisé', famille: 'securite' },
  reglage_modifie: { libelle: 'Réglage modifié', famille: 'securite' },
  televersement: { libelle: 'Téléversement', famille: 'arbre' },
  media_supprime: { libelle: 'Média supprimé', famille: 'arbre' },
  vue_comme_membre: { libelle: 'Vue comme un membre', famille: 'securite' },
  incarnation_debut: { libelle: 'Incarnation : début', famille: 'securite' },
  incarnation_fin: { libelle: 'Incarnation : fin', famille: 'securite' },
  partage_naissance: { libelle: 'Partage de naissance', famille: 'compte' },
  autorisation_naissance: { libelle: 'Autorisation de naissance', famille: 'compte' },
  restriction_naissance: { libelle: 'Restriction de naissance', famille: 'compte' },
  demande_rgpd: { libelle: 'Demande RGPD', famille: 'demande' },
  appel_modele_langage: { libelle: 'Appel au modèle', famille: 'ia' },
}

/** Le libellé d'une action. Une action inconnue de l'écran s'affiche telle quelle. */
export function libelleAction(action: string): string {
  return ACTIONS[action]?.libelle ?? action
}

/** La famille d'une action. Une action inconnue tombe dans « Arbre et médias », la plus neutre. */
export function familleDe(action: string): Famille {
  return ACTIONS[action]?.famille ?? 'arbre'
}

export function couleurDe(famille: Famille): string {
  return FAMILLES.find((f) => f.cle === famille)!.couleur
}

/* ───────────────── L'état global : la pastille de l'en-tête ───────────────── */

export type Niveau = 'ok' | 'attention' | 'panne' | 'inconnu'

export interface EtatGlobal {
  niveau: Niveau
  libelle: string
}

export interface EntreesDeLEtat {
  /** La dernière mesure de `/api/sante`, ou `null` avant la première. */
  sante: MesureDeSante | null
  /** Ce qui attend une décision, tous types confondus ; `null` = pas encore compté. */
  aTraiter: number | null
  /** Connexions échouées dans la fenêtre récente. */
  connexionsEchouees: number
}

/** Au-delà, une latence se signale : la sonde n'est qu'un `SELECT 1`. */
export const LATENCE_LENTE_MS = 800
/** Au-delà, des connexions échouées se signalent : cinq, c'est le limiteur (README). */
export const SEUIL_CONNEXIONS_ECHOUEES = 5

/**
 * Vert, orange ou rouge — **dans cet ordre de gravité, et un seul motif dit**.
 * Rouge : la base ne répond pas. Orange : lente, ou des essais de connexion en
 * rafale. Ce qui attend dans « À traiter » n'est **pas** une alerte : c'est le
 * travail ordinaire de l'administration, il a son compteur à côté.
 */
export function etatGlobal(e: EntreesDeLEtat): EtatGlobal {
  if (e.sante === null) return { niveau: 'inconnu', libelle: 'Première mesure…' }
  if (!e.sante.ok) return { niveau: 'panne', libelle: 'La base ne répond pas' }
  if (e.sante.latenceMs > LATENCE_LENTE_MS) return { niveau: 'attention', libelle: `Réponse lente (${e.sante.latenceMs} ms)` }
  if (e.connexionsEchouees >= SEUIL_CONNEXIONS_ECHOUEES) {
    return { niveau: 'attention', libelle: `${e.connexionsEchouees} connexions échouées en 24 h` }
  }
  return { niveau: 'ok', libelle: 'Tout répond' }
}

/* ───────────────── Fraîcheur de la connexion ───────────────── */

/** « à l'instant », « il y a 12 s », « il y a 3 min » — ou la panne de rafraîchissement. */
export function fraicheur(derniereReussite: number | null, maintenant: number): string {
  if (derniereReussite === null) return 'jamais mis à jour'
  const s = Math.max(0, Math.round((maintenant - derniereReussite) / 1000))
  if (s < 3) return 'à l’instant'
  if (s < 60) return `il y a ${s} s`
  const min = Math.round(s / 60)
  if (min < 60) return `il y a ${min} min`
  return `il y a ${Math.round(min / 60)} h`
}

/** Une heure relative courte pour une ligne du journal : « 14:02 », ou « hier 14:02 », ou la date. */
export function heureCourte(iso: string, maintenant: Date): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  const hm = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  const jour = (x: Date): string => x.toLocaleDateString('fr-FR')
  if (jour(d) === jour(maintenant)) return hm
  const hier = new Date(maintenant)
  hier.setDate(hier.getDate() - 1)
  if (jour(d) === jour(hier)) return `hier ${hm}`
  return `${d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })} ${hm}`
}

/* ───────────────── Fenêtres sur le journal chargé ───────────────── */

/** Les lignes des dernières `heures`, mesurées depuis `maintenant`. */
export function depuis(lignes: readonly LigneDuJournal[], heures: number, maintenant: number): LigneDuJournal[] {
  const borne = maintenant - heures * 3_600_000
  return lignes.filter((l) => new Date(l.occurredAt).getTime() >= borne)
}

/** Combien de lignes par famille, dans l'ordre de `FAMILLES` ; les familles vides comprises. */
export function repartition(lignes: readonly LigneDuJournal[]): { famille: Famille; libelle: string; couleur: string; n: number }[] {
  const n = new Map<Famille, number>()
  for (const l of lignes) n.set(familleDe(l.action), (n.get(familleDe(l.action)) ?? 0) + 1)
  return FAMILLES.map((f) => ({ famille: f.cle, libelle: f.libelle, couleur: f.couleur, n: n.get(f.cle) ?? 0 }))
}

/** La clé locale d'un jour, `AAAA-MM-JJ` — en heure du navigateur, comme ce qu'on lit à l'écran. */
function cleDuJour(d: Date): string {
  const z = (n: number): string => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}`
}

/**
 * Les `jours` derniers jours, du plus ancien au plus récent, **jours vides
 * compris** : un jour sans activité est un zéro qu'on doit voir, pas un trou que
 * l'axe referme.
 */
export function activiteParJour(
  lignes: readonly LigneDuJournal[],
  jours: number,
  maintenant: Date,
): { jour: string; libelle: string; n: number }[] {
  const compte = new Map<string, number>()
  for (const l of lignes) {
    const d = new Date(l.occurredAt)
    if (!Number.isNaN(d.getTime())) compte.set(cleDuJour(d), (compte.get(cleDuJour(d)) ?? 0) + 1)
  }
  const resultat: { jour: string; libelle: string; n: number }[] = []
  for (let i = jours - 1; i >= 0; i--) {
    const d = new Date(maintenant)
    d.setDate(d.getDate() - i)
    resultat.push({ jour: cleDuJour(d), libelle: String(d.getDate()), n: compte.get(cleDuJour(d)) ?? 0 })
  }
  return resultat
}

/**
 * La recherche texte de l'historique : sur la page chargée, sans accent ni
 * casse, dans l'acteur, l'action (code et libellé), le sujet et son libellé.
 * Le serveur filtre déjà par action, acteur et type ; le texte libre, lui,
 * n'existe pas côté API — il ne porte donc que sur ce qui est à l'écran.
 */
export function filtrerTexte(lignes: readonly LigneDuJournal[], q: string): LigneDuJournal[] {
  const plier = (s: string): string => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
  const cherche = plier(q.trim())
  if (cherche === '') return [...lignes]
  return lignes.filter((l) =>
    plier(
      [l.actorEmail, l.action, libelleAction(l.action), l.subjectType, l.subjectId, l.subjectLabel, l.ip]
        .filter((x): x is string => typeof x === 'string')
        .join(' '),
    ).includes(cherche),
  )
}

/* ───────────────── Les appels au modèle de langage ───────────────── */

/** `Assistant\Analyse` → « Analyse ». Le module ne dit rien de plus utile ici. */
export function natureDAppel(subjectType: string): string {
  const nom = subjectType.split('\\').pop() ?? subjectType
  const NOMS: Record<string, string> = {
    Analyse: 'Analyse de documents',
    Question: 'Question à l’assistant',
    Transcription: 'Transcription vocale',
    Rattachement: 'Suggestion de rattachement',
    Redaction: 'Rédaction de questions',
  }
  return NOMS[nom] ?? nom
}

/**
 * Les modèles appelés et combien de fois, du plus appelé au moins appelé.
 * Le modèle est le `subjectLabel` de la ligne : c'est ce que les services y
 * consignent (`reglages->modele()`).
 */
export function modelesAppeles(lignes: readonly LigneDuJournal[]): { modele: string; n: number; natures: string[] }[] {
  const parModele = new Map<string, { n: number; natures: Set<string> }>()
  for (const l of lignes) {
    if (l.action !== 'appel_modele_langage') continue
    const modele = l.subjectLabel ?? 'modèle inconnu'
    const e = parModele.get(modele) ?? { n: 0, natures: new Set<string>() }
    e.n++
    e.natures.add(natureDAppel(l.subjectType))
    parModele.set(modele, e)
  }
  return [...parModele.entries()]
    .map(([modele, e]) => ({ modele, n: e.n, natures: [...e.natures].sort() }))
    .sort((a, b) => b.n - a.n || a.modele.localeCompare(b.modele))
}

/** Les compteurs d'un appel (`changes`), en « clé : valeur » lisibles. Jamais de contenu : le serveur n'en consigne pas. */
export function detailsDAppel(changes: Record<string, unknown> | null): string[] {
  if (changes === null) return []
  return Object.entries(changes).map(([cle, valeur]) => {
    const texte = typeof valeur === 'boolean' ? (valeur ? 'oui' : 'non') : valeur === null ? '—' : String(valeur)
    return `${cle.replace(/_/g, ' ')} : ${texte}`
  })
}

/* ───────────────── La file « À traiter » ───────────────── */

export interface ElementEnAttente {
  cle: string
  /** La clé de sélection d'« À traiter » (`?sel=d:42`, `r:7`, `g:3`). */
  sel: string
  type: 'demande' | 'inscription' | 'rgpd'
  nature: string
  libelle: string
  depuis: string | null
}

/**
 * Tout ce qui attend une décision, **du plus ancien au plus récent** — trié sur
 * l'instant et non sur le texte : deux dates ISO à fuseaux différents ne se
 * comparent pas comme des chaînes. `null` (lecture refusée sous une vue
 * empruntée) compte pour une liste vide.
 */
export function elementsEnAttente(
  demandes: readonly Demande[],
  rattachements: readonly CompteARattacher[] | null,
  rgpd: readonly DemandeRgpdAdministration[] | null,
): ElementEnAttente[] {
  const elements: ElementEnAttente[] = [
    ...demandes.map((d) => ({
      cle: `d${d.id}`,
      sel: `d:${d.id}`,
      type: 'demande' as const,
      nature: LIBELLES_NATURE[d.kind] ?? d.kind,
      libelle: `${d.targetLabel ?? 'Nouvelle fiche'} — par ${d.authorLabel}`,
      depuis: d.submittedAt ?? d.createdAt,
    })),
    ...(rattachements ?? []).map((r) => ({
      cle: `r${r.memberId}`,
      sel: `r:${r.memberId}`,
      type: 'inscription' as const,
      nature: 'Inscription à rattacher',
      libelle: r.email,
      depuis: r.inscritLe,
    })),
    ...(rgpd ?? []).map((g) => ({
      cle: `g${g.id}`,
      sel: `g:${g.id}`,
      type: 'rgpd' as const,
      nature: `RGPD — ${libelleNatureRgpd(g.nature)}`,
      libelle: g.userEmail,
      depuis: g.creeeLe,
    })),
  ]
  const instant = (e: ElementEnAttente): number => (e.depuis === null ? Infinity : new Date(e.depuis).getTime())
  return elements.sort((a, b) => instant(a) - instant(b))
}

/* ───────────────── Mini-courbe ───────────────── */

/** Les points d'une mini-courbe SVG (`polyline`), dans un cadre `largeur × hauteur`. */
export function pointsDeCourbe(valeurs: readonly number[], largeur: number, hauteur: number): string {
  if (valeurs.length === 0) return ''
  const max = Math.max(1, ...valeurs)
  const n = valeurs.length
  return valeurs
    .map((v, i) => {
      const x = n > 1 ? (i / (n - 1)) * largeur : largeur / 2
      const y = hauteur - (Math.max(0, v) / max) * (hauteur - 2) - 1
      return `${x.toFixed(1)},${y.toFixed(1)}`
    })
    .join(' ')
}

/** Rafraîchissements : l'onglet « En direct » toutes les 2 s, les autres toutes les 10 s. */
export const CADENCE_DIRECT_MS = 2_000
export const CADENCE_AUTRES_MS = 10_000
/** Le journal et les compteurs ne bougent pas à la seconde : ils suivent au plus cette cadence. */
export const CADENCE_JOURNAL_MS = 10_000
/** Le chantier recalcule des listes de fiches : une fois par minute suffit largement. */
export const CADENCE_CHANTIER_MS = 60_000
/** Combien de mesures de latence garder pour la mini-courbe. */
export const MESURES_GARDEES = 60
