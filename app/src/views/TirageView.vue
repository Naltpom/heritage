<script setup lang="ts">
/**
 * Un tirage au sort : à qui j'offre cette année, qui vient, et — pour
 * l'organisateur — les réglages, les participants et le tirage lui-même.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le nom du receveur est masqué jusqu'au geste « Découvrir »**
 * (`CarteAttribution`).
 *
 * Le serveur ne rend au lecteur que ses propres attributions ; l'écran ajoute
 * une précaution de plus, parce qu'on l'ouvre volontiers devant d'autres, un
 * soir de famille.
 * ────────────────────────────────────────────────────────────────────
 *
 * Chaque écriture rend le tirage relu : l'écran remplace son état par la
 * réponse, sans recoller de morceaux.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ajouterParticipant,
  analyserErreur,
  annulerLeTirage,
  definirPresence,
  lancerLeTirage,
  lireTirage,
  ouvrirEdition,
  reglerTirage,
  rejoindreTirage,
  retirerParticipant,
  supprimerTirage,
  type DetailDeTirage,
  type EditionDuTirage,
  type PresenceAuTirage,
  type ReglesDuTirage,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import SelecteurPersonne from '@/components/proposition/SelecteurPersonne.vue'
import { estFicheProposee, type ChoixDePersonne } from '@/components/proposition/creation'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import CarteAttribution from '@/components/tirages/CarteAttribution.vue'
import FormulaireReglages from '@/components/tirages/FormulaireReglages.vue'
import {
  PRESENCES,
  anneeProposee,
  dateLongue,
  libelleDeMemoire,
  libelleDuJour,
  monPersonId,
} from '@/components/tirages/tirages'
import { useSession } from '@/composables/useSession'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()
const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

const id = computed(() => Number(route.params['id']))
const tirage = ref<DetailDeTirage | null>(null)
const erreurDeChargement = ref<unknown>(null)
let avorteur: AbortController | null = null

async function charger(): Promise<void> {
  avorteur?.abort()
  avorteur = new AbortController()
  erreurDeChargement.value = null

  try {
    tirage.value = await lireTirage(id.value, avorteur.signal)
  } catch (e) {
    erreurDeChargement.value = e
  }
}

watch(id, charger, { immediate: true })
onBeforeUnmount(() => avorteur?.abort())

/* ───────────────── Écritures ───────────────── */

const enCours = ref<string | null>(null)
const erreur = ref<unknown>(null)

/** Une écriture à la fois ; sa réponse remplace le tirage. */
async function agir(cle: string, geste: () => Promise<DetailDeTirage>): Promise<boolean> {
  if (enCours.value !== null) return false
  enCours.value = cle
  erreur.value = null

  try {
    tirage.value = await geste()
    return true
  } catch (e) {
    erreur.value = e
    return false
  } finally {
    enCours.value = null
  }
}

/* ───────────────── L'édition courante ───────────────── */

const moi = computed(() => (tirage.value === null ? null : monPersonId(tirage.value)))

/** L'édition à venir la plus proche ; à défaut, la plus récente. */
const edition = computed<EditionDuTirage | null>(() => {
  const editions = tirage.value?.editions ?? []
  const aVenir = editions.filter((e) => !e.passee)
  return aVenir.at(-1) ?? editions[0] ?? null
})

const anciennes = computed(() => (tirage.value?.editions ?? []).filter((e) => e !== edition.value))

const suivis = computed(() => new Set((tirage.value?.participants ?? []).filter((p) => p.suivi).map((p) => p.personId)))

function peutReglerLaPresence(personId: number): boolean {
  return (tirage.value?.peutGerer ?? false) || suivis.value.has(personId)
}

function changerPresence(annee: number, personId: number, presence: PresenceAuTirage): void {
  void agir(`presence-${personId}`, () => definirPresence(id.value, annee, personId, presence))
}

const jeSuisArriveApres = computed(
  () => moi.value !== null && (edition.value?.arrivesApresLeTirage ?? []).includes(moi.value),
)

const memoireRelachee = computed(() => {
  const e = edition.value
  const t = tirage.value
  return e !== null && t !== null && e.memoireAppliquee !== null && e.memoireAppliquee < t.memoire
    ? e.memoireAppliquee
    : null
})

/* ───────────────── Organisateur ───────────────── */

const anneeAOuvrir = computed(() => (tirage.value === null ? null : anneeProposee(tirage.value, new Date())))

