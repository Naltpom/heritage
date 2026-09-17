<script setup lang="ts">
/**
 * La file de validation — § 6.7.
 *
 * Ce qui attend une décision : modifications de fiche, créations de personne,
 * ajouts de lien, ajouts de document. **Un seul mécanisme**, donc une seule
 * file : « deux routes seraient deux écrans, deux formats de diff, deux chemins
 * de notification, et deux endroits où oublier le message obligatoire au
 * refus » (ADR 0004).
 *
 * Le serveur trie par date de soumission **croissante** — une file se prend par
 * le début — et le front **regroupe par fiche visée**. Ce regroupement n'est pas
 * décoratif : deux demandes sur la même personne s'influencent, valider la
 * première peut rendre la seconde caduque, et les traiter dans deux écrans
 * séparés fait découvrir la caducité après le clic. Voir `file.ts`.
 *
 * Le filtre par état est volontairement ouvert au-delà de `en_attente` : une
 * demande **caduque** ou **refusée** reste consultable, et c'est ce qui permet
 * de répondre à quelqu'un qui demande ce qu'est devenue sa proposition.
 */
import { computed, onMounted, ref, watch } from 'vue'
import {
  listerAuteursDesDemandes,
  listerDemandesAdministration,
  type AuteurDeLaFile,
  type Demande,
  type StatutDemande,
} from '@/lib/api'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import ApercuFiche from '@/components/administration/ApercuFiche.vue'
import LoupeMedia from '@/components/medias/LoupeMedia.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import {
  grouperParCible,
  ligneAuteur,
  personneDuGroupe,
  trierDemandes,
  type SensDeTri,
} from '@/components/administration/file'

/** Les états proposés au filtre. `''` veut dire « tous ». */
const ETATS: { valeur: StatutDemande | ''; libelle: string }[] = [
  { valeur: 'en_attente', libelle: 'En attente' },
  { valeur: 'validee', libelle: 'Validées' },
  { valeur: 'refusee', libelle: 'Refusées' },
  { valeur: 'caduque', libelle: 'Devenues caduques' },
  { valeur: '', libelle: 'Toutes' },
]

const NATURES: Record<string, string> = {
  modification_personne: 'Modification',
  modification_famille: 'Modification de famille',
  modification_citation: 'Modification de source',
  creation_personne: 'Ajout d’une personne',
  ajout_lien: 'Ajout d’un lien',
  ajout_media: 'Ajout d’un document',
}

const PASTILLES = ['en_attente', 'validee', 'refusee', 'caduque'] as const

/** Les deux statuts que `PastilleEtat` ne connaît pas. Ils passent en pastille
 *  neutre **avec leur libellé** : sans lui, la pastille s'afficherait vide, ce
 *  qui se lit comme un défaut d'affichage plutôt que comme un état. La valeur
 *  reste celle du modèle, jamais traduite (§ 1.2). */
const LIBELLES_HORS_PASTILLE: Record<string, string> = {
  brouillon: 'Brouillon',
  annulee: 'Retirée par son auteur',
}

const etat = ref<StatutDemande | ''>('en_attente')
/** Le filtre par demandeur. `0` vaut « tout le monde », la même convention que
 *  le serveur : un `authorUserId` est toujours strictement positif. */
const auteur = ref(0)
/** Les auteurs qui ont réellement déposé. Servie par la file elle-même, et non
 *  par la liste des utilisateurs : un sélecteur où presque toutes les entrées ne
 *  donnent aucun résultat est un sélecteur qu'on n'ouvre plus. */
const auteurs = ref<readonly AuteurDeLaFile[]>([])
/** Le défaut reprend l'ordre du serveur : la plus ancienne en premier. */
const tri = ref<SensDeTri>('anciennes')
const demandes = ref<Demande[]>([])
const total = ref(0)
const chargement = ref(false)
const erreur = ref<unknown>(null)

/** La fiche en aperçu. `null` ferme la modale — un identifiant, pas un booléen
 *  plus un identifiant, pour qu'« ouverte sur personne » soit inreprésentable. */
const apercu = ref<number | null>(null)
/** Le portrait en grand, si on le demande depuis l'aperçu. Une seule image
 *  d'origine décodée à la fois : la loupe est tenue ici. */
const loupe = ref<CibleLoupe | null>(null)

// Trier **puis** grouper : le regroupement conserve l'ordre qu'on lui donne, et
// c'est ce qui fait que changer le tri réordonne aussi les groupes entre eux.
const groupes = computed(() => grouperParCible(trierDemandes(demandes.value, tri.value)))

function pastille(statut: StatutDemande): (typeof PASTILLES)[number] | 'neutre' {
  return (PASTILLES as readonly string[]).includes(statut)
    ? (statut as (typeof PASTILLES)[number])
    : 'neutre'
}

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const liste = await listerDemandesAdministration({
      status: etat.value === '' ? undefined : etat.value,
      auteur: auteur.value === 0 ? undefined : auteur.value,
      parPage: 100,
    })
    demandes.value = liste.elements
    total.value = liste.total
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

