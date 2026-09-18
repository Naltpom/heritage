<script setup lang="ts">
/**
 * Mon compte — courriel et mot de passe modifiables, identité en lecture seule.
 *
 * ── Ce que la page NE fait pas, et pourquoi c'est écrit ici ──────────────────
 *
 * Elle ne modifie ni le nom ni le prénom. Ce sont ceux de la **fiche** rattachée
 * au compte, pas ceux du compte : les corriger, c'est corriger l'arbre, et
 * l'arbre ne se corrige que par une demande validée. Le bouton « proposer une
 * modification » mène donc au formulaire de proposition de la fiche, et la page
 * ne porte aucun champ pour ces deux valeurs — pas même désactivé, qui laisserait
 * croire qu'un droit manque plutôt qu'un circuit existe.
 *
 * ── Deux formulaires, deux preuves ───────────────────────────────────────────
 *
 * Les deux exigent le mot de passe actuel, et le refus est un `422` sur le champ,
 * jamais une `401` : le compte est authentifié, c'est la donnée saisie qui est
 * mauvaise (§ 2.1). Un `401` ferait déconnecter par le rattrapage global —
 * l'inverse du service rendu.
 *
 * Le courriel ne change pas à la soumission : un lien part vers la nouvelle
 * adresse, et c'est le clic qui change l'identifiant (§ 2.5). La page le dit en
 * toutes lettres après l'envoi, avec l'échéance, parce qu'un « c'est fait » ici
 * serait faux.
 *
 * Après un changement de mot de passe, la page **se reconnecte** avec le nouveau,
 * puis relit la session. Symfony compare l'empreinte du mot de passe à chaque
 * requête (`User::isEqualTo`) : sans cette reconnexion, la requête suivante
 * rendrait `401` et la personne serait mise dehors par le changement qu'elle
 * vient de faire.
 */
import { computed, ref } from 'vue'
import { analyserErreur, changerMotDePasse, demanderChangementDeCourriel } from '@/lib/api'
import { connecter, useSession } from '@/composables/useSession'
import { erreurCourriel, erreurMotDePasse } from '@/components/inscription/reglesMotDePasse'
import BoutonBase from '@/components/base/BoutonBase.vue'
import ChampTexte from '@/components/base/ChampTexte.vue'
import MessageErreur from '@/components/base/MessageErreur.vue'
import PanneauBase from '@/components/base/PanneauBase.vue'

const { session, rattache } = useSession()

const courrielActuel = computed(() => session.value?.utilisateur.email ?? '')

// `membre` est une union : avant activation, `personId` et `personLabel` sont
// **absents** du type, pas seulement vides (§ 2.2). On ne les lit donc qu'après
// avoir constaté le rattachement, et le type suit.
const membreRattache = computed(() => {
  const membre = session.value?.membre ?? null
  return rattache.value && membre !== null && membre.status !== 'en_attente' ? membre : null
})
const personneId = computed(() => membreRattache.value?.personId ?? null)
const nomDeLaFiche = computed(() => membreRattache.value?.personLabel ?? null)

/* --- courriel ------------------------------------------------------- */

const nouveauCourriel = ref('')
const motDePassePourCourriel = ref('')
const envoiCourriel = ref(false)
const erreurEnvoiCourriel = ref<unknown>(null)
const lienEnvoyeA = ref<string | null>(null)
const lienExpireLe = ref<string | null>(null)

const controleCourriel = computed(() => {
  if (nouveauCourriel.value === '') return null
  const base = erreurCourriel(nouveauCourriel.value)
  if (base !== null) return base
  return nouveauCourriel.value.trim().toLowerCase() === courrielActuel.value.toLowerCase()
    ? 'C’est déjà l’adresse de ce compte.'
    : null
})

const peutDemanderLeCourriel = computed(
  () =>
    !envoiCourriel.value &&
    nouveauCourriel.value !== '' &&
    controleCourriel.value === null &&
    motDePassePourCourriel.value !== '',
)

async function soumettreCourriel(): Promise<void> {
  if (!peutDemanderLeCourriel.value) return
  envoiCourriel.value = true
  erreurEnvoiCourriel.value = null
  lienEnvoyeA.value = null
  try {
    const reponse = await demanderChangementDeCourriel(
      motDePassePourCourriel.value,
      nouveauCourriel.value.trim(),
    )
    lienEnvoyeA.value = nouveauCourriel.value.trim()
    lienExpireLe.value = reponse.expiresAt
    nouveauCourriel.value = ''
  } catch (e) {
    erreurEnvoiCourriel.value = e
  } finally {
    // Le mot de passe n'est jamais gardé plus longtemps que l'appel qui l'a
    // demandé — succès ou échec.
    motDePassePourCourriel.value = ''
    envoiCourriel.value = false
  }
}

const champsCourriel = computed(() => {
  const analyse = analyserErreur(erreurEnvoiCourriel.value)
  return analyse?.champs ?? null
})

/* --- mot de passe --------------------------------------------------- */

const motDePasseActuel = ref('')
const nouveauMotDePasse = ref('')
const confirmation = ref('')
const envoiMotDePasse = ref(false)
const erreurMotDePasseEnvoi = ref<unknown>(null)
const motDePasseChange = ref(false)

const controleNouveau = computed(() =>
  nouveauMotDePasse.value === '' ? null : erreurMotDePasse(nouveauMotDePasse.value),
)
const controleConfirmation = computed(() =>
  confirmation.value !== '' && confirmation.value !== nouveauMotDePasse.value
    ? 'Les deux saisies ne correspondent pas.'
    : null,
)

