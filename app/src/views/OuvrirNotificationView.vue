<script setup lang="ts">
/**
 * `/notification/:id` — l'écran de passage d'une notification **push**.
 *
 * Le lecteur a touché une notification sur son téléphone ; le service worker
 * (`public/sw.js`) a ouvert `/notification/<id>?r=<linkRoute>&p=<linkParams>`.
 * Cet écran ne s'affiche qu'un instant :
 *
 * 1. il marque la notification lue (`POST /api/notifications/{id}/lue`) — elle
 *    a été vue, la cloche ne doit plus la compter ;
 * 2. il remplace l'adresse (`replace`, pas `push` : le « retour » ne doit pas
 *    ramener sur cet écran de passage) par le lien de la notification, résolu
 *    **par nom de route** comme dans la cloche (`lienDeNotification`) ;
 * 3. un lien absent ou qui ne se résout plus — route renommée depuis, adresse
 *    bricolée — retombe sur l'accueil, sans message d'erreur.
 *
 * Le marquage n'est pas attendu plus d'une seconde et demie : un réseau lent
 * ne doit pas laisser le lecteur devant « Ouverture… ». La requête continue de
 * toute façon après la navigation.
 *
 * `acces: 'attente'` : une session tombée entre-temps passe par la connexion,
 * qui ramène ici par `?suite=` — et la notification s'ouvre quand même.
 */
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { marquerNotificationLue } from '@/lib/api'
import { rafraichirCompteur } from '@/composables/useNotifications'
import { lienDepuisRequetePush } from '@/lib/notifications'

const DELAI_MARQUAGE_MS = 1_500

const route = useRoute()
const routeur = useRouter()

async function ouvrir(): Promise<void> {
  const id = Number(route.params['id'])
  if (Number.isInteger(id) && id > 0) {
    const marquage = marquerNotificationLue(id)
      .then(() => rafraichirCompteur())
      .catch(() => undefined)
    await Promise.race([marquage, new Promise((r) => setTimeout(r, DELAI_MARQUAGE_MS))])
  }

  let cible: RouteLocationRaw | null = null
  try {
    cible = lienDepuisRequetePush(routeur, route.query['r'], route.query['p'])
  } catch {
    cible = null
  }
  await routeur.replace(cible ?? { name: 'accueil' })
}

void ouvrir()
</script>

<template>
  <p class="flex min-h-dvh items-center justify-center px-4 text-sm text-attenue" role="status">Ouverture…</p>
</template>
