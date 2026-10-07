"""La voix off de la vidéo de présentation, avec ElevenLabs.

    python narration/voix.py            # génère (une seule requête), puis découpe
    python narration/voix.py --decouper # redécoupe la prise en cache, sans rien payer

Le texte entier part en UNE requête : la voix garde sa respiration et son
intonation d'une phrase à l'autre, ce qui sonne bien plus naturel que vingt
phrases générées séparément. On demande l'alignement caractère par caractère,
puis on découpe la prise au milieu du silence entre deux segments : chaque
segment devient narration/voix/<n>.mp3, et narration/voix.json donne l'instant
de chaque mot (relatif au début du segment). La timeline (../timeline.js)
replace ensuite les segments avec les pauses voulues.

Variables d'environnement : ELEVENLABS_API_KEY, ELEVENLABS_VOICE_ID.
Seul le texte de la voix off est envoyé à ElevenLabs.
"""
import base64, json, os, re, subprocess, sys, urllib.request

ICI = os.path.dirname(os.path.abspath(__file__))
PRISE = os.path.join(ICI, 'prise')          # la prise brute + l'alignement (cache, versionné)
DECOUPE = os.path.join(ICI, 'voix')         # un mp3 par segment
SR = 44100

# Une voix posée, conversationnelle : stabilité moyenne (un peu de variation,
# sans dérive), pas d'exagération de style (style = 0 : rien de « théâtral »),
# un débit à peine ralenti.
REGLAGES = {
    'model_id': 'eleven_multilingual_v2',
    'voice_settings': {'stability': 0.5, 'similarity_boost': 0.8, 'style': 0.0, 'use_speaker_boost': True, 'speed': 1.05},
}


def segments():
    return json.load(open(os.path.join(ICI, 'texte.json'), encoding='utf-8'))['segments']


def generer():
    cle, voix = os.environ['ELEVENLABS_API_KEY'], os.environ['ELEVENLABS_VOICE_ID']
    texte = ' '.join(s.get('dit', s['texte']) for s in segments())
    corps = json.dumps({'text': texte, 'language_code': 'fr', **REGLAGES}).encode()
    req = urllib.request.Request(
        f'https://api.elevenlabs.io/v1/text-to-speech/{voix}/with-timestamps?output_format=mp3_44100_128',
        data=corps, headers={'xi-api-key': cle, 'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=300) as r:
        rep = json.load(r)
    os.makedirs(PRISE, exist_ok=True)
    open(os.path.join(PRISE, 'prise.mp3'), 'wb').write(base64.b64decode(rep['audio_base64']))
    json.dump({'texte': texte, 'reglages': REGLAGES, 'alignement': rep['alignment']},
              open(os.path.join(PRISE, 'alignement.json'), 'w', encoding='utf-8'), ensure_ascii=False)
    print('prise :', len(texte), 'caractères')


def pcm(chemin):
    import numpy as np
    brut = subprocess.run(['ffmpeg', '-v', 'error', '-i', chemin, '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'],
                          capture_output=True, check=True).stdout
    return np.frombuffer(brut, np.float32)


def decouper():
    import numpy as np
    from scipy.io import wavfile
    a = json.load(open(os.path.join(PRISE, 'alignement.json'), encoding='utf-8'))
    al = a['alignement']
    car, deb, fin = al['characters'], al['character_start_times_seconds'], al['character_end_times_seconds']
    texte = ''.join(car)
    audio = pcm(os.path.join(PRISE, 'prise.mp3'))
    duree = len(audio) / SR

    # les bornes de chaque segment dans le texte envoyé
    segs, i = [], 0
    for s in segments():
        dit = s.get('dit', s['texte'])
        j = texte.index(dit, i)
        segs.append((s, j, j + len(dit)))
        i = j + len(dit)

    # les mots : suites de lettres (apostrophes et traits d'union compris)
    def mots(j0, j1):
        res = []
        for m in re.finditer(r"[\wÀ-ÿ’'\-]+", texte[j0:j1]):
            a0, a1 = j0 + m.start(), j0 + m.end() - 1
            res.append({'mot': m.group(), 'debut': deb[a0], 'fin': fin[a1]})
        return res

    # la coupe entre deux segments : au point le plus calme du silence qui les sépare
    env = np.convolve(np.abs(audio), np.ones(441) / 441, 'same')
    def coupe(t0, t1):
        i0, i1 = int(t0 * SR), max(int(t0 * SR) + 1, int(t1 * SR))
        return (i0 + int(np.argmin(env[i0:i1]))) / SR

    bornes = []
    for k, (s, j0, j1) in enumerate(segs):
        ms = mots(j0, j1)
        bornes.append(ms)
    coupes = [0.0]
    for k in range(len(segs) - 1):
        coupes.append(coupe(bornes[k][-1]['fin'], bornes[k + 1][0]['debut']))
    coupes.append(duree)

    os.makedirs(DECOUPE, exist_ok=True)
    sortie = []
    for k, (s, j0, j1) in enumerate(segs):
        a0, a1 = coupes[k], coupes[k + 1]
        # on rogne le silence : 60 ms avant le premier mot, 120 ms après le dernier
        b0 = max(a0, bornes[k][0]['debut'] - 0.06)
        b1 = min(a1, bornes[k][-1]['fin'] + 0.12)
        morceau = audio[int(b0 * SR):int(b1 * SR)].copy()
        n = len(morceau)
        f = min(int(0.012 * SR), n // 2)  # fondus de 12 ms : pas de clic aux coupes
        morceau[:f] *= np.linspace(0, 1, f); morceau[n - f:] *= np.linspace(1, 0, f)
        wav = os.path.join(DECOUPE, f'{k}.wav')
        wavfile.write(wav, SR, morceau.astype(np.float32))
        sortie.append({
            'id': s['id'], 'texte': s['texte'], 'duree': round(n / SR, 3),
            # le silence d'origine après ce segment (indication pour la timeline)
            'pauseOrigine': round(coupes[k + 1] - b1 + (max(0, bornes[k + 1][0]['debut'] - 0.06 - coupes[k + 1]) if k + 1 < len(segs) else 0), 3),
            'mots': [{'mot': m['mot'], 'debut': round(m['debut'] - b0, 3), 'fin': round(m['fin'] - b0, 3)} for m in bornes[k]],
        })
    json.dump({'source': 'ElevenLabs', 'reglages': a['reglages'], 'segments': sortie},
              open(os.path.join(ICI, 'voix.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    # la même chose, lisible par la page de rendu ouverte en file:// (pas de fetch possible)
    open(os.path.join(ICI, 'voix.js'), 'w', encoding='utf-8').write(
        'window.VOIX = ' + json.dumps(sortie, ensure_ascii=False) + '\n')
    total = sum(s['duree'] for s in sortie)
    print(f'{len(sortie)} segments, {total:.1f} s de parole (prise : {duree:.1f} s)')
    for s in sortie:
        print(f"  {s['id']:<12} {s['duree']:5.2f} s  pause d'origine {s['pauseOrigine']:.2f} s")


if __name__ == '__main__':
    if '--decouper' not in sys.argv:
        generer()
    decouper()
