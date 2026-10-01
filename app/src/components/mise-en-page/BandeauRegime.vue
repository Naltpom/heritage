<script setup lang="ts">
/**
 * Les deux bandeaux permanents du cadre. Aucun n'est décoratif : chacun évite
 * qu'un lecteur conclue à une panne devant un arbre incomplet.
 *
 * **1. « Voir comme un membre » (§ 10.4).** Le contrat l'exige en toutes
 * lettres : « Le front affiche un bandeau permanent : sans lui, un
 * administrateur conclura à un bug en ne voyant plus la moitié des fiches. »
 * Tant que la bascule est active, **toute route d'écriture rend
 * `409 vue_comme_membre_active`** — le front désactive ses boutons, et l'API ne
 * compte pas dessus.
 *
 * **2. Le régime « en attente de validation » (§ 12).** Le compte ne voit
 * **rien de l'arbre**, ni vivants ni défunts (ADR 0003, révision du
 * 01/10/2026) : ses seuls écrans sont son inscription, son compte et Moi. Le
 * bandeau dit pourquoi l'application lui paraît si vide, et quand cela
 * changera — sans quoi il conclurait à un compte cassé.
 */
import { ref, watch } from 'vue'
import { useSession } from '@/composables/useSession'

const { regime, vueEmpruntee, incarnation, nomAffiche } = useSession()

/**
 * Le bandeau d'incarnation se **réduit** (croix) mais ne disparaît jamais :
 * réduit, il garde « En tant que … » et le bouton pour quitter, sur une ligne.
 * Le choix tient le temps de l'onglet, et se réinitialise à chaque nouvelle
 * incarnation — on ne commence pas à agir au nom de quelqu'un sans le lire.
 */
const CLE = 'heritage.bandeau-incarnation-reduit'
function lireReduit(): boolean {
  try {
    return sessionStorage.getItem(CLE) === '1'
  } catch {
    return false
  }
}
const reduit = ref(lireReduit())
function reduire(valeur: boolean): void {
  reduit.value = valeur
  try {
    if (valeur) sessionStorage.setItem(CLE, '1')
    else sessionStorage.removeItem(CLE)
  } catch {
    /* stockage indisponible : le choix vaut pour la page */
  }
}
watch(
  () => incarnation.value?.par.id ?? null,
  (avant, apres) => {
    if (avant !== apres && apres === null) reduire(false)
  },
)
</script>

<template>
  <div>
    <!-- L'incarnation passe avant tout : l'administrateur **agit** au nom de
         quelqu'un, et chaque geste sera enregistré sous ce nom (ADR 0010). -->
    <div
      v-if="incarnation !== null"
      class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-alerte-bord bg-alerte-doux px-4 text-sm text-alerte"
      :class="reduit ? 'py-1' : 'py-2'"
      role="status"
    >
      <template v-if="reduit">
        <strong class="font-semibold">En tant que {{ nomAffiche }}</strong>
        <slot name="quitter-incarnation" />
        <button
          type="button"
          class="ml-auto rounded-champ px-1.5 py-0.5 text-xs font-semibold underline hover:bg-alerte-bord/30"
          aria-label="Déplier le bandeau"
          title="Déplier le bandeau"
          @click="reduire(false)"
        >
          Détails
        </button>
      </template>
      <template v-else>
        <strong class="font-semibold">Connecté en tant que {{ nomAffiche }}</strong>
        <span>
          Chaque action est enregistrée à votre nom, {{ incarnation.par.displayName }}.
        </span>
        <slot name="quitter-incarnation" />
        <button
          type="button"
          class="ml-auto inline-flex size-7 items-center justify-center rounded-champ hover:bg-alerte-bord/30"
          aria-label="Réduire le bandeau"
          title="Réduire le bandeau"
          @click="reduire(true)"
        >
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </template>
    </div>

    <div
      v-else-if="vueEmpruntee !== null"
      class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-accent bg-accent-doux px-4 py-2 text-sm text-accent"
      role="status"
    >
      <strong class="font-semibold">Vue empruntée</strong>
      <span>
        Vous voyez l’arbre comme
        <span class="font-semibold">{{ vueEmpruntee.memberLabel }}</span
        >. Aucune modification possible pendant ce temps.
      </span>
      <slot name="quitter-vue" />
    </div>

    <div
      v-else-if="regime === 'en_attente'"
      class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-ligne bg-panneau px-4 py-2 text-sm"
      role="status"
    >
      <strong class="font-semibold">Inscription en cours de vérification</strong>
      <span class="text-attenue">
        L’arbre vous sera ouvert dès qu’elle sera validée.
      </span>
      <RouterLink :to="{ name: 'inscription.etat' }" class="font-semibold text-accent underline">
        Voir l'état de mon inscription
      </RouterLink>
    </div>
  </div>
</template>
