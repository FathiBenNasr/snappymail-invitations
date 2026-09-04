# Le banc de la journée

`php tests/run.php` prouve ce que le serveur renvoie ; `node --test` prouve
l'arithmétique de la bande — quel pourcentage pour quel bloc. Ni l'un ni l'autre
ne touche à ce qui décide si la colonne est **lisible**, et aucune des pannes
ci-dessous ne lève d'erreur.

| Ce que le banc regarde | Pourquoi |
|---|---|
| la carte est rendue, avec ses trois boutons | **une liaison `data-bind` qui échoue emporte tout le sous-arbre** — la carte disparaît entière, boutons compris, sans un mot dans la console |
| les blocs tombent en face de leur heure | une propriété CSS mal écrite est ignorée en silence : `insetBlockStart` fautif empile tout sur la première heure |
| deux réunions simultanées ne se recouvrent pas | sans les voies, la seconde n'existe que dans le DOM |
| le bloc en conflit ne se peint pas comme les autres | c'est la seule raison d'être de la colonne |
| l'entrée « toute la journée » est une pastille | dessinée sur la piste, elle la remplirait |
| un agenda illisible **le dit** | dessiné vide, il ressemble à un après-midi libre — la pire des trois sorties |
| au large côte à côte, à l'étroit dessous | la largeur qui compte est celle du volet de lecture, pas celle de la fenêtre |
| en RTL, l'axe et les voies basculent ensemble | une propriété physique oubliée envoie l'axe d'un côté et les blocs de l'autre |
| en RTL, les textes gardent leur sens propre | voir ci-dessous : le banc a trouvé la panne |
| les couleurs bougent quand le thème bascule | une couleur codée en dur ne bouge pas, et c'est ce que le lot 2 est censé avoir supprimé |

## Trois pannes que ce banc a déjà trouvées

- **Le nom coupé par la tête.** Dans une page en arabe, `text-overflow: ellipsis`
  tronque par la fin *logique*, qui est la gauche : « Entretien annuel » se
  lisait « …nuel ». Et « 1 event overlaps this slot. » se réordonnait en
  « .event overlaps this slot 1 », le chiffre et le point étant neutres.
  Corrigé par `unicode-bidi: plaintext` — le sens d'un texte est décidé par son
  premier caractère fort, pas par la page.
- **La journée datée de la veille.** Les heures étaient formatées dans le fuseau
  du navigateur pendant que la bande était tracée depuis le minuit d'un autre :
  une réunion de 9 h dessinée à neuf heures et **étiquetée huit**, la veille.
  Les deux fuseaux coïncident tant que le navigateur a pu annoncer le sien —
  c'est-à-dire dans le cas ordinaire, celui qu'on regarde en concevant.
- **La deuxième ligne coupée en son milieu.** Une réunion d'une heure fait
  vingt-cinq pixels de haut ; le nom *et* l'heure n'y tiennent pas. Le bloc
  tient désormais sur une ligne, et c'est l'heure — déjà lisible sur l'axe —
  qui est tronquée, jamais le nom.

## Ce que la page fournit, et pourquoi si peu

`apercu.html` n'est pas une copie de SnappyMail : c'est le strict nécessaire
pour que le greffon réel s'exécute **sans être modifié**.

- un `<template id="MailMessageView">` portant un `.attachmentsPlace` — c'est
  tout ce que le greffon touche du cœur ;
- `Element.fromHTML`, qui est une extension de SnappyMail et **pas** une API du
  DOM : sans elle le greffon lève à l'insertion et la carte n'existe jamais ;
- `window.rl` déclaré **avant** le `<script src>` du greffon — il le lit à son
  chargement ; déclaré après, il vaut `undefined` pour toujours ;
- le montage par `ko.applyBindingAccessorsToNode` avec une liaison `template`,
  repris de `dev/Knoin/Knoin.js`. Le Knockout de SnappyMail est une variante
  (`3.5.1-sm`) qui **n'expose pas** `applyBindings` : monter avec le Knockout
  d'amont validerait un chemin qui n'existe pas en production ;
- les rôles `--cv-*` du thème Convergent, clair et sombre, et un corps à 13 px.

## Lancer

Le Knockout est celui de SnappyMail, pris dans le dépôt voisin — pas une copie
versionnée ici.

```sh
sh tests/browser/preparer.sh
```

Elle recopie le banc, **les deux fichiers du greffon** et knockout — ce dernier
**sous le nom `knockout.js`**, qui n'est pas son nom de départ : `apercu.html`
charge ce nom-là, et déposé autrement le banc s'arrête sur
`TypeError: Cannot read properties of undefined (reading 'r')`, où c'est le
greffon qui tombe et non la page.

⚠️ **Pourquoi une commande, et non les huit lignes d'avant.** Le 4 septembre
2026, le banc de `snappymail-servicedesk` s'est révélé éprouver depuis cinq
jours une copie du greffon **d'avant son garde `authentifie()`** : son README
demandait de rafraîchir les copies à la main, et personne ne l'avait fait. Il
restait vert. *Une copie qu'il faut penser à rafraîchir diverge ; une commande
qui la rafraîchit toujours ne le peut pas.*

**Les cinq copies ne sont pas facultatives.** Sans elles la page se charge sans
le greffon, la carte ne se dessine pas, et le banc annonce trente-six échecs
identiques.

Pour garder les captures :

```sh
podman exec puppeteer-test sh -c 'mkdir -p /essai/captures && \
  CAPTURES=/essai/captures node /essai/invitations/tests/browser/journee.js'
podman cp puppeteer-test:/essai/captures ./captures
```

## Ce que le banc ne peut pas voir

SnappyMail **concatène le JS de tous les greffons** en un seul paquet : une
erreur de syntaxe dans l'un emporte tous les autres, et le banc ne regarde que
celui-ci. Le contrôle qui manque est côté production, après dépôt :

```sh
curl -s -H 'Host: webmail.example.com' \
  'http://127.0.0.1/?/Plugins/0/' | grep -c 'meeting-invitation-day'
```
