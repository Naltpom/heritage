<script setup lang="ts">
/**
 * Les actes demandés par la famille — à une mairie, à des archives — et où ils
 * en sont.
 *
 * Par défaut, **ce qui attend encore la pièce** — envoyées, relancées,
 * expédiées par courrier : c'est la question qu'on vient poser ici (« a-t-on
 * des retours en attente ? »), et celle qui dit quoi relancer. Chaque statut se
 * coche à part, avec son compteur ; « Toutes » montre l'historique complet.
 *
 * **Le filtre vit dans l'adresse** (`?statuts=…`, voir `lireStatuts`) : un
 * lien partagé — « les refusées, à refaire » — montre la même chose à qui
 * l'ouvre, et le bouton « précédent » revient au filtre d'avant.
 *
 * La recherche sert le geste le plus courant : **une réponse arrive, on
 * retrouve la démarche** — par un nom, une commune, ou l'adresse de la mairie
 * qui écrit. Elle s'applique par-dessus le filtre. Avant d'écrire à une
 * mairie, c'est aussi ici qu'on vérifie que personne ne l'a déjà fait.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { StatutDeDemarche } from '@/lib/api'
import FiltreStatuts from '@/components/demarches/FiltreStatuts.vue'
import ListeDemarches from '@/components/demarches/ListeDemarches.vue'
import { ecrireStatuts, lireStatuts, type ComptesDeDemarches } from '@/components/demarches/demarches'

const route = useRoute()
const router = useRouter()

const statuts = computed(() => lireStatuts(route.query.statuts))
const recherche = ref('')
const comptes = ref<ComptesDeDemarches | null>(null)

function choisir(selection: StatutDeDemarche[]): void {
  const query = { ...route.query }
  const valeur = ecrireStatuts(selection)
  if (valeur === undefined) delete query.statuts
  else query.statuts = valeur
  void router.replace({ query })
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <header class="mb-5">
      <h1 class="text-xl font-semibold text-texte">Actes demandés</h1>
      <p class="mt-1 text-sm text-attenue">
        Les pièces demandées à une mairie ou à des archives, avec le texte de chaque message. Une réponse arrive :
        retrouvez la démarche par un nom, une commune ou l’adresse de la mairie. Avant d’écrire à une mairie, vérifiez
        ici que personne ne l’a déjà fait.
      </p>
    </header>

    <div class="mb-4 flex flex-col gap-3">
      <FiltreStatuts :selection="statuts" :comptes="comptes" @update:selection="choisir" />

      <label class="flex min-w-0 flex-col gap-1">
        <span class="lecteur-ecran">Rechercher une démarche</span>
        <input
          v-model="recherche"
          type="search"
          class="w-full rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
          placeholder="Rechercher : Provost, Fleurance, mairie@…, mariage…"
        />
      </label>
    </div>

    <ListeDemarches :statuts="statuts" :recherche="recherche" @comptes="comptes = $event" />
  </div>
</template>
