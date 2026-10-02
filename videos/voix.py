"""Voix off : une phrase par plan, avec l'instant de chaque mot (pour le karaoké).

    python voix.py              # toutes les vidéos de textes.json
    python voix.py bienvenue    # une seule

Sortie : voix/<id>/<n>.mp3 et voix/<id>.json  [{texte, mots:[{mot, debut, fin}]}]
Seul le texte des phrases part chez Microsoft (synthèse Edge) : aucune donnée de l'arbre.
"""
import asyncio, json, os, sys
import edge_tts

VOIX = 'fr-FR-DeniseNeural'
DEBIT = '+3%'
ICI = os.path.dirname(os.path.abspath(__file__))


async def une(dossier, i, phrase):
    com = edge_tts.Communicate(phrase, VOIX, rate=DEBIT, boundary='WordBoundary')
    mots, audio = [], bytearray()
    async for morceau in com.stream():
        if morceau['type'] == 'audio':
            audio.extend(morceau['data'])
        elif morceau['type'] == 'WordBoundary':
            d = morceau['offset'] / 1e7
            mots.append({'mot': morceau['text'], 'debut': round(d, 3), 'fin': round(d + morceau['duration'] / 1e7, 3)})
    open(os.path.join(dossier, f'{i}.mp3'), 'wb').write(audio)
    return {'texte': phrase, 'mots': mots}


async def main():
    textes = json.load(open(os.path.join(ICI, 'textes.json'), encoding='utf-8'))
    for vid in sys.argv[1:] or textes:
        dossier = os.path.join(ICI, 'voix', vid)
        os.makedirs(dossier, exist_ok=True)
        res = [await une(dossier, i, p) for i, p in enumerate(textes[vid])]
        json.dump(res, open(os.path.join(ICI, 'voix', f'{vid}.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        print(vid, [len(r['mots']) for r in res])

asyncio.run(main())
