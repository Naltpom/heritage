import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { ErreurApi, analyserErreur, type ResultatValidation } from '@/lib/api'
import {
  actionValidation,
  annonceEnchainement,
  annonceValidation,
  erreurMotifRefus,
  issueDeValidation,
  issueDepuisRequete,
  peutRefuser,
} from './decision'

/**
 * Ce que ces tests protègent, dans l'ordre où ça coûte cher :
 *
 *  1. **Un refus sans motif ne part pas.** Exigence explicite du commanditaire :
 *     l'auteur reçoit le motif en notification, et une demande refusée reste
 *     consultable avec lui. La contrainte `ck_prp_request_motif_refus` refuse la
 *     ligne en base de toute façon — mais un refus parti sans motif reviendrait
 *     en `422` après que l'administrateur a cru avoir tranché.
 *
 *  2. **Les deux `409` donnent deux conseils opposés.** `verrou_concurrent` :
 *     rien n'a été écrit, réessayer. `demande_caduque` : la demande est morte,
 *     surtout pas réessayer. Un front qui proposerait « Réessayer » sur les deux
 *     ferait boucler l'administrateur sur une demande caduque.
 *
 *  3. **Un rejeu automatique se voit.** C'est une écriture qu'aucun humain n'a
 *     réexaminée ; le journal la distingue, l'écran doit la distinguer aussi.
 */

/* ------------------------------------------------------------------ */
/* 1. Le motif de refus                                                */
/* ------------------------------------------------------------------ */

describe('le motif de refus est obligatoire', () => {
  it('refuse un motif vide', () => {
    expect(peutRefuser('')).toBe(false)
    expect(erreurMotifRefus('')).not.toBeNull()
  })

  it('refuse un motif fait d’espaces — le serveur `btrim` aussi', () => {
    // Une règle front plus laxiste enverrait une requête vouée au 422.
    expect(peutRefuser('   ')).toBe(false)
    expect(peutRefuser('\n\t ')).toBe(false)
  })

  it('accepte un motif court mais réel', () => {
    // Pas de longueur minimale inventée : le serveur n'en impose pas, et en
    // ajouter une refuserait un « doublon » parfaitement clair.
    expect(peutRefuser('doublon')).toBe(true)
    expect(erreurMotifRefus('doublon')).toBeNull()
  })

  it('dit que le motif partira à l’auteur', () => {
    // Le message explique la conséquence, pas seulement la contrainte : c'est
    // ce qui fait écrire une phrase utile plutôt qu'un point.
    expect(erreurMotifRefus('')).toMatch(/auteur/i)
  })
})

