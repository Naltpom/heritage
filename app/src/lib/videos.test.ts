import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
// `@/router` appellerait `createWebHistory()` au chargement : la table seule.
import { routes } from '@/router/routes'
import {
  demarrerLaLecture,
  CLE_BIENVENUE_VUE,
  DOSSIER_VIDEOS,
  VIDEOS,
  bienvenueDejaVue,
  cheminsDe,
  dureeEnToutesLettres,
  formaterDuree,
  marquerBienvenueVue,
  prefereMoinsDAnimations,
  videoDeLaRequete,
  videoParId,
  videosDuGroupe,
  type Stockage,
} from './videos'

/**
 * Le catalogue des vidéos de présentation, et les décisions qui l'entourent.
 *
 * Ce qui se casse en silence ici :
 *
 * - un identifiant en double ou mal formé — deux cartes ouvriraient la même
 *   vidéo, ou un nom de fichier ne correspondrait plus à celui déposé dans
 *   `public/videos/`, et rien ne le dirait avant qu'un membre touche la carte ;
 * - un lien « Ouvrir l'arbre » vers une route renommée — la page Aide se
 *   rendrait, et le lien lèverait au toucher ;
 * - la mémoire de « Bienvenue » : rejouée à chaque visite, elle devient une
 *   vidéo qu'on ferme sans la regarder ; jamais jouée, elle ne sert à rien.
 */

/** Un faux `localStorage`, en mémoire. */
function stockage(initial: Record<string, string> = {}): Stockage & { donnees: Record<string, string> } {
  const donnees = { ...initial }
  return {
    donnees,
    getItem: (cle) => donnees[cle] ?? null,
    setItem: (cle, valeur) => {
      donnees[cle] = valeur
    },
  }
}

/** Un stockage qui lève à chaque appel, comme Safari en navigation privée ancienne. */
const STOCKAGE_HOSTILE: Stockage = {
  getItem: () => {
    throw new Error('SecurityError')
  },
  setItem: () => {
    throw new Error('QuotaExceededError')
  },
}

describe('le catalogue', () => {
  it('compte les huit vidéos, dans l’ordre d’affichage', () => {
    expect(VIDEOS.map((v) => v.id)).toEqual([
      'bienvenue',
      'decouvrir',
      'lire-l-arbre',
      'filtres-de-l-arbre',
      'proposer-une-correction',
      'sources-et-actes',
      'notifications-et-calendrier',
      'carte-et-chronologie',
    ])
  })

  it('n’a aucun identifiant en double', () => {
    const ids = VIDEOS.map((v) => v.id)
    expect(new Set(ids).size).toBe(ids.length)
  })

  /**
   * L'identifiant devient un nom de fichier ET une valeur de `?video=` : des
   * minuscules, des chiffres et des tirets, rien qui demande un encodage.
   */
  it('n’emploie que des identifiants sûrs dans un nom de fichier et une URL', () => {
    for (const v of VIDEOS) expect(v.id).toMatch(/^[a-z0-9]+(?:-[a-z0-9]+)*$/)
  })

  it('donne à chaque vidéo un titre, une phrase et une durée plausible', () => {
    for (const v of VIDEOS) {
      expect(v.titre.trim()).not.toBe('')
      expect(v.description.trim()).not.toBe('')
      // Une phrase, pas un paragraphe : elle tient sous le titre d'une carte.
      expect(v.description.length).toBeLessThanOrEqual(120)
      expect(v.dureeSecondes).toBeGreaterThan(5)
      expect(v.dureeSecondes).toBeLessThan(180)
    }
  })

  it('met « Bienvenue » et « Découvrir » en tête, et le reste en chapitres', () => {
    expect(videosDuGroupe('bien-commencer').map((v) => v.id)).toEqual(['bienvenue', 'decouvrir'])
    expect(videosDuGroupe('fonctionnalite')).toHaveLength(6)
    expect(videosDuGroupe('bien-commencer').length + videosDuGroupe('fonctionnalite').length).toBe(VIDEOS.length)
  })
})

