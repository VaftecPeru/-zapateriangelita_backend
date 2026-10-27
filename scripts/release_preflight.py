#!/usr/bin/env python3
"""Read-only production preflight for GitHub Actions. No credentials or mutations."""

import datetime as dt
import json
import os
import pathlib
import sys
import urllib.error
import urllib.request

API = "https://api.zapateriangelita.com"
STORE = "https://zapateriangelita.com"
ORIGIN = STORE
TIMEOUT_SECONDS = 12
RESULT_PATH = pathlib.Path("release-preflight.json")
CHECKS = []
BLOCKERS = [
    "No se han comprobado backups y restauracion MySQL desde Actions.",
    "No se han comprobado copias .env, storage e imagenes desde Actions.",
    "No se ha validado rollback real en el hosting.",
    "No se ha comprobado PHP/vendor/migrate:status en cPanel.",
    "Faltan UAT con credenciales de prueba, correos SMTP y checkout sin cobro.",
    "Falta aprobacion final de despliegue por el responsable.",
]


def request(method, url, headers=None):
    custom_headers = {
        "User-Agent": "ZapateriaAngelita-ReleaseReadiness/1.0",
        "Accept": "application/json",
    }
    custom_headers.update(headers or {})
    req = urllib.request.Request(url, headers=custom_headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT_SECONDS) as resp:
            return resp.status, resp.headers, resp.read(131072), None
    except urllib.error.HTTPError as err:
        return err.code, err.headers, err.read(131072), None
    except Exception as err:
        return None, {}, b"", f"{type(err).__name__}: {str(err)[:160]}"


def record(name, ok, detail):
    CHECKS.append({"check": name, "ok": bool(ok), "detail": str(detail)[:260]})
    print(f"{'PASS' if ok else 'FAIL'}: {name}: {detail}")


def json_check(name, url, require_health=False):
    status, headers, payload, error = request("GET", url)
    content_type = headers.get("Content-Type", "")
    is_json = "application/json" in content_type.lower()
    parsed = None
    if is_json:
        try:
            parsed = json.loads(payload)
        except (UnicodeError, ValueError):
            pass
    ok = status == 200 and isinstance(parsed, (dict, list))
    detail = f"HTTP {status}, content-type={content_type}"
    if require_health:
        ok = (
            ok
            and isinstance(parsed, dict)
            and parsed.get("ok") is True
            and isinstance(parsed.get("database"), dict)
            and parsed["database"].get("ok") is True
            and parsed.get("app", {}).get("debug") is False
        )
        detail += ", health/DB/debug checks"
    if error:
        detail += f", {error}"
    if b"Please wait while your request is being verified" in payload:
        ok = False
        detail += ", anti-bot challenge HTML detected"
    record(name, ok, detail)


def cors_check():
    status, headers, payload, error = request(
        "OPTIONS",
        f"{API}/api/login",
        {
            "Origin": ORIGIN,
            "Access-Control-Request-Method": "POST",
            "Access-Control-Request-Headers": "content-type",
        },
    )
    allow_origin = headers.get("Access-Control-Allow-Origin", "")
    allow_methods = headers.get("Access-Control-Allow-Methods", "").lower()
    allow_headers = headers.get("Access-Control-Allow-Headers", "").lower()
    ok = (
        status in (200, 204)
        and allow_origin == ORIGIN
        and "post" in allow_methods
        and (allow_headers == "*" or "content-type" in allow_headers)
        and b"Please wait while your request is being verified" not in payload
    )
    detail = f"HTTP {status}, origin={allow_origin or 'missing'}, POST={'post' in allow_methods}"
    if error:
        detail += f", {error}"
    record("CORS preflight /api/login (sin POST de autenticacion)", ok, detail)


def html_check(name, url):
    status, headers, payload, error = request("GET", url, {"Accept": "text/html"})
    content_type = headers.get("Content-Type", "")
    challenge = b"Please wait while your request is being verified" in payload
    ok = status == 200 and "text/html" in content_type.lower() and not challenge
    detail = f"HTTP {status}, content-type={content_type}, anti-bot-challenge={challenge}"
    if error:
        detail += f", {error}"
    record(name, ok, detail)


def main():
    json_check("Laravel /api/health y conexion MySQL", f"{API}/api/health", True)
    cors_check()
    json_check("Lectura publica de producto #976", f"{API}/api/products/976")
    html_check("Tienda principal", STORE)
    html_check("Checkout navegable sin realizar cobro", f"{STORE}/checkout")

    probe_ok = all(item["ok"] for item in CHECKS)
    report = {
        "checked_at_utc": dt.datetime.now(dt.timezone.utc).isoformat(),
        "git_sha": os.environ.get("GITHUB_SHA", ""),
        "probe_ok": probe_ok,
        "deployment_authorized": False,
        "release_decision": "NO-GO: requisitos manuales sin comprobar",
        "checks": CHECKS,
        "release_blockers": BLOCKERS,
    }
    RESULT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    summary_path = os.environ.get("GITHUB_STEP_SUMMARY")
    lines = [
        "## Zapateria Angelita — Preflight SOLO LECTURA",
        "",
        f"**Red/API:** {'PASS' if probe_ok else 'FAIL'}",
        "**Liberacion:** NO-GO — respaldos, rollback, UAT y aprobacion no automatizados todavia.",
        "",
        "| Prueba | Estado | Evidencia |",
        "|---|---|---|",
    ]
    for check in CHECKS:
        detail = check["detail"].replace("|", "/").replace("\n", " ")
        lines.append(f"| {check['check']} | {'PASS' if check['ok'] else 'FAIL'} | {detail} |")
    lines.extend(["", "### Bloqueantes antes de produccion", ""])
    lines.extend(f"- {blocker}" for blocker in BLOCKERS)
    lines.append("")
    if summary_path:
        with open(summary_path, "a", encoding="utf-8") as summary:
            summary.write("\n".join(lines) + "\n")
    print("\n".join(lines))
    return 0 if probe_ok else 1


if __name__ == "__main__":
    sys.exit(main())
