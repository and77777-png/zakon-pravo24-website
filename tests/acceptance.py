import http.cookiejar
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
php = sys.argv[1]
with tempfile.TemporaryDirectory(prefix="zakon-acceptance-") as directory:
    env = dict(os.environ, ZAKON_DATA=directory)
    password = os.urandom(22).hex()
    hashed = subprocess.check_output(
        [php, "-r", 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT);'],
        input=password.encode(), env=env
    ).decode()
    Path(directory, "config.php").write_text(
        "<?php return ['password_hash' => '" + hashed + "'];", encoding="ascii"
    )
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]
    args = [php, "-d", "extension_dir=" + str(Path(php).parent / "ext"),
            "-d", "extension=gd", "-S", f"127.0.0.1:{port}", "tests/router.php"]
    process = subprocess.Popen(args, cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    base = f"http://127.0.0.1:{port}"
    def request(path, data=None, headers=None):
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data).encode()
        try:
            response = client.open(urllib.request.Request(base + path, data=data, headers=headers or {}), timeout=4)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read(), response.headers

    try:
        for _ in range(40):
            try:
                assert request("/")[0] == 200
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        _, sitemap, _ = request("/sitemap.xml")
        routes = [urllib.parse.urlsplit(node.text).path for node in ET.fromstring(sitemap).iterfind(".//{*}loc")]
        assert len(routes) == 17
        for route in routes:
            status, html, headers = request(route)
            assert status == 200 and b'noindex,nofollow' in html and b'application/ld+json' in html, route
            assert f'https://zakon-pravo24.ru{route}'.encode() in html and 'noindex' in headers['X-Robots-Tag']
        assert request("/unknown-page/")[0] == 404
        assert b'Disallow: /' in request("/robots.txt")[1]
        assert request("/admin/", {"action": "publish"})[0] == 403

        def input_value(html, name):
            return re.search(rb'name="' + name.encode() + rb'" value="([^"]+)"', html)[1].decode()

        login = request("/admin/")[1]
        assert request("/admin/?preview=1")[1] == login
        status, page, _ = request("/admin/", {
            "action": "login", "csrf": input_value(login, "csrf"), "password": password
        })
        assert status == 200 and b'name="version"' in page

        def action(kind, extra=None):
            html = request("/admin/")[1]
            values = {"action": kind, "csrf": input_value(html, "csrf"), "version": input_value(html, "version")}
            values.update(extra or {})
            return request("/admin/", values)

        title = re.search(rb'name="values\[(text-0-[0-9a-f]+)\]"', page)[1].decode()
        assert action("save", {f"values[{title}]": "Acceptance & draft <example>"})[0] == 200
        assert b"<title>Acceptance &amp; draft &lt;example&gt;</title>" in request("/admin/?preview=1")[1]
        assert b"Acceptance" not in request("/")[1]
        assert action("publish")[0] == 200
        assert b"Acceptance" in request("/")[1]
        assert action("backup")[0] == 200
        assert action("rollback", {"target": 0})[0] == 200
        assert b"Acceptance" not in request("/")[1]
        assert action("restore")[0] == 200
        assert b"Acceptance" in request("/")[1]
        assert action("save", {"version": 0, f"values[{title}]": "stale update"})[0] == 422
        html = request("/admin/")[1]
        boundary = "zakon-" + os.urandom(12).hex()
        parts = [
            (f'Content-Disposition: form-data; name="{key}"', value.encode())
            for key, value in {"action": "upload", "csrf": input_value(html, "csrf"),
                               "version": input_value(html, "version")}.items()
        ]
        parts.append(('Content-Disposition: form-data; name="photo"; filename="test.webp"\r\nContent-Type: image/webp',
                      (root / "assets" / "lawyer-portrait.webp").read_bytes()))
        body = b"".join(f"--{boundary}\r\n{head}\r\n\r\n".encode() + value + b"\r\n" for head, value in parts)
        status, html, _ = request("/admin/", body + f"--{boundary}--\r\n".encode(),
                                  {"Content-Type": f"multipart/form-data; boundary={boundary}"})
        assert status == 200, status
        media = re.search(rb'/media.php\?id=([a-f0-9]{64})&amp;preview', html)[1].decode()
        path = "/media.php?id=" + media
        assert request(path)[0] == 404 and request(path + "&preview=1")[0] == 200
        image = re.search(rb'name="values\[(src-[0-9]+-[0-9a-f]+)\]"', html)[1].decode()
        assert action("save", {f"values[{title}]": "Acceptance", f"values[{image}]": path})[0] == 200
        assert action("publish")[0] == 200 and request(path)[2]["Content-Type"] == "image/webp"
        assert action("logout")[0] == 200 and request(path + "&preview=1")[0] == 404
        print("PASS: 17 pages, robots, sitemap, 404, authentication, CSRF, draft, publish, backup, rollback, restore, optimistic lock, media, logout")
    finally:
        process.terminate()
        process.wait(timeout=5)
