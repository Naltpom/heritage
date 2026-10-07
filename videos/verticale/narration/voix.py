"""La voix off, avec ElevenLabs (Eleven v4, à défaut v3), en UNE prise.

    python narration/voix.py            # génère la prise, puis l'analyse
    python narration/voix.py --analyser # réanalyse la prise en cache, sans rien payer

Le script (`script.txt`) part tel quel, audio tags compris ([reflective], [pause],
[softly]…) : c'est le modèle qui les interprète. Seules deux choses changent dans ce
qui est dit, pas dans ce qui est écrit :
  - les nombres en toutes lettres (« 2 307 » → « deux mille trois cent sept ») ;
  - « Heritage » accentué, pour qu'il soit prononcé à la française.

La vidéo est montée sur la prise entière, sans la redécouper : on garde les silences
et la respiration du modèle. L'analyse produit `voix.json` (et `voix.js` pour la page) :
pour chaque ligne du script, son texte de sous-titre et l'instant de chaque mot dans
la prise ; et pour chaque [pause], l'instant où elle tombe.

Variables d'environnement : ELEVENLABS_API_KEY, ELEVENLABS_VOICE_ID.
Seul le texte du script est envoyé à ElevenLabs.
"""
import base64, json, os, re, sys, urllib.request

ICI = os.path.dirname(os.path.abspath(__file__))
PRISE = os.path.join(ICI, 'prise')
MODELES = ['eleven_v4', 'eleven_v3']
# v3/v4 : la stabilité ne prend que 0 (créative), 0,5 (naturelle) ou 1 (robuste)
REGLAGES = {'voice_settings': {'stability': 0.5, 'similarity_boost': 0.8, 'use_speaker_boost': True}}
DIT = [('2 307', 'Deux mille trois cent sept'), ('14 générations', 'Quatorze générations'), ('Heritage', 'Héritage')]
TAG = re.compile(r'^\[[^\]]+\]$')


def lignes():
    """Les lignes du script : ('tag', '[pause]') ou ('texte', écrit, dit)."""
    res = []
    for brut in open(os.path.join(ICI, 'script.txt'), encoding='utf-8').read().splitlines():
        l = brut.strip()
        if not l:
            continue
        if TAG.match(l):
            res.append(('tag', l))
        else:
            dit = l
            for a, b in DIT:
                dit = dit.replace(a, b)
            res.append(('texte', l, dit))
    return res


def texte_envoye():
    return '\n'.join(l[1] if l[0] == 'tag' else l[2] for l in lignes())


def generer():
    cle, voix = os.environ['ELEVENLABS_API_KEY'], os.environ['ELEVENLABS_VOICE_ID']
    texte = texte_envoye()
    for modele in MODELES:
        corps = json.dumps({'text': texte, 'model_id': modele, 'language_code': 'fr', **REGLAGES}).encode()
        req = urllib.request.Request(
            f'https://api.elevenlabs.io/v1/text-to-speech/{voix}/with-timestamps?output_format=mp3_44100_128',
            data=corps, headers={'xi-api-key': cle, 'Content-Type': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=600) as r:
                rep = json.load(r)
        except urllib.error.HTTPError as e:
            print(f'{modele} : refusé ({e.code}) {e.read()[:300]!r}')
            continue
        os.makedirs(PRISE, exist_ok=True)
        open(os.path.join(PRISE, 'prise.mp3'), 'wb').write(base64.b64decode(rep['audio_base64']))
        json.dump({'modele': modele, 'texte': texte, 'reglages': REGLAGES, 'alignement': rep['alignment']},
                  open(os.path.join(PRISE, 'alignement.json'), 'w', encoding='utf-8'), ensure_ascii=False)
        print(f'prise : {modele}, {len(texte)} caractères')
        return
    raise SystemExit('aucun modèle n’a accepté la requête')


def analyser():
    a = json.load(open(os.path.join(PRISE, 'alignement.json'), encoding='utf-8'))
    al = a['alignement']
    car, deb, fin = al['characters'], al['character_start_times_seconds'], al['character_end_times_seconds']
    recu = ''.join(car)
    # on retrouve chaque ligne dite dans le texte aligné (le modèle peut avoir retiré les tags)
    sortie, pauses, i = [], [], 0
    attente = []  # les tags rencontrés depuis la dernière ligne
    for l in lignes():
        if l[0] == 'tag':
            attente.append(l[1])
            continue
        _, ecrit, dit = l
        j = recu.find(dit, i)
        if j < 0:
            # tolérance : on cherche le premier mot de la ligne
            j = recu.find(dit.split()[0], i)
        mots = []
        for m in re.finditer(r"[\wÀ-ÿ’'\-]+", dit):
            a0, a1 = j + m.start(), j + m.end() - 1
            if a1 < len(deb):
                mots.append({'mot': m.group(), 'debut': round(deb[a0], 3), 'fin': round(fin[a1], 3)})
        i = j + len(dit)
        entree = {'texte': ecrit, 'debut': mots[0]['debut'], 'fin': mots[-1]['fin'], 'mots': mots, 'avant': attente}
        if '[pause]' in attente and sortie:
            # la pause : le silence entre la ligne précédente et celle-ci
            pauses.append({'apres': len(sortie) - 1, 'debut': sortie[-1]['fin'], 'fin': entree['debut']})
        attente = []
        sortie.append(entree)
    duree = fin[-1] if fin else 0
    import subprocess
    sonde = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', os.path.join(PRISE, 'prise.mp3')], capture_output=True, text=True)
    duree = float(sonde.stdout.strip() or duree)
    resultat = {'modele': a['modele'], 'duree': round(duree, 3), 'lignes': sortie, 'pauses': pauses}
    json.dump(resultat, open(os.path.join(ICI, 'voix.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    open(os.path.join(ICI, 'voix.js'), 'w', encoding='utf-8').write('window.VOIX = ' + json.dumps(resultat, ensure_ascii=False) + '\n')
    print(f"{a['modele']} : {duree:.1f} s, {len(sortie)} lignes, {len(pauses)} pauses")
    for k, s in enumerate(sortie):
        print(f"  {k:2d} {s['debut']:6.2f} → {s['fin']:6.2f}  {s['texte'][:70]}")


if __name__ == '__main__':
    if '--analyser' not in sys.argv:
        generer()
    analyser()
