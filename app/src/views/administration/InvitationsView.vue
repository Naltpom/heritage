<script setup lang="ts">
/**
 * Les invitations — § 3.
 *
 * **Aucune inscription ouverte** : on n'entre sur cet arbre que par un lien
 * d'invitation (§ 6.1 du dossier). Cet écran est donc la seule porte, et il en
 * porte deux propriétés que rien d'autre ne rattrape.
 *
 * **1. Le lien n'apparaît qu'une fois, et il n'y a pas de bouton « renvoyer ».**
 * Le serveur ne stocke que `hash(jeton)` ; `lien` n'existe que dans la réponse
 * de création, jamais dans la liste — absent, pas `null` (§ 3.2). La route
 * paraît manquante alors qu'elle est **impossible**, et c'est le bouton le plus
 * probable qu'on ajouterait par erreur. Un administrateur qui a perdu le lien
 * révoque et recrée ; l'écran le dit, au moment où ça compte.
 *
 * **2. Le lien s'affiche tel quel, pour copie manuelle.** Il est bâti sur le
 * `Host` de la requête et porte le jeton **dans le chemin**
 * (`/inscription/<jeton>`) — c'est la forme que le serveur émet réellement, je
 * l'ai vérifiée sur l'API. On ne le reconstruit pas : le `tokenPrefix` ne fait
 * que 8 caractères sur 43, et une URL recomposée serait un lien mort.
 *
 * Les deux chemins de remise coexistent, comme le dossier l'exige : copie
 * manuelle **et** envoi par courriel. Un envoi qui échoue ne perd pas
 * l'invitation — elle est créée quand même, `courrielEnvoye` vaut `false`, et
 * le lien reste affiché (§ 3.1).
 *
 * Le pré-lien (`personId`) est le **cas nominal** : le candidat n'aura qu'à
 * confirmer une date de naissance. Sans lui, il passera par la recherche floue,
 * qui est plus fragile pour lui et plus exposée pour l'arbre.
 */