describe('l’écran applique la garde avant d’appeler l’API', () => {
  /**
   * Le **code** de l'écran, commentaires retirés.
   *
   * Le dépouillement n'est pas une précaution de style : sans lui, ce test passe
   * alors que la garde a disparu. Le commentaire de tête de `DecisionDemande.vue`
   * explique que « la garde `peutRefuser()` passe avant l'appel » — la chaîne
   * `peutRefuser(` y figure donc, avant l'appel, et l'ordre est vérifié sur une
   * phrase de documentation plutôt que sur du code. Vérifié par mutation : en
   * supprimant la ligne de garde, ce test doit échouer, et il échoue.
   */
  const SOURCE = readFileSync(fileURLToPath(new URL('./DecisionDemande.vue', import.meta.url)), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')

  it('vérifie `peutRefuser` avant `refuserDemande`', () => {
    // Écrit en ordre de lecture du fichier : la seule façon de vérifier « avant
    // l'envoi » sans monter un navigateur.
    const garde = SOURCE.indexOf('peutRefuser(motif.value)')
    const appel = SOURCE.indexOf('await refuserDemande(')

    expect(garde).toBeGreaterThan(-1)
    expect(appel).toBeGreaterThan(-1)
    expect(garde).toBeLessThan(appel)
  })

  it('n’a pas de second chemin d’appel qui contournerait la garde', () => {
    // Un seul appel à `refuserDemande` dans le code : deux chemins, et l'un des
    // deux finira par oublier la garde.
    const occurrences = SOURCE.split('refuserDemande(').length - 1
    // L'import compte pour une occurrence, l'appel pour la seconde.
    expect(occurrences).toBeLessThanOrEqual(2)
  })
})

/* ------------------------------------------------------------------ */
/* 2. Les deux 409                                                     */
/* ------------------------------------------------------------------ */

describe('les deux 409 de la validation', () => {
  /** § 6.8, issue 5 — deux transactions concurrentes. Rien n'a été écrit. */
  const VERROU = new ErreurApi(
    409,
    'verrou_concurrent',
    "Une autre écriture a touché cette fiche pendant la validation. Rien n'a été écrit.",
    null,
    { demandeId: 14, statut: 'en_attente', rejouable: true },
  )

  /** § 6.8, issue 3 — **une 409 qui écrit** : la demande passe `caduque`. */
  const CADUQUE = new ErreurApi(
    409,
    'demande_caduque',
    'La demande est devenue caduque : la fiche a changé depuis son dépôt.',
    null,
    { demandeId: 14, statut: 'caduque', rejouable: false, champs: [] },
  )

  it('conduisent à deux conduites différentes', () => {
    expect(analyserErreur(VERROU).conduite).toBe('reessayer')
    expect(analyserErreur(CADUQUE).conduite).toBe('refaire')
  })

  it('proposent deux libellés différents, et jamais le même', () => {
    const verrou = analyserErreur(VERROU).libelleAction
    const caduque = analyserErreur(CADUQUE).libelleAction

    expect(verrou).toBe('Réessayer')
    expect(caduque).toBe('Refaire cette demande')
    expect(caduque).not.toBe(verrou)
  })

  it('ne proposent jamais « Réessayer » sur une demande morte', () => {
    // C'est l'assertion qui compte : elle interdit la boucle infinie sur une
    // demande caduque, qui est le mode de défaillance nommé par le contrat.
    expect(analyserErreur(CADUQUE).libelleAction).not.toMatch(/réessayer/i)
    expect(analyserErreur(CADUQUE).rejouable).toBe(false)
  })

  it('portent le même statut HTTP — c’est le code qui les sépare', () => {
    // Un écran qui brancherait sur le statut 409 les confondrait toutes les
    // deux. C'est pour cela qu'elles ont deux codes.
    expect(VERROU.statut).toBe(CADUQUE.statut)
    expect(VERROU.code).not.toBe(CADUQUE.code)
  })
})

describe('l’écran de décision ne retraite pas les codes d’erreur', () => {
  const FICHIERS = ['./DecisionDemande.vue', '../../views/DemandeView.vue'] as const

  function code(chemin: string): string {
    return readFileSync(fileURLToPath(new URL(chemin, import.meta.url)), 'utf8')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/<!--[\s\S]*?-->/g, ' ')
      .replace(/(^|[^:])\/\/.*$/gm, '$1')
  }

  it('ne teste aucun des deux codes à la main', () => {
    for (const chemin of FICHIERS) {
      const source = code(chemin)
      // Le socle les distingue dans `erreurs.ts`. Les retester ici, c'est créer
      // un second endroit où l'un des deux sera oublié le jour où le contrat en
      // ajoute un troisième.
      expect(source).not.toMatch(/demande_caduque/)
      expect(source).not.toMatch(/verrou_concurrent/)
    }
  })

  it('n’écrit pas non plus les libellés d’action en dur', () => {
    for (const chemin of FICHIERS) {
      const source = code(chemin)
      expect(source).not.toMatch(/'Réessayer'|"Réessayer"/)
      expect(source).not.toMatch(/Refaire cette demande/)
    }
  })
})

/* ------------------------------------------------------------------ */
/* 3. resolutionPrevue, et le rejeu                                    */
/* ------------------------------------------------------------------ */

