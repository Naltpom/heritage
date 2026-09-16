<script setup lang="ts">
/**
 * La liste « Mes demandes », **rendu seul**.
 *
 * Elle ne fait aucun appel : l'écran qui la monte lui passe ce qu'il a reçu.
 * C'est ce qui rend vérifiables les deux états qu'on ne voit jamais pendant le
 * développement, et qui sont pourtant ceux qui décident si l'écran est bon :
 *
 * - **le vide** — un membre qui n'a encore rien proposé ne doit pas trouver une
 *   page blanche, qui se lit comme une panne ;
 * - **l'échec** — « un écran qui ne dit rien quand la requête échoue » est le
 *   défaut dont ce lot est né. `MessageErreur` s'en charge, avec le message du
 *   serveur, le code, et la conduite à tenir.
 *
 * `mes-demandes-rendu.test.ts` les rend tous les deux et lit la chaîne produite.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le motif de refus est la raison d'être de cet écran.** Il est affiché en
 * clair, sur la ligne, sans qu'il faille ouvrir quoi que ce soit : c'est
 * exactement ce que le commanditaire avait demandé et que rien n'affichait.
 *
 * Il n'est pas dans la charge de la liste — `GET /api/demandes` n'a pas de
 * `decisionMessage` —, donc il arrive après, par une seconde lecture. Trois
 * états, et **les trois se disent** : pas encore reçu, reçu, impossible à lire.
 * Le troisième est le plus important : il renvoie au détail plutôt que de
 * laisser une ligne muette là où il y avait un motif.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed } from 'vue'
import type { ResumeDemande, StatutDemande } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import {
  FILTRES,
  LIBELLES_NATURE,
  lienDeReprise,
  messageQuandVide,
  pastilleDeStatut,
  type MotifDecision,
} from './mes-demandes'

const {
  demandes,
  motifs,
  filtre,
  total = 0,
  chargement = false,
  erreur = null,
} = defineProps<{
  demandes: readonly ResumeDemande[]
  /**
   * Par identifiant de demande. **Trois états, pas deux** : clé absente = pas
   * encore lu ; valeur `null` = lu, mais rien à montrer (lecture échouée, ou
   * aucun motif) ; valeur = le motif.
   */
  motifs: ReadonlyMap<number, MotifDecision | null>
  filtre: StatutDemande | ''
  total?: number
  chargement?: boolean
  erreur?: unknown
}>()

const emit = defineEmits<{
  /** Le lecteur change de filtre. L'écran relit ; la liste ne décide rien. */
  filtrer: [valeur: StatutDemande | '']
  /** Reprendre l'appel après un échec. */
  recharger: []
}>()

const vide = computed(() => demandes.length === 0)

/** Le serveur plafonne `parPage` à 100 **sans le dire** (mesuré : `?parPage=999`
 *  rend `parPage: 100`). Au-delà, on le dit plutôt que de laisser croire que le
 *  compte affiché est la liste entière. */
const tronquee = computed(() => total > demandes.length)

