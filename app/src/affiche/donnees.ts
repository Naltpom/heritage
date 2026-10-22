/**
 * L'arbre de l'affiche, en mémoire — portage de `Personne`, `Union` et `Arbre` de
 * `commun.py`, nourri par `GET /api/arbre/familles/{id}/affiche` (§ 5.4 bis du contrat).
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Le front ne filtre rien
 *
 * Le cercle et la réciprocité sont appliqués **au serveur** : un vivant hors cercle arrive
 * sous le masque, sans date ; une naissance non partagée arrive réduite ou masquée. Ce
 * module dessine ce qu'il reçoit et n'essaie jamais de retrouver ce qu'on ne lui a pas
 * envoyé.
 *
 * ## « Masquer les dates des vivants » n'est pas un filtre non plus
 *
 * C'est une **mise en page** (option cochée par défaut) : une personne vivante garde son
 * nom et perd ses dates ; un mariage dont un conjoint est vivant perd sa date et son lieu,
 * mais garde son signe ∞. Les dates masquées sont celles que le lecteur voit déjà sur les
 * fiches — le but est une affiche qu'on peut accrocher dans un couloir.
 *
 * Deux écarts assumés avec `couple.py --vivants-sans-dates` :
 *
 * - **qui est vivant** : l'état `vivant` que le serveur calcule (§ 9.2 du modèle : seuil,
 *   repli par génération, confidentialité forcée), la seule définition du produit — et non
 *   « ni décès enregistré ni date de décès », qui aurait effacé les dates d'ancêtres du
 *   XVIIIᵉ siècle dont l'acte de décès manque ;
 * - **ce que le masque ne change pas** : l'ordre des unions et le veuvage se calculent sur
 *   les vraies dates (`decesReel`, `mariageReel`). Le script d'origine gardait déjà l'ordre
 *   (`f['tri']`) ; pour le veuvage, il lisait les dates masquées, et pouvait écrire « veuf »
 *   devant un conjoint mort *après* le remariage. Seul le mot de l'issue est dessiné, jamais
 *   la date qui le fonde.
 * ────────────────────────────────────────────────────────────────────
 */
import type { ArbreDAffiche, BlocDate, FamilleDAffiche, PersonneDAffiche } from '@/lib/api'
import { DateAffiche, normNom, normPrenoms } from './dates'

/** Ce que l'affiche écrit à la place du nom d'un vivant hors cercle. */
export const NOM_MASQUE = 'Personne vivante'

/** Un bloc date de l'API en date de l'affiche. Une naissance `masquee` ne garde que jour et mois. */
export function dateDepuisBloc(bloc: BlocDate | null | undefined): DateAffiche {
  if (!bloc) return DateAffiche.inconnue()
  if (bloc.qualifier === 'masquee') {
    // Sans l'année, le serveur n'envoie que les composantes sûres et partagées. L'année,
    // elle, n'est jamais là : quand elle est partagée, la date arrive réduite, pas masquée.
    const { jour, mois } = bloc.composantes ?? {}
    if (!jour && !mois) return DateAffiche.inconnue()
    return new DateAffiche(null, 'inconnue', null, null, { ...(jour ? { jour } : {}), ...(mois ? { mois } : {}) })
  }
  return new DateAffiche(bloc.raw, bloc.qualifier, bloc.min, bloc.max)
}

export class Personne {
  /** Une personne d'une demande « probable » — l'affiche d'origine en dessinait ; pas celle-ci. */
  readonly probable = false
  naissance: DateAffiche
  deces: DateAffiche
  /** Le décès tel qu'il est, même masqué à l'affichage — pour le veuvage. */
  readonly decesReel: DateAffiche

  constructor(
    readonly id: number,
    readonly prenoms: string,
    readonly nom: string,
    readonly sexe: string,
    readonly decede: boolean,
    /** L'état `vivant` du serveur (§ 9.2 du modèle) — jamais déduit ici. */
    readonly vivant: boolean,
    /** Hors du cercle du lecteur : sous le masque, sans date. */
    readonly masquee: boolean,
    naissance: DateAffiche,
    deces: DateAffiche,
  ) {
    this.naissance = naissance
    this.deces = deces
    this.decesReel = deces
  }

  static depuis(p: PersonneDAffiche): Personne {
    if (p.visibilite === 'restreinte') {
      return new Personne(p.id, NOM_MASQUE, '', p.sex, false, true, true, DateAffiche.inconnue(), DateAffiche.inconnue())
    }
    return new Personne(
      p.id,
      normPrenoms(p.givenNames),
      normNom(p.surname),
      p.sex,
      p.deceased,
      p.vivant,
      false,
      dateDepuisBloc(p.birth),
      dateDepuisBloc(p.death),
    )
  }

  get annee(): number | null {
    return this.naissance.annee
  }
}

/** Une union vue d'une personne : son conjoint, ses enfants, le mariage, son issue. */
export interface Union {
  fam: number
  conjoint: Personne | null
  enfants: Personne[]
  /** (date, lieu) **affichés** — vides quand le masque des vivants les a retirés. */
  mariage: [DateAffiche, string | null]
  /** La date du mariage telle qu'elle est, pour l'ordre des unions et le veuvage. */
  mariageReel: DateAffiche
  divorce: boolean
  /** Un mariage est connu : signe ∞ ; sinon & (union sans mariage connu). */
  marie: boolean
}

