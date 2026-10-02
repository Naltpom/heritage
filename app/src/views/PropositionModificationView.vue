<script setup lang="ts">
/**
 * **Proposer la modification d'une fiche** — § 6.1, § 6.2, § 6.10.
 *
 * C'est la deuxième capacité demandée par le commanditaire, dans ses mots :
 * *« une possibilité d'edit des personnes ⇒ qui crée une notification à l'admin
 * pour valider le changement »*. Un membre ne modifie donc **jamais**
 * directement : il dépose une demande qui entre dans la file de l'administrateur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Trois décisions qui se paient si on les inverse.**
 *
 * 1. **Le cliché est pris à l'ouverture, et il ne bouge plus.** `baseVersion` et
 *    `oldValue` voyagent tels que l'auteur les a **lus**, pas tels qu'ils sont
 *    au moment du clic. Le scénario réel du § 9.6 est *déposer lundi, valider
 *    vendredi* : relire la fiche à l'envoi ferait dire à la demande « je pars de
 *    ce qui existe à l'instant », et le verrou optimiste ne protégerait plus
 *    rien. C'est pour cela que l'écran garde `instantane` figé et que rien ne le
 *    rafraîchit.
 * 2. **Le calque « en attente » est signalé, jamais recopié.** Si un champ est
 *    déjà visé par une demande en cours, l'écran le dit — mais le formulaire
 *    part de la valeur **validée**. Partir de la valeur proposée déposerait une
 *    demande dont l'`oldValue` n'a jamais existé en base : caduque à coup sûr.
 * 3. **Les deux `409` ne se traitent pas pareil.** Un dépôt peut rendre
 *    `conflit` (la fiche a bougé pendant la saisie) ; une validation rendra plus
 *    tard `demande_caduque` ou `verrou_concurrent`. Le premier demande de
 *    rouvrir, le deuxième de refaire, le troisième de réessayer — et jamais
 *    l'inverse : rejouer une demande caduque **écrit deux fois**.
 * ────────────────────────────────────────────────────────────────────
 *
 * **La parenté est sur le même écran**, parce que pour le contributeur c'est
 * une valeur de la fiche comme une autre. Pour le modèle, elle ne l'est pas :
 * une demande porte **une** nature, et les liens sont une `ajout_lien`. Le
 * bouton dépose donc une ou deux demandes, selon ce qui a changé — champs
 * d'abord, liens ensuite — et un second clic après un échec partiel ne
 * redépose pas ce qui est déjà parti.
 *
 * Une fiche **restreinte** (personne vivante hors du cercle du lecteur) ne se
 * modifie pas : le lecteur n'en voit aucune valeur, il ne peut donc en proposer
 * aucune ancienne. Ce n'est pas une panne — c'est l'impasse cohérente du § 5.1,
 * et l'écran la dit en une phrase plutôt que d'afficher un formulaire vide.
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  creerDemande,
  invaliderGraphe,
  lireFichePersonne,
  type FichePersonne,
  type FichePersonneComplete,
  type OrigineReprise,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'
import PastilleEtat from '@/components/base/PastilleEtat.vue'
import SqueletteChargement from '@/components/base/SqueletteChargement.vue'
import { ecrituresSuspendues, motifSuspension } from '@/components/administration/ecriture'
import ChampDateIncertaine from '@/components/proposition/ChampDateIncertaine.vue'
import ChampsIdentite from '@/components/proposition/ChampsIdentite.vue'
import SectionLiens from '@/components/proposition/SectionLiens.vue'
import PanneauConflit from '@/components/proposition/PanneauConflit.vue'
import { etiquetteDeChamp, type ChampPersonne } from '@/components/proposition/champs'
import { conflitDeDemande, type ConflitDeDemande } from '@/components/proposition/conflit'
import { erreursParChamp } from '@/components/proposition/creation'
import { corpsAjoutLien, liensVides, nombreDeLiensProposes, type SaisieLiens } from '@/components/proposition/liens'
import {
  champsModifies,
  construireOps,
  corpsModificationPersonne,
  instantaneDeFiche,
  motifDeBlocage,
  saisieDepuisFiche,
  valeursDepuisSaisie,
  type InstantaneFiche,
  type SaisieModification,
} from '@/components/proposition/operations'
import { chargerReprise, phraseOrigine, saisieDepuisProposee } from '@/components/proposition/reprise'
import type { Avertissement } from '@/components/proposition/reprise'

const route = useRoute()
const routeur = useRouter()
const { vueEmpruntee } = useSession()

const fiche = ref<FichePersonneComplete | null>(null)
const restreinte = ref(false)
const instantane = ref<InstantaneFiche | null>(null)
const saisie = ref<SaisieModification | null>(null)
const liens = ref<SaisieLiens>(liensVides(0))
const erreursLiens = ref<Record<string, string>>({})
/** La demande de champs déjà déposée, quand celle des liens a échoué juste
 *  après : on ne la redépose pas au second clic. */
