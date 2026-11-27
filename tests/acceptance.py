import html as html_entities
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
    args = [php, "-d", "opcache.enable_cli=0"]
    if os.name == "nt":
        args += ["-d", "extension_dir=" + str(Path(php).parent / "ext"), "-d", "extension=gd"]
    args += ["-S", f"127.0.0.1:{port}", "tests/router.php"]
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

        if len(sys.argv) > 2:
            from playwright.sync_api import sync_playwright
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(executable_path=sys.argv[2])
                for width in (320, 390, 768, 1440):
                    view = browser.new_page(viewport={"width": width, "height": 900}, reduced_motion="reduce")
                    errors = []
                    view.on("pageerror", lambda error: errors.append(str(error)))
                    for route in ("/", "/uslugi/", "/privacy/"):
                        url = base + route
                        original = root / route.strip("/") / "index.html"
                        view.route(url, lambda flow: flow.fulfill(
                            body=original.read_bytes(), content_type="text/html; charset=UTF-8"),
                            times=1)
                        view.goto(url, wait_until="networkidle")
                        before = view.screenshot(animations="disabled")
                        view.goto(url, wait_until="networkidle")
                        assert view.screenshot(animations="disabled") == before, (route, width)
                    view.goto(base + "/admin/", wait_until="networkidle")
                    assert view.locator("input[type=password]").is_visible()
                    assert view.evaluate("document.documentElement.scrollWidth === innerWidth")
                    assert not errors, errors
                    view.close()
                browser.close()
            print("PASS: 12 pixel comparisons, admin login responsive, no page errors")
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
        assert re.search(rb"<title[^>]*>Acceptance &amp; draft &lt;example&gt;</title>", request("/admin/?preview=1")[1])
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
        page = request("/admin/")[1]
        assert request("/admin/", {"action": "login", "csrf": input_value(page, "csrf"), "password": password})[0] == 200
        assert action("contacts", {"mobile": "+7 (999) 000-00-01", "landline": "+7 (391) 000-00-02",
                                   "telegram": "https://t.me/Acceptance_Test", "max": "https://max.ru/u/AcceptanceTest"})[0] == 200
        assert b"tel:+79990000001" not in request("/")[1]
        assert action("publish")[0] == 200
        for route in routes:
            html = request(route)[1]
            assert b"tel:+79990000001" in html and b"+7 (999) 000-00-01" in html, route
            assert b"tel:+79333385567" not in html and b"https://t.me/Tati_Urist" not in html, route
        assert "bytes" not in Path(directory, "state.json").read_text(encoding="utf-8")
        assert Path(directory, "media-" + media + ".webp").exists()
        assert action("delete_media", {"media_id": media})[0] == 422
        assert action("service", {"slug": "acceptance-service", "title": "Проверка услуги",
                                  "description": "Только синтетические данные", "order": 1})[0] == 200
        assert action("service", {"slug": "bankrotstvo", "title": "Банкротство", "description": "",
                                  "order": 2, "hidden": "on"})[0] == 200
        assert request("/acceptance-service/")[0] == 404 and request("/bankrotstvo/")[0] == 200
        assert action("publish")[0] == 200
        assert "Только синтетические данные" in html_entities.unescape(request("/acceptance-service/")[1].decode("utf-8"))
        assert request("/bankrotstvo/")[0] == 404
        catalog = request("/uslugi/")[1]
        assert b'href="/acceptance-service/"' in catalog and b'../bankrotstvo/' not in catalog
        assert catalog.index(b'href="/acceptance-service/"') < catalog.index(b'directory-item" href="../ekspertiza/')
        sitemap = request("/sitemap.xml")[1]
        assert b'/acceptance-service/' in sitemap and b'/bankrotstvo/' not in sitemap
        assert request("/stroitelnaya_ekspertiza")[0] == 200  # urllib follows the 301
        assert list(Path(directory).glob('release-*/*.html'))
        new_password = os.urandom(22).hex()
        assert action("password", {"current_password": password, "new_password": new_password,
                                   "repeat_password": new_password})[0] == 200
        page = request("/admin/")[1]
        assert b'name="version"' not in page
        assert request("/admin/", {"action": "login", "csrf": input_value(page, "csrf"), "password": password})[0] == 422
        assert request("/admin/", {"action": "login", "csrf": input_value(page, "csrf"), "password": new_password})[0] == 200
        committed = Path(directory, "state.json").read_bytes()
        live = request("/acceptance-service/")[1]
        probe = """
        require 'admin/core.php';
        [$directory] = openStore();
        try {
            state($directory, static function (array &$data) use ($directory): void {
                $data['release'] = releasePages($directory, [], [], [], PHP_INT_MAX);
            });
            exit(1);
        } catch (RuntimeException $error) {
            exit(0);
        }
        """
        subprocess.run([php, "-r", probe], cwd=root, env=env, check=True)
        assert Path(directory, "state.json").read_bytes() == committed
        assert request("/acceptance-service/")[1] == live
        assert request("/sitemap")[1] == request("/sitemap.xml")[1]
        assert request("/privacy/index.html")[1] == request("/privacy/")[1]
        print("PASS: simulated low disk leaves state and published HTML intact")
        print("PASS: centralized contacts, external media files, referenced-image guard, password rotation")
        print("PASS: 17 pages, robots, sitemap, 404, authentication, CSRF, draft, publish, backup, rollback, restore, optimistic lock, media, logout")
    finally:
        process.terminate()
        process.wait(timeout=5)