function dateLisible(valeur: string | null): string {
  if (valeur === null || valeur.length === 0) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

function changerFiltre(valeur: string): void {
  emit('filtrer', valeur as StatutDemande | '')
}
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <label for="filtre-mes-demandes" class="text-sm font-medium">Afficher</label>
      <select
        id="filtre-mes-demandes"
        :value="filtre"
        class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
        @change="changerFiltre(($event.target as HTMLSelectElement).value)"
      >
        <option v-for="choix in FILTRES" :key="choix.libelle" :value="choix.valeur">
          {{ choix.libelle }}
        </option>
      </select>

      <span v-if="!chargement && erreur === null" class="text-sm text-attenue tnum">
        {{ total }} demande{{ total > 1 ? 's' : '' }}
      </span>
    </div>

    <PanneauBase v-if="chargement && vide">
      <p class="mb-3 text-sm text-attenue" role="status">Chargement de vos demandes…</p>
      <SqueletteChargement :lignes="3" />
    </PanneauBase>

    <!-- L'échec parle. C'est tout l'objet de ce composant. -->
    <MessageErreur
      v-else-if="erreur !== null"
      :erreur="erreur"
      titre="Vos demandes n’ont pas pu être chargées."
      @action="emit('recharger')"
    />

    <PanneauBase v-else-if="vide">
      <p class="text-sm">{{ messageQuandVide(filtre) }}</p>
      <p v-if="filtre === ''" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <RouterLink :to="{ name: 'proposition.creation' }" class="font-semibold text-accent underline">
          Proposer une personne
        </RouterLink>
        <RouterLink :to="{ name: 'arbre' }" class="font-semibold text-accent underline">
          Parcourir l’arbre
        </RouterLink>
      </p>
    </PanneauBase>

    <template v-else>
      <ul class="flex flex-col gap-3">
        <li v-for="demande in demandes" :key="demande.id">
          <PanneauBase>
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              <RouterLink
                :to="{ name: 'demande.detail', params: { id: demande.id } }"
                class="font-semibold text-accent underline"
              >
                {{ LIBELLES_NATURE[demande.kind] }}
              </RouterLink>
              <PastilleEtat
                :etat="pastilleDeStatut(demande.status).etat"
                :libelle="pastilleDeStatut(demande.status).libelle"
              />
              <span class="text-sm text-attenue">
                <template v-if="demande.targetLabel">sur {{ demande.targetLabel }} — </template>
                déposée le {{ dateLisible(demande.submittedAt ?? demande.createdAt) }}
              </span>
            </div>

            <p v-if="demande.message" class="mt-1.5 text-sm text-attenue">
              « {{ demande.message }} »
            </p>

            <!-- Le motif, en clair, sur la ligne. Le geste qu'il fallait faire
                 jusqu'ici pour le lire : taper `/demandes/6` dans la barre
                 d'adresse. -->
            <template v-if="demande.status === 'refusee'">
              <div
                v-if="motifs.get(demande.id)"
                class="mt-3 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
              >
                <p class="mb-1 font-semibold">Motif du refus</p>
                <p class="whitespace-pre-line">{{ motifs.get(demande.id)!.message }}</p>
                <p v-if="motifs.get(demande.id)!.parQui" class="mt-2 text-xs text-attenue">
                  {{ motifs.get(demande.id)!.parQui }}<template v-if="motifs.get(demande.id)!.quand">,
                  le {{ dateLisible(motifs.get(demande.id)!.quand) }}</template>
                </p>
              </div>

              <!-- Lu, mais rien à montrer : on renvoie au détail au lieu de
                   laisser une ligne muette là où il y avait un motif. -->
              <p v-else-if="motifs.has(demande.id)" class="mt-3 text-sm text-attenue">
                Le motif de ce refus n’a pas pu être chargé.
                <RouterLink
                  :to="{ name: 'demande.detail', params: { id: demande.id } }"
                  class="font-semibold text-accent underline"
                >
                  Ouvrir la demande
                </RouterLink>
                pour le lire.
              </p>

              <p v-else class="mt-3 text-sm text-attenue" role="status">
                Chargement du motif du refus…
              </p>
            </template>

            <!-- « Une caducité sans ce bouton est une contribution perdue »
                 (§ 9.6) — et un refus motivé sans ce bouton aussi : on vient de
                 dire à quelqu'un ce qui manquait à sa proposition. -->
            <p v-if="lienDeReprise(demande) !== null" class="mt-3 text-sm">
              <RouterLink :to="lienDeReprise(demande)!" class="font-semibold text-accent underline">
                Refaire cette demande
              </RouterLink>
            </p>
          </PanneauBase>
        </li>
      </ul>

      <p v-if="tronquee" class="mt-3 text-sm text-attenue">
        Les {{ demandes.length }} plus récentes de vos {{ total }} demandes sont affichées.
      </p>
    </template>
  </div>
</template>