const champsDeposes = ref<number | null>(null)
const message = ref('')
const avertissements = ref<Avertissement[]>([])
/** § 6.10 — d'où vient cette demande. Sa seule fonction est de faire suivre les
 *  médias déjà téléversés vers la demande neuve. */
const origine = ref<OrigineReprise | null>(null)

const chargement = ref(false)
const envoi = ref(false)
const erreur = ref<unknown>(null)
const conflit = ref<ConflitDeDemande | null>(null)
const erreursLocales = ref<Record<string, string>>({})

const identifiant = computed(() => Number(route.params['id']))
const repriseDemandee = computed(() => {
  const brut = route.query['refaire']
  const valeur = Number(Array.isArray(brut) ? brut[0] : brut)
  return Number.isInteger(valeur) && valeur > 0 ? valeur : null
})

const suspendu = computed(() => ecrituresSuspendues(vueEmpruntee.value))
const motifSuspendu = computed(() => motifSuspension(vueEmpruntee.value))

async function charger(): Promise<void> {
  chargement.value = true
  erreur.value = null
  conflit.value = null
  try {
    const recue: FichePersonne = await lireFichePersonne(identifiant.value)

    // Discriminer sur `visibilite` **avant** de lire un champ : une fiche
    // réduite porte sept clés, et `fiche.birth` n'y existe pas (§ 11.1).
    if (recue.visibilite === 'restreinte') {
      restreinte.value = true
      fiche.value = null
      return
    }

    restreinte.value = false
    fiche.value = recue
    instantane.value = instantaneDeFiche(recue)
    saisie.value = saisieDepuisFiche(recue)
    liens.value = liensVides(recue.id)
    champsDeposes.value = null

    const demandeReprise = repriseDemandee.value
    if (demandeReprise !== null) await appliquerReprise(demandeReprise)
  } catch (cause) {
    erreur.value = cause
  } finally {
    chargement.value = false
  }
}

/**
 * Superpose les valeurs d'une demande caduque au formulaire.
 *
 * On ne reprend **que** `newValue` : `oldValue` et `baseVersion` sont
 * reconstruits depuis la fiche qu'on vient de lire. C'est la lecture de l'auteur
 * qui doit faire foi, et le corps rendu par l'API a pu être calculé sur une
 * version qu'il n'a jamais eue sous les yeux.
 */
async function appliquerReprise(demandeId: number): Promise<void> {
  const reprise = await chargerReprise(demandeId)
  if (reprise === null || reprise.kind !== 'modification_personne' || saisie.value === null) return

  message.value = reprise.message
  origine.value = reprise.origine
  avertissements.value = reprise.avertissements

  const courante = saisie.value
  for (const [brut, valeur] of Object.entries(reprise.proposees)) {
    // Le nom est rétréci **avant** le `switch` : discriminer sur une expression
    // castée laisserait `brut` en `string`, et `courante[brut]` ne compilerait
    // pas — c'est la même exigence que celle qui interdit `field: 'birthMin'`.
    const champ = brut as ChampPersonne
    switch (champ) {
      case 'birth':
        courante.naissance = saisieDepuisProposee(valeur)
        break
      case 'death':
        courante.deces = saisieDepuisProposee(valeur)
        break
      case 'sex':
        if (valeur === 'masculin' || valeur === 'feminin' || valeur === 'inconnu') courante.sex = valeur
        break
      case 'deceased':
        courante.deceased = valeur === true
        break
      case 'forcePrivacy':
        courante.forcePrivacy = valeur === true
        break
      case 'givenNames':
      case 'surname':
      case 'marriedName':
      case 'nickname':
      case 'privacyNote':
        courante[champ] = typeof valeur === 'string' ? valeur : ''
        break
    }
  }
}

watch(identifiant, charger, { immediate: true })

/* --- Ce qui changerait ------------------------------------------- */

const conversion = computed(() =>
  saisie.value === null ? null : valeursDepuisSaisie(saisie.value),
)

const ops = computed(() => {
  const cliche = instantane.value
  const valeurs = conversion.value?.valeurs ?? null
  if (cliche === null || valeurs === null) return []
  return construireOps(cliche, valeurs)
})

const modifications = computed(() => champsModifies(ops.value))
const totalLiens = computed(() => nombreDeLiensProposes(liens.value))
const aEnvoyerChamps = computed(() => champsDeposes.value === null && ops.value.length > 0)
/** `motifDeBlocage` ne parle que des champs : des liens suffisent à envoyer. */
const blocage = computed(() => (totalLiens.value > 0 && !aEnvoyerChamps.value ? null : motifDeBlocage(ops.value)))

