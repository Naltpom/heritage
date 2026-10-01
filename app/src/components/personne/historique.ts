/**
 * L'onglet Historique de la fiche — **une seule frise**, du plus récent au plus
 * ancien, qui rassemble ce qui a été ajouté à la fiche et ce qui y a été changé.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Deux origines, une seule chronologie
 *
 * - **Ce que la fiche porte déjà** : ses sources (`citations`), ses notes et ses
 *   documents (`medias`), chacun daté par `createdAt` — la date de la **ligne**,
 *   pas celle de l'acte. Rien à charger de plus.
 * - **Les révisions appliquées** (`GET /api/demandes/historique/personne/{id}`,
 *   § 6.11) : ce qu'une demande validée a réellement écrit sur la fiche, avec
 *   son auteur et la demande d'origine. Celles-là se chargent à part — voir
 *   `lireHistoriqueDePersonne()`, qui les prend **toutes**, et pourquoi.
 *
 * Une source ajoutée par une demande produit une révision sur la **citation**,
 * pas sur la personne : la route du § 6.11 ne la rend donc pas, et elle n'est
 * pas comptée deux fois. Elle figure une fois, comme source.
 *
 * ## Ce que ce module ne fait pas
 *
 * Il ne recompose ni les libellés d'appui (`appuiLabel` vient du serveur) ni les
 * valeurs d'une révision : `changes` arrive **formaté** (« entre 1901 et 1909 »,
 * jamais un bloc date brut), et on l'affiche tel quel. Seuls les **noms** de
 * champ sont traduits, par la table de `administration/diff.ts` — la même que
 * celle qui relit une demande, pour que « birth » se lise « Naissance » partout.
 *
 * Module pur : la frise se prouve sans monter de composant (`historique.test.ts`).
 * ────────────────────────────────────────────────────────────────────
 */
import type { CitationDeFiche, FichePersonneComplete, RevisionDeFiche } from '@/lib/api'
import { libelleDeChamp } from '@/components/administration/diff'

export type NatureEntree = 'revision' | 'citation' | 'note' | 'media'

/**
 * Où en sont les révisions, l'autre moitié de la frise : `reserve` quand la
 * route est fermée au lecteur (compte en attente, `403 regime_en_attente`),
 * `tronque` quand le plafond de chargement a été atteint.
 */
export type EtatRevisions = 'chargement' | 'pret' | 'erreur' | 'reserve' | 'tronque'

export interface EntreeHistorique {
  /** Unique dans la frise : `citation-12`, `revision-210`… */
  cle: string
  nature: NatureEntree
  /** ATOM, tel que le serveur l'a rendu. */
  date: string
  titre: string
  /** Le détail, une ligne par élément : source et cote, début d'une note,
   *  champs changés. Déjà lisible, jamais vide de sens. */
  lignes: string[]
  auteur: string | null
  /** La demande d'origine, quand on la connaît (`/demandes/{id}`). */
  demandeId: number | null
  /** Une révision sans demande vient de l'import GEDCOM (§ 6.11). */
  importGedcom: boolean
  /** Un document : sa vignette, et de quoi l'ouvrir dans la loupe. */
  media: { mediaId: number; vignetteUrl: string; legende: string } | null
  /** Une source : son niveau d'appui, libellé du serveur. */
  appui: { niveau: CitationDeFiche['appui']; libelle: string } | null
}

/** Combien de champs une révision détaille avant de résumer le reste. */
const CHAMPS_AU_PLUS = 4

/** Le début d'un texte long, coupé à un espace. */
export function extrait(texte: string, longueur = 160): string {
  const net = texte.replace(/\s+/g, ' ').trim()
  if (net.length <= longueur) return net
  const coupe = net.slice(0, longueur)
  const espace = coupe.lastIndexOf(' ')
  return `${(espace > longueur * 0.6 ? coupe.slice(0, espace) : coupe).trimEnd()}…`
}

const TITRES_DE_REVISION: Record<string, string> = {
  creation: 'Fiche créée',
  modification: 'Fiche modifiée',
  suppression: 'Fiche supprimée',
  lien_ajoute: 'Lien ajouté',
  lien_retire: 'Lien retiré',
}

/** Une valeur de `changes`, déjà formatée par le serveur, en texte. */
function valeurLisible(valeur: unknown): string | null {
  if (valeur === null || valeur === undefined || valeur === '') return '(vide)'
  if (typeof valeur === 'string') return valeur
  if (typeof valeur === 'number') return String(valeur)
  if (typeof valeur === 'boolean') return valeur ? 'oui' : 'non'
  // Un objet sans forme connue : on nomme le champ, sans inventer sa valeur.
  return null
}