import { computed, onMounted, ref } from 'vue'
import {
  creerInvitation,
  listerInvitations,
  rechercherPersonnes,
  revoquerInvitation,
  type Invitation,
  type InvitationCreee,
  type ResultatDeRecherche,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import BarreEcran from '@/components/mise-en-page/BarreEcran.vue'
import OngletsAdministration from './OngletsAdministration.vue'
import LienInvitation from '@/components/administration/LienInvitation.vue'
import { ecrituresSuspendues, infobulleSuspension, motifSuspension } from '@/components/administration/ecriture'

const { vueEmpruntee } = useSession()

const invitations = ref<Invitation[]>([])
const chargement = ref(false)
const erreur = ref<unknown>(null)

/** L'invitation qui vient d'être créée : le seul instant où le lien existe. */
const creee = ref<InvitationCreee | null>(null)

const courriel = ref('')
const note = ref('')
const envoyerCourriel = ref(false)
const personneChoisie = ref<ResultatDeRecherche | null>(null)
const recherche = ref('')
const resultats = ref<ResultatDeRecherche[]>([])
const envoi = ref(false)

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const raisonSuspension = computed(() => motifSuspension(vueEmpruntee.value))
const infobulle = computed(() => infobulleSuspension(vueEmpruntee.value))

const PASTILLES: Record<string, 'actif' | 'en_attente' | 'refusee' | 'neutre'> = {
  active: 'actif',
  utilisee: 'neutre',
  expiree: 'neutre',
  revoquee: 'refusee',
}

const LIBELLES: Record<string, string> = {
  active: 'Active',
  utilisee: 'Utilisée',
  expiree: 'Expirée',
  revoquee: 'Révoquée',
}

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    invitations.value = (await listerInvitations({ parPage: 100 })).elements
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

onMounted(charger)

async function chercherPersonne(): Promise<void> {
  // Le serveur exige deux caractères ; ne pas appeler avant est à l'appelant.
  if (recherche.value.trim().length < 2) {
    resultats.value = []
    return
  }
  try {
    resultats.value = (await rechercherPersonnes(recherche.value.trim(), 8)).elements
  } catch (cause) {
    erreur.value = cause
  }
}

function choisir(personne: ResultatDeRecherche): void {
  personneChoisie.value = personne
  resultats.value = []
  recherche.value = ''
}

async function creer(): Promise<void> {
  if (suspendu.value || envoi.value) return

  envoi.value = true
  erreur.value = null
  try {
    creee.value = await creerInvitation({
      personId: personneChoisie.value?.personId,
      email: courriel.value.trim().length > 0 ? courriel.value.trim() : undefined,
      envoyerCourriel: envoyerCourriel.value,
      note: note.value.trim().length > 0 ? note.value.trim() : undefined,
    })
    courriel.value = ''
    note.value = ''
    envoyerCourriel.value = false
    personneChoisie.value = null
    await charger()
  } catch (cause) {
    erreur.value = cause
  } finally {
    envoi.value = false
  }
}

async function revoquer(invitation: Invitation): Promise<void> {
  if (suspendu.value) return
  erreur.value = null
  try {
    await revoquerInvitation(invitation.id)
    await charger()
  } catch (cause) {
    erreur.value = cause
  }
}

function dateLisible(valeur: string | null): string {
  if (valeur === null) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <!-- La barre d'écran de l'administration : les trois sections en onglets,
       et rien d'autre ici — pas de filtre, donc pas de feuillet ni de bouton
       filtres sur téléphone. Voir `OngletsAdministration`. -->
  <BarreEcran ecran="Administration" :reinitialisable="false">
    <template #vue><OngletsAdministration /></template>
  </BarreEcran>

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-6">
    <p
      v-if="raisonSuspension !== null"
      class="rounded-panneau border border-accent bg-accent-doux px-3.5 py-3 text-sm text-accent"
      role="status"
    >
      {{ raisonSuspension }}
    </p>

    <!-- ---------------------------------------------------------------- -->
    <!-- Le lien fraîchement créé — le seul moment où il existe.           -->
    <!-- ---------------------------------------------------------------- -->
    <LienInvitation v-if="creee !== null" :invitation="creee" @fermer="creee = null" />

    <PanneauBase titre="Inviter quelqu’un">
      <form novalidate @submit.prevent="creer">
        <!-- Le pré-lien : cas nominal. Le candidat n'aura qu'à confirmer une
             date de naissance, au lieu de passer par la recherche floue. -->
        <div class="flex flex-col gap-1">
          <label for="recherche-personne" class="text-sm font-medium">
            Personne de l’arbre (recommandé)
          </label>
          <p id="recherche-personne-aide" class="text-xs text-attenue">
            Si vous désignez la personne ici, l’invité n’aura qu’à confirmer sa date de
            naissance. Sinon, il devra se décrire et le serveur cherchera — c’est plus
            fragile pour lui.
          </p>

          <div v-if="personneChoisie !== null" class="flex flex-wrap items-center gap-2">
            <span class="rounded-champ border border-accent bg-accent-doux px-2.5 py-1 text-sm">
              {{ personneChoisie.displayName }}
            </span>
            <BoutonBase variante="discret" @click="personneChoisie = null">Changer</BoutonBase>
          </div>

          <template v-else>
            <input
              id="recherche-personne"
              v-model="recherche"
              type="search"
              aria-describedby="recherche-personne-aide"
              placeholder="Nom ou prénom"
              class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
              @input="chercherPersonne"
            />
            <ul v-if="resultats.length > 0" class="mt-1 flex flex-col gap-1">
              <li v-for="personne in resultats" :key="personne.personId">
                <button
                  type="button"
                  class="w-full rounded-champ border border-ligne px-2.5 py-1.5 text-left text-sm hover:border-accent"
                  @click="choisir(personne)"
                >
                  {{ personne.displayName }}
                  <span v-if="personne.ligneDates" class="text-attenue">
                    — {{ personne.ligneDates }}
                  </span>
                </button>
              </li>
            </ul>
          </template>
        </div>

        <div class="mt-3.5 flex flex-col gap-3.5">
          <ChampTexte
            v-model="courriel"
            etiquette="Adresse de courriel"
            type="email"
            aide="Nécessaire seulement si vous demandez l’envoi automatique."
            autocomplete="off"
          />

          <ChampTexte
            v-model="note"
            etiquette="Note (facultatif)"
            aide="Un aide-mémoire pour vous : « la cousine de Saule ». L’invité ne la voit pas."
          />

          <label class="flex items-center gap-2 text-sm">
            <input v-model="envoyerCourriel" type="checkbox" class="accent-accent" />
            Envoyer l’invitation par courriel
          </label>
        </div>

        <div class="mt-4">
          <BoutonBase type="submit" :chargement="envoi" :desactive="suspendu" :title="infobulle">
            Créer l’invitation
          </BoutonBase>
        </div>
      </form>
    </PanneauBase>

    <MessageErreur v-if="erreur !== null" :erreur="erreur" @action="charger" />

    <!-- ---------------------------------------------------------------- -->
    <!-- La liste                                                          -->
    <!-- ---------------------------------------------------------------- -->
    <PanneauBase titre="Invitations envoyées">
      <SqueletteChargement v-if="chargement && invitations.length === 0" :lignes="3" />

      <p v-else-if="invitations.length === 0" class="text-sm text-attenue">
        Aucune invitation pour l’instant.
      </p>

      <ul v-else class="flex flex-col divide-y divide-ligne">
        <li v-for="invitation in invitations" :key="invitation.id" class="py-2.5 first:pt-0 last:pb-0">
          <div class="flex flex-wrap items-center gap-2">
            <PastilleEtat
              :etat="PASTILLES[invitation.status] ?? 'neutre'"
              :libelle="LIBELLES[invitation.status] ?? invitation.status"
            />
            <span class="font-mono text-sm">{{ invitation.tokenPrefix }}…</span>
            <span v-if="invitation.personLabel" class="text-sm font-medium">
              {{ invitation.personLabel }}
            </span>
            <span v-if="invitation.email" class="text-sm text-attenue">{{ invitation.email }}</span>

            <BoutonBase
              v-if="invitation.status === 'active'"
              variante="discret"
              :desactive="suspendu"
              :title="infobulle"
              @click="revoquer(invitation)"
            >
              Révoquer
            </BoutonBase>
          </div>

          <p class="mt-1 text-xs text-attenue">
            Créée le {{ dateLisible(invitation.createdAt) }} par {{ invitation.createdByEmail }},
            expire le {{ dateLisible(invitation.expiresAt) }}.
            <template v-if="invitation.note"> — {{ invitation.note }}</template>
          </p>

          <!-- Les tentatives de rattachement de l'heure écoulée : c'est ce qui
               permet de voir qu'un lien est en train d'être sondé. Les critères
               soumis, eux, ne sont jamais rendus — et n'ont pas à l'être. -->
          <p v-if="invitation.tentatives > 0" class="mt-1 text-xs text-alerte">
            {{ invitation.tentatives }} tentative{{ invitation.tentatives > 1 ? 's' : '' }} de
            rattachement dans la dernière heure.
          </p>
        </li>
      </ul>
    </PanneauBase>
  </div>
</template>
