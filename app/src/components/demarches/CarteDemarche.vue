<script setup lang="ts">
/**
 * Une démarche dans une liste : ce qui a été demandé, à qui, où elle en est —
 * et, dépliée, **chaque message en entier**.
 *
 * Le détail ne se charge qu'au dépliage : une liste de trente démarches ne
 * transporte pas trente fois le texte de leurs courriers.
 *
 * Tout membre qui la voit peut la faire avancer — ajouter la réponse reçue,
 * changer le statut. Seuls les gestes qui effacent sont réservés, et c'est le
 * serveur qui dit à qui (`peutSupprimer`, `peutRetirer`).
 */
import { computed, ref } from 'vue'
import {
  ajouterMessageADemarche,
  analyserErreur,
  lireDemarche,
  retirerMessageDeDemarche,
  suivreDemarche,
  supprimerDemarche,
  type DetailDeDemarche,
  type MessageASaisir,
  type ResumeDeDemarche,
  type StatutDeDemarche,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import FormulaireDemarche from './FormulaireDemarche.vue'
import FormulaireMessage from './FormulaireMessage.vue'
import { STATUTS, aRelancer, dateLisible, libelleDAttente, messageAEnvoyer, messageVide, nulSiVide } from './demarches'

const { demarche, suspendu = false, avecLiensVersLesFiches = true } = defineProps<{
  demarche: ResumeDeDemarche
  suspendu?: boolean
  /** Sur la fiche d'une personne, la lier à elle-même n'apporte rien. */
  avecLiensVersLesFiches?: boolean
}>()

const emit = defineEmits<{ changee: [demarche: ResumeDeDemarche]; supprimee: [id: number] }>()

const ouverte = ref(false)
const detail = ref<DetailDeDemarche | null>(null)
const erreur = ref<unknown>(null)
const enCours = ref(false)

type Geste = 'aucun' | 'message' | 'suivi' | 'description' | 'suppression'
const geste = ref<Geste>('aucun')

const nouveauMessage = ref<MessageASaisir>(messageVide('recu'))
const statut = ref<StatutDeDemarche>(demarche.statut)
const resultat = ref(demarche.resultat ?? '')

const champs = computed<Record<string, string>>(() => (erreur.value === null ? {} : analyserErreur(erreur.value).champs))

const pastille = computed<{ etat: 'en_attente' | 'validee' | 'refusee' | 'neutre'; libelle: string }>(() => {
  if (demarche.statut === 'recue') return { etat: 'validee', libelle: demarche.statutLibelle }
  if (demarche.statut === 'refusee') return { etat: 'refusee', libelle: demarche.statutLibelle }
  if (demarche.statut === 'sans_suite') return { etat: 'neutre', libelle: demarche.statutLibelle }
  return { etat: 'en_attente', libelle: demarche.statutLibelle }
})

const attente = computed(() => libelleDAttente(demarche.joursDAttente))

async function basculer(): Promise<void> {
  ouverte.value = !ouverte.value
  if (ouverte.value && detail.value === null) await recharger()
}

async function recharger(): Promise<void> {
  erreur.value = null
  try {
    remplacer(await lireDemarche(demarche.id))
  } catch (e) {
    erreur.value = e
  }
}

function remplacer(relue: DetailDeDemarche): void {
  detail.value = relue
  statut.value = relue.statut
  resultat.value = relue.resultat ?? ''
  emit('changee', relue)
}

async function executer(action: () => Promise<DetailDeDemarche>): Promise<void> {
  if (enCours.value) return
  enCours.value = true
  erreur.value = null
  try {
    remplacer(await action())
    geste.value = 'aucun'
  } catch (e) {
    erreur.value = e
  } finally {
    enCours.value = false
  }
}

function ouvrirGeste(g: Geste): void {
  erreur.value = null
  if (g === 'message') nouveauMessage.value = messageVide('recu')
  geste.value = g
}

const ajouterLeMessage = () => executer(() => ajouterMessageADemarche(demarche.id, messageAEnvoyer(nouveauMessage.value)))
const enregistrerLeSuivi = () =>
  executer(() => suivreDemarche(demarche.id, { statut: statut.value, resultat: nulSiVide(resultat.value) }))
const retirerLeMessage = (messageId: number) => executer(() => retirerMessageDeDemarche(demarche.id, messageId))

async function supprimer(): Promise<void> {
  if (enCours.value) return
  enCours.value = true
  erreur.value = null
  try {
    await supprimerDemarche(demarche.id)
    emit('supprimee', demarche.id)
  } catch (e) {
    erreur.value = e
  } finally {
    enCours.value = false
  }
}
</script>

<template>
  <article class="rounded-panneau border border-ligne bg-panneau">
    <button
      type="button"
      class="flex w-full cursor-pointer flex-wrap items-start justify-between gap-2 border-0 bg-transparent p-4 text-left"
      :aria-expanded="ouverte"
      @click="basculer"
    >
      <span class="min-w-0 flex-1">
        <span class="block font-medium text-texte">{{ demarche.intitule }}</span>
        <span class="mt-0.5 block text-xs text-attenue">
          {{ demarche.natureLibelle }} · {{ demarche.formeLibelle }} · demandée à {{ demarche.destinataire }}
          <template v-if="demarche.dernierEnvoiLe"> le {{ dateLisible(demarche.dernierEnvoiLe) }}</template>
        </span>
      </span>
      <span class="flex flex-col items-end gap-1">
        <PastilleEtat :etat="pastille.etat" :libelle="pastille.libelle" />
        <span v-if="attente" class="text-[11px]" :class="aRelancer(demarche) ? 'font-semibold text-alerte' : 'text-attenue'">
          {{ attente }}<template v-if="aRelancer(demarche)"> — à relancer</template>
        </span>
      </span>
    </button>

    <div v-if="ouverte" class="border-t border-ligne px-4 pb-4 pt-3">
      <SqueletteChargement v-if="detail === null && erreur === null" :lignes="3" />
      <MessageErreur v-else-if="detail === null" :erreur="erreur" titre="La démarche n’a pas pu être chargée" @action="recharger" />

      <template v-else>
        <dl class="mb-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
          <template v-if="detail.commune || detail.dateDeLActe">
            <dt class="text-attenue">Acte</dt>
            <dd>{{ [detail.commune, detail.dateDeLActe].filter(Boolean).join(', ') }}</dd>
          </template>
          <dt class="text-attenue">Concerne</dt>
          <dd class="flex flex-wrap gap-x-2">
            <template v-for="(p, i) in detail.personnes" :key="p.personId">
              <RouterLink
                v-if="avecLiensVersLesFiches"
                :to="{ name: 'personne.detail', params: { id: p.personId }, query: { onglet: 'documents' } }"
                class="text-accent underline"
              >{{ p.libelle }}</RouterLink>
              <span v-else>{{ p.libelle }}</span><span v-if="i < detail.personnes.length - 1">,</span>
            </template>
          </dd>
          <dt class="text-attenue">Enregistrée par</dt>
          <dd>{{ detail.auteur.libelle }}</dd>
          <template v-if="detail.resultat">
            <dt class="text-attenue">Résultat</dt>
            <dd class="whitespace-pre-line">{{ detail.resultat }}</dd>
          </template>
        </dl>

        <h4 class="mb-2 text-xs uppercase tracking-wider text-attenue">
          Messages ({{ detail.messages.length }})
        </h4>
        <p v-if="detail.messages.length === 0" class="mb-3 text-sm text-attenue">Aucun message enregistré.</p>
        <ol class="mb-3 flex list-none flex-col gap-3 p-0">
          <li
            v-for="m in detail.messages"
            :key="m.id"
            class="rounded-champ border border-ligne bg-fond p-3"
            :class="m.sens === 'recu' ? 'border-l-4 border-l-accent' : ''"
          >
            <p class="text-xs text-attenue">
              <span class="font-semibold text-texte">{{ m.sensLibelle }}</span>
              le {{ dateLisible(m.date) }} · {{ m.canalLibelle }}
              <template v-if="m.reference"> · réf. {{ m.reference }}</template>
            </p>
            <p v-if="m.de || m.a" class="mt-0.5 text-xs text-attenue">
              <template v-if="m.de">De : {{ m.de }}</template>
              <template v-if="m.de && m.a"> — </template>
              <template v-if="m.a">À : {{ m.a }}</template>
            </p>
            <p v-if="m.objet" class="mt-1 text-sm font-medium">{{ m.objet }}</p>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed">{{ m.texte }}</p>
            <p class="mt-2 flex items-center justify-between gap-2 text-[11px] text-attenue">
              <span>saisi par {{ m.saisiPar }}</span>
              <BoutonBase
                v-if="m.peutRetirer"
                variante="discret"
                :desactive="suspendu || enCours"
                @click="retirerLeMessage(m.id)"
              >
                Retirer
              </BoutonBase>
            </p>
          </li>
        </ol>

        <div v-if="geste === 'aucun'" class="flex flex-wrap gap-2">
          <BoutonBase variante="secondaire" :desactive="suspendu" @click="ouvrirGeste('message')">Ajouter un message</BoutonBase>
          <BoutonBase variante="secondaire" :desactive="suspendu" @click="ouvrirGeste('suivi')">Changer le statut</BoutonBase>
          <BoutonBase variante="discret" :desactive="suspendu" @click="ouvrirGeste('description')">Corriger</BoutonBase>
          <BoutonBase v-if="detail.peutSupprimer" variante="discret" :desactive="suspendu" @click="ouvrirGeste('suppression')">
            Supprimer
          </BoutonBase>
        </div>

        <form v-else-if="geste === 'message'" class="flex flex-col gap-3" @submit.prevent="ajouterLeMessage">
          <p class="text-sm text-attenue">
            Une relance, ou la réponse du service. Une seconde demande envoyée fait passer la démarche à « relancée ».
          </p>
          <FormulaireMessage v-model="nouveauMessage" :erreurs="champs" />
          <div class="flex gap-2">
            <BoutonBase type="submit" :chargement="enCours" :desactive="suspendu">Ajouter</BoutonBase>
            <BoutonBase variante="discret" @click="geste = 'aucun'">Annuler</BoutonBase>
          </div>
        </form>

        <form v-else-if="geste === 'suivi'" class="flex flex-col gap-3" @submit.prevent="enregistrerLeSuivi">
          <div class="flex flex-col gap-1">
            <label class="text-sm font-medium" :for="`statut-${demarche.id}`">Statut</label>
            <select
              :id="`statut-${demarche.id}`"
              v-model="statut"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
            >
              <option v-for="s in STATUTS" :key="s.valeur" :value="s.valeur">{{ s.libelle }}</option>
            </select>
          </div>
          <div class="flex flex-col gap-1">
            <label class="text-sm font-medium" :for="`resultat-${demarche.id}`">Résultat</label>
            <textarea
              :id="`resultat-${demarche.id}`"
              v-model="resultat"
              rows="3"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
              placeholder="Copie reçue le …, déposée sur la fiche ; ou : la mairie renvoie vers les archives."
            />
          </div>
          <div class="flex gap-2">
            <BoutonBase type="submit" :chargement="enCours" :desactive="suspendu">Enregistrer</BoutonBase>
            <BoutonBase variante="discret" @click="geste = 'aucun'">Annuler</BoutonBase>
          </div>
        </form>

        <FormulaireDemarche
          v-else-if="geste === 'description'"
          :existante="detail"
          :suspendu="suspendu"
          @enregistree="remplacer($event); geste = 'aucun'"
          @annuler="geste = 'aucun'"
        />

        <div v-else-if="geste === 'suppression'" class="flex flex-wrap items-center gap-2">
          <span class="text-sm text-attenue">Supprimer la démarche et tous ses messages ?</span>
          <BoutonBase variante="danger" :chargement="enCours" :desactive="suspendu" @click="supprimer">Oui, supprimer</BoutonBase>
          <BoutonBase variante="discret" @click="geste = 'aucun'">Non</BoutonBase>
        </div>

        <MessageErreur
          v-if="erreur !== null && geste !== 'description' && Object.keys(champs).length === 0"
          class="mt-3"
          :erreur="erreur"
          titre="L’opération n’a pas abouti"
        />
      </template>
    </div>
  </article>
</template>