/** Les erreurs affichées : la validation locale d'abord, le serveur ensuite —
 *  et le message du serveur l'emporte, parce que c'est la règle réellement
 *  appliquée (§ 15.1). */
const erreursAffichees = computed<Record<string, string>>(() => ({
  ...(conversion.value?.erreurs ?? {}),
  ...erreursLocales.value,
}))

/** Les champs déjà visés par une demande en attente (§ 13). On le signale : le
 *  contributeur doit savoir qu'il propose par-dessus quelqu'un d'autre. */
const champsEnAttente = computed<string[]>(() =>
  Object.keys(fiche.value?.enAttente ?? {}).map((champ) =>
    etiquetteDeChamp(champ as ChampPersonne),
  ),
)

/* --- Le dépôt ----------------------------------------------------- */

async function deposer(soumettre: boolean): Promise<void> {
  const cliche = instantane.value
  const valeurs = conversion.value?.valeurs ?? null
  if (cliche === null || valeurs === null || envoi.value) return

  envoi.value = true
  erreur.value = null
  conflit.value = null
  erreursLocales.value = {}
  erreursLiens.value = {}

  try {
    // Les liens sont validés **avant** tout dépôt : une erreur de saisie ne
    // doit pas laisser partir les champs seuls.
    const corpsLiens =
      totalLiens.value > 0 ? corpsAjoutLien(liens.value, { message: message.value, soumettre }) : null
    if (corpsLiens !== null && !corpsLiens.valide) {
      erreursLiens.value = erreursParChamp(corpsLiens.erreurs)
      return
    }

    const deposees: number[] = champsDeposes.value === null ? [] : [champsDeposes.value]

    if (aEnvoyerChamps.value) {
      const options = { message: message.value, soumettre }
      // `origine` n'est posée que si elle existe : `exactOptionalPropertyTypes`
      // interdit une clé facultative à `undefined`, et le serveur compte les clés.
      const provenance = origine.value
      const corps =
        provenance === null
          ? corpsModificationPersonne(cliche, valeurs, options)
          : corpsModificationPersonne(cliche, valeurs, { ...options, origine: provenance })
      const demande = await creerDemande(corps)
      champsDeposes.value = demande.id
      deposees.push(demande.id)
    }

    if (corpsLiens !== null) {
      const demande = await creerDemande(corpsLiens.corps)
      deposees.push(demande.id)
    }

    // Le graphe incorpore les nœuds en attente du lecteur : son `ETag` est
    // périmé dès qu'une demande naît (§ 5.2, § 13.4).
    invaliderGraphe()

    // Le suivi se lit sur `demande.detail` ; deux demandes, sur la liste.
    if (deposees.length === 1) {
      await routeur.push({ name: 'demande.detail', params: { id: deposees[0]! } })
    } else {
      await routeur.push({ name: 'demandes.miennes' })
    }
  } catch (cause) {
    const reconnu = conflitDeDemande(cause)
    if (reconnu !== null) conflit.value = reconnu
    else erreur.value = cause
  } finally {
    envoi.value = false
  }
}

function refaire(demandeId: number | null): void {
  if (demandeId === null) return
  void routeur.push({
    name: 'proposition.modification',
    params: { id: identifiant.value },
    query: { refaire: String(demandeId) },
  })
}
</script>

