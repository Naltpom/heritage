"""Voix off avec une voix ElevenLabs (par exemple la voix clonée de Nathan), au même
format que voix.py : une phrase par plan, avec l'instant de chaque mot.

    ELEVENLABS_API_KEY=… ELEVENLABS_VOICE_ID=… python voix_elevenlabs.py un-mot-de-nathan

Sortie : voix/<id>/<n>.mp3 et voix/<id>.json  [{texte, mots:[{mot, debut, fin}]}]
Seul le texte des phrases part chez ElevenLabs : aucune donnée de l'arbre.

ElevenLabs donne l'instant de chaque caractère ; on en refait des mots découpés
exactement comme les jetons du karaoké (jetonsDe dans commun.js) : la ponctuation
isolée du français (« : », « ? », « ! »…) reste collée au mot d'avant, pour qu'il
y ait toujours un mot dit par jeton affiché.
"""
import base64, json, os, re, ssl, sys, urllib.request

ICI = os.path.dirname(os.path.abspath(__file__))
MODELE = os.environ.get('ELEVENLABS_MODEL', 'eleven_multilingual_v2')
# Une voix vive : peu de stabilité (plus d'intonation), du style, un débit un peu relevé.
REGLAGES = {'stability': 0.35, 'similarity_boost': 0.85, 'style': 0.45, 'use_speaker_boost': True, 'speed': 1.05}


def jetons_avec_positions(phrase):
    """Les jetons de jetonsDe (commun.js), avec la plage de caractères de chacun."""
    res, pos, ouvrant = [], 0, None
    for brut in phrase.split(' '):
        debut, fin = pos, pos + len(brut)
        pos = fin + 1
        if brut == '«':
            ouvrant = debut
            continue
        if res and re.fullmatch(r'[:;?!»][:;?!».,]*', brut):
            res[-1] = (res[-1][0] + ' ' + brut, res[-1][1], fin)
            continue
        res.append(('« ' + brut if ouvrant is not None else brut, ouvrant if ouvrant is not None else debut, fin))
        ouvrant = None
    return res


def mots_depuis_caracteres(phrase, alignement):
    """Un mot par jeton : du premier caractère dit au dernier caractère qui n'est pas de la ponctuation."""
    car, debuts, fins = alignement['characters'], alignement['character_start_times_seconds'], alignement['character_end_times_seconds']
    if ''.join(car) != phrase:
        raise ValueError(f'alignement inattendu pour : {phrase!r}')
    mots = []
    for jeton, a, b in jetons_avec_positions(phrase):
        lettres = [k for k in range(a, b) if car[k].isalnum()] or list(range(a, b))
        mots.append({'mot': jeton, 'debut': round(debuts[lettres[0]], 3), 'fin': round(fins[lettres[-1]], 3)})
    return mots


def appeler(cle, voix, phrase, avant, apres):
    corps = {'text': phrase, 'model_id': MODELE, 'voice_settings': REGLAGES, 'previous_text': avant, 'next_text': apres}
    req = urllib.request.Request(
        f'https://api.elevenlabs.io/v1/text-to-speech/{voix}/with-timestamps?output_format=mp3_44100_128',
        data=json.dumps(corps).encode(), headers={'xi-api-key': cle, 'Content-Type': 'application/json'})
    ctx = ssl.create_default_context(cafile=os.environ.get('SSL_CERT_FILE'))
    with urllib.request.urlopen(req, context=ctx, timeout=120) as r:
        return json.load(r)


def main():
    cle, voix = os.environ.get('ELEVENLABS_API_KEY'), os.environ.get('ELEVENLABS_VOICE_ID')
    if not cle or not voix:
        sys.exit('ELEVENLABS_API_KEY et ELEVENLABS_VOICE_ID doivent être définis.')
    textes = json.load(open(os.path.join(ICI, 'textes.json'), encoding='utf-8'))
    for vid in sys.argv[1:]:
        dossier = os.path.join(ICI, 'voix', vid)
        os.makedirs(dossier, exist_ok=True)
        phrases, res = textes[vid], []
        for i, p in enumerate(phrases):
            # la phrase d'avant et celle d'après : l'intonation reste suivie d'une phrase à l'autre
            r = appeler(cle, voix, p, ' '.join(phrases[max(0, i - 2):i]), ' '.join(phrases[i + 1:i + 2]))
            open(os.path.join(dossier, f'{i}.mp3'), 'wb').write(base64.b64decode(r['audio_base64']))
            res.append({'texte': p, 'mots': mots_depuis_caracteres(p, r['alignment'])})
            print(f'{vid} {i} : {len(res[-1]["mots"])} mots, {res[-1]["mots"][-1]["fin"]:.2f} s')
        json.dump(res, open(os.path.join(ICI, 'voix', f'{vid}.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)


if __name__ == '__main__':
    main()
