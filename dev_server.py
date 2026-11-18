"""Local test server: serves the folder and emulates upload.php (no PHP needed).

    python dev_server.py [port]      (default 5176)
"""
import json
import re
import sys
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"


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

    def do_POST(self):
        if self.path.split("?")[0] != "/upload.php":
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
