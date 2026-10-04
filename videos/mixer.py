"""Bande-son et assemblage : musique composée ici (donc libre de droits),
bruitages synthétisés, voix ; la musique s'efface sous la voix.

    python mixer.py bienvenue

Lit   sortie/<id>.muet.mp4, sortie/<id>.piste.json, sortie/<id>.jpg, voix/<id>/<n>.mp3
Écrit ../app/public/videos/<id>.mp4, <id>.jpg (affiche), <id>.vtt (sous-titres)
"""
import json, os, subprocess, sys
import numpy as np
from scipy.signal import butter, sosfilt
from scipy.io import wavfile

ICI = os.path.dirname(os.path.abspath(__file__))
PUBLIC = os.path.join(ICI, '..', 'app', 'public', 'videos')
SR = 48000

# Une couleur musicale par vidéo : tonalité (demi-tons au-dessus de do), tempo,
# pulsation (0 = sans batterie, pour les tutoriels où la voix compte d'abord).
AMBIANCES = {
    'bienvenue': dict(ton=0, bpm=100, pulse=0.6, mineur=False),
    'lire-l-arbre': dict(ton=2, bpm=96, pulse=0.35, mineur=False),
    'filtres-de-l-arbre': dict(ton=5, bpm=96, pulse=0.35, mineur=False),
    'proposer-une-correction': dict(ton=7, bpm=96, pulse=0.35, mineur=False),
    'sources-et-actes': dict(ton=4, bpm=92, pulse=0.25, mineur=True),
    'notifications-et-calendrier': dict(ton=9, bpm=100, pulse=0.35, mineur=False),
    'carte-et-chronologie': dict(ton=3, bpm=92, pulse=0.3, mineur=False),
    'les-evenements': dict(ton=10, bpm=100, pulse=0.4, mineur=False),
    # « Un mot de Nathan » : une musique épique, dont la scène donne la forme (piste['musique']).
    'un-mot-de-nathan': dict(ton=2, bpm=120, style='epique'),
}


def hz(m):
    return 440 * 2 ** ((m - 69) / 12)


def lp(y, fc, ordre=2):
    return sosfilt(butter(ordre, fc, 'low', fs=SR, output='sos'), y)


def hp(y, fc, ordre=2):
    return sosfilt(butter(ordre, fc, 'high', fs=SR, output='sos'), y)


def poser(piste, s, t, gain=1.0):
    i = int(t * SR)
    if i < 0:
        s, i = s[-i:], 0
    j = min(len(piste), i + len(s))
    if j > i:
        piste[i:j] += s[:j - i] * gain


def note(f, d, forme='saw', att=0.01, rel=0.3):
    t = np.arange(int(SR * d)) / SR
    if forme == 'saw':
        y = sum(np.sin(2 * np.pi * f * k * t * (1 + 0.002 * (k % 3 - 1))) / k for k in range(1, 7))
    else:
        y = np.sin(2 * np.pi * f * t) + 0.3 * np.sin(4 * np.pi * f * t) * np.exp(-t * 6)
    env = np.minimum(1, t / att) * np.clip((d - t) / rel, 0, 1)
    return y * env