/**
 * Les auteurs se chargent **une fois**, au montage, et par leur propre route.
 *
 * Leur échec ne remonte pas dans `erreur` : sans eux le sélecteur reste sur
 * « tout le monde » et la file fonctionne entièrement. Remplacer l'écran par un
 * message d'erreur parce qu'un filtre facultatif n'a pas pu se peupler serait
 * hors de proportion — même motif que le chargement secondaire de
 * `MesDemandesView`.
 */
async function chargerLesAuteurs(): Promise<void> {
  try {
    auteurs.value = (await listerAuteursDesDemandes()).elements
  } catch {
    auteurs.value = []
  }
}

onMounted(() => {
  void charger()
  void chargerLesAuteurs()
})
watch([etat, auteur], charger)

function dateLisible(valeur: string | null): string {
  if (valeur === null) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <label for="filtre-etat" class="text-sm font-medium">Afficher</label>
      <select
        id="filtre-etat"
        v-model="etat"
        class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
      >
        <option v-for="choix in ETATS" :key="choix.libelle" :value="choix.valeur">
          {{ choix.libelle }}
        </option>
      </select>

      <label for="filtre-auteur" class="ml-2 text-sm font-medium">Déposées par</label>
      <select
        id="filtre-auteur"
        v-model.number="auteur"
        class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
      >
        <option :value="0">Tout le monde</option>
        <option v-for="a in auteurs" :key="a.userId" :value="a.userId">
          {{ a.libelle }}
        </option>
      </select>

      <label for="tri-file" class="ml-2 text-sm font-medium">Trier</label>
      <select
        id="tri-file"
        v-model="tri"
        class="rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
      >
        <option value="anciennes">Les plus anciennes d’abord</option>
        <option value="recentes">Les plus récentes d’abord</option>
      </select>

      <span v-if="!chargement" class="text-sm text-attenue tnum">
        {{ total }} demande{{ total > 1 ? 's' : '' }}
      </span>
    </div>

    <PanneauBase v-if="chargement && demandes.length === 0">
      <p class="mb-3 text-sm text-attenue" role="status">Chargement de la file…</p>
      <SqueletteChargement :lignes="4" />
    </PanneauBase>

    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

    <PanneauBase v-else-if="groupes.length === 0">
      <p class="text-sm text-attenue">
        {{
          etat === 'en_attente'
            ? 'Rien n’attend de décision. La file est vide.'
            : 'Aucune demande ne correspond à ce filtre.'
        }}
      </p>
    </PanneauBase>

    <div v-else class="flex flex-col gap-4">
      <PanneauBase v-for="groupe in groupes" :key="groupe.cle">
        <template #entete>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
              <h2 class="text-sm font-semibold tracking-tight">{{ groupe.titre }}</h2>
              <!-- Voir qui est visé sans perdre le filtre, le tri, ni sa place
                   dans la file : revenir en arrière rechargerait tout. -->
              <button
                v-if="personneDuGroupe(groupe) !== null"
                type="button"
                class="cursor-pointer border-0 bg-transparent p-0 text-xs text-accent underline"
                @click="apercu = personneDuGroupe(groupe)"
              >
                Voir la fiche
              </button>
            </div>
            <!-- Deux demandes sur la même fiche : l'ordre de décision compte,
                 et le dire ici évite de le découvrir en cliquant. -->
            <span v-if="!groupe.sansCible && groupe.demandes.length > 1" class="text-xs text-alerte">
              {{ groupe.demandes.length }} demandes sur cette fiche — décidez-les ensemble
            </span>
          </div>
        </template>

        <ul class="flex flex-col divide-y divide-ligne">
          <li v-for="demande in groupe.demandes" :key="demande.id" class="py-2.5 first:pt-0 last:pb-0">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              <RouterLink
                :to="{ name: 'demande.detail', params: { id: demande.id } }"
                class="font-semibold text-accent underline"
              >
                {{ NATURES[demande.kind] ?? demande.kind }}
              </RouterLink>
              <PastilleEtat
                :etat="pastille(demande.status)"
                :libelle="LIBELLES_HORS_PASTILLE[demande.status] ?? ''"
              />
              <!-- Composée dans `file.ts` : la virgule se colle au nom, et aucun
                   blanc de gabarit ne peut l’en détacher. -->
              <span class="text-sm text-attenue">
                {{ ligneAuteur(demande.authorLabel, dateLisible(demande.submittedAt)) }}
              </span>
            </div>

            <p v-if="demande.message" class="mt-1 line-clamp-2 text-sm text-attenue">
              {{ demande.message }}
            </p>
          </li>
        </ul>
      </PanneauBase>
    </div>

    <!-- Déclarée après la file, donc peinte par-dessus. La loupe vient ensuite,
         pour passer par-dessus l'aperçu lui-même. -->
    <ApercuFiche :personne-id="apercu" @fermer="apercu = null" @agrandir="loupe = $event" />
  </div>

  <LoupeMedia :cible="loupe" @fermer="loupe = null" />
</template>
