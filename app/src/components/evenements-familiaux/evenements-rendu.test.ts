/**
 * Les composants des événements, rendus côté serveur — donc sans geste.
 *
 * 1. **Les boutons de réponse** : deux, pas trois ; la réponse courante marquée.
 * 2. **Le lien personnel** : l'annonce et les deux boutons, **rien d'autre** —
 *    ni qui vient, ni l'organisateur ; un lien invalide le dit, sans rien
 *    montrer ; un événement annulé ne propose plus de répondre.
 * 3. **Le suivi** : les quatre groupes, les fantômes nommés comme tels, le
 *    « vu il y a … », et le geste du lien pour qui n'a pas de compte.
 * 4. **Le sélecteur** : le compteur, « Tous les vivants », les branches.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { AnnonceParLien, LigneDeSuivi, SuiviDEvenement } from '@/lib/api'
import BoutonsDeReponse from './BoutonsDeReponse.vue'
import ReponseParLien, { type EtatDuLienDEvenement } from './ReponseParLien.vue'
import SelecteurDInvites from './SelecteurDInvites.vue'
import SuiviDesInvites from './SuiviDesInvites.vue'
import type { Invitable } from './invites'

async function rendre(composant: object, props: Record<string, unknown>): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(composant, props) }))
}

describe('BoutonsDeReponse', () => {
  it('deux boutons — Présent, Absent — et pas de « peut-être »', async () => {
    const html = await rendre(BoutonsDeReponse, { reponse: null })

    expect(html).toContain('Présent')
    expect(html).toContain('Absent')
    expect(html.toLowerCase()).not.toContain('peut-être')
    expect(html.match(/aria-pressed="true"/g)).toBeNull()
  })

  it('marque la réponse courante', async () => {
    const html = await rendre(BoutonsDeReponse, { reponse: 'present' })
    expect(html.match(/aria-pressed="true"/g)).toHaveLength(1)
    expect(html).toContain('bg-ok')
  })
})

function lecture(partiel: Partial<AnnonceParLien['evenement']> = {}, invite: Partial<AnnonceParLien['invite']> = {}): AnnonceParLien {
  return {
    evenement: {
      titre: 'Cousinade été 2027',
      debut: '2027-07-17',
      fin: '2027-07-18',
      lieu: 'Chez Tante Ardoise',
      description: 'Apportez un plat.\nOn dort sur place.',
      annule: false,
      passe: false,
      ...partiel,
    },
    invite: { libelle: 'Mamie Rose', reponse: null, peutRepondre: true, ...invite },
  }
}

describe('ReponseParLien', () => {
  const rendreLien = (etat: EtatDuLienDEvenement): Promise<string> => rendre(ReponseParLien, { etat })

  it('lien invalide : le dit clairement, sans rien montrer de l’événement', async () => {
    const html = await rendreLien({ type: 'invalide' })
    expect(html).toContain('Ce lien n’est plus valable')
    expect(html).not.toContain('Présent')
  })

  it('l’annonce, l’invitée, et les deux boutons — rien d’autre', async () => {
    const html = await rendreLien({ type: 'pret', lecture: lecture() })

    expect(html).toContain('Cousinade été 2027')
    expect(html).toContain('Du sam. 17 au dim. 18 juillet 2027')
    expect(html).toContain('Chez Tante Ardoise')
    expect(html).toContain('Apportez un plat.')
    expect(html).toContain('Pour Mamie Rose')
    expect(html).toContain('Venez-vous ?')
    expect(html).toContain('aria-label="Ma réponse"')
    expect(html).not.toContain('Qui vient')
    expect(html).not.toContain('Organisé par')
  })

  it('événement annulé : le dit, et ne propose plus de répondre', async () => {
    const html = await rendreLien({ type: 'pret', lecture: lecture({ annule: true }, { reponse: 'present', peutRepondre: false }) })

    expect(html).toContain('Cet événement est annulé.')
    expect(html).not.toContain('aria-label="Ma réponse"')
    expect(html).toContain('Vous avez dit : présent.')
  })
})

function ligne(personId: number, libelle: string, partiel: Partial<LigneDeSuivi> = {}): LigneDeSuivi {
  return {
    personId,
    libelle,
    estMoi: false,
    aUnCompte: true,
    lienActif: false,
    peutPartagerLien: false,
    vuLe: null,
    reponduLe: null,
    relanceLe: null,
    ...partiel,
  }
}

describe('SuiviDesInvites', () => {
  const maintenant = new Date(2027, 6, 1, 10)
  const ilYA = (jours: number): string => new Date(maintenant.getTime() - jours * 86_400_000).toISOString()

  const suivi: SuiviDEvenement = {
    presents: [ligne(1, 'Cobalt Romarin', { reponduLe: ilYA(1), vuLe: ilYA(1) })],
    absents: [ligne(2, 'Jaspe Tilleul', { reponduLe: ilYA(3), vuLe: ilYA(3) })],
    fantomes: [ligne(3, 'Onyx Romarin', { vuLe: ilYA(2) })],
    nonVus: [
      ligne(4, 'Mamie Rose', { aUnCompte: false, peutPartagerLien: true }),
      ligne(5, 'Ardoise Tilleul'),
    ],
    derniereRelanceLe: null,
    prochaineRelancePossibleLe: null,
    aRelancer: 2,
  }

  it('les quatre groupes, avec leur compte, et depuis quand', async () => {
    const html = await rendre(SuiviDesInvites, { suivi, maintenant, modifiable: true })

    expect(html).toContain('Présents')
    expect(html).toContain('Absents')
    expect(html).toContain('Vu, sans réponse 👻')
    expect(html).toContain('Pas encore vu')
    expect(html).toContain('vu il y a 2 j')
    expect(html).toContain('sans compte · pas encore de lien')
    expect(html).toContain('a un compte')
  })

  it('« Relancer 2 invités », et le rappel du lien pour qui n’a pas de compte', async () => {
    const html = await rendre(SuiviDesInvites, { suivi, maintenant, modifiable: true })

    expect(html).toContain('Relancer 2 invités')
    expect(html).toContain('1 invité sans compte n’a pas répondu')
    // Le geste du lien : sur la seule ligne sans compte.
    expect(html.match(/Copier son lien/g)).toHaveLength(1)
  })

  it('un événement terminé ou annulé ne propose plus ni relance, ni retrait', async () => {
    const html = await rendre(SuiviDesInvites, { suivi, maintenant, modifiable: false })

    expect(html).not.toContain('Relancer')
    expect(html).not.toContain('Retirer')
  })
})

describe('SelecteurDInvites', () => {
  const invitables: Invitable[] = [
    { personId: 1, libelle: 'Cobalt Romarin', groupe: 'pp', nomDuGroupe: 'Romarin' },
    { personId: 2, libelle: 'Ambre Chardon', groupe: 'mp', nomDuGroupe: 'Chardon' },
    { personId: 3, libelle: 'Jaspe Tilleul', groupe: 'mm', nomDuGroupe: 'Tilleul' },
  ]

  it('le compteur, « Tous les vivants » et un raccourci par branche', async () => {
    const html = await rendre(SelecteurDInvites, { invitables, modelValue: [1, 2] })

    expect(html).toContain('2 invités')
    expect(html).toContain('Tous les vivants')
    expect(html).toContain('Romarin')
    expect(html).toContain('Chardon')
    expect(html).toContain('Tilleul')
    expect(html.match(/type="checkbox"/g)).toHaveLength(3)
  })

  it('ne propose plus ceux qui sont déjà invités', async () => {
    const html = await rendre(SelecteurDInvites, { invitables, dejaInvites: [1, 2, 3], modelValue: [] })

    expect(html).toContain('Tous les vivants que vous voyez dans l’arbre sont déjà invités.')
    expect(html).not.toContain('type="checkbox"')
  })
})
