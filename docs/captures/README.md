# Captures — la journée à côté de l'invitation

Les écrans que ce greffon expose, tels que son banc navigateur les dessine.

**Elles sont engendrées, jamais prises à la main.** Elles sortent de données
fictives, ne portent aucune donnée de locataire, et se refont d'une commande —
c'est ce qui les empêche de vieillir en silence pendant que l'interface change.

| Fichier | Ce qu'il montre |
|---|---|
| `journee-01-journee-complete.png` | Le volet de lecture : l'invitation à gauche, la journée à droite |
| `journee-02-conflit.png` | Un rendez-vous qui chevauche le créneau proposé, en ambre |
| `journee-03-volet-etroit.png` | Volet étroit : la journée passe sous l'invitation |
| `journee-04-rtl.png` | En écriture droite-à-gauche |
| `journee-05-sombre.png` | Thème sombre |

## Les refaire

```sh
podman exec puppeteer-test sh -c 'mkdir -p /essai/captures && \
  CAPTURES=/essai/captures node /essai/invitations/tests/browser/journee.js'
podman cp puppeteer-test:/essai/captures/. docs/captures/
```

Les copies dans le conteneur sont celles du `README.md` du banc, à côté.
