#!/usr/bin/env python3
"""Fail-closed, read-only SSH capability probe for GitHub Actions.

This script only authenticates using a host-key-pinned SSH connection and
executes a constant set of 'command -v' checks. It does not read application
secrets, files, database contents, or modify the host.
"""
from __future__ import annotations

import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path


REQUIRED = (
    "ANGELITA_READONLY_SSH_HOST",
    "ANGELITA_READONLY_SSH_USER",
    "ANGELITA_READONLY_SSH_PRIVATE_KEY",
    "ANGELITA_READONLY_SSH_KNOWN_HOSTS",
)
MARKERS = {
    "ssh": "ANGELITA_SSH_PROBE_OK",
    "php": "ANGELITA_PHP_AVAILABLE",
    "mysql_dump": "ANGELITA_DB_DUMP_AVAILABLE",
    "tar": "ANGELITA_TAR_AVAILABLE",
    "gzip": "ANGELITA_GZIP_AVAILABLE",
}
REMOTE_COMMAND = (
    "printf 'ANGELITA_SSH_PROBE_OK\\n'; "
    "if command -v php >/dev/null 2>&1 || test -x /opt/alt/php83/usr/bin/php; "
    "then printf 'ANGELITA_PHP_AVAILABLE\\n'; fi; "
    "if command -v mysqldump >/dev/null 2>&1 || "
    "command -v mariadb-dump >/dev/null 2>&1; "
    "then printf 'ANGELITA_DB_DUMP_AVAILABLE\\n'; fi; "
    "if command -v tar >/dev/null 2>&1; then printf 'ANGELITA_TAR_AVAILABLE\\n'; fi; "
    "if command -v gzip >/dev/null 2>&1; then printf 'ANGELITA_GZIP_AVAILABLE\\n'; fi"
)


def check_config(env: dict[str, str]) -> tuple[list[str], int]:
    missing = [name for name in REQUIRED if not env.get(name, "").strip()]
    if missing:
        return missing, 0

    host = env["ANGELITA_READONLY_SSH_HOST"].strip()
    user = env["ANGELITA_READONLY_SSH_USER"].strip()
    raw_port = env.get("ANGELITA_READONLY_SSH_PORT", "22").strip() or "22"

    if not re.fullmatch(r"[A-Za-z0-9_.:-]{1,253}", host) or host.startswith("-"):
        raise ValueError("Formato de host SSH invalido")
    if not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", user) or user.startswith("-"):
        raise ValueError("Formato de usuario SSH invalido")
    if not raw_port.isdecimal() or not (1 <= int(raw_port) <= 65535):
        raise ValueError("Puerto SSH invalido")
    if not env["ANGELITA_READONLY_SSH_PRIVATE_KEY"].strip().startswith("-----BEGIN "):
        raise ValueError("Se requiere una clave SSH privada en formato PEM/OpenSSH")
    if "ssh-" not in env["ANGELITA_READONLY_SSH_KNOWN_HOSTS"] and "ecdsa-" not in env["ANGELITA_READONLY_SSH_KNOWN_HOSTS"]:
        raise ValueError("Se requiere archivo known_hosts de clave publica verificada")
    return [], int(raw_port)


def interpret_markers(stdout: str) -> dict[str, bool]:
    observed = set(stdout.splitlines())
    return {key: marker in observed for key, marker in MARKERS.items()}


def main() -> int:
    env = dict(os.environ)
    try:
        missing, port = check_config(env)
    except ValueError as exc:
        print(f"::error::{exc}", flush=True)
        return 2
    if missing:
        print("::error::Faltan secrets de Environment GitHub: " + ", ".join(missing), flush=True)
        return 2

    host = env["ANGELITA_READONLY_SSH_HOST"].strip()
    user = env["ANGELITA_READONLY_SSH_USER"].strip()

    with tempfile.TemporaryDirectory(prefix="angelita-ssh-check-") as folder:
        root = Path(folder)
        root.chmod(0o700)
        keyfile = root / "id_readonly"
        hostsfile = root / "known_hosts"
        keyfile.write_text(env["ANGELITA_READONLY_SSH_PRIVATE_KEY"].rstrip("\n") + "\n", encoding="utf-8")
        hostsfile.write_text(env["ANGELITA_READONLY_SSH_KNOWN_HOSTS"].rstrip("\n") + "\n", encoding="utf-8")
        keyfile.chmod(0o600)
        hostsfile.chmod(0o600)

        command = [
            "ssh",
            "-F", "/dev/null",
            "-i", str(keyfile),
            "-p", str(port),
            "-o", "BatchMode=yes",
            "-o", "StrictHostKeyChecking=yes",
            "-o", "IdentitiesOnly=yes",
            "-o", "PasswordAuthentication=no",
            "-o", "KbdInteractiveAuthentication=no",
            "-o", "ConnectTimeout=12",
            "-o", "ConnectionAttempts=1",
            "-o", f"UserKnownHostsFile={hostsfile}",
            "-o", "GlobalKnownHostsFile=/dev/null",
            "-o", "LogLevel=ERROR",
            f"{user}@{host}",
            REMOTE_COMMAND,
        ]
        try:
            result = subprocess.run(
                command, capture_output=True, text=True, timeout=35, check=False
            )
        except (OSError, subprocess.TimeoutExpired):
            print("::error::La conexion SSH de solo lectura no pudo completarse.", flush=True)
            return 1

    if result.returncode != 0:
        # No imprimir stderr de ssh: podria contener informacion del servidor.
        print("::error::Fallo autenticacion o validacion de host SSH. Revisar secretos y huella.", flush=True)
        return 1

    outcomes = interpret_markers(result.stdout)
    summary = ["### Zapateria Angelita: SSH de solo lectura", ""]
    for name, passed in outcomes.items():
        summary.append(f"- {name}: {'PASS' if passed else 'NO DETECTADO'}")
        print(f"{name}: {'PASS' if passed else 'NO DETECTADO'}", flush=True)

    summary += [
        "",
        "**No se revisaron backups, DB, .env ni directorios; no hay autorizacion para desplegar.**",
    ]
    summary_file = env.get("GITHUB_STEP_SUMMARY")
    if summary_file:
        with open(summary_file, "a", encoding="utf-8") as output:
            output.write("\n".join(summary) + "\n")

    # SSH is necessary, but not sufficient. Backups need a DB dump utility.
    missing_capabilities = [k for k in ("ssh", "php", "mysql_dump", "tar", "gzip") if not outcomes[k]]
    if missing_capabilities:
        print("::error::Faltan capacidades detectadas: " + ", ".join(missing_capabilities), flush=True)
        return 1
    print("PASS: transporte SSH y herramientas basicas; deployment sigue NO-GO.", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
