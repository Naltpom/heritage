<script setup lang="ts">
/**
 * « Partager » : envoyer le lien de cette fiche à un proche.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## Ce qui part, et ce qui ne part pas
 *
 * **L'adresse canonique de la fiche, et rien d'autre** : la route
 * `personne.detail` résolue par le routeur, posée sur l'origine
 * (`adresseAbsolue`, `composables/partage.ts`) — `https://…/personnes/17`. Ni
 * l'onglet ouvert, ni une ancre, ni une donnée de la fiche dans l'URL. Le lien
 * n'ouvre rien par lui-même : qui le reçoit doit être connecté, et ne voit que
 * ce que **son** cercle de visibilité lui ouvre — une personne vivante hors de
 * son cercle lui donnera la fiche réduite, comme dans l'arbre.
 *
 * Le titre de la feuille de partage porte le nom affiché, que l'expéditeur a
 * sous les yeux ; c'est lui qui choisit à qui il l'envoie.
 *
 * ## Les trois issues (`partagerOuCopier`)
 *
 * - **Téléphone** : la feuille de partage du système (SMS, WhatsApp,
 *   courriel…). Elle est sa propre confirmation : on n'ajoute rien. Fermée sans
 *   rien choisir (`AbortError`) : on ne dit rien non plus.
 * - **Ordinateur**, ou feuille refusée : le lien est copié, et « Lien copié »
 *   s'affiche sous le bouton quelques secondes.
 * - **Copie refusée** (contexte non sécurisé, permission) : on le dit, et
 *   l'adresse s'affiche, sélectionnable, jusqu'à ce qu'on ferme.
 *
 * La région `role="status"` est **toujours** dans le document, vide au repos :
 * une région qui apparaît avec son texte n'est pas annoncée partout.
 *
 * ## Où il se pose
 *
 * `PageFiche` le place dans le bandeau, à côté des icônes — sur ordinateur
 * **et** sur téléphone, où il est le seul geste du bandeau (les autres sont
 * dans la barre du bas) et se réduit à son icône. Il est offert à tout lecteur
 * de la fiche complète, compte en attente compris : partager ne modifie rien.
 * Il se retire en mode édition, comme les onglets.
 * ────────────────────────────────────────────────────────────────────
 */
import { onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { FichePersonneComplete } from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import { adresseAbsolue, partagerOuCopier } from '@/composables/partage'

const { fiche } = defineProps<{ fiche: FichePersonneComplete }>()

/** Combien de temps « Lien copié » reste affiché. */
const DUREE_CONFIRMATION_MS = 4000

// Le routeur n'est lu qu'au clic : le rendu serveur des tests n'en a pas.
const routeur = useRouter()

const etat = ref<'repos' | 'en_cours' | 'copie' | 'echec'>('repos')
const adresse = ref('')
let minuterie: ReturnType<typeof setTimeout> | undefined

async function surClic(): Promise<void> {
  if (etat.value === 'en_cours') return
  clearTimeout(minuterie)
  adresse.value = adresseAbsolue(routeur, { name: 'personne.detail', params: { id: fiche.id } })
  etat.value = 'en_cours'

  const issue = await partagerOuCopier({ titre: `${fiche.displayName} — Heritage`, url: adresse.value })

  if (issue === 'copie') {
    etat.value = 'copie'
    minuterie = setTimeout(() => {
      etat.value = 'repos'
    }, DUREE_CONFIRMATION_MS)
  } else {
    etat.value = issue === 'echec' ? 'echec' : 'repos'
  }
}

onBeforeUnmount(() => clearTimeout(minuterie))
</script>

<template>
  <div class="relative flex-none" data-partage>
    <BoutonBase
      variante="secondaire"
      class="h-9 max-md:h-11 max-md:w-11 max-md:px-0"
      title="Partager le lien de cette fiche"
      :desactive="etat === 'en_cours'"
      @click="surClic"
    >
      <svg viewBox="0 0 24 24" class="size-4 flex-none" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 15V3M8 7l4-4 4 4" />
        <path d="M8 11H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-2" />
      </svg>
      <span class="max-md:sr-only">Partager</span>
    </BoutonBase>

    <div class="absolute top-full right-0 z-10 mt-1.5" role="status" aria-live="polite">
      <span
        v-if="etat === 'copie'"
        class="block rounded-pastille border border-ok-bord bg-ok-doux px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap text-ok shadow-panneau"
        data-partage-copie
      >
        Lien copié<span class="sr-only"> dans le presse-papiers</span>
      </span>
      <div
        v-else-if="etat === 'echec'"
        class="flex w-[min(20rem,calc(100vw-2.5rem))] flex-col gap-2 rounded-panneau border border-alerte-bord bg-panneau p-3 text-xs shadow-panneau"
        data-partage-echec
      >
        <p class="m-0 font-semibold text-alerte">Le lien n’a pas pu être copié. Le voici :</p>
        <p class="m-0 rounded-champ border border-ligne bg-fond px-2 py-1.5 font-mono break-all select-all">{{ adresse }}</p>
        <button type="button" class="self-end text-attenue underline hover:text-texte" @click="etat = 'repos'">Fermer</button>
      </div>
    </div>
  </div>
</template>
