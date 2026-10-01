/**
 * Le tirage vu par un lien personnel (`/t/<clé>`), rendu côté serveur — donc
 * sans geste.
 *
 * 1. **Lien invalide** : un message clair, et rien d'autre.
 * 2. **Édition ouverte** : sa présence se règle en trois boutons.
 * 3. **Tirage fait** : le bouton violet « Voir à qui je fais le cadeau », et le
 *    nom du receveur **absent du HTML** ; la présence n'est plus réglable.
 * 4. **Le référent** : l'attribution de celui qu'on accompagne est nommée comme
 *    telle.
 * 5. **Rien d'autre que ce participant** : ni en-tête applicatif, ni liste des
 *    participants — le composant n'a d'ailleurs rien pour les rendre.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type { LectureParLien } from '@/lib/api'
import SuiviParLien, { type EtatDuLien } from './SuiviParLien.vue'

function lecture(partiel: Partial<LectureParLien> = {}): LectureParLien {
  return {
    tirage: { nom: 'Noël des Romarin', lieu: 'Fleurance' },
    edition: { annee: 2026, date: '2026-12-24', statut: 'ouverte', passee: false },
    participant: { personId: 4, libelle: 'Léa Romarin', presence: 'sur_place', peutChangerPresence: true },
    attributions: [],
    ...partiel,
  }
}

async function rendre(etat: EtatDuLien): Promise<string> {
  return renderToString(createSSRApp({ render: () => h(SuiviParLien, { etat }) }))
}

describe('SuiviParLien', () => {
  it('lien invalide : le dit clairement, sans rien montrer du tirage', async () => {
    const html = await rendre({ type: 'invalide' })

    expect(html).toContain('Ce lien n’est plus valable')
    expect(html).toContain('remplacé par un nouveau lien, ou révoqué')
    expect(html).not.toContain('Ma présence')
  })

  it('chargement : ni message d’erreur ni contenu', async () => {
    const html = await rendre({ type: 'chargement' })

    expect(html).not.toContain('Ce lien n’est plus valable')
    expect(html).not.toContain('Ma présence')
  })

  it('édition ouverte : titre, date, lieu, et la présence en trois boutons', async () => {
    const html = await rendre({ type: 'pret', lecture: lecture() })

    expect(html).toContain('Noël des Romarin')
    expect(html).toContain('24 décembre 2026')
    expect(html).toContain('Fleurance')
    expect(html).toContain('Pour Léa Romarin')
    expect(html).toContain('aria-label="Ma présence"')
    expect(html).toContain('Sur place')
    expect(html).toContain('À distance')
    expect(html).toContain('Ne participe pas')
    expect(html).toContain('n’a pas encore eu lieu')
    expect(html).not.toContain('Voir à qui')
    // Ni en-tête applicatif ni navigation : rien qui suppose un compte.
    expect(html).not.toContain('Se connecter')
    expect(html).not.toContain('Qui vient')
  })

  it('tirage fait : le bouton violet, et le receveur absent du HTML', async () => {
    const html = await rendre({
      type: 'pret',
      lecture: lecture({
        edition: { annee: 2026, date: '2026-12-24', statut: 'tiree', passee: false },
        participant: { personId: 4, libelle: 'Léa Romarin', presence: 'a_distance', peutChangerPresence: false },
        attributions: [
          {
            donneur: { personId: 4, libelle: 'Léa Romarin' },
            receveur: { personId: 2, libelle: 'Cobalt Romarin' },
            presenceDuDonneur: 'a_distance',
          },
        ],
      }),
    })

    expect(html).toContain('Voir à qui je fais le cadeau')
    expect(html).toContain('bg-cadeau')
    expect(html).not.toContain('Cobalt Romarin')
    expect(html).toContain('Le tirage est fait.')
    expect(html).not.toContain('aria-label="Ma présence"')
    expect(html).toContain('À distance')
    expect(html).toContain('prévenez l’organisateur')
  })

  it('référent : l’attribution de celui qu’on accompagne est nommée comme telle', async () => {
    const html = await rendre({
      type: 'pret',
      lecture: lecture({
        edition: { annee: 2026, date: '2026-12-24', statut: 'tiree', passee: false },
        participant: { personId: 3, libelle: 'Opale Tilleul', presence: 'sur_place', peutChangerPresence: false },
        attributions: [
          {
            donneur: { personId: 3, libelle: 'Opale Tilleul' },
            receveur: { personId: 2, libelle: 'Cobalt Romarin' },
            presenceDuDonneur: 'sur_place',
          },
          {
            donneur: { personId: 5, libelle: 'Petit Tilleul' },
            receveur: { personId: 6, libelle: 'Ambre Chardon' },
            presenceDuDonneur: 'sur_place',
          },
        ],
      }),
    })

    expect(html).toContain('Voir à qui je fais le cadeau')
    expect(html).toContain('Voir à qui Petit Tilleul fait le cadeau')
    expect(html).not.toContain('Cobalt Romarin')
    expect(html).not.toContain('Ambre Chardon')
  })

  it('tirage fait sans lui : le dit, sans bouton cadeau', async () => {
    const html = await rendre({
      type: 'pret',
      lecture: lecture({
        edition: { annee: 2026, date: '2026-12-24', statut: 'tiree', passee: false },
        participant: { personId: 4, libelle: 'Léa Romarin', presence: 'absente', peutChangerPresence: false },
      }),
    })

    expect(html).toContain('Vous n’y participez pas cette année')
    expect(html).not.toContain('Voir à qui')
  })

  it('aucune année ouverte : le dit, sans réglage de présence', async () => {
    const html = await rendre({
      type: 'pret',
      lecture: lecture({ edition: null, participant: { personId: 4, libelle: 'Léa Romarin', presence: null, peutChangerPresence: false } }),
    })

    expect(html).toContain('Aucune année n’est ouverte')
    expect(html).not.toContain('Ma présence')
  })
})