describe('les chemins des fichiers', () => {
  it('déduit les trois fichiers de l’identifiant, sous /videos/', () => {
    expect(cheminsDe({ id: 'bienvenue' })).toEqual({
      mp4: '/videos/bienvenue.mp4',
      affiche: '/videos/bienvenue.jpg',
      sousTitres: '/videos/bienvenue.vtt',
    })
  })

  it('donne à chaque vidéo des chemins distincts, absolus, dans le même dossier', () => {
    const tous = VIDEOS.flatMap((v) => Object.values(cheminsDe(v)))

    expect(new Set(tous).size).toBe(VIDEOS.length * 3)
    for (const chemin of tous) {
      expect(chemin.startsWith(DOSSIER_VIDEOS)).toBe(true)
      expect(chemin).toMatch(/^\/videos\/[a-z0-9-]+\.(mp4|jpg|vtt)$/)
    }
  })
})

describe('les écrans des chapitres', () => {
  const routeur = createRouter({ history: createMemoryHistory(), routes })

  it('ne cite que des routes qui existent', () => {
    for (const v of VIDEOS) {
      if (!v.ecran) continue
      expect(routeur.hasRoute(v.ecran.nom), `${v.id} → ${v.ecran.nom}`).toBe(true)
      expect(v.ecran.libelle.trim()).not.toBe('')
    }
  })

  /**
   * La page Aide est réservée aux membres : un lien de chapitre vers un écran
   * d'entrée (`anonyme`) ou d'administration renverrait le lecteur ailleurs.
   */
  it('ne mène qu’à des écrans qu’un membre peut ouvrir', () => {
    for (const v of VIDEOS) {
      if (!v.ecran) continue
      const acces = routeur.resolve({ name: v.ecran.nom }).meta.acces
      expect(['membre', 'attente', 'public'], `${v.id} → ${acces}`).toContain(acces)
    }
  })

  it('n’en donne pas aux deux présentations générales', () => {
    for (const v of videosDuGroupe('bien-commencer')) expect(v.ecran).toBeUndefined()
  })
})

describe('?video=<id>', () => {
  it('trouve la vidéo nommée', () => {
    expect(videoDeLaRequete('filtres-de-l-arbre')?.titre).toBe('Les filtres de l’arbre')
  })

  it('prend la première valeur d’un paramètre répété', () => {
    expect(videoDeLaRequete(['decouvrir', 'bienvenue'])?.id).toBe('decouvrir')
  })

  it('ignore, sans erreur, un identifiant inconnu, vide ou absent', () => {
    expect(videoDeLaRequete('video-retiree')).toBeNull()
    expect(videoDeLaRequete('')).toBeNull()
    expect(videoDeLaRequete(undefined)).toBeNull()
    expect(videoDeLaRequete(null)).toBeNull()
    expect(videoDeLaRequete([])).toBeNull()
    expect(videoDeLaRequete([null])).toBeNull()
  })

  it('ne devine pas : la casse et les espaces comptent', () => {
    expect(videoDeLaRequete('Bienvenue')).toBeNull()
    expect(videoDeLaRequete(' bienvenue')).toBeNull()
  })

  it('lève sur un identifiant inconnu quand on le demande par son nom dans le code', () => {
    expect(videoParId('bienvenue').id).toBe('bienvenue')
    expect(() => videoParId('inexistante')).toThrow(/inexistante/)
  })
})

describe('la durée affichée', () => {
  it.each([
    [35, '0:35'],
    [28, '0:28'],
    [5, '0:05'],
    [60, '1:00'],
    [65, '1:05'],
    [34.6, '0:35'],
    [0, '0:00'],
    [-3, '0:00'],
    [Number.NaN, '0:00'],
  ])('%s s → « %s »', (secondes, attendu) => {
    expect(formaterDuree(secondes)).toBe(attendu)
  })

  it.each([
    [35, '35 secondes'],
    [1, '1 seconde'],
    [60, '1 minute'],
    [65, '1 minute 5'],
    [125, '2 minutes 5'],
  ])('%s s se dit « %s »', (secondes, attendu) => {
    expect(dureeEnToutesLettres(secondes)).toBe(attendu)
  })
})

