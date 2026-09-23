<script setup lang="ts">
/**
 * Une ligne de la fiche — **et le seul endroit où le calque se dessine.**
 *
 * La règle du § 13.2 tient en une phrase, et ce composant est sa mise en
 * œuvre : *si `enAttente[champ]` existe, afficher `propose` avec la pastille
 * « en attente de validation », et rendre `actuel` accessible ; sinon, afficher
 * `fiche[champ]`.*
 *
 * Trois choses cohabitent donc dans une ligne en attente, et elles ne se
 * remplacent pas :
 *
 * | Ce qu'on voit | D'où ça vient | Pourquoi c'est là |
 * |---|---|---|
 * | la valeur **validée**, en premier, à sa place | `fiche[champ]` | elle reste dans le corps principal : le calque est à côté, jamais à la place |
 * | **à côté**, le calque : pastille, valeur **proposée**, auteur, date, n° de demande | `enAttente[champ]` | c'est ce que le demandeur veut voir — « changement visible pour le demandeur avant la vraie validation » |
 * | la valeur **au dépôt** | `enAttente[champ].actuel` | elle peut différer de la validée, et c'est ce qui annonce une caducité (§ 6.8) |
 *
 * Le calque est un bloc `border-accent bg-accent-doux rounded-champ` posé sous
 * la valeur validée, dans la même cellule : la ligne se lit d'abord comme
 * l'arbre validé, puis comme ce qui attend une décision.
 *
 * Le repli `<details>` existe pour la troisième : le contrat demande que
 * `actuel` soit *accessible* — « au survol, dans un repli, comme on veut ». Il
 * l'est donc toujours, y compris quand il coïncide avec la valeur validée ; et
 * quand il en diffère, l'écart remonte au-dessus du repli, parce qu'il annonce
 * quelque chose que le lecteur doit savoir sans avoir à cliquer.
 *
 * **Une valeur vide s'écrit avec un mot, jamais avec un tiret.** Un « — » se lit
 * comme « rien à cet endroit », ce qui est justement ce qu'on doit réserver à
 * l'absence de ligne — laquelle, elle, ne dessine rien du tout (§ 1.3).
 */
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import { horodatageLisible, type LigneDeFiche, type ValeurAffichee } from './champs'

const { ligne } = defineProps<{ ligne: LigneDeFiche }>()

// Le composant rend deux racines (`<dt>` et `<dd>`) pour s'insérer dans la
// grille du `<dl>` parent : sans cela, un conteneur intermédiaire casserait
// l'alignement des deux colonnes.
defineOptions({ inheritAttrs: false })

function classeValeur(valeur: ValeurAffichee): string {
  return valeur.vide ? 'text-attenue italic' : ''
}
</script>

<template>
  <dt class="text-attenue">{{ ligne.etiquette }}</dt>

  <!-- ------------------------------------------------------------ -->
  <!-- Aucune demande sur ce champ : la valeur validée, point.        -->
  <!-- ------------------------------------------------------------ -->
  <dd v-if="ligne.etat === 'validee'" class="m-0 min-w-0">
    <span :class="[classeValeur(ligne.valeur), ligne.chasseFixe ? 'font-mono tnum' : '']">
      {{ ligne.valeur.principal }}
    </span>
    <span v-if="ligne.valeur.secondaire" class="block text-xs text-attenue">
      {{ ligne.valeur.secondaire }}
    </span>
  </dd>

  <!-- ------------------------------------------------------------ -->
  <!-- La valeur validée d'abord, le calque à côté.                   -->
  <!-- ------------------------------------------------------------ -->
  <dd v-else class="m-0 flex min-w-0 flex-col gap-2">
    <!-- La valeur validée **reste dans le corps**, à sa place : un lecteur qui
         ignore le calque doit voir l'arbre validé, pas une proposition
         présentée comme acquise (§ 13.2, conséquence n° 1). -->
    <span data-valeur="validee">
      <span :class="[classeValeur(ligne.validee), ligne.chasseFixe ? 'font-mono tnum' : '']">
        {{ ligne.validee.principal }}
      </span>
      <span v-if="ligne.validee.secondaire" class="block text-xs text-attenue">
        {{ ligne.validee.secondaire }}
      </span>
    </span>

    <div
      data-calque
      class="flex flex-col items-start gap-0.5 rounded-champ border border-accent bg-accent-doux px-3 py-2 text-[13px]"
    >
      <PastilleEtat etat="en_attente" />
      <span :class="[classeValeur(ligne.propose), ligne.chasseFixe ? 'font-mono tnum' : '', 'font-semibold']">
        {{ ligne.propose.principal }}
      </span>
      <span v-if="ligne.propose.secondaire" class="text-xs text-attenue">
        {{ ligne.propose.secondaire }}
      </span>
      <span class="text-xs text-attenue">
        Proposé par {{ ligne.entree.auteur }}<template v-if="ligne.entree.depuis">
          le {{ horodatageLisible(ligne.entree.depuis) }}</template
        >
        ·
        <RouterLink
          :to="{ name: 'demande.detail', params: { id: ligne.entree.demandeId } }"
          class="text-accent underline"
        >
          Demande n° {{ ligne.entree.demandeId }}
        </RouterLink>
      </span>

      <!-- `actuel` ≠ `fiche[champ]` : la fiche a bougé depuis le dépôt. On le
           dit ici, pas dans le repli — c'est une nouvelle, pas un détail. -->
      <span
        v-if="ligne.caduciteAnnoncee"
        class="mt-1.5 block rounded-champ border border-alerte-bord bg-alerte-doux px-2 py-1 text-xs"
        role="status"
      >
        La fiche a changé depuis le dépôt de cette demande : elle portait alors
        «&nbsp;{{ ligne.actuel.principal }}&nbsp;». La demande deviendra caduque si elle n’est pas
        reprise.
      </span>

      <details class="mt-1 text-xs text-attenue">
        <summary class="cursor-pointer select-none">Détail de la proposition</summary>
        <!-- `actuel` est la valeur **au moment de la soumission**. Rendue
             accessible sans condition : c'est ce que le § 13.2 demande. -->
        <span class="mt-1 block">
          Valeur au dépôt de la demande : «&nbsp;{{ ligne.actuel.principal }}&nbsp;».
        </span>
        <span v-if="ligne.entree.autresDemandes.length > 0" class="mt-1 block">
          {{ ligne.entree.autresDemandes.length }} autre<template
            v-if="ligne.entree.autresDemandes.length > 1"
            >s</template
          >
          demande<template v-if="ligne.entree.autresDemandes.length > 1">s</template> en attente
          sur ce champ :
          <template v-for="(autre, index) in ligne.entree.autresDemandes" :key="autre">
            <template v-if="index > 0">, </template>
            <RouterLink
              :to="{ name: 'demande.detail', params: { id: autre } }"
              class="text-accent underline"
            >
              n° {{ autre }}
            </RouterLink>
          </template>
        </span>
      </details>
    </div>
  </dd>
</template>
