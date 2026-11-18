# Aura Icons

Reprise d'`aura_fluid` : on colle une capture de l'écran d'accueil du spectateur, les icônes sont détectées (même détection qu'aura_fluid). Ensuite, au lieu de fondre, **le spectateur peut prendre n'importe quelle icône avec le doigt et la lancer hors de son écran : elle arrive sur l'iPhone du magicien**. Le lien entre les deux iPhones passe par [AuraKit](https://github.com/splitter77/aura_framework) (Bluetooth).

## Rôles

| Où la page s'ouvre | Rôle |
| --- | --- |
| App Clip (scan du tag) | Spectateur : Paste de la capture, puis glisser‑lancer des icônes. |
| Aura Browser | Magicien : Paste de son propre écran d'accueil (ou **Black screen**), les icônes lancées y arrivent. |

Forcer un rôle : `?role=performer` ou `?role=spectator`. Code de salon au lieu de l'appairage par le tag : `?room=CODE`.

## Déroulé

1. Spectateur : copier une capture d'écran, toucher **📋 Paste**. L'écran ressemble à son écran d'accueil.
2. Il pose le doigt sur une icône : elle se soulève, son emplacement (et son nom dessous) est remplacé par le fond d'écran reconstitué.
   - Lâchée doucement : elle revient à sa place.
   - Lancée (ou lâchée hors de l'écran) : elle s'envole dans la direction du geste.
3. Magicien : l'icône entre par le bord opposé (comme si les deux téléphones étaient côte à côte), dans la même direction, glisse puis s'arrête. Vibration à l'arrivée.
4. Le magicien peut la déplacer, ou la relancer : elle revient alors se reposer à sa place sur l'écran du spectateur.

## Mode duo : deux iPhone empruntés (`?duo`)

Le téléphone du magicien n'intervient pas : deux spectateurs scannent **le même tag** (même adresse, avec `?duo`), et chacun colle la capture de son propre écran d'accueil. Chaque téléphone peut lancer ses icônes et reçoit celles de l'autre, dans n'importe quel sens.

- Une icône reçue glisse puis **se range dans la place libre la plus proche**, c'est‑à‑dire celle d'une icône que ce téléphone a lancée, à sa taille. Sans place libre, elle reste là où elle s'arrête.
- Relancée vers l'autre téléphone, une icône reçue retourne à sa place d'origine. Si une icône occupe sa place à ce moment‑là, celle‑ci glisse pour lui laisser la place.
- **Les messages passent par deux chemins à la fois** : Bluetooth (lien AuraKit) et le serveur (`relay.php`). Le premier arrivé l'emporte, le second est ignoré. Si le Bluetooth ne se connecte pas, le serveur suffit (avec internet, environ 0,1 à 0,5 s de plus).
- **Rôles Bluetooth** : c'est le serveur qui les attribue. Le premier téléphone qui ouvre la page est l'hôte, le second rejoint. Si le premier s'en va, l'autre devient hôte.
- Code de salon : `?room=CODE` (par défaut `duo`). Les deux téléphones doivent avoir le même.
- La ligne d'état sous le bouton Paste indique l'état du Bluetooth et du serveur.

Exemple d'adresse pour le tag : `https://<serveur>/?duo&room=show1`

## Transfert des icônes

Le Bluetooth est lent pour des images : elles passent donc par le serveur web.

1. Juste après la détection, le téléphone du spectateur envoie toutes les icônes en une seule requête à `upload.php`, qui les range dans `data/<session>.json` (fichiers effacés au bout de 24 h).
2. Une fois l'envoi terminé, un court message Bluetooth `icons-ready` donne au magicien l'adresse du fichier ; son téléphone télécharge toutes les icônes d'un coup. Le message est renvoyé à chaque reconnexion.
3. Pendant le tour, le Bluetooth ne transporte que de petits messages (identifiant de l'icône, direction, vitesse : environ 150 octets).

Si l'envoi au serveur échoue, l'ancien mode prend le relais : l'image part en Bluetooth dès que le spectateur touche l'icône.

## Réglages

Dans `index.html`, objet `THROW` : vitesse minimale d'un lancer, vitesse de vol, distance de glisse chez le magicien, taille de l'image envoyée.

## Fichiers

```
index.html     Page unique (détection d'aura_fluid, glisser-lancer, réception)
upload.php     Reçoit les icônes du spectateur (serveur PHP)
relay.php      Mode duo : relaie les messages entre les deux iPhone, attribue les rôles Bluetooth
dev_server.py  Serveur de test local qui imite upload.php et relay.php (pas besoin de PHP)
aurakit/       aurakit.js + aurakit-mock.js (copiés d'aura_twist)
```

## Tester sur ordinateur

```bash
python dev_server.py 5176
```

Deux onglets du même navigateur : `http://localhost:5176/?mock=join` (spectateur) et `http://localhost:5176/?mock=host` (magicien). Mode duo : `http://localhost:5176/?mock=join&duo&room=test` dans les deux onglets. `?debug` montre les zones détectées.

## Mise en ligne

Servez le dossier en HTTPS avec PHP, sur un domaine présent dans les favoris Aura, et faites pointer le lien de l'App Clip vers `index.html`. PHP doit pouvoir créer le dossier `data/` à côté d'`upload.php`. Pour un autre emplacement du script : `?upload=URL` (même domaine).
