import { createSSRApp, h, shallowRef } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { EtatInscription } from '@/lib/api'
import DeclarationFamille from './DeclarationFamille.vue'
import EtapePhoto from './EtapePhoto.vue'
import { fermerFlux, ouvrirFlux, retenirDeclaration } from './fluxInscription'
import InscriptionCompteView from '@/views/InscriptionCompteView.vue'
import InscriptionEtatView from '@/views/InscriptionEtatView.vue'

/**
 * « Je ne me trouve pas », la photo à l'inscription, et l'écran d'état d'un
 * compte sans fiche — rendus côté serveur.
 *
 * Ce que ces cas tiennent :
 *
 * 1. **La description** : le champ est obligatoire, porte l'exemple, le
 *    compteur, et surtout **la mention** — seuls les administrateurs lisent ce
 *    texte, qui peut être analysé par Google Gemini. L'identité saisie à la
 *    recherche est reprise.
 * 2. **La photo** est présentée comme attendue — « Votre photo », jamais
 *    « facultatif » — mais un lien « Passer cette étape » permet de continuer.
 * 3. **L'écran d'état** a un texte propre au compte sans fiche, et ne propose
 *    d'ajouter la photo que si le serveur dit qu'il n'y en a pas.
 * 4. Rien de la description ne passe dans un attribut autre que la valeur du
 *    champ — et l'étape 2 annonce le compte « sans fiche ».
 */

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: () => Promise.resolve(), replace: () => Promise.resolve() }),
}))

vi.mock('@/composables/useSession', async () => {
  const { computed } = await import('vue')
  return {
    rafraichirSession: () => Promise.resolve(),
    useSession: () => ({ rattache: computed(() => false) }),
  }
})

const etatServi: { valeur: EtatInscription | null } = { valeur: null }

vi.mock('@/composables/useAppelApi', () => ({
  useAppelApi: () => ({
    donnees: shallowRef(etatServi.valeur),
    erreur: shallowRef(null),
    chargement: shallowRef(false),
    executer: () => Promise.resolve(etatServi.valeur),
  }),
}))

const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' }, custom: Boolean },
  template: '<a href="#"><slot :navigate="() => {}" /></a>',
}

