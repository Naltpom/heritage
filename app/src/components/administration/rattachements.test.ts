import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { ErreurApi, type CompteARattacher, type SuggestionDeRattachement } from '@/lib/api'
import {
  FileDAnalyses,
  ajouterParent,
  compteDisparu,
  creationPossible,
  dateDeclaree,
  etatApresErreur,
  exigencesDeCreation,
  ficheARattacher,
  identiteDeclaree,
  initialesDeclarees,
  type EtatDAnalyse,
} from './rattachements'
import CarteARattacher from './CarteARattacher.vue'

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  LES INSCRIPTIONS À RATTACHER — CE QUE LA CARTE DÉCIDE ET CE QU'ELLE IMPRIME.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Trois choses se cassent séparément, et chacune coûte cher à sa façon :
 *
 *  1. **La file des analyses.** Si deux analyses partent ensemble, dix cartes
 *     font dix appels au modèle d'un coup, sur un quota global de 40 par jour.
 *  2. **La règle des parents.** Sans création recevable, « Créer la fiche »
 *     partirait sans lien et reviendrait en `422 demande_sans_lien` — après
 *     que l'administrateur a cru avoir fini.
 *  3. **Le gabarit.** La carte doit rester utilisable sans analyse, et le
 *     bouton « Rattacher » doit s'éteindre sur une fiche déjà réclamée.
 *
 * Rendu en SSR, comme le reste du projet — pas de `jsdom`. Les noms de ce jeu
 * d'essai sont inventés : la base porte l'arbre réel du commanditaire.
 */

function compte(sur: Partial<CompteARattacher> = {}): CompteARattacher {
  return {
    memberId: 14,
    userId: 22,
    email: 'lea@example.net',
    inscritLe: '2026-10-01T09:12:00+02:00',
    invitationId: 3,
    invitationPersonId: null,
    invitationPersonLabel: null,
    declaration: {
      surname: 'Serpolet',
      givenNames: 'Léa Marie',
      birthDate: '1998-04-12',
      description: 'Mon père est Jean Serpolet, né vers 1960 à Lille ;\nma mère Marie Sarriette.',
    },
    photo: null,
    suggestionCalculeeLe: null,
    ...sur,
  }
}

function suggestion(sur: Partial<SuggestionDeRattachement> = {}): SuggestionDeRattachement {
  return {
    memberId: 14,
    calculeeLe: '2026-10-01T09:13:00+02:00',
    modele: 'gemini-pro-latest',
    verdict: 'fiche_existante',
    resume: 'La description correspond à une fiche de l’arbre.',
    avertissements: [],
    fiche: {
      personId: 812,
      displayName: 'Léa Serpolet',
      ligneDates: '1998',
      justification: 'Même père, même année.',
      confiance: 'haute',
      dejaRattachee: false,
    },
    alternatives: [],
    creation: null,
    ...sur,
  }
}

function creation(recevable: boolean, motif: string | null = null): NonNullable<SuggestionDeRattachement['creation']> {
  return {
    titre: 'Ajouter Léa Serpolet',
    justification: 'Absente de l’arbre ; son père y est.',
    confiance: 'moyenne',
    corps: { kind: 'creation_personne', soumettre: true, brouillons: [], liens: [] },
    changements: [{ libelle: 'Prénoms', avant: null, apres: 'Léa Marie' }],
    personnes: [],
    recevable,
    motifIrrecevable: motif,
  }
}

/** Une promesse qu'on résout de l'extérieur : de quoi tenir une analyse « en cours ». */
function differee(): { promesse: Promise<void>; resoudre: () => void } {
  let resoudre!: () => void
  const promesse = new Promise<void>((r) => {
    resoudre = r
  })
  return { promesse, resoudre }
}

/* ------------------------------------------------------------------ */