interface FamilleEnMemoire {
  id: number
  h: number | null
  w: number | null
  mariage: DateAffiche
  lieu: string | null
  mariageReel: DateAffiche
  divorce: boolean
  marie: boolean
  enfants: { personId: number; relation: string }[]
}

/**
 * « Marié », selon la règle du commanditaire (22/10/2026) :
 *
 * 1. `married`, quand le serveur le sert et qu'il n'est pas nul — la colonne en préparation
 *    sur `tree_family` : un mariage attesté sans date, ou une union libre déclarée ;
 * 2. sinon, vrai si une date, un lieu ou une chaîne GEDCOM de mariage existe ;
 * 3. sinon, vrai si la famille porte le **divorce** : un divorce suppose un mariage.
 *
 * Faux, c'est le « & » : une union sans mariage connu.
 */
export function estMarie(f: Pick<FamilleDAffiche, 'marriage' | 'divorced' | 'married'>): boolean {
  if (typeof f.married === 'boolean') return f.married
  const m = f.marriage
  return Boolean(m.raw || m.min || m.max || m.place) || f.divorced
}

export class ArbreAffiche {
  readonly P = new Map<number, Personne>()
  private readonly F = new Map<number, FamilleEnMemoire>()
  private readonly familleParents = new Map<number, number>()
  private readonly unionsDe = new Map<number, number[]>()

  constructor(readonly charge: ArbreDAffiche) {
    for (const p of charge.personnes) this.P.set(p.id, Personne.depuis(p))
    for (const f of charge.familles) {
      const mariage = dateDepuisBloc(f.marriage)
      this.F.set(f.id, {
        id: f.id,
        h: f.husband,
        w: f.wife,
        mariage,
        lieu: f.marriage.place,
        mariageReel: mariage,
        divorce: f.divorced,
        marie: estMarie(f),
        enfants: f.enfants,
      })
      for (const k of [f.husband, f.wife]) {
        if (k !== null) this.unionsDe.set(k, [...(this.unionsDe.get(k) ?? []), f.id])
      }
    }
    // La famille d'origine de chacun : `naissance` d'abord, puis le plus petit identifiant de
    // famille (`_famille_parents.setdefault()` sur des lignes ainsi triées).
    const lignes = charge.familles.flatMap((f) => f.enfants.map((e) => ({ f: f.id, p: e.personId, naissance: e.relation === 'naissance' })))
    lignes.sort((a, b) => Number(!a.naissance) - Number(!b.naissance) || a.f - b.f)
    for (const l of lignes) if (!this.familleParents.has(l.p)) this.familleParents.set(l.p, l.f)
  }

  personne(id: number | null): Personne | null {
    return id === null ? null : (this.P.get(id) ?? null)
  }

  parents(pid: number): [Personne | null, Personne | null] {
    const fam = this.familleParents.get(pid)
    if (fam === undefined) return [null, null]
    const f = this.F.get(fam)!
    return [this.personne(f.h), this.personne(f.w)]
  }

  /** Les enfants dans l'ordre du serveur — naissance, rang, identifiant, sur les vraies dates. */
  enfants(fam: number): Personne[] {
    const f = this.F.get(fam)
    if (!f) return []
    return f.enfants.map((e) => this.P.get(e.personId)).filter((p): p is Personne => p !== undefined)
  }

  mariage(fam: number): [DateAffiche, string | null] {
    const f = this.F.get(fam)!
    return [f.mariage, f.lieu]
  }

  unions(pid: number): Union[] {
    const sortie: Union[] = []
    for (const fam of this.unionsDe.get(pid) ?? []) {
      const f = this.F.get(fam)!
      sortie.push({
        fam,
        conjoint: this.personne(f.h === pid ? f.w : f.h),
        enfants: this.enfants(fam),
        mariage: [f.mariage, f.lieu],
        mariageReel: f.mariageReel,
        divorce: f.divorce,
        marie: f.marie,
      })
    }
    // Dans l'ordre des mariages, sur la vraie date même masquée ; sans date, après.
    sortie.sort((a, b) => cmp(a.mariageReel.tri ?? '9999', b.mariageReel.tri ?? '9999') || a.fam - b.fam)
    return sortie
  }

  familleDuCouple(h: number, f: number): number | null {
    const deF = this.unionsDe.get(f) ?? []
    return (this.unionsDe.get(h) ?? []).find((fam) => deF.includes(fam)) ?? null
  }

  /**
   * « Masquer les dates des vivants » : une personne vivante garde son nom et perd ses
   * dates ; un mariage dont un conjoint est vivant perd sa date et son lieu — pas son signe.
   * Rend les identifiants des vivants.
   */
  masquerVivants(): Set<number> {
    const vivants = new Set<number>()
    for (const p of this.P.values()) {
      if (p.vivant) {
        vivants.add(p.id)
        p.naissance = DateAffiche.inconnue()
        p.deces = DateAffiche.inconnue()
      }
    }
    for (const f of this.F.values()) {
      if ((f.h !== null && vivants.has(f.h)) || (f.w !== null && vivants.has(f.w))) {
        f.mariage = DateAffiche.inconnue()
        f.lieu = null
      }
    }
    return vivants
  }
}

function cmp(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0
}
