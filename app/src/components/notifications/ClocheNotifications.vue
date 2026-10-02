<script setup lang="ts">
/**
 * **La cloche** — ce qui arrive au lecteur, sans qu'il ait à le chercher.
 *
 * Elle existe pour une phrase du commanditaire : « notif de refus si l'admin
 * refuse avec le msg ». Le serveur produisait ces notifications depuis le
 * début ; aucun écran ne les montrait. `Mes demandes` a donné le chemin, la
 * cloche donne l'**alerte** : on n'a plus à penser à aller voir.
 *
 * Sans prop : l'en-tête la monte telle quelle. Elle ne rend **rien** pour un
 * visiteur anonyme — `entete-rendu.test.ts` exige qu'un en-tête anonyme
 * n'ait aucun `<button>`, et un anonyme n'a de toute façon pas de notification.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Un seul bloc, deux formes** (même parti que `BarreEcran`) :
 * - **téléphone** : une feuille **plein écran** qui monte du bas, avec son
 *   bouton « Fermer ». Plein écran et non un feuillet à mi-hauteur : la liste
 *   est la chose qu'on est venu lire, et la barre du bas (z-30) ne doit pas
 *   rester par-dessus ;
 * - **bureau** : un menu déroulant de 380 px ancré sous la cloche.
 * Les déclarations mobiles s'écrivent `max-md:`, les autres `md:` : aucune ne
 * peut fuir de l'autre côté du seuil.
 *
 * **Fermeture** : Échap (écouté en capture, arrêté là — l'écran derrière ne
 * l'entend pas), clic extérieur, clic sur une notification, « Fermer ». Le
 * focus va au titre à l'ouverture et **revient à la cloche** à la fermeture —
 * sauf quand on part vers la page d'une notification, où il suit la page.
 * **Toute navigation** la referme aussi : le « retour » d'Android, le
 * « précédent » du navigateur ou un lien pris au clavier changent la page
 * derrière, et la feuille plein écran resterait sinon posée sur le nouvel
 * écran. `router.afterEach` ne referme que les tiroirs de `useTiroirs` : la
 * cloche s'y abonne elle-même, au montage, et se désabonne au démontage.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { Notification } from '@/lib/api'
import { useSession } from '@/composables/useSession'
import { useNotifications } from '@/composables/useNotifications'
import { useEstMobile } from '@/composables/useTiroirs'
import { etiquetteCloche, texteCompteur } from '@/lib/notifications'
import ListeNotifications from './ListeNotifications.vue'
import NotificationsTelephone from './NotificationsTelephone.vue'

const { connecte } = useSession()
const { nonLues, notifications, etatListe, charger, chargerLaSuite, resteAvant, chargementSuite, marquerLue, toutMarquerLu } =
  useNotifications()
const estMobile = useEstMobile()
const routeur = useRouter()

const ouvert = ref(false)
const zone = ref<HTMLElement | null>(null)
const bouton = ref<HTMLButtonElement | null>(null)
const titre = ref<HTMLElement | null>(null)
const echecToutLu = ref(false)

const pastille = computed(() => texteCompteur(nonLues.value))
const etiquette = computed(() => etiquetteCloche(nonLues.value))
const aDesNonLues = computed(() => notifications.value.some((n) => n.readAt === null))

/** L'heure de référence des dates relatives : figée à l'ouverture, pour
 *  qu'une liste ouverte ne se réécrive pas sous les yeux. */
const maintenant = ref(new Date())

async function ouvrir(): Promise<void> {
  ouvert.value = true
  echecToutLu.value = false
  maintenant.value = new Date()
  await nextTick()
  titre.value?.focus()
  await charger()
}

function fermer(rendreLeFocus = true): void {
  if (!ouvert.value) return
  ouvert.value = false
  if (rendreLeFocus) bouton.value?.focus()
}

function basculer(): void {
  if (ouvert.value) fermer()
  else void ouvrir()
}

/** Clic sur une notification : marquée lue. Si elle mène quelque part, la
 *  cloche se ferme et `RouterLink` navigue lui-même — le marquage n'attend pas
 *  le serveur. Sans lien, on reste dans la liste. */
function surChoix(n: Notification, navigue: boolean): void {
  void marquerLue(n)
  if (navigue) fermer(false)
}

async function surToutLu(): Promise<void> {
  echecToutLu.value = !(await toutMarquerLu())
}

function surEchap(e: KeyboardEvent): void {
  if (e.key !== 'Escape' || !ouvert.value) return
  e.stopImmediatePropagation()
  fermer()
}

