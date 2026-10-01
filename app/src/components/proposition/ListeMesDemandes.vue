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
 *
 * **La barre d'écran** (handoff « En-tête, barres d'écran et fiche », § 2) :
 * le titre « Mes demandes », puis le filtre d'état — naguère un menu déroulant —
 * en **segmenté avec compteurs**, puis « {n} demandes ». **Pas de bouton
 * Filtres** : il n'y a rien au-delà de la vue, donc `BarreEcran` n'annonce
 * aucun feuillet, et l'en-tête mobile n'a pas de bouton filtres. Sur téléphone,
 * les états deviennent des pastilles de 36 px qui défilent horizontalement.
 *
 * Les compteurs sont ceux que la vue connaît (`comptesDepuisListe()`) ; un
 * compte inconnu ne s'affiche pas, il ne vaut pas zéro.
 *
 * **Annuler une demande** (`retrait.ts`) : une icône à droite de la pastille,
 * sur les seules lignes que le serveur accepterait de retirer — les siennes, en
 * brouillon ou en attente. Le composant n'appelle toujours rien : il émet
 * `retirer`, et la vue pose la question puis envoie. Une icône seule porte son
 * libellé deux fois, en `title` pour le survol et en `aria-label` pour le
 * lecteur d'écran — c'est la convention de la barre de décision.
 */
import { computed } from 'vue'
import type { ResumeDemande, StatutDemande } from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import { peutRetirer } from './retrait'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import {
  LIBELLES_NATURE,
  choixDuSegmente,
  lienDeReprise,
  messageQuandVide,
  pastilleDeStatut,
  type ComptesParFiltre,
  type MotifDecision,
} from './mes-demandes'

const {
  demandes,
  motifs,
  filtre,
  total = 0,
  chargement = false,
  erreur = null,
  comptes = {},
  lecteurId = null,
  suspendu = false,
  infobulle = undefined,
  retraitEnCours = null,
  erreurRetrait = null,
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
  /** Le compte connu de chaque filtre, pour le segmenté. Clé absente : inconnu. */
  comptes?: ComptesParFiltre
  /** L'utilisateur de la session : seul l'auteur annule (`peutRetirer()`). */
  lecteurId?: number | null
  /** Écritures suspendues (vue empruntée) : l'icône reste, éteinte. */
  suspendu?: boolean
  infobulle?: string | undefined
  /** La demande dont l'annulation est en cours d'envoi. */
  retraitEnCours?: number | null
  /** Le dernier échec d'annulation, affiché sous sa ligne. */
  erreurRetrait?: { id: number; cause: unknown } | null
}>()

const emit = defineEmits<{
  /** Le lecteur change de filtre. L'écran relit ; la liste ne décide rien. */
  filtrer: [valeur: StatutDemande | '']
  /** Reprendre l'appel après un échec. */
  recharger: []
  /** Le lecteur veut annuler cette demande. La vue confirme, puis envoie. */
  retirer: [demande: ResumeDemande]
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

const choix = computed(() => choixDuSegmente(comptes, filtre))

function changerFiltre(valeur: StatutDemande | ''): void {
  emit('filtrer', valeur)
}
</script>

<template>
  <div>
    <BarreEcran ecran="Mes demandes">
      <template #titre>Mes demandes</template>

      <template #vue>
        <!-- Bureau : le segmenté. Enveloppé, parce que `Segmente` pose son
             `display` en style et qu'une classe ne le masquerait pas. -->
        <div class="max-md:hidden">
          <Segmente
            :options="choix"
            :valeur="filtre"
            etiquette="État des demandes affichées"
            @choisir="changerFiltre"
          />
        </div>

        <!-- Téléphone : les mêmes états, en pastilles qui défilent. -->
        <div class="flex gap-2 md:hidden" role="group" aria-label="État des demandes affichées">
          <button
            v-for="o in choix"
            :key="o.libelle"
            type="button"
            class="flex h-9 flex-none items-center rounded-pastille border px-[13px] text-[13px]"
            :class="o.valeur === filtre ? 'border-accent bg-accent-doux font-semibold' : 'border-ligne text-attenue'"
            :aria-pressed="o.valeur === filtre"
            @click="changerFiltre(o.valeur)"
          >
            {{ o.libelle }}<template v-if="o.compte !== undefined"> · {{ o.compte }}</template>
          </button>
        </div>
      </template>

      <template v-if="!chargement && erreur === null" #compteur>
        {{ total }} demande{{ total > 1 ? 's' : '' }}
      </template>
    </BarreEcran>

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
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
                <!-- Annuler : une icône, son libellé en `title` et en
                     `aria-label`. Éteinte, et non cachée, sous la vue
                     empruntée : le motif est dans l'infobulle. -->
                <button
                  v-if="peutRetirer(demande, lecteurId)"
                  type="button"
                  data-retirer
                  class="inline-flex size-8 flex-none cursor-pointer items-center justify-center self-center rounded-champ border border-ligne bg-panneau text-attenue transition-colors hover:border-alerte hover:text-alerte disabled:cursor-not-allowed disabled:opacity-55"
                  :disabled="suspendu || retraitEnCours !== null"
                  :aria-busy="retraitEnCours === demande.id"
                  :title="infobulle ?? `Annuler la demande n° ${demande.id}`"
                  :aria-label="`Annuler la demande n° ${demande.id}`"
                  @click="emit('retirer', demande)"
                >
                  <span
                    v-if="retraitEnCours === demande.id"
                    class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"
                    aria-hidden="true"
                  />
                  <svg
                    v-else
                    viewBox="0 0 24 24"
                    class="size-4 fill-none stroke-current stroke-2 [stroke-linecap:round]"
                    aria-hidden="true"
                  >
                    <circle cx="12" cy="12" r="8.5" />
                    <path d="M9 9l6 6M15 9l-6 6" />
                  </svg>
                </button>
                <span class="text-sm text-attenue">
                  <template v-if="demande.targetLabel">sur {{ demande.targetLabel }} — </template>
                  déposée le {{ dateLisible(demande.submittedAt ?? demande.createdAt) }}
                </span>
              </div>

              <MessageErreur
                v-if="erreurRetrait !== null && erreurRetrait.id === demande.id"
                class="mt-3"
                :erreur="erreurRetrait.cause"
                titre="Cette demande n’a pas pu être annulée."
                @action="emit('recharger')"
              />

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
  </div>
</template>
