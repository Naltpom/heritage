/**
 * Les gestes réservés de la fiche — **chacun avec sa propre condition.**
 *
 * > **Le rôle du lecteur choisit la route, jamais l'affichage** (§ 10.4).
 *
 * Un **champ** s'affiche parce que la charge utile le porte. Une **action**, elle,
 * se cache quand elle serait vouée au refus — et c'est la seule chose que la
 * session décide sur cette page. Ces conditions vivaient dans
 * `PointProposition.vue`, `PointSuppression.vue` et `GalerieMedias.vue` ; la
 * refonte les fait apparaître à trois endroits (le bandeau desktop, la barre
 * d'action mobile, le menu ⋯), et elles sont rassemblées ici pour qu'aucun de
 * ces endroits n'en écrive une variante.
 *
 * - **proposer** (modification, liens) et **demander la suppression** exigent un
 *   rattachement validé : un compte en attente recevrait `403 regime_en_attente`,
 *   et aucun geste de sa part ne l'active (§ 12) ;
 * - **déposer un document** : même condition, même raison (§ 7) ;
 * - **aucun** sous la bascule « voir comme un membre » : toute écriture y rend
 *   `409 vue_comme_membre_active` (§ 10.4).
 *
 * Les trois conditions se ressemblent aujourd'hui ; elles restent **trois**
 * calculs nommés, parce que le jour où l'une change (un dépôt ouvert aux
 * comptes en attente, par exemple), l'autre ne doit pas changer avec elle.
 *
 * La charge utile a aussi son mot à dire : ces gestes ne se proposent que sur une
 * **fiche complète**, qui porte `version` — « sur une fiche réduite, `version`
 * est absente, et c'est ce qui rend matériellement impossible de déposer une
 * demande » (§ 11.1). Les composants qui s'en servent ne reçoivent donc qu'une
 * `FichePersonneComplete`.
 *
 * Masquer un geste n'est pas une mesure de sécurité — la garde de route le
 * double, et l'API double les deux. C'est éviter de proposer ce qui sera refusé.
 */
import { computed } from 'vue'
import { useSession } from '@/composables/useSession'

export function useGestesFiche() {
  const { rattache, vueEmpruntee } = useSession()

  const ecritureOuverte = computed(() => rattache.value && vueEmpruntee.value === null)

  return {
    /** « Proposer une modification », « Proposer des liens ». */
    peutProposer: computed(() => ecritureOuverte.value),
    /** « Demander la suppression » — un geste séparé, jamais dans le même bloc. */
    peutDemanderSuppression: computed(() => ecritureOuverte.value),
    /** « Ajouter un document » : même condition que le dépôt de la galerie. */
    peutDeposer: computed(() => ecritureOuverte.value),
  }
}