describe('ce que propose le bouton « Valider »', () => {
  it('sur `application` : valider, sans commentaire', () => {
    const action = actionValidation('application')
    expect(action.desactive).toBe(false)
    expect(action.libelle).toBe('Valider')
    expect(action.explication).toBeNull()
  })

  it('sur `rejeu` : valider, en annonçant le rejeu', () => {
    const action = actionValidation('rejeu')
    expect(action.desactive).toBe(false)
    expect(action.libelle).toMatch(/rejouée/i)
    expect(action.explication).not.toBeNull()
  })

  it('sur `caducite` : le bouton est éteint, et on dit pourquoi', () => {
    // Sans ce calcul, l'administrateur découvre la caducité en cliquant — ce qui
    // la rend indistinguable d'une panne.
    const action = actionValidation('caducite')
    expect(action.desactive).toBe(true)
    expect(action.libelle).not.toMatch(/^Valider$/)
    expect(action.explication).toMatch(/écraserait|refaite/i)
  })

  it('sur `cible_disparue` : éteint, et surtout pas `undefined`', () => {
    // **Le cas que l'union ignorait.** La valeur n'était pas dans
    // `ResolutionPrevue`, donc elle ne tombait dans aucun `case`, donc cette
    // fonction rendait `undefined` — sur la seule demande qu'il fallait
    // surtout ne pas valider, et l'écran de décision plantait à l'ouverture.
    const action = actionValidation('cible_disparue')
    expect(action).toBeDefined()
    expect(action.desactive).toBe(true)
    expect(action.explication).toMatch(/n’existe plus/i)
  })

  it('sur `dependance_en_attente` : on dit qu’il y a un ordre, pas une mort', () => {
    // Ni un succès ni une fin : la demande s'appliquera **telle quelle** après
    // l'autre, sans que son auteur la refasse. Dire « validation impossible »
    // enverrait l'administrateur refuser une demande parfaitement valide.
    const action = actionValidation('dependance_en_attente')
    expect(action.desactive).toBe(true)
    expect(action.libelle).toMatch(/après/i)
    expect(action.explication).toMatch(/Validez d’abord/i)
    // Et le contraire d'une caducité : la demande n'est pas à refaire, elle est
    // à attendre. La phrase le dit dans ces termes.
    expect(action.explication).toMatch(/sans que son auteur ait à la refaire/i)
  })

  it('sur `dependance_morte` : éteint, et la reprise est la sortie', () => {
    const action = actionValidation('dependance_morte')
    expect(action.desactive).toBe(true)
    expect(action.explication).toMatch(/n’aboutira pas/i)
    expect(action.explication).toMatch(/refaire/i)
  })

  it('sans `resolutionPrevue` : défaut fermé', () => {
    // La charge de l'auteur (§ 6.5) ne porte pas ce champ. Ne rien savoir de
    // l'état de la cible n'autorise pas à promettre une validation.
    const action = actionValidation(undefined)
    expect(action.desactive).toBe(true)
    expect(action.explication).not.toBeNull()
  })
})

describe('un rejeu automatique se voit', () => {
  const base = {
    demande: { id: 14, status: 'validee' },
    revisions: [],
  } as unknown as ResultatValidation

  it('une validation ordinaire se dit simplement', () => {
    const annonce = annonceValidation({ ...base, resultat: 'validee' })

    expect(annonce.rejoue).toBe(false)
    expect(annonce.titre).toBe('Demande validée')
  })

  it('un rejeu se distingue d’une validation ordinaire', () => {
    const annonce = annonceValidation({
      ...base,
      resultat: 'rejouee',
      rejeu: {
        motif: 'version_modifiee',
        versionALaSoumission: 7,
        versionAppliquee: 8,
        champsVerifies: ['birth'],
      },
    })

    expect(annonce.rejoue).toBe(true)
    expect(annonce.titre).not.toBe('Demande validée')
    expect(annonce.titre).toMatch(/rejeu/i)
  })

  it('dit qu’aucun humain n’a réexaminé l’écriture', () => {
    // C'est la raison d'être de la distinction, et elle est tracée au journal
    // pour la même raison : une écriture non réexaminée doit se voir.
    const annonce = annonceValidation({
      ...base,
      resultat: 'rejouee',
      rejeu: {
        motif: 'version_modifiee',
        versionALaSoumission: 7,
        versionAppliquee: 8,
        champsVerifies: ['birth'],
      },
    })

    expect(annonce.detail).toMatch(/réexamin/i)
    expect(annonce.detail).toContain('birth')
  })
})

