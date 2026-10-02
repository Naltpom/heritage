"""La consigne parlée de l'écran « Aucune correspondance trouvée » (inscription).

    python audio/consigne.py   →  audio/aide-declaration.mp3
"""
import asyncio, os
import edge_tts

TEXTE = (
    "Nous n'avons pas trouvé de fiche qui corresponde exactement à ce que vous avez saisi. "
    "Ce n'est pas grave : racontez-nous simplement qui vous êtes. "
    "Votre date de naissance, puis vos parents, avec leurs dates. "
    "Si vous avez des frères et sœurs, un conjoint ou des enfants, donnez aussi leurs dates. "
    "Vous pouvez l'écrire ci-dessous, ou appuyer sur le micro pour le dire à voix haute, comme vous préférez."
)
ICI = os.path.dirname(os.path.abspath(__file__))


async def main():
    await edge_tts.Communicate(TEXTE, 'fr-FR-DeniseNeural', rate='+0%').save(os.path.join(ICI, 'aide-declaration.mp3'))

asyncio.run(main())
