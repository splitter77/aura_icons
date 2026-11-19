# Aura Icons

A spin-off of `aura_fluid`: you paste a screenshot of the spectator's home screen and the icons are detected (same detection as aura_fluid). Then, instead of melting, **the spectator can grab any icon with a finger and throw it off their screen: it lands on the magician's iPhone**. The two iPhones are linked through [AuraKit](https://github.com/splitter77/aura_framework) (Bluetooth).

## Roles

| Where the page opens | Role |
| --- | --- |
| App Clip (tag scan) | Spectator: paste the screenshot, then drag and throw icons. |
| Aura Browser | Magician: paste their own home screen (or **Black screen**); thrown icons land there. |

Force a role: `?role=performer` or `?role=spectator`. Room code instead of pairing through the tag: `?room=CODE`.

## Flow

1. Spectator: copy a screenshot, tap **📋 Paste**. The screen looks like their home screen.
2. They put a finger on an icon: it lifts up, and its spot (and the name below it) is replaced by the reconstructed wallpaper.
   - Released gently: it goes back to its spot.
   - Thrown (or released off screen): it flies away in the direction of the gesture.
3. Magician: the icon enters from the opposite edge (as if the two phones were side by side), in the same direction, glides, then stops. Vibration on arrival.
4. The magician can move it, or throw it back: it then returns to rest in its spot on the spectator's screen.

## Duo mode: two borrowed iPhones (`?duo`)

The magician's phone is not involved: two spectators scan **the same tag** (same address, with `?duo`), and each pastes a screenshot of their own home screen. Each phone can throw its icons and receives the other's, in any direction.

- A received icon glides, then **settles into the nearest free spot**, i.e. the spot of an icon this phone has thrown, at that icon's size. If there is no free spot, it stays where it stops.
- Thrown back to the other phone, a received icon returns to its original spot. If an icon occupies that spot at that moment, it slides away to make room.
- **Messages travel over two paths at once**: Bluetooth (AuraKit link) and the server (`relay.php`). The first to arrive wins, the second is ignored. If Bluetooth does not connect, the server is enough (with internet, roughly 0.1 to 0.5 s slower).
- **Bluetooth roles** are assigned by the server. The first phone to open the page is the host, the second joins. If the first one leaves, the other becomes host.
- Room code: `?room=CODE` (default `duo`). Both phones must use the same one.
- `duo`, `room` and `role` are read from the page address or, in the App Clip, from the scanned link (`Aura.launchParams()`), e.g. `https://appclip.apple.com/id?p=…&duo&room=show1`.
- The status line under the Paste button shows the Bluetooth and server state.

Example tag address: `https://<server>/?duo&room=show1`

## Locking the App Clip (volume button)

In the App Clip (spectator, or both phones in duo mode), pressing **either volume button** locks the App Clip, with a light vibration: the App Clip quits 0.5 s later, without a black screen (`LOCK_PRESS_QUIT_DELAY`). If it is relaunched, it only shows a black screen, then quits after about 0.6 s. The lock is kept in the App Clip's storage.

- The lock clears itself after 12 h (`LOCK_HOURS` at the top of `index.html`).
- Unlock right away: scan an App Clip link (NFC tag or QR code) with `&unlock` at the end, e.g. `https://appclip.apple.com/id?p=…&userId=…&unlock`. This parameter only works once: for it to work again, a link without `&unlock` (the usual tag for the trick) must be scanned first. Outside the App Clip: `?unlock` in the address.
- Volume changes within 1.5 s of launch are ignored (detection starting up).
- Requires a version of Aura that supports the volume buttons (Aura 1.9+, `feature/aurakit-quit` branch of fsbrowser). The status line under the Paste button shows `Volume: ready`, `locked`, or `not supported by this Aura version`. In the App Clip, iOS may show the volume indicator during the press.
- Quitting the App Clip requires Bluetooth to be on and authorized; otherwise the screen stays black.
- On a computer (`?mock=join`): **+** or **-** key.

## Icon transfer

Bluetooth is slow for images, so they go through the web server.

1. Right after detection, the spectator's phone sends all the icons in a single request to `upload.php`, which stores them in `data/<session>.json` (files deleted after 24 h).
2. Once the upload is done, a short `icons-ready` Bluetooth message gives the magician the file's address; their phone downloads all the icons at once. The message is resent on every reconnection.
3. During the trick, Bluetooth only carries small messages (icon ID, direction, speed: about 150 bytes).

If the upload to the server fails, the old mode takes over: the image is sent over Bluetooth as soon as the spectator touches the icon.

## Settings

In `index.html`, `THROW` object: minimum throw speed, flight speed, glide distance on the magician's side, size of the sent image.

## Files

```
index.html     Single page (aura_fluid detection, drag-and-throw, receiving)
upload.php     Receives the spectator's icons (PHP server)
relay.php      Duo mode: relays messages between the two iPhones, assigns Bluetooth roles
dev_server.py  Local test server that mimics upload.php and relay.php (no PHP needed)
aurakit/       aurakit.js + aurakit-mock.js (copied from aura_twist)
```

## Testing on a computer

```bash
python dev_server.py 5176
```

Two tabs in the same browser: `http://localhost:5176/?mock=join` (spectator) and `http://localhost:5176/?mock=host` (magician). Duo mode: `http://localhost:5176/?mock=join&duo&room=test` in both tabs (as the App Clip gets it from the scanned link: `?mock=join&launch=duo%26room%3Dtest`). `?debug` shows the detected areas.

## Deployment

Serve the folder over HTTPS with PHP, on a domain listed in Aura's bookmarks, and point the App Clip link to `index.html`. PHP must be able to create the `data/` folder next to `upload.php`. For a different script location: `?upload=URL` (same domain).
