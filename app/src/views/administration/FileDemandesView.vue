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
 * Depuis le 2026-10-04, la file s'affiche **à plat, par numéro** — chaque ligne
 * porte « n° 42 » —, en **onglets** par nature (Toutes, Modifications, Nouvelles
 * personnes, Liens), avec une **recherche** qui accepte un numéro ou un texte
 * (fiche, auteur, message, nom d'une personne proposée). Une création nomme les
 * personnes qu'elle ajoute. Le regroupement par fiche a disparu ; ce qu'il
 * protégeait reste dit sur la ligne : « 2 autres demandes sur cette fiche ».
 * Voir `file.ts`.
 *
 * Le filtre par état est volontairement ouvert au-delà de `en_attente` : une
 * demande **caduque** ou **refusée** reste consultable, et c'est ce qui permet
 * de répondre à quelqu'un qui demande ce qu'est devenue sa proposition.
 *
 * ────────────────────────────────────────────────────────────────────
 * **L'historique** (handoff « mode Administration », § 4.6). Les demandes en
 * attente se décident désormais dans « À traiter » ; cet écran garde la file
 * complète, pour consulter ce qui a été traité. Le nom de route
 * (`administration.demandes`) est conservé : c'est un contrat.
 *
 * L'état, l'auteur et le tri passent derrière **un seul bouton** « {État} ▾ »,
 * avec une pastille quand un filtre s'écarte du défaut. Il ouvre un feuillet :
 * puces d'état, puces « Déposées par », segmenté « Trier », puis
 * « Réinitialiser » et « Voir N demandes ». Les natures restent en puces
 * défilantes au-dessus de la liste, la recherche à côté du bouton.
 *
 * L'écran ne délègue rien : `etat`, `auteur` et `tri` vivent ici.
 * ────────────────────────────────────────────────────────────────────
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { definirResume } from '@/composables/entete'
import {
  listerAuteursDesDemandes,
  listerDemandesAdministration,
  type AuteurDeLaFile,
  type Demande,
  type StatutDemande,
} from '@/lib/api'
import BoutonBase from '@/components/base/BoutonBase.vue'
import Feuillet from '@/components/base/Feuillet.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import ApercuFiche from '@/components/administration/ApercuFiche.vue'
import LoupeMedia from '@/components/medias/LoupeMedia.vue'
import Segmente from '@/components/mise-en-page/Segmente.vue'
import type { CibleLoupe } from '@/components/medias/medias'
import {
  CONTEXTE_PAR_DEFAUT,
  ONGLETS,
  autresSurLaMemeFiche,
  contexteDepuisRequete,
  ligneAuteur,
  natureDeLOnglet,
  nouvellesPersonnes,
  personneDeLaDemande,
  requeteDeContexte,
  trierDemandes,
  type OngletDeFile,
  type SensDeTri,
} from '@/components/administration/file'
import { LIBELLES_NATURE } from '@/components/proposition/mes-demandes'
import { annonceEnchainement, issueDepuisRequete } from '@/components/administration/decision'

/** Les états proposés au filtre. `''` veut dire « tous ». `court` est le
 *  libellé du segmenté de bureau, où la place est comptée. */
const ETATS: { valeur: StatutDemande | ''; libelle: string; court: string }[] = [
  { valeur: 'en_attente', libelle: 'En attente', court: 'En attente' },
  { valeur: 'validee', libelle: 'Validées', court: 'Validées' },
  { valeur: 'refusee', libelle: 'Refusées', court: 'Refusées' },
  { valeur: 'caduque', libelle: 'Devenues caduques', court: 'Caduques' },
  { valeur: '', libelle: 'Toutes', court: 'Toutes' },
]

/** Les deux sens de tri — par numéro —, pour le bouton de bureau et le segmenté du feuillet. */
const TRIS: { valeur: SensDeTri; libelle: string }[] = [
  { valeur: 'anciennes', libelle: 'N° croissant' },
  { valeur: 'recentes', libelle: 'N° décroissant' },
]

/** Les libellés de nature, partagés avec « Mes demandes » : une seule table, complète
 *  par construction (`Record<NatureDemande, string>`). */
const NATURES: Record<string, string> = LIBELLES_NATURE

const PASTILLES = ['en_attente', 'validee', 'refusee', 'caduque'] as const

/** Les deux statuts que `PastilleEtat` ne connaît pas. Ils passent en pastille
 *  neutre **avec leur libellé** : sans lui, la pastille s'afficherait vide, ce
 *  qui se lit comme un défaut d'affichage plutôt que comme un état. La valeur
 *  reste celle du modèle, jamais traduite (§ 1.2). */
const LIBELLES_HORS_PASTILLE: Record<string, string> = {
  brouillon: 'Brouillon',
  annulee: 'Annulée par son auteur',
}

const route = useRoute()

/** Le filtre et le tri de départ : ceux de l'adresse, quand on revient d'une
 *  demande par « & suivant » en fin de file ; le défaut sinon. */
const depart = contexteDepuisRequete(route.query)

/** « Demande n° 12 validée. Il n'y a plus de demande en attente », au retour
 *  d'un enchaînement qui a épuisé la file. */
const annonceDeFin = computed(() => {
  const precedente = Number(route.query['precedente'])
  const issue = issueDepuisRequete(route.query['issue'])
  return Number.isInteger(precedente) && precedente > 0 && issue !== null
    ? annonceEnchainement(precedente, issue, true)
    : null
})

const etat = ref<StatutDemande | ''>(depart.etat)
/** Le filtre par demandeur. `0` vaut « tout le monde », la même convention que
 *  le serveur : un `authorUserId` est toujours strictement positif. */
const auteur = ref(depart.auteur)
/** Les auteurs qui ont réellement déposé. Servie par la file elle-même, et non
 *  par la liste des utilisateurs : un sélecteur où presque toutes les entrées ne
 *  donnent aucun résultat est un sélecteur qu'on n'ouvre plus. */
const auteurs = ref<readonly AuteurDeLaFile[]>([])
/** Le défaut : le plus petit numéro en premier — c'est aussi le plus ancien dépôt. */
const tri = ref<SensDeTri>(depart.tri)
/** L'onglet : les natures affichées. */
const onglet = ref<OngletDeFile>(depart.onglet)
/** Ce que l'administrateur tape ; `recherche` en est la version posée, après une
 *  courte pause, pour ne pas interroger le serveur à chaque touche. */
const saisie = ref(depart.recherche)
const recherche = ref(depart.recherche)
let minuterie = 0
watch(saisie, (valeur) => {
  window.clearTimeout(minuterie)
  minuterie = window.setTimeout(() => (recherche.value = valeur.trim()), 300)
})
onBeforeUnmount(() => window.clearTimeout(minuterie))

/** Ce que la demande ouverte emporte de la file, pour que « & suivant »
 *  enchaîne dans la liste qu'on a sous les yeux. */
const requeteDeLaFile = computed(() =>
  requeteDeContexte({
    etat: etat.value,
    auteur: auteur.value,
    tri: tri.value,
    onglet: onglet.value,
    recherche: recherche.value,
  }),
)
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

/** La file à plat, par numéro. */
const liste = computed(() => trierDemandes(demandes.value, tri.value))
/** Pour chaque demande en attente, les autres en attente sur la même fiche. */
const autres = computed(() => autresSurLaMemeFiche(demandes.value))

function pastille(statut: StatutDemande): (typeof PASTILLES)[number] | 'neutre' {
  return (PASTILLES as readonly string[]).includes(statut)
    ? (statut as (typeof PASTILLES)[number])
    : 'neutre'
}

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  try {
    const page = await listerDemandesAdministration({
      status: etat.value === '' ? undefined : etat.value,
      auteur: auteur.value === 0 ? undefined : auteur.value,
      kind: natureDeLOnglet(onglet.value),
      q: recherche.value,
      parPage: 100,
    })
    demandes.value = page.elements
    total.value = page.total
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
watch([etat, auteur, onglet, recherche], charger)

/* ------------------------------------------------------------------ */
/* Le feuillet des filtres                                             */
/* ------------------------------------------------------------------ */

const filtresOuverts = ref(false)

/** Le libellé du bouton : l'état affiché. */
const libelleEtat = computed(() => ETATS.find((choix) => choix.valeur === etat.value)?.court ?? 'Toutes')

/** Ce qui s'écarte du défaut dans le feuillet : l'état, l'auteur, le tri. */
const filtresActifs = computed(
  () =>
    (etat.value === CONTEXTE_PAR_DEFAUT.etat ? 0 : 1) +
    (auteur.value === 0 ? 0 : 1) +
    (tri.value === CONTEXTE_PAR_DEFAUT.tri ? 0 : 1),
)

/** « Réinitialiser » du feuillet : en attente, tout le monde, le plus petit numéro d'abord. */
function reinitialiser(): void {
  etat.value = CONTEXTE_PAR_DEFAUT.etat
  auteur.value = 0
  tri.value = CONTEXTE_PAR_DEFAUT.tri
}

const libelleTotal = computed(() => `${total.value} demande${total.value > 1 ? 's' : ''}`)

// Le résumé sous le titre, dans l'en-tête du téléphone.
watch(
  [total, chargement, erreur, etat],
  () => {
    if (chargement.value || erreur.value !== null) {
      definirResume('')
      return
    }
    const n = total.value
    definirResume(
      etat.value === 'en_attente' ? `${n} demande${n > 1 ? 's' : ''} à décider` : libelleTotal.value,
    )
  },
  { immediate: true },
)

function dateLisible(valeur: string | null): string {
  if (valeur === null) return ''
  const date = new Date(valeur)
  return Number.isNaN(date.getTime())
    ? valeur
    : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2">
      <label class="flex min-h-11 min-w-0 flex-[1_1_240px] items-center gap-2 rounded-champ border border-ligne bg-panneau px-3 text-sm md:min-h-9">
        <span class="sr-only">Rechercher une demande</span>
        <span aria-hidden="true" class="text-attenue">⌕</span>
        <input
          id="recherche-file"
          v-model="saisie"
          type="search"
          inputmode="search"
          placeholder="N° de demande, nom, auteur…"
          class="h-8 min-w-0 flex-1 bg-transparent text-sm focus:outline-none"
        />
      </label>
      <!-- L'état, l'auteur et le tri, derrière un seul bouton. -->
      <button
        type="button"
        class="flex min-h-11 items-center gap-2 rounded-champ border bg-panneau px-3 text-[13.5px] md:min-h-9"
        :class="filtresActifs > 0 ? 'border-accent' : 'border-ligne'"
        aria-haspopup="dialog"
        data-ouvrir-filtres
        @click="filtresOuverts = true"
      >
        <span>{{ libelleEtat }}</span>
        <span
          v-if="filtresActifs > 0"
          class="flex h-[18px] min-w-[18px] items-center justify-center rounded-pastille bg-accent px-[5px] text-[11px] font-bold text-fond"
          :aria-label="`${filtresActifs} filtre${filtresActifs > 1 ? 's' : ''} actif${filtresActifs > 1 ? 's' : ''}`"
        >{{ filtresActifs }}</span>
        <span class="text-attenue" aria-hidden="true">▾</span>
      </button>
    </div>

    <div class="puces-defilantes flex gap-2 overflow-x-auto overflow-y-hidden py-0.5" role="tablist" aria-label="Nature des demandes">
      <button
        v-for="o in ONGLETS"
        :key="o.valeur"
        type="button"
        role="tab"
        class="flex min-h-11 flex-none items-center rounded-pastille border px-[13px] text-[13px] whitespace-nowrap md:min-h-9"
        :class="o.valeur === onglet ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
        :aria-selected="o.valeur === onglet"
        @click="onglet = o.valeur"
      >
        {{ o.libelle }}
      </button>
    </div>

    <span v-if="!chargement" class="text-[13px] text-attenue">{{ libelleTotal }}</span>

    <p
      v-if="annonceDeFin !== null"
      class="rounded-panneau border border-ok-bord bg-ok-doux px-3.5 py-3 text-sm"
      role="status"
    >
      {{ annonceDeFin }}
    </p>

    <PanneauBase v-if="chargement && demandes.length === 0">
      <p class="mb-3 text-sm text-attenue" role="status">Chargement de la file…</p>
      <SqueletteChargement :lignes="4" />
    </PanneauBase>

    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

    <p
      v-else-if="liste.length === 0"
      class="rounded-panneau border border-ligne bg-panneau px-4 py-7 text-center text-attenue"
    >
      {{
        recherche !== ''
          ? 'Aucune demande ne correspond à cette recherche.'
          : etat === 'en_attente' && onglet === 'toutes'
            ? 'Rien n’attend de décision.'
            : 'Aucune demande ne correspond à ce filtre.'
      }}
    </p>

    <ul v-else class="m-0 flex list-none flex-col gap-px overflow-hidden rounded-panneau border border-ligne bg-ligne p-0">
      <li v-for="demande in liste" :key="demande.id" class="bg-panneau px-4 py-3">
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
          <span class="font-mono text-[12.5px] text-attenue tabular-nums">n° {{ demande.id }}</span>
          <RouterLink
            :to="{ name: 'demande.detail', params: { id: demande.id }, query: requeteDeLaFile }"
            class="font-semibold text-accent underline"
          >
            {{ NATURES[demande.kind] ?? demande.kind }}
          </RouterLink>
          <PastilleEtat
            :etat="pastille(demande.status)"
            :libelle="LIBELLES_HORS_PASTILLE[demande.status] ?? ''"
          />
          <span v-if="demande.targetLabel" class="text-sm">{{ demande.targetLabel }}</span>
          <!-- Voir qui est visé sans perdre le filtre, le tri, ni sa place dans la file. -->
          <button
            v-if="personneDeLaDemande(demande) !== null"
            type="button"
            class="cursor-pointer border-0 bg-transparent p-0 text-xs text-accent underline"
            @click="apercu = personneDeLaDemande(demande)"
          >
            Voir la fiche
          </button>
        </div>

        <!-- Les personnes que la demande ajoute à l'arbre, par leur nom. -->
        <p v-if="nouvellesPersonnes(demande) !== ''" class="mt-1 text-sm">
          <span class="text-attenue">Nouvelle{{ (demande.nouvellesPersonnes?.length ?? 0) > 1 ? 's' : '' }} personne{{ (demande.nouvellesPersonnes?.length ?? 0) > 1 ? 's' : '' }} :</span>
          {{ nouvellesPersonnes(demande) }}
        </p>

        <!-- Composée dans `file.ts` : la virgule se colle au nom, et aucun
             blanc de gabarit ne peut l’en détacher. -->
        <p class="mt-0.5 text-[13px] text-attenue">
          {{ ligneAuteur(demande.authorLabel, dateLisible(demande.submittedAt)) }}
        </p>

        <!-- Ce que le regroupement par fiche montrait : l'ordre de décision compte. -->
        <p v-if="autres.get(demande.id)" class="mt-0.5 text-[12.5px] text-alerte">
          {{ autres.get(demande.id) }} autre{{ (autres.get(demande.id) ?? 0) > 1 ? 's' : '' }} demande{{ (autres.get(demande.id) ?? 0) > 1 ? 's' : '' }} en attente sur cette fiche : décidez-les ensemble
        </p>

        <p v-if="demande.message" class="mt-1 line-clamp-2 text-[13px] text-attenue">
          « {{ demande.message }} »
        </p>
      </li>
    </ul>

    <!-- Déclarée après la file, donc peinte par-dessus. La loupe vient ensuite,
         pour passer par-dessus l'aperçu lui-même. -->
    <ApercuFiche :personne-id="apercu" @fermer="apercu = null" @agrandir="loupe = $event" />
  </div>

  <LoupeMedia :cible="loupe" @fermer="loupe = null" />

  <Feuillet :ouvert="filtresOuverts" titre="Filtrer l’historique" @fermer="filtresOuverts = false">
    <span class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">État</span>
    <div class="flex flex-wrap gap-2" role="group" aria-label="État des demandes affichées">
      <button
        v-for="choix in ETATS"
        :key="choix.libelle"
        type="button"
        class="flex min-h-11 items-center rounded-pastille border px-[13px] text-[13px] md:min-h-9"
        :class="choix.valeur === etat ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
        :aria-pressed="choix.valeur === etat"
        :data-etat="choix.valeur === '' ? 'toutes' : choix.valeur"
        @click="etat = choix.valeur"
      >
        {{ choix.libelle }}
      </button>
    </div>

    <span class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Déposées par</span>
    <div class="flex flex-wrap gap-2" role="group" aria-label="Auteur des demandes affichées">
      <button
        v-for="a in [{ userId: 0, libelle: 'Tout le monde' }, ...auteurs]"
        :key="a.userId"
        type="button"
        class="flex min-h-11 items-center rounded-pastille border px-[13px] text-[13px] md:min-h-9"
        :class="a.userId === auteur ? 'border-accent bg-accent-doux font-semibold text-texte' : 'border-ligne bg-panneau text-attenue'"
        :aria-pressed="a.userId === auteur"
        :data-auteur="a.userId"
        @click="auteur = a.userId"
      >
        {{ a.libelle }}
      </button>
    </div>

    <span class="text-[11px] font-semibold tracking-[.08em] text-attenue uppercase">Trier</span>
    <Segmente :options="TRIS" :valeur="tri" etiquette="Ordre de la file" :colonnes="2" @choisir="tri = $event" />

    <div class="flex gap-2">
      <BoutonBase variante="secondaire" class="min-h-11 flex-1" @click="reinitialiser">Réinitialiser</BoutonBase>
      <BoutonBase class="min-h-11 flex-[2]" @click="filtresOuverts = false">Voir {{ libelleTotal }}</BoutonBase>
    </div>
  </Feuillet>
</template>