describe('la file des analyses', () => {
  it('n’en mène jamais deux à la fois, et les mène dans l’ordre', async () => {
    const file = new FileDAnalyses()
    const ordre: number[] = []
    let simultanees = 0
    let maximum = 0
    const attentes = [differee(), differee(), differee()]

    const fin = Promise.all(
      [1, 2, 3].map((cle, index) =>
        file.ajouter(cle, async () => {
          simultanees++
          maximum = Math.max(maximum, simultanees)
          ordre.push(cle)
          await attentes[index]!.promesse
          simultanees--
        }),
      ),
    )

    expect(file.cleEnCours).toBe(1)
    attentes.forEach((attente) => attente.resoudre())
    await fin

    expect(maximum).toBe(1)
    expect(ordre).toEqual([1, 2, 3])
    expect(file.cleEnCours).toBeNull()
  })

  it('n’ajoute pas deux fois une carte qui attend déjà — le quota ne paie qu’une analyse', async () => {
    const file = new FileDAnalyses()
    const bloquante = differee()
    const appels: number[] = []

    void file.ajouter(1, () => bloquante.promesse)
    void file.ajouter(2, async () => void appels.push(2))
    const fin = file.ajouter(2, async () => void appels.push(22))

    bloquante.resoudre()
    await fin
    expect(appels).toEqual([2])
  })

  it('continue après une analyse qui échoue', async () => {
    const file = new FileDAnalyses()
    const appels: number[] = []
    void file.ajouter(1, () => Promise.reject(new Error('panne')))
    await file.ajouter(2, async () => void appels.push(2))
    expect(appels).toEqual([2])
  })

  it('vider() abandonne ce qui attend et coupe l’appel en cours', async () => {
    const file = new FileDAnalyses()
    const bloquante = differee()
    let signalRecu: AbortSignal | null = null
    const appels: number[] = []

    const fin = file.ajouter(1, (signal) => {
      signalRecu = signal
      return bloquante.promesse
    })
    void file.ajouter(2, async () => void appels.push(2))

    file.vider()
    expect((signalRecu as AbortSignal | null)?.aborted).toBe(true)
    bloquante.resoudre()
    await fin
    expect(appels).toEqual([])
  })

  it('retirer() ôte une carte traitée de la file', async () => {
    const file = new FileDAnalyses()
    const bloquante = differee()
    const appels: number[] = []

    const fin = file.ajouter(1, () => bloquante.promesse)
    void file.ajouter(2, async () => void appels.push(2))
    void file.ajouter(3, async () => void appels.push(3))
    file.retirer(2)

    bloquante.resoudre()
    await fin
    expect(appels).toEqual([3])
  })
})

/* ------------------------------------------------------------------ */

describe('l’état d’une analyse après une erreur', () => {
  it('503 : non configurée — un réglage, pas une panne, donc pas de « Relancer »', () => {
    expect(etatApresErreur(new ErreurApi(503, 'assistant_indisponible', 'x'))).toEqual({ etat: 'non_configuree' })
  })

  it('429 : le quota, avec le délai quand le serveur le donne', () => {
    const etat = etatApresErreur(new ErreurApi(429, 'trop_de_tentatives', 'x', null, { retryAfter: 3600 }))
    expect(etat.etat).toBe('echec')
    if (etat.etat !== 'echec') return
    expect(etat.message).toMatch(/quota/)
    expect(etat.attenteSecondes).toBe(3600)
  })

  it('502 : l’échec du modèle se dit, et se relance', () => {
    const etat = etatApresErreur(new ErreurApi(502, 'assistant_erreur', 'x'))
    expect(etat).toMatchObject({ etat: 'echec', attenteSecondes: null })
  })

  it('404 : le compte a été traité ailleurs, la carte doit partir', () => {
    expect(compteDisparu(new ErreurApi(404, 'ressource_introuvable', 'x'))).toBe(true)
    expect(compteDisparu(new ErreurApi(502, 'assistant_erreur', 'x'))).toBe(false)
  })
})

/* ------------------------------------------------------------------ */

describe('la déclaration', () => {
  it('écrit la date sans passer par Date — pas de jour perdu à l’ouest de Greenwich', () => {
    expect(dateDeclaree('1998-04-12')).toBe('12 avril 1998')
    expect(dateDeclaree('2001-08-01')).toBe('1er août 2001')
    expect(dateDeclaree('1998-13-01')).toBe('1998-13-01')
    expect(dateDeclaree('vers 1998')).toBe('vers 1998')
  })

  it('nomme la personne prénoms puis nom, et en tire des initiales', () => {
    expect(identiteDeclaree(compte())).toBe('Léa Marie Serpolet')
    expect(initialesDeclarees(compte())).toBe('LS')
  })
})

/* ------------------------------------------------------------------ */

describe('ce que la suggestion permet', () => {
  it('une fiche déjà réclamée reste montrée, mais « Rattacher » s’éteint', () => {
    expect(ficheARattacher(suggestion())).toEqual({ personId: 812, displayName: 'Léa Serpolet', possible: true })
    const reclamee = suggestion()
    reclamee.fiche!.dejaRattachee = true
    expect(ficheARattacher(reclamee)?.possible).toBe(false)
    expect(ficheARattacher(null)).toBeNull()
  })

  it('sans analyse, créer exige un parent : la déclaration seule ne porte aucun lien', () => {
    expect(exigencesDeCreation(null).parentsRequis).toBe(true)
    expect(creationPossible(null, [])).toBe(false)
    expect(creationPossible(null, [{ personId: 12, libelle: 'Jean Serpolet' }])).toBe(true)
  })

  it('une création irrecevable exige un parent et dit pourquoi, avec le motif du serveur', () => {
    const avecMotif = suggestion({ verdict: 'absente', fiche: null, creation: creation(false, 'Aucun lien vers l’arbre.') })
    expect(exigencesDeCreation(avecMotif)).toEqual({ parentsRequis: true, motif: 'Aucun lien vers l’arbre.' })
    expect(creationPossible(avecMotif, [])).toBe(false)
  })

  it('une création recevable part sans parent — les parents choisis la corrigent', () => {
    const recevable = suggestion({ verdict: 'absente', fiche: null, creation: creation(true) })
    expect(exigencesDeCreation(recevable)).toEqual({ parentsRequis: false, motif: null })
    expect(creationPossible(recevable, [])).toBe(true)
  })

  it('deux parents au plus, jamais deux fois le même', () => {
    const pere = { personId: 12, libelle: 'Jean Serpolet' }
    const mere = { personId: 57, libelle: 'Marie Sarriette' }
    let parents = ajouterParent([], pere)
    parents = ajouterParent(parents, pere)
    expect(parents).toEqual([pere])
    parents = ajouterParent(parents, mere)
    parents = ajouterParent(parents, { personId: 99, libelle: 'Un troisième' })
    expect(parents).toEqual([pere, mere])
  })
})

