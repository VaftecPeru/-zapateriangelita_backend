"""Offline unit tests for GitHub Actions' read-only SSH probe (no network)."""
from __future__ import annotations

import importlib.util
import pathlib
import unittest


MODULE = pathlib.Path(__file__).resolve().parents[2] / "scripts" / "ssh_readonly_probe.py"
SPEC = importlib.util.spec_from_file_location("ssh_readonly_probe", MODULE)
PROBE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PROBE)


class SSHReadinessTests(unittest.TestCase):
    def demo(self):
        return {
            "ANGELITA_READONLY_SSH_HOST": "example.test",
            "ANGELITA_READONLY_SSH_USER": "probeuser",
            "ANGELITA_READONLY_SSH_PRIVATE_KEY": "-----BEGIN OPENSSH PRIVATE KEY-----\nexample\n-----END OPENSSH PRIVATE KEY-----",
            "ANGELITA_READONLY_SSH_KNOWN_HOSTS": "example.test ssh-ed25519 AAAAE3NzaC1lZDI1NTE5AAAAIKfakeforunittestonly",
            "ANGELITA_READONLY_SSH_PORT": "2222",
        }

    def test_missing_secrets_prevent_network_probe(self):
        self.assertEqual(PROBE.check_config({}), (list(PROBE.REQUIRED), 0))

    def test_valid_config_uses_explicit_port(self):
        missing, port = PROBE.check_config(self.demo())
        self.assertEqual(missing, [])
        self.assertEqual(port, 2222)

    def test_invalid_user_name_is_rejected(self):
        env = self.demo()
        env["ANGELITA_READONLY_SSH_USER"] = "-oProxyCommand=malicious"
        with self.assertRaises(ValueError):
            PROBE.check_config(env)

    def test_host_must_not_be_ssh_flag(self):
        env = self.demo()
        env["ANGELITA_READONLY_SSH_HOST"] = "-evil.example"
        with self.assertRaises(ValueError):
            PROBE.check_config(env)

    def test_port_out_of_range_rejected(self):
        env = self.demo()
        env["ANGELITA_READONLY_SSH_PORT"] = "65536"
        with self.assertRaises(ValueError):
            PROBE.check_config(env)

    def test_missing_pinned_host_key_rejected(self):
        env = self.demo()
        env["ANGELITA_READONLY_SSH_KNOWN_HOSTS"] = ""
        missing, _ = PROBE.check_config(env)
        self.assertEqual(missing, ["ANGELITA_READONLY_SSH_KNOWN_HOSTS"])

    def test_interpret_markers_rejects_unrecognized_lines(self):
        result = PROBE.interpret_markers("Banner from host\nANGELITA_SSH_PROBE_OK\n")
        self.assertTrue(result["ssh"])
        self.assertFalse(result["mysql_dump"])
        self.assertFalse(result["php"])

    def test_source_never_uses_mutating_commands(self):
        command = PROBE.REMOTE_COMMAND.lower()
        for forbidden in (
            "rm ", "mv ", "cp ", "chmod ", "chown ", "; mysqldump ",
            "mysql ", "migrate", "artisan ", "curl ", "wget ", "sudo ",
            "write", "cat ", "sed -i", "tar -", "git "
        ):
            self.assertNotIn(forbidden, command)


if __name__ == "__main__":
    unittest.main()