function surClicExterieur(e: MouseEvent): void {
  // Sur téléphone, la feuille couvre tout l'écran : il n'y a pas d'extérieur.
  if (!ouvert.value || estMobile.value) return
  if (zone.value?.contains(e.target as Node)) return
  fermer(false)
}

/** Désabonnement de `afterEach`, rendu par le routeur à l'abonnement. */
let oublierNavigation: (() => void) | null = null

onMounted(() => {
  document.addEventListener('click', surClicExterieur, true)
  window.addEventListener('keydown', surEchap, true)
  // Le focus n'est pas rendu à la cloche : il suit la nouvelle page.
  oublierNavigation = routeur.afterEach(() => fermer(false))
})
onBeforeUnmount(() => {
  oublierNavigation?.()
  oublierNavigation = null
  document.removeEventListener('click', surClicExterieur, true)
  window.removeEventListener('keydown', surEchap, true)
})

</script>

<template>
  <div v-if="connecte" ref="zone" class="relative flex-none">
    <button
      ref="bouton"
      type="button"
      class="relative flex h-9 w-9 items-center justify-center rounded-champ text-texte hover:bg-accent-doux max-md:h-11 max-md:w-11"
      :class="ouvert ? 'bg-accent-doux' : ''"
      :aria-label="etiquette"
      :aria-expanded="ouvert"
      aria-controls="panneau-notifications"
      aria-haspopup="dialog"
      @click="basculer"
    >
      <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16Z"
          fill="none"
          stroke="currentColor"
          stroke-width="1.7"
          stroke-linejoin="round"
        />
        <path d="M10 20.5a2.2 2.2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
      </svg>
      <span
        v-if="pastille"
        data-pastille
        class="absolute -top-0.5 -right-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-pastille border-2 border-panneau bg-accent px-1 text-[10.5px] leading-none font-bold text-fond tnum"
        aria-hidden="true"
        >{{ pastille }}</span
      >
    </button>

    <Transition
      enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
      leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
      enter-from-class="max-md:translate-y-full md:-translate-y-1 md:opacity-0"
      leave-to-class="max-md:translate-y-full md:-translate-y-1 md:opacity-0"
    >
      <div
        v-if="ouvert"
        id="panneau-notifications"
        role="dialog"
        aria-labelledby="titre-notifications"
        :aria-modal="estMobile ? 'true' : undefined"
        class="flex flex-col bg-panneau max-md:fixed max-md:inset-0 max-md:z-50 max-md:pt-[env(safe-area-inset-top)] md:absolute md:top-[calc(100%+8px)] md:right-0 md:z-40 md:max-h-[min(560px,calc(100vh-80px))] md:w-[380px] md:rounded-panneau md:border md:border-ligne md:shadow-panneau"
      >
        <div class="flex flex-none items-center gap-2 border-b border-ligne px-4 max-md:h-14 md:h-12">
          <h2 id="titre-notifications" ref="titre" tabindex="-1" class="flex-1 text-[17px] font-semibold outline-none md:text-[15px]">
            Notifications
          </h2>
          <button
            v-if="aDesNonLues"
            type="button"
            class="h-9 text-[13px] font-semibold text-accent"
            @click="surToutLu"
          >
            Tout marquer comme lu
          </button>
          <button
            type="button"
            class="flex h-9 w-9 items-center justify-center rounded-champ text-attenue hover:bg-accent-doux md:hidden"
            aria-label="Fermer les notifications"
            @click="fermer()"
          >
            <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true">
              <path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
            </svg>
          </button>
        </div>

        <p v-if="echecToutLu" role="alert" class="flex-none px-4 pt-3 text-[13px] text-alerte">
          Impossible de tout marquer comme lu pour l’instant. Réessayez dans un moment.
        </p>

        <!-- « Recevoir aussi sur ce téléphone » : ne se montre que si un clic
             suffit à activer le push (voir `NotificationsTelephone`). -->
        <NotificationsTelephone forme="lien" />

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain max-md:pb-[max(16px,env(safe-area-inset-bottom))]">
          <ListeNotifications
            :notifications="notifications"
            :etat="etatListe"
            :maintenant="maintenant"
            :reste-avant="resteAvant"
            :chargement-suite="chargementSuite"
            @choisir="surChoix"
            @reessayer="charger()"
            @suite="chargerLaSuite()"
          />
        </div>
      </div>
    </Transition>
  </div>
</template>