async function rendre(composant: object, props: Record<string, unknown> = {}): Promise<string> {
  const app = createSSRApp({ render: () => h(composant, props) })
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

/* ------------------------------------------------------------------ */

describe('DeclarationFamille', () => {
  const IDENTITE = { surname: 'Dupont', givenNames: 'Léa Marie', birthDate: '1998-04-12' }

  it('pose le champ obligatoire, son exemple et son compteur', async () => {
    const html = await rendre(DeclarationFamille, { ...IDENTITE, description: '' })

    expect(html).toContain('Décrivez votre famille')
    expect(html).toMatch(/<textarea[^>]*required/)
    expect(html).toContain('Mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin ; ma grand-mère paternelle …')
    expect(html).toContain('0 / 60 caractères au minimum')
    expect(html).toContain('(obligatoire sans message vocal)')
  })

  it('porte la mention : administrateurs seulement, analyse possible par Gemini', async () => {
    const html = await rendre(DeclarationFamille, { ...IDENTITE, description: '' })

    expect(html).toContain('Lu seulement par les administrateurs')
    expect(html).toContain('Google Gemini')
  })

  it('reprend l’identité saisie à la recherche', async () => {
    const html = await rendre(DeclarationFamille, { ...IDENTITE, description: '' })

    expect(html).toContain('Dupont')
    expect(html).toContain('Léa Marie')
    // La date ferme, lue à la française — sans décalage de fuseau.
    expect(html).toContain('12/04/1998')
  })

  it('ne souligne aucune erreur avant le premier envoi', async () => {
    const html = await rendre(DeclarationFamille, { ...IDENTITE, description: 'court' })

    expect(html).not.toContain('aria-invalid')
    expect(html).not.toMatch(/Encore \d+ caractères/)
  })
})

/* ------------------------------------------------------------------ */

describe('EtapePhoto', () => {
  it('présente la photo comme attendue, avec un lien pour passer', async () => {
    const html = await rendre(EtapePhoto)

    expect(html).toContain('Votre photo')
    expect(html).toContain('Ajouter ma photo')
    expect(html).toContain('Passer cette étape')
    expect(html).not.toMatch(/facultati/i)
    expect(html).not.toMatch(/optionnel/i)
  })

  it('dit qui la voit tant que le compte attend', async () => {
    expect(await rendre(EtapePhoto, { enAttente: true })).toContain(
      'Seuls les administrateurs la voient',
    )
    expect(await rendre(EtapePhoto, { enAttente: false })).toContain('aussitôt le portrait')
  })
})

/* ------------------------------------------------------------------ */

describe('InscriptionCompteView, venue de « Je ne me trouve pas »', () => {
  afterEach(() => fermerFlux())

  it('annonce un compte sans fiche et ne rend pas la description', async () => {
    ouvrirFlux('jeton-essai', {
      mode: 'recherche',
      expiresAt: '2026-10-08T00:00:00+02:00',
      candidatsMax: 3,
      champsDemandes: ['surname', 'givenNames', 'birthDate'],
    })
    retenirDeclaration({
      surname: 'Dupont',
      givenNames: 'Léa Marie',
      birthDate: '1998-04-12',
      description: 'Mon père est Jean Dupont, né vers 1960 à Lille ; ma mère Marie Martin.',
    })

    const html = await rendre(InscriptionCompteView)

    expect(html).toContain('Choisir vos identifiants')
    expect(html).toContain('à partir de votre description')
    expect(html).toContain('Modifier ma description')
    // La description reste en mémoire, pour l'envoi : elle ne s'affiche pas ici.
    expect(html).not.toContain('Jean Dupont')
  })

  it('sans choix ni déclaration, dit que le parcours est perdu', async () => {
    const html = await rendre(InscriptionCompteView)
    expect(html).toContain('Reprenons depuis le début')
  })
})

/* ------------------------------------------------------------------ */

describe('InscriptionEtatView', () => {
  const BASE: EtatInscription = {
    status: 'en_attente',
    depuis: '2026-10-01T09:12:00+02:00',
    matchKind: 'a_rattacher',
    regime: 'aucun',
  }

  it('a un texte propre au compte sans fiche', async () => {
    etatServi.valeur = { ...BASE, portraitEnAttente: true }
    const html = await rendre(InscriptionEtatView)

    expect(html).toContain('à partir de votre description')
    expect(html).not.toContain('la fiche choisie')
    expect(html).not.toContain('y compris pour la fiche')
    // Plus rien de l'arbre, pas même les deux nombres (ADR 0003, 01/10/2026).
    expect(html).not.toContain('256')
  })

  it('garde le texte de confirmation pour un candidat qui s’est trouvé', async () => {
    etatServi.valeur = { ...BASE, matchKind: 'recherche_confirmee', portraitEnAttente: true }
    const html = await rendre(InscriptionEtatView)

    expect(html).toContain('la fiche choisie')
    expect(html).not.toContain('à partir de votre description')
  })

  it('propose la photo seulement quand le serveur dit qu’il n’y en a pas', async () => {
    etatServi.valeur = { ...BASE, portraitEnAttente: false }
    expect(await rendre(InscriptionEtatView)).toContain('Ajouter votre photo')

    etatServi.valeur = { ...BASE, portraitEnAttente: true }
    expect(await rendre(InscriptionEtatView)).not.toContain('Ajouter votre photo')

    // Clé absente : rien n'est proposé — l'absence ne vaut pas `false`.
    etatServi.valeur = { ...BASE }
    expect(await rendre(InscriptionEtatView)).not.toContain('Ajouter votre photo')
  })
})
