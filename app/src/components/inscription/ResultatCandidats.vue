<script setup lang="ts">
/**
 * Le résultat d'une recherche de rattachement — **et le point le plus délicat
 * du produit**.
 *
 * Ce composant a une seule entrée : la réponse du serveur. Il n'en a pas
 * d'autre, et c'est le fond du sujet.
 *
 * **Le serveur ment délibérément, et cet écran le respecte.** Au-delà de cinq
 * tentatives par heure sur un même jeton, le serveur ne rend pas une erreur de
 * débit : il rend `200 { "candidats": [] }`, exactement la réponse d'une
 * recherche infructueuse — sans `Retry-After`, sans en-tête de quota,
 * `tentativesRestantes` à `null` pour tout le monde (§ 4.2 ; exception nommée au
 * § 15.3, la seule de tout le projet à la convention d'erreur).
 *
 * La raison tient en une phrase : annoncer « trop de tentatives » révélerait à
 * qui cherche que la limite existe, donc qu'il y a quelque chose à trouver. Le
 * lien d'invitation deviendrait un moyen d'énumérer les personnes vivantes de
 * l'arbre — « le principal risque du produit » (§ 6.1 du dossier).
 *
 * **Trois causes, un seul écran** : recherche infructueuse, plafond horaire
 * atteint, date de naissance non confirmée en mode confirmation. Ce composant
 * n'a aucun moyen de les distinguer, ne doit pas essayer, et ne doit pas non
 * plus les laisser transparaître par une nuance de formulation. Toute tentative
 * de deviner — compter les essais côté client, chronométrer la réponse —
 * rouvrirait la fuite que le serveur vient de fermer. `ResultatCandidats.test.ts`
 * vérifie que les trois cas rendent la **même chaîne d'HTML**, au caractère près.
 *
 * Sur les candidats eux-mêmes, deux règles :
 *
 * - **`libelle` s'affiche tel quel.** « P. Romarin, 1980 » : initiale du premier
 *   prénom, nom, année. La forme est fixée au caractère près côté serveur pour
 *   qu'aucune implémentation ne « complète utilement » ; on ne la recompose ni
 *   ne l'enrichit ici.
 * - **`ref` est opaque.** Ce n'est pas un identifiant de personne, il ne le
 *   deviendra pas : il sert de valeur de formulaire, ne s'affiche pas, ne part
 *   pas dans une URL, ne se journalise pas.
 *
 * Sur l'écran vide, enfin : il porte **trois pistes concrètes** — l'orthographe
 * du nom, la date, et la personne qui a envoyé l'invitation. Un écran vide sans
 * recours est ce qui pousse à l'abandon, et aucune des trois ne laisse entendre
 * qu'il faudrait simplement attendre — ce qui serait la formulation qui trahit
 * le plafond.
 *
 * Dernier détail, appris à la dure : **les commentaires de `<template>` partent
 * dans l'HTML rendu.** Le raisonnement sur le plafond vit donc ici, dans le
 * `<script>`, et pas au milieu du balisage où il s'afficherait dans la source de
 * la page — en expliquant à qui la lit la mécanique exacte qu'on cherche à taire.
 */
import { computed, useId } from 'vue'
import type { ResultatRattachement } from '@/lib/api'
import PanneauBase from '@/components/base/PanneauBase.vue'

const { resultat } = defineProps<{
  /** `null` tant qu'aucune recherche n'a été faite — pas la même chose qu'une
   *  recherche sans résultat, qui, elle, a quelque chose à dire. */
  resultat: ResultatRattachement | null
}>()

/** Le `ref` retenu. `defineModel` pour que la vue garde la main sur le choix. */
const refChoisi = defineModel<string | null>('refChoisi', { default: null })

const groupe = useId()
const aCherche = computed(() => resultat !== null)
const candidats = computed(() => resultat?.candidats ?? [])
const aucun = computed(() => aCherche.value && candidats.value.length === 0)
</script>

<template>
  <PanneauBase v-if="aCherche">
    <!-- ------------------------------------------------------------------ -->
    <!-- Aucun candidat. Un seul écran pour les trois causes.                -->
    <!-- ------------------------------------------------------------------ -->
    <div v-if="aucun" role="status">
      <h2 class="text-base font-semibold">Aucune correspondance trouvée</h2>


      <!-- Les trois pistes : voir le commentaire de tête. -->
      <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-attenue">
        <li>Vérifiez l’orthographe du nom de naissance et des prénoms.</li>
        <li>Vérifiez la date de naissance.</li>
        <li>Sinon, demandez à la personne qui vous a envoyé l’invitation sous quel nom vous figurez.</li>
      </ul>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Au plus trois candidats, en affichage minimal.                      -->
    <!-- ------------------------------------------------------------------ -->
    <fieldset v-else>
      <legend class="text-base font-semibold">
        {{ candidats.length === 1 ? 'Est-ce bien vous ?' : 'Laquelle de ces personnes êtes-vous ?' }}
      </legend>

      <p class="mt-2 text-sm text-attenue">
        Le détail s’affichera une fois votre inscription validée.
      </p>

      <div class="mt-3 flex flex-col gap-2">
        <label
          v-for="candidat in candidats"
          :key="candidat.ref"
          class="flex cursor-pointer items-center gap-2.5 rounded-champ border px-3 py-2 text-sm transition-colors"
          :class="
            refChoisi === candidat.ref
              ? 'border-accent bg-accent-doux'
              : 'border-ligne hover:border-accent'
          "
        >
          <input
            v-model="refChoisi"
            type="radio"
            :name="groupe"
            :value="candidat.ref"
            class="accent-accent"
          />
          <!-- Le libellé du serveur, tel quel. -->
          <span class="font-medium">{{ candidat.libelle }}</span>
        </label>
      </div>
    </fieldset>
  </PanneauBase>
</template>