/* ------------------------------------------------------------------ */
/* Le gabarit                                                          */
/* ------------------------------------------------------------------ */

/** Le balisage rendu, commentaires retirés (voir `fiche-proposee-rendu.test.ts`). */
async function rendre(
  sujet: CompteARattacher,
  analyse: EtatDAnalyse,
  suspendu = false,
): Promise<string> {
  const app = createSSRApp({ render: () => h(CarteARattacher, { compte: sujet, analyse, suspendu }) })
  return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * Le bouton dont le texte contient `libelle` est-il éteint ? On lit l'attribut
 * `disabled` de sa balise ouvrante — pas la chaîne « disabled », que les
 * classes utilitaires (`disabled:opacity-55`) portent sur tous les boutons.
 */
function eteint(html: string, libelle: string): boolean {
  const morceaux = html.split('<button')
  const trouve = morceaux.find((morceau) => morceau.split('</button>')[0]!.includes(libelle))
  if (trouve === undefined) throw new Error(`Bouton absent : ${libelle}`)
  const ouvrante = trouve.slice(0, trouve.indexOf('>'))
  return /\sdisabled(?=[\s=>]|$)/.test(ouvrante)
}

describe('la carte « À rattacher »', () => {
  it('montre ce que la personne a déclaré, description comprise, et ses initiales sans photo', async () => {
    const html = await rendre(compte(), { etat: 'attente' })
    expect(html).toContain('Léa Marie Serpolet')
    expect(html).toContain('12 avril 1998')
    expect(html).toContain('lea@example.net')
    expect(html).toContain('Mon père est Jean Serpolet')
    // La description garde ses retours à la ligne.
    expect(html).toMatch(/data-description[^>]*whitespace-pre-line|whitespace-pre-line[^>]*data-description/)
    expect(html).toContain('data-initiales')
    expect(html).not.toContain('<img')
  })

  it('montre la vignette de la photo déposée, telle que le serveur l’écrit', async () => {
    const html = await rendre(
      compte({ photo: { mediaId: 91, url: '/api/medias/91/fichier', vignetteUrl: '/api/medias/91/vignette' } }),
      { etat: 'attente' },
    )
    expect(html).toContain('src="/api/medias/91/vignette"')
    expect(html).toContain('href="/api/medias/91/fichier"')
    expect(html).not.toContain('data-initiales')
  })

  it('pendant l’analyse, le dit — et laisse décider à la main', async () => {
    const html = await rendre(compte(), { etat: 'en_cours' })
    expect(html).toContain('data-analyse="en_cours"')
    expect(html).toContain('20 à 60 secondes')
    expect(eteint(html, 'Choisir une autre fiche')).toBe(false)
    expect(eteint(html, 'Créer la fiche et rattacher')).toBe(false)
  })

  it('sans clé : une phrase neutre, et pas de « Relancer » qui échouerait pareil', async () => {
    const html = await rendre(compte(), { etat: 'non_configuree' })
    expect(html).toContain('n’est pas configurée')
    expect(html).not.toContain('Relancer')
  })

  it('après un échec : le message et « Relancer l’analyse »', async () => {
    const html = await rendre(compte(), { etat: 'echec', message: 'L’analyse a échoué.', attenteSecondes: null })
    expect(html).toContain('L’analyse a échoué.')
    expect(html).toContain('Relancer l’analyse')
  })

  it('prête : « Rattacher à … » la fiche désignée', async () => {
    const html = await rendre(compte(), { etat: 'prete', suggestion: suggestion() })
    expect(html).toContain('Probablement déjà dans l’arbre')
    expect(eteint(html, 'Rattacher à Léa Serpolet')).toBe(false)
  })

  it('une fiche déjà réclamée par un autre compte : montrée, bouton éteint', async () => {
    const reclamee = suggestion()
    reclamee.fiche!.dejaRattachee = true
    const html = await rendre(compte(), { etat: 'prete', suggestion: reclamee })
    expect(html).toContain('déjà rattachée à un autre compte')
    expect(eteint(html, 'Rattacher à Léa Serpolet')).toBe(true)
  })

  it('sous la bascule, toutes les décisions sont éteintes', async () => {
    const html = await rendre(compte(), { etat: 'prete', suggestion: suggestion() }, true)
    for (const libelle of ['Rattacher à Léa Serpolet', 'Choisir une autre fiche', 'Créer la fiche et rattacher', 'Refuser l’inscription']) {
      expect(eteint(html, libelle)).toBe(true)
    }
  })
})
