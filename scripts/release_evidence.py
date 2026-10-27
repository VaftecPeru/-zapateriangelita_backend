#!/usr/bin/env python3
"""Evidence-only gate for GitHub Actions release candidates.

No hosting access, no database writes, no GitHub mutations, no deployment.
Passing this gate NEVER means production is authorized.
"""
from __future__ import annotations

import datetime as datetime
import json
import os
import pathlib
import sys
import urllib.error
import urllib.parse
import urllib.request

API = "https://api.github.com"
FRONT_REPO = "VaftecPeru/zapateriangelita_frontend"
BACK_REPO = "VaftecPeru/-zapateriangelita_backend"
REQUIREMENTS = {
    FRONT_REPO: {
        "Validate corrective frontend": None,
        "Build dist final verified": "zapateriangelita-dist-final-verificado",
    },
    BACK_REPO: {
        "Validate corrective backend": None,
        "Package cPanel backend (no deploy)": "angelita-backend-main-cpanel-vendor",
    },
}
UNVERIFIED_PRODUCTION_REQUIREMENTS = [
    "Backup MySQL íntegro y recuperable",
    "Backup .env, storage, fotografías y adjuntos, fuera del document root",
    "Rollback probado y verificado",
    "PHP, vendor, migraciones y cron en servidor cPanel",
    "UAT de login, contraseña, variantes y checkout sin cobro real",
    "Salida SMTP del hosting y recuperación de contraseña",
    "Salud JSON de API y ausencia de desafío antibots en varios clientes",
    "Aprobación final expresa antes del deployment",
]


def api_get(path: str, token: str = "") -> dict:
    """Read public metadata; a GitHub token is optional for public repositories."""
    url = API + path
    headers = {
        "Accept": "application/vnd.github+json",
        "User-Agent": "angelita-release-evidence/1.0",
        "X-GitHub-Api-Version": "2022-11-28",
    }
    if token:
        headers["Authorization"] = "Bearer " + token
    request = urllib.request.Request(url, headers=headers, method="GET")
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return json.load(response)
    except urllib.error.HTTPError as exc:
        # GITHUB_TOKEN is repository-scoped; public sibling repos may require
        # unauthenticated GET if the token lacks access. Never bypass private access.
        if token and exc.code in (403, 404):
            return api_get(path, "")
        raise


def select_run(runs: list[dict], sha: str, workflow: str) -> dict | None:
    """The newest main push run for this exact immutable commit wins."""
    eligible = [
        item for item in runs
        if item.get("name") == workflow
        and item.get("head_sha") == sha
        and item.get("head_branch") == "main"
        and item.get("event") == "push"
    ]
    return max(
        eligible,
        key=lambda item: (item.get("created_at") or "", item.get("run_attempt") or 0),
        default=None,
    )


def evaluate_run(run: dict | None, artifact_name: str | None,
                 artifacts: list[dict] | None = None) -> tuple[bool, str]:
    if run is None:
        return False, "No se encontró una ejecución push a main para el SHA esperado."
    if run.get("status") != "completed" or run.get("conclusion") != "success":
        return False, f"Run {run.get('id')}: {run.get('status')}/{run.get('conclusion')}"
    if artifact_name:
        matched = [
            item for item in artifacts or []
            if item.get("name") == artifact_name
            and item.get("expired") is False
            and item.get("size_in_bytes", 0) > 0
        ]
        if not matched:
            return False, f"Run {run.get('id')}: falta artefacto vigente '{artifact_name}'."
        item = matched[0]
        return True, f"Run {run.get('id')} OK; artefacto {item.get('id')} ({item.get('size_in_bytes')} bytes)."
    return True, f"Run {run.get('id')} OK."


def check(name: str, ok: bool, detail: str, rows: list[dict]) -> None:
    rows.append({"check": name, "pass": bool(ok), "detail": detail[:400]})
    print(f"{'PASS' if ok else 'FAIL'} {name}: {detail}", flush=True)


def main() -> int:
    expected = {
        FRONT_REPO: os.environ.get("EXPECTED_FRONTEND_SHA", "").strip(),
        BACK_REPO: os.environ.get("EXPECTED_BACKEND_SHA", "").strip(),
    }
    token = os.environ.get("GITHUB_TOKEN", "")
    rows: list[dict] = []

    for repo, sha in expected.items():
        if len(sha) != 40 or any(char not in "0123456789abcdef" for char in sha.lower()):
            check(f"{repo} SHA", False, "Se requiere SHA de 40 caracteres.", rows)
            continue
        try:
            branch = api_get(f"/repos/{repo}/branches/main", token)
            observed = branch.get("commit", {}).get("sha")
            matches = observed == sha
            check(f"{repo} HEAD main", matches,
                  f"Esperado {sha}, GitHub main {observed}", rows)
            if not matches:
                # An unplanned main change blocks release for that repository.
                continue

            params = urllib.parse.urlencode({"head_sha": sha, "per_page": 100})
            runs = api_get(f"/repos/{repo}/actions/runs?{params}", token)
            for workflow, artifact_name in REQUIREMENTS[repo].items():
                run = select_run(runs.get("workflow_runs", []), sha, workflow)
                artifacts = None
                if run and artifact_name:
                    listing = api_get(
                        f"/repos/{repo}/actions/runs/{run['id']}/artifacts?per_page=100",
                        token,
                    )
                    artifacts = listing.get("artifacts", [])
                good, detail = evaluate_run(run, artifact_name, artifacts)
                check(f"{repo} / {workflow}", good, detail, rows)
        except (urllib.error.URLError, OSError, ValueError, KeyError) as exc:
            check(f"{repo} GitHub API", False,
                  f"No verificable ({type(exc).__name__}); falla de forma segura.", rows)

    source_green = bool(rows) and all(row["pass"] for row in rows)
    report = {
        "generated_at_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "workflow_source_sha": os.environ.get("GITHUB_SHA", ""),
        "candidate_main_shas": expected,
        "github_evidence_green": source_green,
        "archive_content_integrity_verified": False,
        "server_backups_verified": False,
        "rollback_verified": False,
        "production_approval": False,
        "deployment_decision": "NO-GO (no existe aprobación ni verificación de backups/rollback)",
        "checks": rows,
        "production_requirements": UNVERIFIED_PRODUCTION_REQUIREMENTS,
    }
    output = pathlib.Path("release-evidence.json")
    output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n",
                      encoding="utf-8")
    summary = [
        "## Zapatería Angelita — evidencia de release GitHub",
        "",
        f"**GitHub CI/artefactos:** {'PASS' if source_green else 'FAIL'}",
        "",
        "**Decisión de producción: NO-GO.** Este workflow no accede al servidor, "
        "no valida el contenido binario de los ZIP y no despliega.",
        "",
        "| Control | Resultado | Detalle |",
        "|---|---|---|",
    ]
    for row in rows:
        summary.append(
            f"| {row['check'].replace('|', '/')} | {'PASS' if row['pass'] else 'FAIL'}"
            f" | {row['detail'].replace('|', '/')} |"
        )
    summary += ["", "### Pendientes obligatorios", ""]
    summary.extend(f"- {item}" for item in UNVERIFIED_PRODUCTION_REQUIREMENTS)
    summary_text = "\n".join(summary) + "\n"
    path = os.environ.get("GITHUB_STEP_SUMMARY")
    if path:
        with open(path, "a", encoding="utf-8") as stream:
            stream.write(summary_text)
    print(summary_text)
    return 0 if source_green else 1


if __name__ == "__main__":
    sys.exit(main())