describe('« Bienvenue », une seule fois par navigateur', () => {
  it('porte une clé de la forme du produit', () => {
    expect(CLE_BIENVENUE_VUE).toBe('heritage.videos.bienvenue.vue')
  })

  it('n’est pas « déjà vue » sur un navigateur neuf', () => {
    expect(bienvenueDejaVue(stockage())).toBe(false)
  })

  it('l’est une fois marquée, et la marque est « 1 »', () => {
    const s = stockage()
    marquerBienvenueVue(s)

    expect(s.donnees[CLE_BIENVENUE_VUE]).toBe('1')
    expect(bienvenueDejaVue(s)).toBe(true)
  })

  it('ne compte pas une autre valeur comme une marque', () => {
    expect(bienvenueDejaVue(stockage({ [CLE_BIENVENUE_VUE]: '0' }))).toBe(false)
  })

  /**
   * Sans stockage, « déjà vue » : une vidéo qui revient à chaque ouverture du
   * lien est pire qu'une vidéo qu'on ne voit pas d'office — le lien « Revoir »
   * reste là.
   */
  it('répond « déjà vue » quand le stockage manque ou lève', () => {
    expect(bienvenueDejaVue(null)).toBe(true)
    expect(bienvenueDejaVue(STOCKAGE_HOSTILE)).toBe(true)
  })

  it('n’échoue pas quand le stockage refuse d’écrire', () => {
    expect(() => marquerBienvenueVue(STOCKAGE_HOSTILE)).not.toThrow()
    expect(() => marquerBienvenueVue(null)).not.toThrow()
  })

  it('lit le vrai stockage par défaut, sans lever hors navigateur', () => {
    // Sous Node, pas de `localStorage` : « déjà vue », et l'écriture ne fait rien.
    expect(() => marquerBienvenueVue()).not.toThrow()
    expect(typeof bienvenueDejaVue()).toBe('boolean')
  })
})

describe('le mouvement réduit', () => {
  it('rend faux hors navigateur, sans lever', () => {
    expect(prefereMoinsDAnimations()).toBe(false)
  })
})

describe('demarrerLaLecture — le son d’abord, le muet en repli', () => {
  const refus = (): Error => Object.assign(new Error('refusé'), { name: 'NotAllowedError' })
  /** Un faux `<video>` : `permis(muet)` dit si le navigateur accepte de jouer. */
  function faux(permis: (muet: boolean) => boolean | 'autre') {
    const essais: boolean[] = []
    const video = {
      muted: true,
      play() {
        essais.push(this.muted)
        const r = permis(this.muted)
        if (r === 'autre') return Promise.reject(Object.assign(new Error('x'), { name: 'AbortError' }))
        return r ? Promise.resolve() : Promise.reject(refus())
      },
    }
    return { video, essais }
  }

  it('joue avec le son quand le navigateur le permet (un clic vient de la demander)', async () => {
    const { video, essais } = faux(() => true)
    expect(await demarrerLaLecture(video)).toBe('son')
    expect(video.muted).toBe(false)
    expect(essais).toEqual([false])
  })

  it('retombe en muet quand le son est refusé (arrivée par un lien)', async () => {
    const { video, essais } = faux((muet) => muet)
    expect(await demarrerLaLecture(video)).toBe('muet')
    expect(video.muted).toBe(true)
    expect(essais).toEqual([false, true])
  })

  it('attend un geste quand même le muet est refusé', async () => {
    const { video } = faux(() => false)
    expect(await demarrerLaLecture(video)).toBe('geste')
  })

  it('ne réessaie pas sur une erreur qui n’est pas une permission', async () => {
    const { video, essais } = faux(() => 'autre')
    expect(await demarrerLaLecture(video)).toBe('echec')
    expect(essais).toEqual([false])
  })
})
