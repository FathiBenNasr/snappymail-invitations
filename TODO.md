
## La coque charge enfin la feuille du cœur — 5 septembre 2026

Le banc jugeait une mise en page **sans la feuille qui peut la défaire** : il
n'écrivait à la main que les rôles de couleur, et ne chargeait `app.css` nulle
part. Un greffon peut alors se dessiner correctement dans le banc et de travers
en production — la forme de panne rapportée par la session `VisualTest` après
son parcours contre la production, résumée en §29 de la suite : *une coque est
fidèle quand elle a **autant** que la production, et **pas plus***.

Ce qui a été fait :

* `apercu.html` charge `app.css` **avant** la feuille du greffon, dans l'ordre
  de la production ;
* `preparer.sh` la **reprend de la production à chaque passage**
  (`COEUR=` pour la déplacer) — figée dans le dépôt, elle vieillirait en silence
  pendant que SnappyMail avance ;
* **un contrôle dit qu'elle est chargée**, et il compte les règles plutôt que de
  regarder le `<link>` : un 404 laisse la feuille dans `document.styleSheets`
  avec **zéro règle**, donc la présence de la balise ne prouve rien.

**Le résultat compte autant que le contrôle : rien n'a bougé.** 36 contrôles
verts avant, **37 verts après** — la mise en page du volet tient sous les règles
du cœur. C'était l'inconnue, et elle est levée.

Éprouvé en le faisant échouer : `app.css` retiré du conteneur, le contrôle tombe
seul — « 0 règles lues depuis app.css » — et les trente-six autres restent
verts, ce qui montre exactement ce que le banc ne voyait pas avant.
