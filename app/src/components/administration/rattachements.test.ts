import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import {
  ErreurApi,
  type CompteARattacher,
  type SuggestionDeRattachement,
  type TranscriptionARattacher,
} from '@/lib/api'
import {
  CLE_DU_QUOTA,
  FileDAnalyses,
  ajouterParent,
  avisAvantLancement,
  brouillonEnregistrable,
  compteDisparu,
  creationPossible,
  dateDeclaree,
  etatApresErreur,
  etatDeTranscription,
  exigencesDeCreation,
  ficheARattacher,
  identiteDeclaree,
  initialesDeclarees,
  libelleDuLancement,
  libelleDuQuota,
  lignesDuChamp,
  mentionDeLAnalyse,
  motifDeBlocageDuLancement,
  quotaDesRattachements,
  transcriptionApresErreur,
  type EtatDAnalyse,
  type EtatDeTranscription,
  type QuotaRestant,
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

/** Une transcription jamais tentée — l'état d'un vocal tout juste déposé. */
const A_FAIRE: TranscriptionARattacher = { statut: 'a_faire', texte: null, erreur: null, le: null }

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
    messageVocal: null,
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
    transcriptionPriseEnCompte: false,
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
    expect(etat.message).toMatch(/quota/i)
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
  transcription?: EtatDeTranscription,
  quota: QuotaRestant | null = null,
): Promise<string> {
  const app = createSSRApp({
    render: () => h(CarteARattacher, { compte: sujet, analyse, suspendu, transcription, quota }),
  })
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

  it('sans message vocal, n’en dit rien', async () => {
    const html = await rendre(compte(), { etat: 'attente' })
    expect(html).not.toContain('data-message-vocal')
    expect(html).not.toContain('<audio')
  })

  it('avec un message vocal : « Message vocal », sa durée, et un lecteur sur la route du serveur', async () => {
    const html = await rendre(
      compte({
        messageVocal: {
          url: '/api/administration/rattachements/14/message-vocal',
          type: 'audio/webm',
          taille: 540 * 1024,
          duree: 134,
          transcription: A_FAIRE,
        },
      }),
      { etat: 'attente' },
    )
    expect(html).toContain('data-message-vocal')
    expect(html).toContain('Message vocal')
    expect(html).toContain('2:14')
    expect(html).toContain('540 ko')
    expect(html).toMatch(/<audio[^>]*src="\/api\/administration\/rattachements\/14\/message-vocal"/)
    expect(html).toMatch(/<audio[^>]*controls/)
    // Rien n'est téléchargé avant qu'on appuie sur lecture.
    expect(html).toMatch(/<audio[^>]*preload="none"/)
    // Le texte et la voix coexistent.
    expect(html).toContain('Mon père est Jean Serpolet')
  })

  it('un vocal sans texte : pas de citation vide', async () => {
    const html = await rendre(
      compte({
        declaration: { surname: 'Serpolet', givenNames: 'Léa Marie', birthDate: '1998-04-12', description: null },
        messageVocal: {
          url: '/api/administration/rattachements/14/message-vocal',
          type: 'audio/mp4',
          taille: 2048,
          duree: null,
          transcription: A_FAIRE,
        },
      }),
      { etat: 'attente' },
    )
    expect(html).not.toContain('data-description')
    expect(html).toContain('data-message-vocal')
    expect(html).toContain('2 ko')
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
    expect(html).toContain('indisponible')
    expect(html).not.toContain('Relancer')
  })

  it('après un échec : le message et « Relancer la recherche »', async () => {
    const html = await rendre(compte(), { etat: 'echec', message: 'La recherche a échoué.', attenteSecondes: null })
    expect(html).toContain('La recherche a échoué.')
    expect(eteint(html, 'Relancer la recherche')).toBe(false)
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

  it('sous la bascule, toutes les décisions sont éteintes — la recherche aussi', async () => {
    const html = await rendre(compte(), { etat: 'prete', suggestion: suggestion() }, true)
    for (const libelle of [
      'Rattacher à Léa Serpolet',
      'Choisir une autre fiche',
      'Créer la fiche et rattacher',
      'Refuser l’inscription',
      'Relancer la recherche',
    ]) {
      expect(eteint(html, libelle)).toBe(true)
    }
  })
})

/* ------------------------------------------------------------------ */
/* La recherche à la demande (3 octobre 2026)                          */
/* ------------------------------------------------------------------ */

const QUOTA: QuotaRestant = { restant: 37, limite: 40, fenetre: 'par 24 h' }

describe('la recherche ne part que sur un clic', () => {
  it('sans recherche faite : une phrase et « Lancer la recherche », allumé — rien en cours', async () => {
    const html = await rendre(compte(), { etat: 'aucune' })
    expect(html).toContain('data-analyse="aucune"')
    expect(html).toContain('Aucune recherche lancée')
    expect(eteint(html, 'Lancer la recherche')).toBe(false)
    expect(html).not.toContain('Recherche en cours')
    // Les gestes à la main restent là.
    expect(eteint(html, 'Choisir une autre fiche')).toBe(false)
    expect(eteint(html, 'Refuser l’inscription')).toBe(false)
  })

  it('le quota restant s’affiche à côté du bouton quand le serveur le donne — et rien sinon', async () => {
    const avec = await rendre(compte(), { etat: 'aucune' }, false, undefined, QUOTA)
    expect(avec).toContain('data-quota')
    expect(avec).toContain('37 appels à l’IA restants sur 40')

    const sans = await rendre(compte(), { etat: 'aucune' })
    expect(sans).not.toContain('data-quota')
    expect(sans).not.toContain('restant')
  })

  it('quota épuisé : le bouton s’éteint, et la carte dit pourquoi', async () => {
    const html = await rendre(compte(), { etat: 'aucune' }, false, undefined, { ...QUOTA, restant: 0 })
    expect(eteint(html, 'Lancer la recherche')).toBe(true)
    expect(html).toContain('Le quota du jour est atteint')
  })

  it('pendant la relecture, en file ou en cours : pas de bouton', async () => {
    for (const etat of ['lecture', 'attente', 'en_cours'] as const) {
      const html = await rendre(compte(), { etat })
      expect(html).not.toContain('data-lancer')
    }
    expect(await rendre(compte(), { etat: 'lecture' })).toContain('Lecture de la recherche déjà faite')
  })

  it('une suggestion périmée est montrée, signalée, et « Relancer la recherche » passe en principal', async () => {
    const html = await rendre(compte(), { etat: 'prete', suggestion: suggestion({ perimee: true }) })
    expect(html).toContain('data-perimee')
    expect(html).toContain('La transcription a changé depuis cette recherche : relancez la recherche.')
    expect(html).toContain('Probablement déjà dans l’arbre')
    expect(eteint(html, 'Relancer la recherche')).toBe(false)

    expect(await rendre(compte(), { etat: 'prete', suggestion: suggestion() })).not.toContain('data-perimee')
  })

  it('le libellé suit l’état : lancer, relancer, ou rien', () => {
    expect(libelleDuLancement({ etat: 'aucune' })).toBe('Lancer la recherche')
    expect(libelleDuLancement({ etat: 'prete', suggestion: suggestion() })).toBe('Relancer la recherche')
    expect(libelleDuLancement({ etat: 'echec', message: 'x', attenteSecondes: null })).toBe('Relancer la recherche')
    for (const etat of ['lecture', 'attente', 'en_cours', 'non_configuree'] as const) {
      expect(libelleDuLancement({ etat })).toBeNull()
    }
  })

  it('bloquée pendant une transcription ou avec une correction non enregistrée ; un simple avis sans transcription', () => {
    expect(motifDeBlocageDuLancement({ etat: 'en_cours' }, false)).toContain('fin de la transcription')
    expect(motifDeBlocageDuLancement({ etat: 'faite', texte: 'x', le: null, corrigeeLe: null }, true)).toContain(
      'Enregistrez',
    )
    expect(motifDeBlocageDuLancement({ etat: 'faite', texte: 'x', le: null, corrigeeLe: null }, false)).toBeNull()
    expect(motifDeBlocageDuLancement(null, false)).toBeNull()

    expect(avisAvantLancement({ etat: 'a_faire' })).toContain('pas transcrit')
    expect(avisAvantLancement({ etat: 'echec', message: 'x', reessayable: true })).toContain('pas transcrit')
    expect(avisAvantLancement({ etat: 'faite', texte: 'x', le: null, corrigeeLe: null })).toBeNull()
    expect(avisAvantLancement(null)).toBeNull()
  })

  it('le quota se lit dans la liste du serveur, et n’est jamais inventé', () => {
    const ligne = {
      cle: CLE_DU_QUOTA,
      libelle: 'Rattachements assistés',
      fenetre: 'par 24 h',
      consomme: 3,
      limite: 40,
      limiteParDefaut: 40,
      personnalisee: false,
    }
    expect(quotaDesRattachements([ligne])).toEqual({ restant: 37, limite: 40, fenetre: 'par 24 h' })
    expect(quotaDesRattachements([{ ...ligne, consomme: 45 }])?.restant).toBe(0)
    expect(quotaDesRattachements([{ ...ligne, cle: 'assistant.analyses' }])).toBeNull()
    expect(quotaDesRattachements([])).toBeNull()

    expect(libelleDuQuota({ restant: 1, limite: 40, fenetre: 'par 24 h' })).toContain('1 appel à l’IA restant sur 40')
    expect(libelleDuQuota({ restant: 0, limite: 0, fenetre: 'par 24 h' })).toContain('fermés')
  })
})

/* ------------------------------------------------------------------ */
/* La transcription du message vocal                                   */
/* ------------------------------------------------------------------ */

const TEXTE_TRANSCRIT = 'Je suis Léa Serpolet, née le 12 avril 1998 ; mon père est Jean Serpolet [?].'

/** Un compte qui n'a rien écrit et a laissé un vocal, transcrit comme indiqué. */
function compteAvecVocal(transcription: TranscriptionARattacher): CompteARattacher {
  return compte({
    declaration: { surname: 'Serpolet', givenNames: 'Léa Marie', birthDate: '1998-04-12', description: null },
    messageVocal: {
      url: '/api/administration/rattachements/14/message-vocal',
      type: 'audio/webm',
      taille: 4096,
      duree: 75,
      transcription,
    },
  })
}

describe('l’état de la transcription', () => {
  it('se lit sur ce que le serveur rend : à faire, faite, en échec', () => {
    expect(etatDeTranscription(A_FAIRE)).toEqual({ etat: 'a_faire' })
    expect(
      etatDeTranscription({ statut: 'faite', texte: 'Bonjour.', erreur: null, le: '2026-10-02T21:00:00+02:00' }),
    ).toEqual({ etat: 'faite', texte: 'Bonjour.', le: '2026-10-02T21:00:00+02:00', corrigeeLe: null })
    expect(
      etatDeTranscription({
        statut: 'faite',
        texte: 'Bonjour.',
        erreur: null,
        le: '2026-10-02T21:00:00+02:00',
        corrigeeLe: '2026-10-03T08:00:00+02:00',
      }),
    ).toMatchObject({ etat: 'faite', corrigeeLe: '2026-10-03T08:00:00+02:00' })
    expect(etatDeTranscription({ statut: 'echec', texte: null, erreur: 'Délai dépassé.', le: null })).toEqual({
      etat: 'echec',
      message: 'Délai dépassé.',
      reessayable: true,
    })
  })

  it('503 : pas de « Réessayer », qui échouerait pareil ; 429 et le reste : réessayable', () => {
    expect(transcriptionApresErreur(new ErreurApi(503, 'assistant_indisponible', 'x'))).toMatchObject({
      etat: 'echec',
      reessayable: false,
    })
    expect(transcriptionApresErreur(new ErreurApi(429, 'trop_de_tentatives', 'x'))).toMatchObject({
      etat: 'echec',
      reessayable: true,
    })
    expect(transcriptionApresErreur(new ErreurApi(500, 'erreur_interne', 'Panne.'))).toMatchObject({
      etat: 'echec',
      reessayable: true,
    })
  })

  it('dit si la recherche affichée a tenu compte de la transcription — et rien sans recherche prête', () => {
    const faite: EtatDeTranscription = { etat: 'faite', texte: TEXTE_TRANSCRIT, le: null, corrigeeLe: null }
    expect(
      mentionDeLAnalyse(faite, { etat: 'prete', suggestion: suggestion({ transcriptionPriseEnCompte: true }) }),
    ).toContain('en a tenu compte')
    expect(mentionDeLAnalyse(faite, { etat: 'prete', suggestion: suggestion() })).toContain('sans elle')
    expect(
      mentionDeLAnalyse(faite, {
        etat: 'prete',
        suggestion: suggestion({ transcriptionPriseEnCompte: true, perimee: true }),
      }),
    ).toContain('autre version')
    expect(mentionDeLAnalyse(faite, { etat: 'en_cours' })).toBeNull()
    expect(mentionDeLAnalyse({ etat: 'a_faire' }, { etat: 'prete', suggestion: suggestion() })).toBeNull()
    expect(mentionDeLAnalyse(null, { etat: 'prete', suggestion: suggestion() })).toBeNull()
  })
})

describe('la carte et la transcription', () => {
  it('sans message vocal, ne parle pas de transcription', async () => {
    const html = await rendre(compte(), { etat: 'attente' })
    expect(html).not.toContain('data-transcription')
    expect(html).not.toContain('Transcription')
  })

  it('pendant la transcription, le dit sous le lecteur — et l’audio reste là', async () => {
    const html = await rendre(compteAvecVocal(A_FAIRE), { etat: 'attente' }, false, { etat: 'en_cours' })
    expect(html).toContain('data-etat="en_cours"')
    expect(html).toContain('Transcription automatique en cours')
    expect(html).toMatch(/<audio[^>]*controls/)
  })

  it('jamais transcrite : rien ne part tout seul — « Transcrire le message vocal », ou l’écrire soi-même', async () => {
    const html = await rendre(compteAvecVocal(A_FAIRE), { etat: 'aucune' })
    expect(html).toContain('data-etat="a_faire"')
    expect(html).toContain('Pas encore transcrit.')
    expect(eteint(html, 'Transcrire le message vocal')).toBe(false)
    expect(eteint(html, 'L’écrire moi-même')).toBe(false)
    // Pas de champ tant qu'il n'y a pas de texte.
    expect(html).not.toContain('<textarea')
    // La recherche peut partir sans elle, mais la carte prévient.
    expect(html).toContain('Le message vocal n’est pas transcrit : la recherche ne l’entendra pas.')
    expect(eteint(html, 'Lancer la recherche')).toBe(false)
  })

  it('faite : le texte dans un champ modifiable, « Enregistrer » éteint tant que rien ne change, et « Retranscrire »', async () => {
    const html = await rendre(
      compteAvecVocal({ statut: 'faite', texte: TEXTE_TRANSCRIT, erreur: null, le: '2026-10-02T21:00:00+02:00' }),
      { etat: 'prete', suggestion: suggestion({ transcriptionPriseEnCompte: true }) },
    )
    expect(html).toContain('data-etat="faite"')
    expect(html).toMatch(/<textarea[^>]*data-champ-transcription[^>]*>[^<]*Jean Serpolet \[\?\]/)
    expect(html).toContain('Transcription automatique')
    expect(html).toContain('tels qu’entendus')
    expect(eteint(html, 'Enregistrer')).toBe(true)
    expect(eteint(html, 'Retranscrire')).toBe(false)
    expect(html).toContain('La recherche ci-dessous en a tenu compte.')
  })

  it('corrigée à la main : la carte le dit, avec la date', async () => {
    const html = await rendre(
      compteAvecVocal({
        statut: 'faite',
        texte: 'Je suis Léa Serpolet ; mon père est Jean Serpolet.',
        erreur: null,
        le: '2026-10-02T21:00:00+02:00',
        corrigeeLe: '2026-10-03T08:30:00+02:00',
      }),
      { etat: 'aucune' },
    )
    expect(html).toContain('Transcription — corrigée à la main')
    expect(html).toContain('Corrigée le')
    expect(html).toContain('la recherche lit ce texte tel quel')
    expect(html).not.toContain('tels qu’entendus')
  })

  it('pendant la transcription, « Lancer la recherche » attend', async () => {
    const html = await rendre(compteAvecVocal(A_FAIRE), { etat: 'aucune' }, false, { etat: 'en_cours' })
    expect(eteint(html, 'Lancer la recherche')).toBe(true)
    expect(html).toContain('Attendez la fin de la transcription.')
  })

  it('la description écrite, elle, n’est jamais un champ : c’est la parole du candidat', async () => {
    const html = await rendre(compte(), { etat: 'aucune' })
    expect(html).toContain('Mon père est Jean Serpolet')
    expect(html).not.toContain('<textarea')
  })

  it('un champ long défile au lieu de pousser les décisions hors de l’écran', () => {
    expect(lignesDuChamp('')).toBe(3)
    expect(lignesDuChamp('x'.repeat(700))).toBe(10)
    expect(lignesDuChamp('x'.repeat(6000))).toBe(14)
  })

  it('« Enregistrer » suit les règles du serveur : ni vide, ni identique, ni trop long', () => {
    expect(brouillonEnregistrable('Bonjour.', 'Bonjour.')).toEqual({ possible: false, motif: null })
    expect(brouillonEnregistrable('  Bonjour.  ', 'Bonjour.')).toEqual({ possible: false, motif: null })
    expect(brouillonEnregistrable('Bonjour, Jean.', 'Bonjour.')).toEqual({ possible: true, motif: null })
    expect(brouillonEnregistrable('   ', 'Bonjour.').motif).toContain('vide')
    expect(brouillonEnregistrable('x'.repeat(16001), null).motif).toContain('dépasse')
    // Une saisie à la main, sans texte enregistré.
    expect(brouillonEnregistrable('Je suis Léa.', null)).toEqual({ possible: true, motif: null })
    expect(brouillonEnregistrable('', null)).toEqual({ possible: false, motif: null })
  })

  it('en échec : le motif, « Réessayer la transcription » et « L’écrire moi-même », éteints sous la bascule', async () => {
    const echec = compteAvecVocal({ statut: 'echec', texte: null, erreur: 'Le modèle n’a pas répondu à temps.', le: null })
    const html = await rendre(echec, { etat: 'attente' })
    expect(html).toContain('data-etat="echec"')
    expect(html).toContain('Le modèle n’a pas répondu à temps.')
    expect(eteint(html, 'Réessayer la transcription')).toBe(false)

    expect(eteint(html, 'L’écrire moi-même')).toBe(false)

    const sousLaBascule = await rendre(echec, { etat: 'attente' }, true)
    expect(eteint(sousLaBascule, 'Réessayer la transcription')).toBe(true)
    expect(eteint(sousLaBascule, 'L’écrire moi-même')).toBe(true)
  })

  it('sans clé : le motif, mais pas de « Réessayer »', async () => {
    const html = await rendre(compteAvecVocal(A_FAIRE), { etat: 'non_configuree' }, false, {
      etat: 'echec',
      message: 'La transcription automatique n’est pas configurée sur cette installation : écoutez le message.',
      reessayable: false,
    })
    expect(html).toContain('pas configurée')
    expect(html).not.toContain('Réessayer la transcription')
    expect(html).toMatch(/<audio[^>]*controls/)
  })
})