/** « Naissance : 1987 → entre 1901 et 1909 », une ligne par champ changé. */
export function lignesDeRevision(revision: Pick<RevisionDeFiche, 'operation' | 'changes'>): string[] {
  const champs = Object.entries(revision.changes ?? {})
  const lignes = champs.slice(0, CHAMPS_AU_PLUS).map(([champ, diff]) => {
    const libelle = libelleDeChamp(champ)
    if (diff === null || typeof diff !== 'object' || Array.isArray(diff)) {
      const seule = valeurLisible(diff)
      return seule === null ? libelle : `${libelle} : ${seule}`
    }
    const { avant, apres } = diff as { avant?: unknown; apres?: unknown }
    const nouvelle = valeurLisible(apres)
    if (nouvelle === null) return libelle
    // Une création n'a pas d'« avant » ; une fiche masquée n'en rend pas.
    const ancienne = 'avant' in diff && revision.operation !== 'creation' ? valeurLisible(avant) : null
    return ancienne === null || ancienne === '(vide)'
      ? `${libelle} : ${nouvelle}`
      : `${libelle} : ${ancienne} → ${nouvelle}`
  })
  const reste = champs.length - CHAMPS_AU_PLUS
  if (reste > 0) lignes.push(`… et ${reste} autre${reste > 1 ? 's' : ''} champ${reste > 1 ? 's' : ''}`)
  return lignes
}

function depuisRevision(revision: RevisionDeFiche): EntreeHistorique {
  return {
    cle: `revision-${revision.id}`,
    nature: 'revision',
    date: revision.appliedAt,
    titre: TITRES_DE_REVISION[revision.operation] ?? revision.operation,
    lignes: lignesDeRevision(revision),
    auteur: revision.actorLabel,
    demandeId: revision.requestId,
    importGedcom: revision.requestId === null,
    media: null,
    appui: null,
  }
}

function depuisCitation(citation: CitationDeFiche): EntreeHistorique {
  const cote = citation.page ?? citation.dataText
  return {
    cle: `citation-${citation.id}`,
    nature: 'citation',
    date: citation.createdAt,
    titre: 'Source ajoutée',
    lignes: [
      citation.source?.title ?? 'Source non nommée',
      ...(cote ? [extrait(cote, 140)] : []),
    ],
    auteur: null,
    demandeId: null,
    importGedcom: false,
    media: null,
    appui: { niveau: citation.appui, libelle: citation.appuiLabel },
  }
}

/** L'instant d'une entrée ; une date illisible part en bas plutôt que de casser le tri. */
function instant(entree: EntreeHistorique): number {
  const t = Date.parse(entree.date)
  return Number.isNaN(t) ? Number.NEGATIVE_INFINITY : t
}

/** À instant égal (un import pose tout à la même seconde) : la révision, puis
 *  ce qu'elle a apporté, dans un ordre stable. */
const RANG_DE_NATURE: Record<NatureEntree, number> = { revision: 0, citation: 1, note: 2, media: 3 }

/**
 * La frise : tout ce qui a été ajouté à la fiche ou changé sur elle, **du plus
 * récent au plus ancien**.
 */
export function entreesDHistorique(
  fiche: Pick<FichePersonneComplete, 'citations' | 'notes' | 'medias'>,
  revisions: readonly RevisionDeFiche[],
): EntreeHistorique[] {
  const entrees: EntreeHistorique[] = [
    ...revisions.map(depuisRevision),
    ...fiche.citations.map(depuisCitation),
    ...fiche.notes.map(
      (note): EntreeHistorique => ({
        cle: `note-${note.id}`,
        nature: 'note',
        date: note.createdAt,
        titre: 'Note ajoutée',
        lignes: [extrait(note.body)],
        auteur: note.authorLabel,
        demandeId: null,
        importGedcom: false,
        media: null,
        appui: null,
      }),
    ),
    ...fiche.medias.map(
      (media): EntreeHistorique => ({
        cle: `media-${media.mediaId}`,
        nature: 'media',
        date: media.createdAt,
        titre: media.kind === 'portrait' ? 'Portrait ajouté' : 'Document ajouté',
        lignes: media.caption ? [media.caption] : [],
        auteur: null,
        demandeId: null,
        importGedcom: false,
        media: {
          mediaId: media.mediaId,
          vignetteUrl: media.vignetteUrl,
          legende: media.caption ?? 'Document d’archive',
        },
        appui: null,
      }),
    ),
  ]

  return entrees.sort(
    (a, b) =>
      instant(b) - instant(a) ||
      RANG_DE_NATURE[a.nature] - RANG_DE_NATURE[b.nature] ||
      b.cle.localeCompare(a.cle, 'fr', { numeric: true }),
  )
}
