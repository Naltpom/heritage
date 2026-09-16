<script setup lang="ts">
/**
 * **Les deux `409`, rendues visiblement différentes** — § 6.8, § 15.4.
 *
 * Elles portent le même code HTTP et la même enveloppe. Les afficher pareil
 * serait une faute de données, pas d'interface : `verrou_concurrent` n'a rien
 * écrit et se rejoue ; `demande_caduque` **a écrit** — la demande est passée
 * `caduque` côté serveur — et la rejouer écrirait deux fois.
 *
 * D'où deux blocs qui ne se ressemblent pas :
 *
 * | | Rejouable | Caduque |
 * |---|---|---|
 * | Ton | neutre : il ne s'est rien passé | alerte : quelque chose a changé |
 * | Contenu | une phrase | **ce qui a bougé, champ par champ, en trois colonnes** |
 * | Bouton | « Réessayer » | « Refaire cette demande » — **jamais « Réessayer »** |
 *
 * Les trois valeurs par champ ne sont pas un luxe : sans la valeur au moment du
 * dépôt, l'auteur voit deux valeurs qui diffèrent et croit à un refus. Avec
 * elle, il voit *ce que quelqu'un d'autre a changé pendant que sa demande
 * attendait*, et le bouton « refaire » prend son sens.
 *
 * `caduque` n'est pas `refusee` : personne n'a refusé, le monde a bougé
 * (ADR 0004). Le vocabulaire de ce panneau ne parle donc jamais de refus.
 */
import BoutonBase from '@/components/base/BoutonBase.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import { valeurLisible, type ConflitDeDemande } from './conflit'

defineProps<{ conflit: ConflitDeDemande }>()

const emit = defineEmits<{
  /** Rien n'a été écrit : le même geste peut être refait à l'identique. */
  reessayer: []
  /** La demande est morte : on en repart, pré-remplie sur les valeurs
   *  courantes (§ 6.10). L'identifiant est celui de la demande caduque. */
  refaire: [demandeId: number | null]
}>()

function dateLisible(valeur: string | null): string {
  if (valeur === null) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <!-- ── Rien n'a été écrit ─────────────────────────────────────────── -->
  <PanneauBase v-if="conflit.sorte === 'rejouable'" titre="Écriture concurrente">
    <p class="text-sm">{{ conflit.message }}</p>
    <p class="mt-2 text-sm text-attenue">
      Deux enregistrements se sont croisés. <strong class="font-semibold">Rien n’a été écrit</strong>,
      et votre saisie est intacte : le même envoi peut être refait tel quel.
    </p>
    <div class="mt-3">
      <BoutonBase @click="emit('reessayer')">{{ conflit.libelleAction }}</BoutonBase>
    </div>
  </PanneauBase>

  <!-- ── Le monde a bougé ───────────────────────────────────────────── -->
  <section
    v-else
    class="rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3"
    role="alert"
  >
    <h2 class="text-sm font-semibold">Cette demande est devenue caduque</h2>
    <p class="mt-1 text-sm">{{ conflit.message }}</p>

    <p class="mt-2 text-sm">
      Personne ne l’a refusée : la fiche a changé pendant qu’elle attendait. Rien n’a été
      appliqué à l’arbre, et <strong class="font-semibold">la relancer telle quelle
      l’écrirait deux fois</strong> — il faut la reprendre sur les valeurs d’aujourd’hui.
    </p>

    <p v-if="conflit.cible !== null" class="mt-2 text-sm">
      La cible visée — {{ conflit.cible.label }} — n’existe plus dans l’arbre.
    </p>

    <!-- Trois valeurs par champ. Le tableau défile seul sous 400 px plutôt que
         de pousser le corps de page vers la droite. -->
    <div v-if="conflit.champs.length > 0" class="mt-3 overflow-x-auto">
      <table class="w-full min-w-[34rem] border-collapse text-left text-xs">
        <thead>
          <tr class="border-b border-alerte-bord">
            <th scope="col" class="py-1.5 pr-3 font-semibold">Champ</th>
            <th scope="col" class="py-1.5 pr-3 font-semibold">Ce que vous proposiez</th>
            <th scope="col" class="py-1.5 pr-3 font-semibold">Ce qu’il y avait au dépôt</th>
            <th scope="col" class="py-1.5 font-semibold">Ce qu’il y a aujourd’hui</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="champ in conflit.champs" :key="champ.field" class="border-b border-alerte-bord/50">
            <th scope="row" class="py-1.5 pr-3 font-medium">{{ champ.etiquette }}</th>
            <td class="py-1.5 pr-3">{{ valeurLisible(champ.propose) }}</td>
            <td class="py-1.5 pr-3 text-attenue">{{ valeurLisible(champ.valeurALaSoumission) }}</td>
            <td class="py-1.5">
              {{ valeurLisible(champ.valeurCourante) }}
              <span v-if="champ.modifieePar?.auteur" class="block text-attenue">
                par {{ champ.modifieePar.auteur }}
                <template v-if="champ.modifieePar.le">
                  le {{ dateLisible(champ.modifieePar.le) }}
                </template>
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Jamais « Réessayer » ici. Le libellé vient du type, pas du gabarit. -->
    <div class="mt-3">
      <BoutonBase variante="secondaire" @click="emit('refaire', conflit.demandeId)">
        {{ conflit.libelleAction }}
      </BoutonBase>
    </div>
  </section>
</template>
