"""Unit tests for immutable Actions release evidence; no network access."""
import importlib.util
import pathlib
import unittest


MODULE_PATH = pathlib.Path(__file__).resolve().parents[2] / "scripts" / "release_evidence.py"
SPEC = importlib.util.spec_from_file_location("release_evidence", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class ReleaseEvidenceTest(unittest.TestCase):
    SHA = "a" * 40

    def test_latest_main_push_run_selected(self):
        runs = [
            {
                "id": 10,
                "name": "Build dist final verified",
                "head_sha": self.SHA,
                "head_branch": "main",
                "event": "push",
                "created_at": "2026-10-26T20:00:00Z",
            },
            {
                "id": 11,
                "name": "Build dist final verified",
                "head_sha": self.SHA,
                "head_branch": "develop",
                "event": "push",
                "created_at": "2026-10-27T21:00:00Z",
            },
            {
                "id": 12,
                "name": "Build dist final verified",
                "head_sha": self.SHA,
                "head_branch": "main",
                "event": "push",
                "created_at": "2026-10-27T22:00:00Z",
            },
        ]
        self.assertEqual(MODULE.select_run(runs, self.SHA, "Build dist final verified")["id"], 12)

    def test_failed_latest_run_blocks_release(self):
        run = {"id": 1, "status": "completed", "conclusion": "failure"}
        self.assertFalse(MODULE.evaluate_run(run, None)[0])

    def test_missing_artifact_blocks_release(self):
        run = {"id": 1, "status": "completed", "conclusion": "success"}
        self.assertFalse(
            MODULE.evaluate_run(run, "zapateriangelita-dist-final-verificado", [])[0]
        )

    def test_expired_artifact_blocks_release(self):
        run = {"id": 1, "status": "completed", "conclusion": "success"}
        artifacts = [{
            "name": "angelita-backend-main-cpanel-vendor",
            "id": 5,
            "expired": True,
            "size_in_bytes": 999,
        }]
        self.assertFalse(
            MODULE.evaluate_run(run, "angelita-backend-main-cpanel-vendor", artifacts)[0]
        )

    def test_successful_artifact_check(self):
        run = {"id": 1, "status": "completed", "conclusion": "success"}
        artifacts = [{
            "name": "angelita-backend-main-cpanel-vendor",
            "id": 7,
            "expired": False,
            "size_in_bytes": 999,
        }]
        self.assertTrue(
            MODULE.evaluate_run(run, "angelita-backend-main-cpanel-vendor", artifacts)[0]
        )


if __name__ == "__main__":
    unittest.main()