const peutChangerLeMotDePasse = computed(
  () =>
    !envoiMotDePasse.value &&
    motDePasseActuel.value !== '' &&
    nouveauMotDePasse.value !== '' &&
    controleNouveau.value === null &&
    confirmation.value === nouveauMotDePasse.value,
)

async function soumettreMotDePasse(): Promise<void> {
  if (!peutChangerLeMotDePasse.value) return
  envoiMotDePasse.value = true
  erreurMotDePasseEnvoi.value = null
  motDePasseChange.value = false
  const nouveau = nouveauMotDePasse.value
  try {
    await changerMotDePasse(motDePasseActuel.value, nouveau)
    // Voir l'en-tête : la session doit être rouverte avec le nouveau mot de passe.
    // `connecter()` range lui-même la charge de session rendue — rien à relire.
    await connecter(courrielActuel.value, nouveau)
    motDePasseChange.value = true
  } catch (e) {
    erreurMotDePasseEnvoi.value = e
  } finally {
    motDePasseActuel.value = ''
    nouveauMotDePasse.value = ''
    confirmation.value = ''
    envoiMotDePasse.value = false
  }
}

const champsMotDePasse = computed(() => {
  const analyse = analyserErreur(erreurMotDePasseEnvoi.value)
  return analyse?.champs ?? null
})

function dateLisible(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  return date.toLocaleString('fr-FR', { dateStyle: 'long', timeStyle: 'short' })
}
</script>

<template>
  <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-6">
    <h1 class="text-xl font-semibold">Mon compte</h1>

    <PanneauBase>
      <section class="flex flex-col gap-3 p-4">
        <h2 class="text-base font-semibold">Identité</h2>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt class="text-attenue">Nom et prénom</dt>
          <dd>
            <span v-if="nomDeLaFiche !== null">{{ nomDeLaFiche }}</span>
            <span v-else class="text-attenue">Rattachement en attente de validation</span>
          </dd>
          <dt class="text-attenue">Courriel</dt>
          <dd>{{ courrielActuel }}</dd>
        </dl>
        <p class="text-sm text-attenue">
          Le nom et le prénom sont ceux de votre fiche dans l'arbre. Ils ne se modifient
          pas ici : proposez une modification, elle sera relue avant d'être appliquée.
        </p>
        <RouterLink
          v-if="personneId !== null"
          :to="`/personnes/${personneId}/proposer`"
          class="self-start text-sm text-accent underline-offset-2 hover:underline"
        >
          Proposer une modification de ma fiche
        </RouterLink>
      </section>
    </PanneauBase>

    <PanneauBase>
      <form class="flex flex-col gap-3 p-4" novalidate @submit.prevent="soumettreCourriel">
        <h2 class="text-base font-semibold">Changer de courriel</h2>
        <p class="text-sm text-attenue">
          Un lien de confirmation sera envoyé à la nouvelle adresse. Votre courriel ne
          changera qu'une fois ce lien ouvert.
        </p>
        <ChampTexte
          v-model="nouveauCourriel"
          etiquette="Nouvelle adresse"
          type="email"
          autocomplete="email"
          :erreur="champsCourriel?.nouveauCourriel ?? controleCourriel ?? ''"
          requis
        />
        <ChampTexte
          v-model="motDePassePourCourriel"
          etiquette="Mot de passe actuel"
          type="password"
          autocomplete="current-password"
          :erreur="champsCourriel?.motDePasseActuel ?? ''"
          requis
        />
        <MessageErreur v-if="erreurEnvoiCourriel !== null && champsCourriel === null" :erreur="erreurEnvoiCourriel" />
        <p v-if="lienEnvoyeA !== null" class="text-sm" role="status">
          Un lien a été envoyé à <strong>{{ lienEnvoyeA }}</strong
          ><template v-if="lienExpireLe !== null"> ; il expire le {{ dateLisible(lienExpireLe) }}</template>.
          Votre courriel actuel reste valable jusqu'à la confirmation.
        </p>
        <BoutonBase
          type="submit"
          variante="principal"
          :chargement="envoiCourriel"
          :desactive="!peutDemanderLeCourriel"
        >
          Envoyer le lien de confirmation
        </BoutonBase>
      </form>
    </PanneauBase>

    <PanneauBase>
      <form class="flex flex-col gap-3 p-4" novalidate @submit.prevent="soumettreMotDePasse">
        <h2 class="text-base font-semibold">Changer de mot de passe</h2>
        <ChampTexte
          v-model="motDePasseActuel"
          etiquette="Mot de passe actuel"
          type="password"
          autocomplete="current-password"
          :erreur="champsMotDePasse?.motDePasseActuel ?? ''"
          requis
        />
        <ChampTexte
          v-model="nouveauMotDePasse"
          etiquette="Nouveau mot de passe"
          type="password"
          autocomplete="new-password"
          aide="12 caractères minimum."
          :erreur="champsMotDePasse?.nouveauMotDePasse ?? controleNouveau ?? ''"
          requis
        />
        <ChampTexte
          v-model="confirmation"
          etiquette="Confirmer le nouveau mot de passe"
          type="password"
          autocomplete="new-password"
          :erreur="controleConfirmation ?? ''"
          requis
        />
        <MessageErreur
          v-if="erreurMotDePasseEnvoi !== null && champsMotDePasse === null"
          :erreur="erreurMotDePasseEnvoi"
        />
        <p v-if="motDePasseChange" class="text-sm" role="status">
          Mot de passe changé. Vos autres sessions ouvertes devront se reconnecter.
        </p>
        <BoutonBase
          type="submit"
          variante="principal"
          :chargement="envoiMotDePasse"
          :desactive="!peutChangerLeMotDePasse"
        >
          Changer le mot de passe
        </BoutonBase>
      </form>
    </PanneauBase>
  </div>
</template>