const confirmation = ref<'annuler' | 'supprimer' | null>(null)

async function tirer(annee: number): Promise<void> {
  await agir('tirer', () => lancerLeTirage(id.value, annee))
}

async function annuler(annee: number): Promise<void> {
  confirmation.value = null
  await agir('annuler', () => annulerLeTirage(id.value, annee))
}

async function supprimer(): Promise<void> {
  confirmation.value = null
  enCours.value = 'supprimer'
  try {
    await supprimerTirage(id.value)
    await routeur.push({ name: 'tirages' })
  } catch (e) {
    erreur.value = e
  } finally {
    enCours.value = null
  }
}

/* Participants */

const referent = ref<number | null>(null)
const dejaChoisis = computed(() => (tirage.value?.participants ?? []).map((p) => `personne:${p.personId}`))

function ajouter(choix: ChoixDePersonne): void {
  if (estFicheProposee(choix)) return
  const avec = referent.value
  void agir('ajouter', () => ajouterParticipant(id.value, choix.personId, avec)).then((ok) => {
    if (ok) referent.value = null
  })
}

function retirer(personId: number): void {
  void agir(`retirer-${personId}`, () => retirerParticipant(id.value, personId))
}

/* Réglages */

const enReglage = ref(false)
const brouillon = ref<ReglesDuTirage | null>(null)
const erreurDeReglage = ref<unknown>(null)
const erreursDeChamps = computed<Record<string, string>>(() =>
  erreurDeReglage.value === null ? {} : analyserErreur(erreurDeReglage.value).champs,
)

function ouvrirLesReglages(): void {
  const t = tirage.value
  if (t === null) return
  brouillon.value = {
    nom: t.nom,
    jour: t.jour,
    mois: t.mois,
    lieu: t.lieu,
    ouvert: t.ouvert,
    memoire: t.memoire,
    exclureConjoints: t.exclureConjoints,
  }
  erreurDeReglage.value = null
  enReglage.value = true
}

async function enregistrerLesReglages(): Promise<void> {
  const regles = brouillon.value
  if (regles === null || enCours.value !== null) return
  enCours.value = 'regler'
  erreurDeReglage.value = null

  try {
    tirage.value = await reglerTirage(id.value, regles)
    enReglage.value = false
  } catch (e) {
    erreurDeReglage.value = e
  } finally {
    enCours.value = null
  }
}