/* ------------------------------------------------------------------ */
/* 4. « Valider & suivant », « Refuser & suivant »                      */
/* ------------------------------------------------------------------ */

describe('« & suivant »', () => {
  const SOURCE = readFileSync(fileURLToPath(new URL('./DecisionDemande.vue', import.meta.url)), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')

  it('offre les deux boutons, qui passent par les mêmes fonctions', () => {
    expect(SOURCE).toContain('aria-label="Valider & suivant"')
    expect(SOURCE).toContain('aria-label="Refuser & suivant"')
    expect(SOURCE).toContain('@click="valider(true)"')
    // « Refuser & suivant » ouvre le formulaire du motif, il ne refuse pas d'un clic.
    expect(SOURCE).toContain('@click="ouvrirRefus(true)"')
    expect(SOURCE.split('validerDemande(').length - 1).toBeLessThanOrEqual(2)
  })

  it('tient les quatre décisions dans une barre fixée en bas, au-dessus de la barre de navigation, en icônes nommées', () => {
    const barre = SOURCE.slice(SOURCE.indexOf('role="toolbar"'))
    expect(SOURCE.slice(SOURCE.lastIndexOf('<div', SOURCE.indexOf('role="toolbar"')))).toMatch(/class="fixed inset-x-0 bottom-\[var\(--hauteur-barre-bas,0px\)\]/)
    const ordre = ['@click="valider()"', '@click="valider(true)"', '@click="ouvrirRefus(false)"', '@click="ouvrirRefus(true)"'].map((c) => barre.indexOf(c))
    expect(ordre.every((i) => i > -1)).toBe(true)
    expect([...ordre].sort((a, b) => a - b)).toEqual(ordre)
    // Pas de texte visible : chaque bouton se nomme par aria-label et title.
    expect(barre.slice(0, barre.indexOf('</div>')).split('aria-label=').length - 1).toBeGreaterThanOrEqual(4)
  })

  it('« Valider & suivant » s’éteint dans les mêmes cas que « Valider »', () => {
    const bouton = SOURCE.slice(0, SOURCE.indexOf('aria-label="Valider & suivant"'))
    const attributs = bouton.slice(bouton.lastIndexOf('<button'))
    expect(attributs).toContain('validation.desactive')
    expect(attributs).toContain('suspendu')
  })

  it('ne rend l’issue qu’après le succès de l’appel', () => {
    const appel = SOURCE.indexOf('await validerDemande(')
    const enchainement = SOURCE.indexOf("emit('decidee', issueDeValidation(resultat))")
    expect(appel).toBeGreaterThan(-1)
    expect(enchainement).toBeGreaterThan(appel)

    const refus = SOURCE.indexOf('await refuserDemande(')
    expect(SOURCE.indexOf("emit('decidee', 'refusee')")).toBeGreaterThan(refus)
  })

  it('distingue un rejeu dans l’issue transmise', () => {
    expect(issueDeValidation({ resultat: 'rejouee' } as ResultatValidation)).toBe('rejouee')
    expect(issueDeValidation({ resultat: 'validee' } as ResultatValidation)).toBe('validee')
  })

  it('relit l’issue de l’adresse sans rien inventer', () => {
    expect(issueDepuisRequete('refusee')).toBe('refusee')
    expect(issueDepuisRequete('supprimee')).toBeNull()
    expect(issueDepuisRequete(undefined)).toBeNull()
  })

  it('annonce la décision précédente, et la fin de la file', () => {
    expect(annonceEnchainement(12, 'validee', false)).toBe('Demande n° 12 validée. Voici la suivante dans la file.')
    expect(annonceEnchainement(12, 'refusee', true)).toBe(
      'Demande n° 12 refusée. Il n’y a plus de demande en attente dans cette file.',
    )
    expect(annonceEnchainement(12, 'rejouee', false)).toMatch(/rejeu automatique/)
  })
})
