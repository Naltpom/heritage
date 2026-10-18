/**
 * Ce que la cloche écrit — sans navigateur. Suite du rendu :
 * `components/notifications/cloche-rendu.test.ts`.
 */
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { Notification } from '@/lib/api'
import { dateRelative, etiquetteCloche, libelleDeType, lienDeNotification, texteCompteur, tonDeType } from './notifications'

describe('la pastille', () => {
  it('ne s’affiche pas à zéro', () => {
    expect(texteCompteur(0)).toBeNull()
    expect(texteCompteur(-2)).toBeNull()
  })

  it('dit le nombre jusqu’à neuf, puis « 9+ »', () => {
    expect(texteCompteur(1)).toBe('1')
    expect(texteCompteur(9)).toBe('9')
    expect(texteCompteur(10)).toBe('9+')
    expect(texteCompteur(137)).toBe('9+')
  })

  it('donne le vrai nombre au lecteur d’écran, pas « 9+ »', () => {
    expect(etiquetteCloche(0)).toBe('Notifications, aucune non lue')
    expect(etiquetteCloche(1)).toBe('Notifications, 1 non lue')
    expect(etiquetteCloche(37)).toBe('Notifications, 37 non lues')
  })
})

describe('le type', () => {
  it('a une étiquette lisible pour chacun des vingt-deux types du serveur', () => {
    const types = [
      'demande_soumise',
      'demande_validee',
      'demande_refusee',
      'demande_caduque',
      'doublon_probable',
      'invitation_utilisee',
      'compte_en_attente',
      'compte_active',
      'media_valide',
      'media_refuse',
      'tirage_effectue',
      'evenement_invitation',
      'evenement_relance',
      'evenement_annule',
      'evenement_dates_modifiees',
      'demande_rgpd',
      'demande_rgpd_traitee',
      'partage_naissance_revu',
      'demande_completee',
      'complement_fusionne',
      'noel_tirage_effectue',
      'noel_tirage_modifie',
    ]
    for (const t of types) {
      expect(libelleDeType(t)).not.toBe('Notification')
      expect(libelleDeType(t)).not.toContain('_')
    }
  })

  it('retombe sur « Notification » pour un type inconnu — jamais le code brut', () => {
    expect(libelleDeType('nouveau_type_2027')).toBe('Notification')
  })

  it('marque un refus en alerte et une acceptation en ok', () => {
    expect(tonDeType('demande_refusee')).toBe('alerte')
    expect(tonDeType('demande_validee')).toBe('ok')
    expect(tonDeType('compte_active')).toBe('ok')
    expect(tonDeType('tirage_effectue')).toBe('neutre')
    expect(tonDeType('evenement_invitation')).toBe('neutre')
    expect(tonDeType('evenement_annule')).toBe('alerte')
    // Un déplacement n'est pas un refus : la réponse tient, on la confirme.
    expect(tonDeType('evenement_dates_modifiees')).toBe('neutre')
    // Une réponse RGPD peut être un refus comme une application : neutre.
    expect(tonDeType('demande_rgpd')).toBe('neutre')
    expect(tonDeType('demande_rgpd_traitee')).toBe('neutre')
  })

  it('nomme les deux types des demandes RGPD', () => {
    expect(libelleDeType('demande_rgpd')).toBe('Demande RGPD')
    expect(libelleDeType('demande_rgpd_traitee')).toBe('Réponse RGPD')
  })

  it('nomme les deux natures de la complétion (ADR 0017)', () => {
    expect(libelleDeType('demande_completee')).toBe('Demande complétée')
    expect(libelleDeType('complement_fusionne')).toBe('Complément repris')
    // Une complétion n'est ni un refus ni une acceptation : neutre.
    expect(tonDeType('demande_completee')).toBe('neutre')
    // Un complément fusionné est clos « refusée » en base, mais repris : jamais en alerte.
    expect(tonDeType('complement_fusionne')).toBe('ok')
  })
})

describe('la date relative', () => {
  const maintenant = new Date(2026, 8, 30, 10, 0, 0)
  const avant = (ms: number): string => new Date(maintenant.getTime() - ms).toISOString()

  it('compte en minutes puis en heures le jour même', () => {
    expect(dateRelative(avant(20_000), maintenant)).toBe('à l’instant')
    expect(dateRelative(avant(5 * 60_000), maintenant)).toBe('il y a 5 min')
    expect(dateRelative(avant(3 * 3_600_000), maintenant)).toBe('il y a 3 h')
  })

  it('dit « hier » pour la veille, puis compte en jours', () => {
    expect(dateRelative(new Date(2026, 8, 29, 8).toISOString(), maintenant)).toBe('hier')
    expect(dateRelative(new Date(2026, 8, 26, 12).toISOString(), maintenant)).toBe('il y a 4 j')
  })

  it('donne la date au-delà d’une semaine, et l’année quand elle diffère', () => {
    expect(dateRelative(new Date(2026, 8, 12, 12).toISOString(), maintenant)).toBe('12 sept.')
    expect(dateRelative(new Date(2025, 4, 1, 12).toISOString(), maintenant)).toBe('1er mai 2025')
  })

  it('rend une chaîne vide pour une date illisible', () => {
    expect(dateRelative('pas une date', maintenant)).toBe('')
  })
})

describe('le lien', () => {
  const routeur = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'accueil', component: { render: () => null } },
      { path: '/demandes/:id', name: 'demande', component: { render: () => null } },
    ],
  })
  const notif = (linkRoute: string | null, linkParams: Notification['linkParams'] = null): Notification => ({
    id: 1,
    kind: 'demande_refusee',
    title: 'Refusée',
    body: '',
    linkRoute,
    linkParams,
    readAt: null,
    createdAt: '2026-09-30T08:00:00Z',
  })

  it('résout une route connue avec ses paramètres', () => {
    expect(lienDeNotification(routeur, notif('demande', { id: 6 }))).toEqual({ name: 'demande', params: { id: 6 } })
  })

  it('n’est pas un lien — et ne lève pas — pour une route disparue ou incomplète', () => {
    expect(lienDeNotification(routeur, notif('route_disparue'))).toBeNull()
    expect(lienDeNotification(routeur, notif('demande'))).toBeNull()
    expect(lienDeNotification(routeur, notif(null))).toBeNull()
  })
})
