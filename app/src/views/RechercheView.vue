<script lang="ts">
/** La saisie portée par l'adresse : `?q=` seul, une chaîne, jamais un tableau.
 *  Hors du `<script setup>` pour être testée seule (comme dans `QuestionsView`). */
export function saisieDeLAdresse(q: unknown): string {
  const valeur = Array.isArray(q) ? q[0] : q
  return typeof valeur === 'string' ? valeur : ''
}
</script>

<script setup lang="ts">
/**
 * `/recherche?q=…` — **tous** les résultats d'une recherche de personnes.
 *
 * L'en-tête s'arrête à huit résultats : de quoi choisir, pas de quoi parcourir.
 * « Voir tous les résultats » mène ici, où la même recherche va jusqu'au plafond
 * de l'API — **50**, liste non paginée (§ 5.5) : « une recherche qui se pagine
 * est une énumération déguisée ». Au-delà, l'écran le dit et demande de préciser,
 * il ne propose pas de page suivante.
 *
 * La mécanique est celle de l'en-tête (`useRecherchePersonnes`) : par l'API,
 * jamais par un index local — le serveur exclut les vivants hors cercle (ADR
 * 0003). Une panne s'affiche par `<MessageErreur>`, avec la conduite que rend
 * `analyserErreur` ; « Personne ne correspond » n'apparaît qu'après la réponse.
 *
 * **La saisie vit dans l'adresse** (`?q=`) : le lien se partage, et une nouvelle
 * « Voir tous les résultats » depuis l'en-tête, sur cette page même, la relance.
 */
import { watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import MessageErreur from '@/components/base/MessageErreur.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { LIMITE_MAXIMALE, LONGUEUR_MINIMALE, useRecherchePersonnes } from '@/composables/useRecherchePersonnes'
import { libelleDeBranche } from '@/histoire/lignees'

const route = useRoute()
const routeur = useRouter()

const { texte, etat, resultats, tronque, erreur, reessayer } = useRecherchePersonnes({
  limite: () => LIMITE_MAXIMALE,
  delai: 200,
})

// L'adresse vers le champ : à l'ouverture, et quand l'en-tête y renvoie avec une autre saisie.
watch(
  () => route.query['q'],
  (q) => {
    const saisie = saisieDeLAdresse(q)
    if (saisie.trim() !== texte.value.trim()) texte.value = saisie
  },
  { immediate: true },
)

// Le champ vers l'adresse, sans entrée d'historique à chaque frappe.
watch(texte, (q) => {
  const saisie = q.trim()
  if (saisie === saisieDeLAdresse(route.query['q']).trim()) return
  void routeur.replace({ query: saisie === '' ? {} : { q: saisie } })
})
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-texte">Rechercher une personne</h1>
      <p class="mt-1 text-sm text-attenue">
        Jusqu’à {{ LIMITE_MAXIMALE }} personnes, par nom ou prénom, dans l’arbre tel que vous le voyez.
      </p>
    </header>

    <label class="mb-4 flex min-w-0 flex-col gap-1">
      <span class="lecteur-ecran">Rechercher une personne</span>
      <input
        v-model="texte"
        type="search"
        enterkeyhint="search"
        autocomplete="off"
        aria-controls="resultats-recherche"
        class="w-full rounded-champ border border-ligne bg-fond px-3 py-2 text-[15px] text-texte outline-none focus:border-accent"
        placeholder="Nom, prénom…"
      />
    </label>

    <section id="resultats-recherche" aria-live="polite" :aria-busy="etat === 'chargement'">
      <p v-if="etat === 'inactif'" class="m-0 text-sm text-attenue" data-inactif>
        Tapez au moins {{ LONGUEUR_MINIMALE }} caractères.
      </p>
      <div v-else-if="etat === 'chargement' && resultats.length === 0" data-chargement>
        <p class="lecteur-ecran">Recherche en cours…</p>
        <SqueletteChargement :lignes="4" />
      </div>
      <MessageErreur v-else-if="etat === 'erreur'" :erreur="erreur" titre="La recherche a échoué" @action="reessayer" />
      <p v-else-if="etat === 'vide'" class="m-0 text-sm text-attenue" data-vide>
        Personne ne correspond à « {{ texte.trim() }} ».
      </p>
      <template v-else>
        <p class="mt-0 mb-2 text-xs text-attenue" data-compte>
          {{ resultats.length }} personne{{ resultats.length > 1 ? 's' : '' }}<template v-if="tronque">
            — les {{ LIMITE_MAXIMALE }} premières : précisez la recherche pour voir les autres</template>
        </p>
        <ul class="m-0 flex list-none flex-col divide-y divide-ligne rounded-panneau border border-ligne bg-panneau p-0">
          <li v-for="r in resultats" :key="r.personId" class="flex items-center gap-3 px-3.5 py-2.5" data-resultat>
            <div class="flex min-w-0 flex-1 flex-col leading-snug">
              <RouterLink
                :to="{ name: 'personne.detail', params: { id: r.personId } }"
                class="truncate text-[15px] font-semibold text-texte hover:underline"
              >{{ r.displayName }}</RouterLink>
              <span class="text-xs text-attenue">{{ libelleDeBranche(r.branch) }}<template v-if="r.ligneDates"> · <span class="font-mono">{{ r.ligneDates }}</span></template></span>
            </div>
            <RouterLink
              :to="{ name: 'arbre', query: { personne: r.personId } }"
              class="flex-none text-sm text-accent hover:underline"
            >Voir dans l’arbre</RouterLink>
          </li>
        </ul>
      </template>
    </section>
  </div>
</template>