def musique(duree, ton, bpm, pulse, mineur, rng):
    n = int(SR * duree)
    temps = 60 / bpm
    # I – V – vi – IV (ou i – VI – III – VII en mineur), deux mesures par accord
    if mineur:
        accords = [[52, 55, 59], [48, 52, 55], [55, 59, 62], [50, 54, 57]]
    else:
        accords = [[48, 52, 55], [55, 59, 62], [57, 60, 64], [53, 57, 60]]
    accords = [[m + ton for m in a] for a in accords]
    nappe, arp, basse, kick, hh = (np.zeros(n) for _ in range(5))
    mesure = 4 * temps
    for m in range(int(duree / mesure) + 1):
        acc = accords[(m // 2) % 4]
        t0 = m * mesure
        if m % 2 == 0:
            for a in acc:
                poser(nappe, note(hz(a), 2 * mesure + 0.8, att=0.7, rel=1.4), t0, 0.12)
            poser(basse, note(hz(acc[0] - 12), 2 * mesure, 'sin', att=0.05, rel=0.5), t0, 0.4)
        for c in range(8):
            a = (acc + [acc[0] + 12])[[0, 1, 2, 3, 2, 1, 2, 3][c]] + 12
            d = 0.4
            poser(arp, note(hz(a), d, 'sin', att=0.004, rel=0.35) * np.exp(-np.arange(int(SR * d)) / SR * 8), t0 + c * temps / 2, 0.14)
        for b in range(4):
            tb = t0 + b * temps
            tt = np.arange(int(SR * 0.25)) / SR
            poser(kick, np.sin(2 * np.pi * (48 + 70 * np.exp(-tt * 30)) * tt) * np.exp(-tt * 14), tb, 0.5)
            poser(hh, rng.standard_normal(int(SR * .05)) * np.exp(-np.arange(int(SR * .05)) / SR * 90), tb + temps / 2, 0.05)
    t = np.arange(n) / SR
    rythme = pulse * np.clip((t - 2.5) / 1.0, 0, 1) * np.clip((duree - 3.0 - t) / 0.5, 0, 1)
    y = lp(nappe, 1800) + arp * np.clip((t - 1.0) / 2, 0, 1) + basse + (kick + hp(hh, 7000)) * rythme
    y *= np.clip(t / 0.8, 0, 1) * np.clip((duree - t) / 2.0, 0, 1)
    return y / np.abs(y).max()


def scie(f, t, desaccord=(0,)):
    """Une ou plusieurs dents de scie (désaccordées en cents) : la matière des accords « supersaw »."""
    return sum(2 * ((f * 2 ** (c / 1200) * t + 0.37 * i) % 1) - 1 for i, c in enumerate(desaccord)) / len(desaccord)


def musique_epique(duree, ton, bpm, forme, rng):
    """Une musique pop épique, découpée en mesures dont chacune a une énergie :
    0 = intro (nappe et arpège filtrés), 0.5 = respiration (nappe, grosse caisse douce),
    1 = groove (grosse caisse, charleston, basse, accords), 2 = à fond (clap, arpège en
    doubles croches, accords ouverts). Chaque hausse d'énergie est précédée d'une
    montée et tombe sur un premier temps, avec une cymbale et un coup de basse.
    `forme` = {sections: [[t, énergie], …], final: t} ; la première section à 2 tombe
    exactement sur un premier temps, les autres sur le premier temps le plus proche."""
    n = int(SR * duree)
    temps = 60 / bpm
    mesure = 4 * temps
    sections = sorted(forme['sections'])
    drop1 = next(t for t, e in sections if e >= 2)
    origine = drop1 - round(drop1 / mesure) * mesure  # la grille des mesures passe par le premier drop
    nb = int((duree - origine) / mesure) + 2
    debut = lambda m: origine + m * mesure
    cale = lambda t: int(round((t - origine) / mesure))
    energie = [0.0] * nb
    for t, e in sections:
        for m in range(max(0, cale(t)), nb):
            energie[m] = e
    final = cale(forme['final']) if forme.get('final') else nb
    # vi – IV – I – V, une mesure par accord
    accords = [[m + ton for m in a] for a in ([57, 60, 64], [53, 57, 60], [48, 52, 55], [55, 59, 62])]
    pistes = {k: np.zeros(n) for k in ('kick', 'clap', 'hh', 'basse', 'accords', 'arp', 'nappe', 'fx')}

    def env_court(d, dec):
        tt = np.arange(int(SR * d)) / SR
        return tt, np.exp(-tt * dec)

    def kick(gain):
        tt, e = env_court(0.4, 9)
        return (np.sin(2 * np.pi * (45 * tt + 105 / 28 * (1 - np.exp(-tt * 28)))) * e + hp(rng.standard_normal(len(tt)), 3000) * np.exp(-tt * 300) * 0.3) * gain

    def clap():
        tt, e = env_court(0.3, 14)
        b = np.zeros(len(tt))
        for k, dt in enumerate((0, 0.012, 0.024)):
            poser(b, rng.standard_normal(int(SR * 0.012)) * (0.7 + 0.15 * k), dt)
        bruit = hp(lp(rng.standard_normal(len(tt)), 6000), 900) * e
        return hp(lp(b, 5000), 800) + bruit * 0.8 + np.sin(2 * np.pi * 190 * tt) * np.exp(-tt * 30) * 0.4

    def charley(ouvert=False):
        tt, e = env_court(0.22 if ouvert else 0.05, 14 if ouvert else 90)
        return hp(rng.standard_normal(len(tt)), 7500) * e

    def cymbale():
        tt, e = env_court(2.2, 2.2)
        return hp(rng.standard_normal(len(tt)), 5000) * e * 0.8

    def boum():
        tt, e = env_court(1.4, 3)
        return np.sin(2 * np.pi * (38 * tt + 60 / 8 * (1 - np.exp(-tt * 8)))) * e

    def montee(d):
        m = int(SR * d)
        tt = np.arange(m) / SR
        b = rng.standard_normal(m)
        out = np.zeros(m)
        for k in range(0, m, 960):
            fc = 300 * (12000 / 300) ** (k / m)
            out[k:k + 960] = lp(b[max(0, k - 2000):k + 960], fc)[-len(out[k:k + 960]):]
        ton_monte = np.sin(2 * np.pi * np.cumsum(220 * 2 ** (2 * tt / d)) / SR) * 0.15
        return (out + ton_monte) * (tt / d) ** 2

    for m in range(nb):
        t0 = debut(m)
        if t0 >= duree or m > final + 1:
            break
        e = energie[m]
        acc = accords[m % 4]
        tm = np.arange(int(SR * mesure)) / SR
        # la nappe, toujours là, plus claire avec l'énergie
        nap = sum(scie(hz(a), np.arange(int(SR * (mesure + 0.6))) / SR, (-8, 8)) for a in acc) / 3
        nap *= np.minimum(1, np.arange(len(nap)) / SR / 0.3) * np.clip((mesure + 0.6 - np.arange(len(nap)) / SR) / 0.6, 0, 1)
        poser(pistes['nappe'], lp(nap, 900 if e < 1 else 1800), t0, 0.22 if e < 2 else 0.1)
        if m > final:
            continue
        # les accords supersaw, qui pompent sur la grosse caisse
        if e >= 2:
            sc = scie(hz(acc[0]), tm, (-18, -7, 0, 7, 18)) + scie(hz(acc[1]), tm, (-15, 0, 15)) + scie(hz(acc[2]), tm, (-15, 0, 15)) + scie(hz(acc[0] + 12), tm, (-10, 10)) * 0.6
            pompe = 1 - 0.75 * np.exp(-(tm % temps) * 9)
            poser(pistes['accords'], lp(sc, 2600 if e < 2 else 5200) * pompe, t0, 0.13 if e < 2 else 0.17)
        # l'arpège : croches en intro et au groove, doubles croches à fond
        pas = 16 if e >= 2 else 8
        motif = [0, 1, 2, 3, 2, 1, 2, 3] * 2
        notes = acc + [acc[0] + 12]
        for c in range(pas):
            f = hz(notes[motif[c]] + 12)
            d = 0.22
            tt = np.arange(int(SR * d)) / SR
            pl = (scie(f, tt, (-6, 6)) * 0.5 + np.sin(2 * np.pi * f * tt)) * np.exp(-tt * 18)
            poser(pistes['arp'], lp(pl, 1500 if e < 1 else 4500), t0 + c * mesure / pas, 0.1 if e < 1 else 0.13)
        # la basse : croches qui roulent, l'octave sur les contretemps
        if e >= 1:
            for c in range(0, 8, 1 if e >= 2 else 2):
                f = hz(acc[0] - 24 + (12 if c % 2 else 0))
                tt = np.arange(int(SR * temps / 2)) / SR
                b = lp(scie(f, tt, (-5, 5)), 500) * np.minimum(1, tt / 0.005) * np.exp(-tt * 4)
                poser(pistes['basse'], b, t0 + c * temps / 2, 0.55)
        # la batterie
        if e >= 0.5:
            for b in range(4):
                poser(pistes['kick'], kick(1 if e >= 1 else 0.6), t0 + b * temps)
        if e >= 1:
            for b in range(4):
                poser(pistes['hh'], charley(True), t0 + b * temps + temps / 2, 0.35)
                if e >= 2:
                    for q in (1, 3):
                        poser(pistes['hh'], charley(), t0 + b * temps + q * temps / 4, 0.25)
            for b in (1, 3):
                poser(pistes['clap'], clap(), t0 + b * temps, 0.55 if e >= 2 else 0.4)
        # la montée vers une mesure plus forte, puis le drop
        suivante = energie[m + 1] if m + 1 < nb else 0
        if (suivante > e and suivante >= 1) or m + 1 == final:
            poser(pistes['fx'], montee(mesure), t0, 0.35)
            for k in range(16):  # un roulement de caisse claire qui s'accélère et grossit
                tk = t0 + mesure / 2 + (mesure / 2) * (1 - (1 - k / 16) ** 1.6)
                poser(pistes['clap'], clap(), tk, 0.12 + 0.35 * k / 16)
            poser(pistes['fx'], cymbale(), t0 + mesure, 0.55)
            poser(pistes['fx'], boum(), t0 + mesure, 0.9)
    # le final : un accord tenu, une cymbale, un grand coup de basse
    if forme.get('final'):
        tf = debut(final)
        accf = accords[2]
        tt = np.arange(int(SR * 4.5)) / SR
        tenu = sum(scie(hz(a), tt, (-12, 0, 12)) for a in accf + [accf[0] + 12, accf[0] - 12]) / 5 * np.exp(-tt * 0.9)
        poser(pistes['accords'], lp(tenu, 4000), tf, 0.35)
        poser(pistes['fx'], cymbale(), tf, 0.7)
        poser(pistes['fx'], boum(), tf, 1.0)
        poser(pistes['kick'], kick(1.2), tf)
    niveau = np.zeros(n)
    for m in range(nb):
        i, j = int(max(0, debut(m)) * SR), int(min(duree, debut(m + 1)) * SR)
        if j > i:
            niveau[i:j] = {0: 0.8, 0.5: 0.55, 1: 0.7}.get(energie[m], 1.0) if m <= final else 1.0
    niveau = np.convolve(niveau, np.ones(int(SR * 0.05)) / int(SR * 0.05), 'same')
    y = (pistes['kick'] * 0.9 + pistes['clap'] * 0.5 + hp(pistes['hh'], 6000) * 0.5 + pistes['basse'] + pistes['accords'] + pistes['arp']
         + pistes['nappe'] + pistes['fx'] * 0.6)
    t = np.arange(n) / SR
    y *= np.clip(t / 0.5, 0, 1) * np.clip((duree - t) / 1.5, 0, 1)
    y = np.tanh(y / np.abs(y).max() * 1.6) / np.tanh(1.6)  # un peu de compression, pour la densité
    return y * niveau


def bruitages(sons, n, rng):
    sfx = np.zeros(n)

    def souffle(d, f0, f1):
        m = int(SR * d)
        b = rng.standard_normal(m + 4000)
        out = np.zeros(m)
        for k in range(0, m, 480):
            fc = f0 * (f1 / f0) ** (k / m)
            # le filtre est amorcé sur 2 000 échantillons pour ne pas claquer à chaque bloc
            out[k:k + 480] = lp(b[k:k + 2480], fc)[2000:2000 + len(out[k:k + 480])]
        tt = np.arange(m) / SR
        return out * np.sin(np.pi * tt / d) ** 2

    def coup(f, d=0.5):
        tt = np.arange(int(SR * d)) / SR
        return np.sin(2 * np.pi * (f + 90 * np.exp(-tt * 25)) * tt) * np.exp(-tt * 9) + lp(rng.standard_normal(len(tt)), 1500) * np.exp(-tt * 40) * 0.6

    def clochette(m, d=1.2):
        tt = np.arange(int(SR * d)) / SR
        f = hz(m)
        return (np.sin(2 * np.pi * f * tt) + 0.4 * np.sin(2 * np.pi * f * 2.76 * tt) * np.exp(-tt * 5)) * np.exp(-tt * 3.5)

    def tape():  # un toucher : un petit « tok » boisé
        tt = np.arange(int(SR * 0.08)) / SR
        return np.sin(2 * np.pi * 1400 * tt * (1 - tt * 3)) * np.exp(-tt * 70) + hp(rng.standard_normal(len(tt)), 3000) * np.exp(-tt * 200) * 0.3

    def touche():  # une touche de clavier, très discrète
        tt = np.arange(int(SR * 0.03)) / SR
        return hp(rng.standard_normal(len(tt)), 2500) * np.exp(-tt * 260)

    def pop():
        tt = np.arange(int(SR * 0.15)) / SR
        return np.sin(2 * np.pi * (500 + 700 * np.exp(-tt * 40)) * tt) * np.exp(-tt * 28)

    def clic():
        tt = np.arange(int(SR * 0.12)) / SR
        return coup(180, 0.25) * 0.6 + np.pad(hp(rng.standard_normal(len(tt)), 2000) * np.exp(-tt * 120), (0, int(SR * 0.25) - len(tt)))

    def decale(son, retard, duree):  # un son retardé, ramené à une durée donnée
        y = np.zeros(int(SR * duree))
        poser(y, son, retard)
        return y

    BANQUE = {
        'eclosion': lambda: np.concatenate([souffle(0.7, 300, 4000) * 0.8, np.zeros(int(SR * 0.5))]) + clochette(84, 1.2) * 0.25,
        'souffle': lambda: souffle(0.55, 400, 5000),
        'recul': lambda: souffle(0.6, 5000, 400),
        'tape': lambda: tape() * 0.8,
        'touche': lambda: touche() * 0.5,
        'pop': lambda: pop() * 0.5,
        'clic': lambda: clic() * 0.7,
        'tampon': lambda: coup(110, 0.4) * 0.9,
        'impact': lambda: coup(70, 0.6),
        'note1': lambda: clochette(76) * 0.35, 'note2': lambda: clochette(79) * 0.35,
        'note3': lambda: clochette(83) * 0.35, 'note4': lambda: clochette(88) * 0.35,
        'notif': lambda: (clochette(88, 0.8) + decale(clochette(93, 0.6), 0.09, 0.8)) * 0.3,
        'succes': lambda: (clochette(81, 1.0) + decale(clochette(88, 0.9), 0.1, 1.0)) * 0.3,
        'final': lambda: (clochette(69) + clochette(76) * 0.7 + clochette(81) * 0.5) * 0.4,
        # plus percutants, pour les vidéos à la musique épique
        'whoosh': lambda: np.concatenate([souffle(0.45, 250, 7000) * 1.1, np.zeros(int(SR * 0.4))]) + decale(coup(55, 0.5) * 0.4, 0.33, 0.85),
        'boum': lambda: coup(48, 0.9) * 1.1,
        'eclat': lambda: (clochette(88, 0.7) + decale(clochette(95, 0.6), 0.06, 0.7) + decale(clochette(100, 0.5), 0.12, 0.7)) * 0.28,
    }
    for s in sons:
        poser(sfx, BANQUE[s['type']](), s['t'], s.get('gain', 1))
    return sfx


def vtt(piste):
    def hms(t):
        return f'{int(t // 3600):02d}:{int(t % 3600 // 60):02d}:{t % 60:06.3f}'
    lignes = ['WEBVTT', '']
    ph = piste['phrases']
    for i, p in enumerate(ph):
        debut = p['mots'][0]['debut'] - 0.1
        fin = min(p['fin'] + 0.6, ph[i + 1]['mots'][0]['debut'] - 0.15) if i + 1 < len(ph) else p['fin'] + 1.0
        lignes += [str(i + 1), f'{hms(max(0, debut))} --> {hms(fin)}', p['texte'], '']
    return '\n'.join(lignes)


def main(vid):
    rng = np.random.default_rng(7)
    piste = json.load(open(os.path.join(ICI, 'sortie', f'{vid}.piste.json'), encoding='utf-8'))
    duree = piste['duree']
    n = int(SR * duree)
    amb = AMBIANCES[vid]
    epique = amb.get('style') == 'epique'
    if epique:
        mus = musique_epique(duree, amb['ton'], amb['bpm'], piste['musique'], rng)
    else:
        mus = musique(duree, amb['ton'], amb['bpm'], amb['pulse'], amb['mineur'], rng)
    sfx = bruitages(piste['sons'], n, rng)
    voix = np.zeros(n)
    for i, p in enumerate(piste['phrases']):
        brut = subprocess.run(['ffmpeg', '-v', 'error', '-i', os.path.join(ICI, 'voix', vid, f'{i}.mp3'), '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'],
                              capture_output=True, check=True).stdout
        poser(voix, np.frombuffer(brut, np.float32).astype(float), p['debut'])
    voix /= np.abs(voix).max()
    # la musique descend d'environ 10 dB quand la voix parle
    env = np.convolve(np.abs(voix), np.ones(int(SR * 0.05)) / int(SR * 0.05), 'same')
    parle = np.convolve((env > 0.02).astype(float), np.ones(int(SR * 0.3)) / int(SR * 0.3), 'same').clip(0, 1)
    if epique:
        # moins d'écrasement, mais on creuse le médium sous la voix pour qu'elle reste claire
        mus = mus * (1 - parle) + (lp(mus, 900) + hp(mus, 5000) * 0.8) * parle
        mus *= 1 - 0.45 * parle
        g = mus * 0.5
    else:
        mus *= 1 - 0.68 * parle
        g = mus * 0.36
    gauche, droite = g * 0.94 + lp(g, 400) * 0.06, g
    stereo = np.stack([gauche, droite], 1) + np.stack([voix, voix], 1) * 0.85 + np.stack([sfx, sfx], 1) * 0.7
    stereo /= np.abs(stereo).max() / 0.89
    bande = os.path.join(ICI, 'sortie', f'{vid}.wav')
    wavfile.write(bande, SR, (stereo * 32767).astype(np.int16))
    os.makedirs(PUBLIC, exist_ok=True)
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', os.path.join(ICI, 'sortie', f'{vid}.muet.mp4'), '-i', bande,
                    '-map', '0:v', '-map', '1:a', '-c:v', 'copy', '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11', '-ar', '48000',
                    '-c:a', 'aac', '-b:a', '160k', '-shortest', '-movflags', '+faststart', os.path.join(PUBLIC, f'{vid}.mp4')], check=True)
    # l'affiche : un JPEG léger, à la largeur d'une carte
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', os.path.join(ICI, 'sortie', f'{vid}.jpg'), '-vf', 'scale=540:-2', '-q:v', '4',
                    os.path.join(PUBLIC, f'{vid}.jpg')], check=True)
    open(os.path.join(PUBLIC, f'{vid}.vtt'), 'w', encoding='utf-8', newline='\n').write(vtt(piste))
    taille = os.path.getsize(os.path.join(PUBLIC, f'{vid}.mp4')) / 1e6
    print(f'{vid} : {duree:.1f} s, {taille:.1f} Mo')


if __name__ == '__main__':
    for v in sys.argv[1:]:
        main(v)
