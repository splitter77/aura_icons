"""Local test server: serves the folder and emulates upload.php and relay.php (no PHP needed).

    python dev_server.py [port]      (default 5176)
"""
import json
import re
import sys
import threading
import time
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"

# relay.php: rooms kept in memory
ROOMS = {}
COND = threading.Condition()
PEER_TTL, MSG_TTL, WAIT = 20, 60, 8


def room_state(name):
    s = ROOMS.setdefault(name, {"seq": 0, "msgs": [], "peers": {}})
    now = time.time()
    s["peers"] = {k: v for k, v in s["peers"].items() if v["last"] > now - PEER_TTL}
    s["msgs"] = [m for m in s["msgs"] if m["at"] > now - MSG_TTL][-60:]
    return s


def touch(s, me):
    now = time.time()
    first = s["peers"].get(me, {}).get("first", now)
    s["peers"][me] = {"first": first, "last": now}


def peer_list(s):
    return [k for k, _ in sorted(s["peers"].items(), key=lambda kv: kv[1]["first"])]


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def reply(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def relay_args(self, d):
        room, me = d.get("room", ""), d.get("me", "")
        if not re.fullmatch(r"[a-z0-9-]{1,40}", str(room)) or not re.fullmatch(r"[a-z0-9]{6,40}", str(me)):
            return None, None
        return room, me

    def do_GET(self):
        u = urlparse(self.path)
        if u.path != "/relay.php":
            return super().do_GET()
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        room, me = self.relay_args(q)
        if not room:
            return self.reply(400, {"ok": False, "error": "bad room or id"})
        since = int(q.get("since", -1))
        end = time.time() + WAIT
        with COND:
            s = room_state(room)
            is_new = me not in s["peers"]
            touch(s, me)
            if is_new:
                COND.notify_all()   # the others learn right away that a new iPhone is there
            known = peer_list(s)
            while True:
                out = [] if since < 0 else [{"t": m["t"], "d": m["d"]} for m in s["msgs"]
                                            if m["seq"] > since and m["from"] != me]
                left = end - time.time()
                if out or since < 0 or left <= 0 or peer_list(s) != known:
                    break
                COND.wait(left)
                s = room_state(room)
            res = {"ok": True, "seq": s["seq"], "msgs": out, "peers": peer_list(s)}
        self.reply(200, res)

    def do_POST(self):
        path = self.path.split("?")[0]
        if path == "/relay.php":
            n = int(self.headers.get("Content-Length") or 0)
            try:
                d = json.loads(self.rfile.read(n))
            except ValueError:
                return self.reply(400, {"ok": False, "error": "bad json"})
            room, me = self.relay_args(d)
            if not room:
                return self.reply(400, {"ok": False, "error": "bad room or id"})
            with COND:
                s = room_state(room)
                if d.get("leave"):
                    s["peers"].pop(me, None)
                    ROOMS[room] = s
                else:
                    touch(s, me)
                    s["seq"] += 1
                    s["msgs"].append({"seq": s["seq"], "from": me, "t": d.get("t"), "d": d.get("d"), "at": time.time()})
                COND.notify_all()
                res = {"ok": True, "seq": s["seq"], "peers": peer_list(s)}
            return self.reply(200, res)
        if path != "/upload.php":
            return self.reply(404, {"ok": False, "error": "not found"})
        n = int(self.headers.get("Content-Length") or 0)
        if n > 4_000_000:
            return self.reply(413, {"ok": False, "error": "too large"})
        try:
            d = json.loads(self.rfile.read(n))
        except ValueError:
            return self.reply(400, {"ok": False, "error": "bad json"})
        session = d.get("session", "")
        if not isinstance(session, str) or not re.fullmatch(r"[a-z0-9]{6,40}", session):
            return self.reply(400, {"ok": False, "error": "bad session"})
        icons = d.get("icons")
        if not isinstance(icons, list):
            return self.reply(400, {"ok": False, "error": "bad icons"})
        for ic in icons:
            if not re.fullmatch(r"[a-z0-9-]{1,60}", str(ic.get("id", ""))) or \
               not str(ic.get("src", "")).startswith("data:image/png;base64,"):
                return self.reply(400, {"ok": False, "error": "bad icon"})
        DATA.mkdir(exist_ok=True)
        (DATA / f"{session}.json").write_text(json.dumps({"session": session, "icons": icons}))
        self.reply(200, {"ok": True, "url": f"data/{session}.json"})


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 5176
    print(f"http://localhost:{port}/")
    ThreadingHTTPServer(("", port), Handler).serve_forever()