<template>
  <div class="mx-auto w-full max-w-3xl px-4 py-8">
    <div v-if="chargement && fiche === null && !restreinte">
      <PanneauBase>
        <p class="mb-3 text-sm text-attenue" role="status">Chargement de la fiche…</p>
        <SqueletteChargement :lignes="5" />
      </PanneauBase>
    </div>

    <MessageErreur v-else-if="erreur !== null" :erreur="erreur" @action="charger" />

    <!-- L'impasse cohérente du § 5.1, dite en une phrase. -->
    <PanneauBase v-else-if="restreinte" titre="Cette fiche ne peut pas être modifiée ici">
      <p class="text-sm">
        Ses informations ne vous sont pas visibles : demandez à un administrateur de faire la
        correction.
      </p>
    </PanneauBase>

    <template v-else-if="fiche !== null && saisie !== null">
      <header class="mb-5">
        <h1 class="text-xl font-semibold tracking-tight">
          Modifier {{ fiche.displayName }}
        </h1>
        <p class="mt-1 text-sm text-attenue">Validé par un administrateur avant d’apparaître dans l’arbre.</p>
      </header>

      <!-- La bascule « voir comme un membre » suspend toute écriture. On le dit
           plutôt que de laisser un bouton partir en 409 à chaque clic. -->
      <p
        v-if="motifSuspendu !== null"
        class="mb-4 rounded-panneau border border-alerte-bord bg-alerte-doux px-3.5 py-3 text-sm"
        role="status"
      >
        {{ motifSuspendu }}
      </p>

      <PanneauConflit
        v-if="conflit !== null"
        :conflit="conflit"
        class="mb-4"
        @reessayer="deposer(true)"
        @refaire="refaire"
      />

      <!-- Une référence disparue a été retirée de la reprise par le serveur :
           sans ce bandeau, on redéposerait une demande amputée sans le voir. -->
      <PanneauBase v-if="avertissements.length > 0" titre="À relire avant d’envoyer" class="mb-4">
        <ul class="list-disc space-y-1 pl-5 text-sm">
          <li v-for="(avertissement, index) in avertissements" :key="index">
            {{ avertissement.message }}
          </li>
        </ul>
      </PanneauBase>

      <p
        v-if="origine !== null"
        class="mb-4 rounded-panneau border border-ligne bg-panneau px-3.5 py-3 text-sm"
      >
        {{ phraseOrigine(origine) }}
      </p>

      <!-- Le calque est **signalé**, jamais recopié dans les champs. -->
      <PanneauBase v-if="champsEnAttente.length > 0" class="mb-4">
        <p class="flex flex-wrap items-center gap-2 text-sm">
          <PastilleEtat etat="en_attente" />
          <span>Déjà proposé : {{ champsEnAttente.join(', ') }}.</span>
        </p>
      </PanneauBase>

      <form class="flex flex-col gap-4" @submit.prevent="deposer(true)">
        <PanneauBase titre="Identité">
          <ChampsIdentite
            v-model:given-names="saisie.givenNames"
            v-model:surname="saisie.surname"
            v-model:married-name="saisie.marriedName"
            v-model:nickname="saisie.nickname"
            v-model:sex="saisie.sex"
            v-model:deceased="saisie.deceased"
            :erreurs="erreursAffichees"
          />
        </PanneauBase>

        <PanneauBase titre="Naissance et décès">
          <div class="flex flex-col gap-4">
            <ChampDateIncertaine
              v-model="saisie.naissance"
              :etiquette="etiquetteDeChamp('birth')"
              :erreur="erreursAffichees['birth'] ?? ''"
            />
            <ChampDateIncertaine
              v-model="saisie.deces"
              :etiquette="etiquetteDeChamp('death')"
              :erreur="erreursAffichees['death'] ?? ''"
            />
          </div>
        </PanneauBase>

        <PanneauBase titre="Confidentialité">
          <div class="flex flex-col gap-3">
            <label class="flex items-start gap-2">
              <input
                v-model="saisie.forcePrivacy"
                type="checkbox"
                class="mt-0.5 size-4 accent-[var(--color-accent)]"
              />
              <span class="text-sm">{{ etiquetteDeChamp('forcePrivacy') }}</span>
            </label>
            <ChampTexte
              v-model="saisie.privacyNote"
              :etiquette="etiquetteDeChamp('privacyNote')"
              :erreur="erreursAffichees['privacyNote'] ?? ''"
            />
          </div>
        </PanneauBase>

        <SectionLiens v-model="liens" :fiche="fiche" :erreurs="erreursLiens" />

        <PanneauBase>
          <ul v-if="modifications.length > 0 || totalLiens > 0" class="mb-3 flex flex-wrap gap-1.5">
            <li
              v-for="modification in modifications"
              :key="modification.champ"
              class="rounded-pastille border border-accent bg-accent-doux px-2 py-0.5 text-[11.5px] font-semibold text-accent"
            >
              {{ modification.etiquette }}
            </li>
            <li
              v-if="totalLiens > 0"
              class="rounded-pastille border border-accent bg-accent-doux px-2 py-0.5 text-[11.5px] font-semibold text-accent"
            >
              {{ totalLiens }} lien{{ totalLiens > 1 ? 's' : '' }} de parenté
            </li>
          </ul>
          <textarea
            v-model="message"
            rows="2"
            aria-label="Message à l’administrateur"
            placeholder="Un mot pour l’administrateur (facultatif)"
            class="w-full rounded-champ border border-ligne bg-fond px-2.5 py-1.5 text-sm text-texte"
          />
        </PanneauBase>

        <div class="flex flex-wrap items-center gap-2">
          <BoutonBase
            type="submit"
            :chargement="envoi"
            :desactive="suspendu || blocage !== null"
          >
            Envoyer
          </BoutonBase>
          <BoutonBase
            variante="secondaire"
            :desactive="suspendu || envoi || blocage !== null"
            @click="deposer(false)"
          >
            Brouillon
          </BoutonBase>
          <RouterLink
            :to="{ name: 'personne.detail', params: { id: identifiant } }"
            class="text-sm text-attenue underline"
          >
            Annuler
          </RouterLink>
        </div>

        <p v-if="blocage !== null" class="text-sm text-attenue" role="status">{{ blocage }}</p>
      </form>
    </template>
  </div>
</template>