const classeSelect = 'h-9 rounded-champ border border-ligne bg-fond px-2 text-sm text-texte'
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-6">
    <SqueletteChargement v-if="tirage === null && erreurDeChargement === null" :lignes="5" />

    <MessageErreur
      v-else-if="tirage === null"
      :erreur="erreurDeChargement"
      titre="Ce tirage n’a pas pu être chargé"
      @action="charger"
    />

    <template v-else>
      <header class="mb-6">
        <h1 class="text-xl font-semibold text-texte">{{ tirage.nom }}</h1>
        <p class="mt-1 text-sm text-attenue">
          Chaque {{ libelleDuJour(tirage.jour, tirage.mois) }}<template v-if="tirage.lieu"> · {{ tirage.lieu }}</template>
          · organisé par {{ tirage.organisateur.estMoi ? 'vous' : tirage.organisateur.libelle }}
        </p>
        <p class="mt-1 text-xs text-attenue">
          {{ libelleDeMemoire(tirage.memoire) }}<template v-if="tirage.exclureConjoints"> · les couples ne se tirent pas</template>
          · {{ tirage.ouvert ? 'ouvert à la famille' : 'sur invitation de l’organisateur' }}
        </p>
      </header>

      <p v-if="motifSuspendu !== null" class="mb-4 rounded-panneau border border-ligne bg-panneau p-3 text-xs text-attenue">
        {{ motifSuspendu }}
      </p>

      <MessageErreur v-if="erreur !== null" class="mb-4" :erreur="erreur" titre="L’opération n’a pas abouti" />

      <div v-if="tirage.peutRejoindre" class="mb-6 flex items-center gap-3 rounded-panneau border border-ligne bg-panneau p-4">
        <p class="flex-1 text-sm text-texte">Vous ne participez pas encore à ce tirage.</p>
        <BoutonBase :chargement="enCours === 'rejoindre'" :desactive="suspendu" @click="agir('rejoindre', () => rejoindreTirage(id))">
          Rejoindre
        </BoutonBase>
      </div>

      <!-- ───────── L'édition de l'année ───────── -->
      <PanneauBase v-if="edition !== null" class="mb-6">
        <template #entete>
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold tracking-tight">Édition {{ edition.annee }}</h2>
            <span class="text-xs text-attenue">{{ dateLongue(edition.date) }} · {{ edition.statutLibelle }}</span>
          </div>
        </template>

        <div class="flex flex-col gap-4">
          <template v-if="edition.statut === 'tiree'">
            <CarteAttribution
              v-for="a in edition.mesAttributions"
              :key="`${edition.annee}-${a.donneur.personId}`"
              :attribution="a"
              :mon-person-id="moi"
            />
            <p v-if="edition.mesAttributions.length === 0 && !jeSuisArriveApres" class="text-sm text-attenue">
              Le tirage est fait. Vous n’y participez pas cette année.
            </p>
            <p v-if="jeSuisArriveApres" class="text-sm text-attenue">
              Vous avez rejoint le tirage après qu’il a eu lieu : l’organisateur doit le refaire pour vous y inclure.
            </p>
            <p v-if="memoireRelachee !== null && tirage.peutGerer" class="text-xs text-attenue">
              Cette année, le tirage n’a pu éviter les personnes que de {{ memoireRelachee }}
              année{{ memoireRelachee > 1 ? 's' : '' }} passée{{ memoireRelachee > 1 ? 's' : '' }} : le groupe est trop petit pour la règle choisie.
            </p>
          </template>
          <p v-else class="text-sm text-attenue">
            Le tirage n’a pas encore eu lieu. Dites si vous venez : ceux qui ne participent pas cette année n’y entrent pas.
          </p>

          <div>
            <h3 class="mb-2 text-sm font-medium text-texte">Qui vient</h3>
            <ul class="flex flex-col divide-y divide-ligne">
              <li v-for="p in edition.presences" :key="p.personId" class="flex items-center justify-between gap-3 py-2">
                <span class="text-sm text-texte">{{ p.libelle }}</span>
                <select
                  v-if="peutReglerLaPresence(p.personId) && !edition.passee"
                  :class="classeSelect"
                  :value="p.presence"
                  :disabled="suspendu || enCours !== null"
                  :aria-label="`Présence de ${p.libelle}`"
                  @change="changerPresence(edition.annee, p.personId, ($event.target as HTMLSelectElement).value as PresenceAuTirage)"
                >
                  <option v-for="o in PRESENCES" :key="o.valeur" :value="o.valeur">{{ o.libelle }}</option>
                </select>
                <span v-else class="text-xs text-attenue">{{ p.presenceLibelle }}</span>
              </li>
            </ul>
          </div>

          <div v-if="tirage.peutGerer && !edition.passee" class="flex flex-wrap items-center gap-2 border-t border-ligne pt-4">
            <BoutonBase
              v-if="edition.statut === 'ouverte'"
              :chargement="enCours === 'tirer'"
              :desactive="suspendu || enCours !== null"
              @click="tirer(edition.annee)"
            >
              Lancer le tirage {{ edition.annee }}
            </BoutonBase>
            <template v-else>
              <BoutonBase
                v-if="confirmation !== 'annuler'"
                variante="secondaire"
                :desactive="suspendu || enCours !== null"
                @click="confirmation = 'annuler'"
              >
                Annuler le tirage
              </BoutonBase>
              <template v-else>
                <span class="text-xs text-attenue">Les attributions seront effacées ; il faudra relancer.</span>
                <BoutonBase variante="danger" :chargement="enCours === 'annuler'" @click="annuler(edition.annee)">Confirmer</BoutonBase>
                <BoutonBase variante="discret" @click="confirmation = null">Garder</BoutonBase>
              </template>
            </template>
            <p class="w-full text-xs text-attenue">
              Vous organisez ce tirage, mais vous ne voyez que votre propre attribution — comme tout le monde.
            </p>
          </div>
        </div>
      </PanneauBase>

      <PanneauBase v-else class="mb-6">
        <p class="text-sm text-attenue">Aucune édition n’est ouverte pour l’instant.</p>
      </PanneauBase>

      <div v-if="tirage.peutGerer && anneeAOuvrir !== null && (edition === null || edition.passee)" class="mb-6">
        <BoutonBase
          variante="secondaire"
          :chargement="enCours === 'ouvrir'"
          :desactive="suspendu || enCours !== null"
          @click="agir('ouvrir', () => ouvrirEdition(id, anneeAOuvrir as number))"
        >
          Ouvrir l’édition {{ anneeAOuvrir }}
        </BoutonBase>
      </div>

      <!-- ───────── Participants ───────── -->
      <PanneauBase :titre="`Participants (${tirage.participants.length})`" class="mb-6">
        <ul class="flex flex-col divide-y divide-ligne">
          <li v-for="p in tirage.participants" :key="p.personId" class="flex items-center justify-between gap-3 py-2">
            <span class="text-sm text-texte">
              {{ p.libelle }}<template v-if="p.estMoi"> (vous)</template>
              <span v-if="p.referent" class="block text-xs text-attenue">accompagné·e par {{ p.referent.libelle }}</span>
            </span>
            <BoutonBase
              v-if="tirage.peutGerer || p.suivi"
              variante="discret"
              :chargement="enCours === `retirer-${p.personId}`"
              :desactive="suspendu || enCours !== null"
              @click="retirer(p.personId)"
            >
              {{ p.estMoi ? 'Quitter' : 'Retirer' }}
            </BoutonBase>
          </li>
        </ul>

        <div v-if="tirage.peutGerer" class="mt-4 flex flex-col gap-2 border-t border-ligne pt-4">
          <label class="flex flex-col gap-1 text-sm text-texte">
            Accompagné·e par (facultatif)
            <select
              :class="classeSelect"
              :value="referent ?? ''"
              @change="referent = ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value)"
            >
              <option value="">Personne — il ou elle a son propre compte</option>
              <option v-for="p in tirage.participants" :key="p.personId" :value="p.personId">{{ p.libelle }}</option>
            </select>
            <span class="text-xs text-attenue">Pour un enfant sans compte : son référent lira son tirage.</span>
          </label>
          <SelecteurPersonne
            etiquette="Ajouter une personne de l’arbre"
            :avec-propositions="false"
            :deja-choisis="dejaChoisis"
            @choisir="ajouter"
          />
        </div>
      </PanneauBase>

      <!-- ───────── Les années passées ───────── -->
      <PanneauBase v-if="anciennes.length > 0" titre="Les autres années" class="mb-6">
        <ul class="flex flex-col gap-2">
          <li v-for="e in anciennes" :key="e.annee" class="text-sm text-texte">
            <span class="font-medium">{{ e.annee }}</span>
            <span class="text-attenue"> · {{ e.statutLibelle }}</span>
            <template v-for="a in e.passee ? e.mesAttributions : []" :key="a.donneur.personId">
              · {{ a.donneur.personId === moi ? 'vous avez offert' : `${a.donneur.libelle} a offert` }} à {{ a.receveur.libelle }}
            </template>
          </li>
        </ul>
      </PanneauBase>

      <!-- ───────── Réglages ───────── -->
      <PanneauBase v-if="tirage.peutGerer" titre="Réglages">
        <form v-if="enReglage && brouillon !== null" class="flex flex-col gap-5" @submit.prevent="enregistrerLesReglages">
          <FormulaireReglages v-model="brouillon" :erreurs="erreursDeChamps" :memoire-conseillee="tirage.memoireConseillee" />
          <MessageErreur
            v-if="erreurDeReglage !== null && Object.keys(erreursDeChamps).length === 0"
            :erreur="erreurDeReglage"
            titre="Les réglages n’ont pas pu être enregistrés"
          />
          <div class="flex gap-2">
            <BoutonBase type="submit" :chargement="enCours === 'regler'" :desactive="suspendu">Enregistrer</BoutonBase>
            <BoutonBase variante="discret" @click="enReglage = false">Annuler</BoutonBase>
          </div>
        </form>
        <div v-else class="flex flex-wrap items-center gap-2">
          <BoutonBase variante="secondaire" :desactive="suspendu" @click="ouvrirLesReglages">Modifier les réglages</BoutonBase>
          <BoutonBase v-if="confirmation !== 'supprimer'" variante="discret" :desactive="suspendu" @click="confirmation = 'supprimer'">
            Supprimer le tirage
          </BoutonBase>
          <template v-else>
            <span class="text-xs text-attenue">Toutes les années et leur historique seront perdus.</span>
            <BoutonBase variante="danger" :chargement="enCours === 'supprimer'" @click="supprimer">Supprimer définitivement</BoutonBase>
            <BoutonBase variante="discret" @click="confirmation = null">Garder</BoutonBase>
          </template>
        </div>
      </PanneauBase>
    </template>
  </div>
</template>
