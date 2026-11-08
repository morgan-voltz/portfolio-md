#!/usr/bin/env python3
"""Unit tests of the pure parts of sync-content.py: no WordPress, no network.

Run from anywhere:  python3 -I .github/scripts/test_sync_content.py

The script's file name carries a hyphen, so it is loaded by path rather than
imported; the tests cover what a CI log would otherwise be the only witness
of: which diff entries become payload entries, in what order and batches,
and how the answers are summarized.
"""

from __future__ import annotations

import importlib.util
import io
import json
import os
import sys
import tempfile
import unittest
import urllib.error
from contextlib import redirect_stdout
from pathlib import Path

SCRIPT = Path(__file__).with_name("sync-content.py")
spec = importlib.util.spec_from_file_location("sync_content", SCRIPT)
assert spec is not None and spec.loader is not None
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)


class IsDocument(unittest.TestCase):
    def test_the_grid_and_the_markdown_files_of_a_language_folder_are_documents(self) -> None:
        for path in ("skills.yaml", "fr/articles/a.md", "de/projects/momocad-de.md", "en/pages/resume.md", "fr/projects/fiche accentuée.md"):
            self.assertTrue(sync.is_document(path), path)

    def test_anything_else_is_not(self) -> None:
        for path in ("README.md", "fr/projects/notes.txt", "fr/projects/momocad/capture.png", "images/logo.md", "projects/momocad.md", "xx/projects/a.md", "fr/images/a.md", "projects/sub/x.md", "fr", ""):
            self.assertFalse(sync.is_document(path), path)


class StrayDocuments(unittest.TestCase):
    def test_lists_the_markdown_files_left_in_a_type_folder_without_language_folder(self) -> None:
        entries = [("M", "content/projects/x.md"), ("A", "content/fr/projects/x.md"), ("M", "content/skills.yaml"), ("A", "docs/x.md"), ("M", "content/projects/sub/x.md"), ("M", "content/projects/notes.txt"), ("A", "./content/pages/cv.md")]

        self.assertEqual(["content/projects/x.md", "./content/pages/cv.md"], sync.stray_documents(entries))

    def test_a_deleted_stray_path_needs_no_warning(self) -> None:
        self.assertEqual([], sync.stray_documents([("D", "content/projects/gone.md"), ("D", "content/pages/cv.md")]))


class RelativeToContent(unittest.TestCase):
    def test_strips_the_content_prefix_and_a_leading_dot_slash(self) -> None:
        self.assertEqual("fr/projects/x.md", sync.relative_to_content("content/fr/projects/x.md"))
        self.assertEqual("skills.yaml", sync.relative_to_content("./content/skills.yaml"))

    def test_keeps_the_path_bytes_as_git_gave_them(self) -> None:
        self.assertEqual("fr/projects/fiche accentuée.md", sync.relative_to_content("content/fr/projects/fiche accentuée.md"))
        self.assertEqual("fr/projects/back\\slash.md", sync.relative_to_content("content/fr/projects/back\\slash.md"))

    def test_a_path_outside_content_is_none(self) -> None:
        for path in ("docs/x.md", "contents/x.md", "content", "README.md"):
            self.assertIsNone(sync.relative_to_content(path), path)


class ParseNameStatus(unittest.TestCase):
    def test_reads_nul_separated_pairs_with_unquoted_paths(self) -> None:
        data = b"A\0content/fr/pages/about.md\0M\0content/fr/projects/fiche accentu\xc3\xa9e.md\0D\0content/fr/projects/tab\tname.md\0"

        entries, problems = sync.parse_name_status(data)

        self.assertEqual([], problems)
        self.assertEqual([
            ("A", "content/fr/pages/about.md"),
            ("M", "content/fr/projects/fiche accentuée.md"),
            ("D", "content/fr/projects/tab\tname.md"),
        ], entries)

    def test_an_empty_diff_is_nothing_to_do(self) -> None:
        self.assertEqual(([], []), sync.parse_name_status(b""))
        self.assertEqual(([], []), sync.parse_name_status(b"\n"))

    def test_refuses_the_line_oriented_form_with_a_hint(self) -> None:
        entries, problems = sync.parse_name_status(b"A\tcontent/fr/pages/about.md\nM\tcontent/skills.yaml\n")

        self.assertEqual([], entries)
        self.assertEqual(1, len(problems))
        self.assertIn("--name-status -z", problems[0])

    def test_a_status_without_path_is_reported_and_the_rest_kept(self) -> None:
        entries, problems = sync.parse_name_status(b"A\0content/fr/pages/about.md\0M\0")

        self.assertEqual([("A", "content/fr/pages/about.md")], entries)
        self.assertEqual(1, len(problems))
        self.assertIn("truncated", problems[0])

    def test_a_path_that_is_not_utf8_is_reported_and_the_rest_kept(self) -> None:
        entries, problems = sync.parse_name_status(b"A\0content/fr/pages/\xff.md\0M\0content/skills.yaml\0")

        self.assertEqual([("M", "content/skills.yaml")], entries)
        self.assertEqual(1, len(problems))
        self.assertIn("unreadable", problems[0])


class FilesFromNameStatus(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self.tmp.name)
        for relative, text in {
            "content/skills.yaml": "referentials: []\n",
            "content/fr/projects/a.md": "---\ntitle: A\n---\n",
            "content/fr/pages/accentuée.md": "---\ntitle: É\n---\n",
            "content/projects/old.md": "---\ntitle: Old\n---\n",
            "content/README.md": "# Content\n",
        }.items():
            path = self.repo / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text, encoding="utf-8")

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def test_the_grid_comes_first_whatever_the_diff_order(self) -> None:
        entries = [("A", "content/fr/projects/a.md"), ("M", "content/fr/pages/accentuée.md"), ("M", "content/skills.yaml")]

        files, problems = sync.files_from_name_status(entries, self.repo)

        self.assertEqual([], problems)
        self.assertEqual(["skills.yaml", "fr/projects/a.md", "fr/pages/accentuée.md"], [f["path"] for f in files])
        self.assertEqual("---\ntitle: É\n---\n", files[2]["content"])
        self.assertFalse(files[1]["deleted"])

    def test_a_deletion_is_sent_without_reading_anything(self) -> None:
        files, problems = sync.files_from_name_status([("D", "content/de/projects/gone-de.md")], self.repo)

        self.assertEqual([], problems)
        self.assertEqual([{"path": "de/projects/gone-de.md", "content": None, "deleted": True}], files)

    def test_files_that_are_not_documents_are_left_out_silently(self) -> None:
        entries = [("M", "content/README.md"), ("A", "docs/x.md"), ("A", "content/fr/projects/a/capture.png"), ("M", "content/projects/old.md"), ("D", "content/projects/gone.md")]

        files, problems = sync.files_from_name_status(entries, self.repo)

        self.assertEqual(([], []), (files, problems), "a Markdown file outside the language folders is not sent: the plugin would skip it")
        self.assertEqual(["content/projects/old.md"], sync.stray_documents(entries), "the modified stray file is reported, the deleted one is not")

    def test_an_unexpected_status_and_an_unreadable_file_are_problems(self) -> None:
        entries = [("U", "content/fr/projects/a.md"), ("A", "content/fr/projects/missing.md")]

        files, problems = sync.files_from_name_status(entries, self.repo)

        self.assertEqual([], files)
        self.assertEqual(2, len(problems))
        self.assertIn("unexpected diff status 'U'", problems[0])
        self.assertIn("fr/projects/missing.md", problems[1])


class FilesFromTree(unittest.TestCase):
    def test_walks_the_grid_then_each_language_then_the_three_folders_in_name_order(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp)
            for relative in ("content/skills.yaml", "content/fr/projects/b.md", "content/fr/projects/a.md", "content/fr/pages/cv.md", "content/de/projects/c-de.md", "content/README.md", "content/fr/projects/a/x.md", "content/projects/stray.md"):
                path = repo / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("x", encoding="utf-8")

            files, problems = sync.files_from_tree(repo)
            stray = sync.stray_documents_of_tree(repo)

        self.assertEqual([], problems)
        self.assertEqual(["skills.yaml", "fr/projects/a.md", "fr/projects/b.md", "fr/pages/cv.md", "de/projects/c-de.md"], [f["path"] for f in files])
        self.assertEqual(["content/projects/stray.md"], stray, "a Markdown file in a type folder at the root is reported, not sent")


class Batches(unittest.TestCase):
    @staticmethod
    def entry(name: str, size: int) -> dict:
        return {"path": f"projects/{name}.md", "content": "x" * size, "deleted": False}

    def test_cuts_by_number_of_files(self) -> None:
        files = [self.entry(str(i), 1) for i in range(5)]

        self.assertEqual([2, 2, 1], [len(b) for b in sync.batches(files, 2, 10**9)])

    def test_cuts_by_bytes_and_keeps_the_order(self) -> None:
        files = [self.entry("a", 100), self.entry("b", 100), self.entry("c", 100)]
        weight = sync.encoded_size(files[0])

        cut = list(sync.batches(files, 100, 2 * weight))

        self.assertEqual([2, 1], [len(b) for b in cut])
        self.assertEqual(["projects/a.md", "projects/b.md", "projects/c.md"], [f["path"] for b in cut for f in b])

    def test_an_entry_larger_than_the_limit_travels_alone(self) -> None:
        files = [self.entry("small", 1), self.entry("huge", 5000), self.entry("other", 1)]

        self.assertEqual([1, 1, 1], [len(b) for b in sync.batches(files, 100, 1000)])

    def test_the_measure_counts_utf8_bytes_as_posted(self) -> None:
        entry = {"path": "pages/é.md", "content": "é", "deleted": False}

        self.assertEqual(len(json.dumps(entry, ensure_ascii=False).encode("utf-8")), sync.encoded_size(entry))


class Answers(unittest.TestCase):
    def test_decode_returns_a_dict_for_an_object_and_text_for_anything_else(self) -> None:
        self.assertEqual({"results": []}, sync.decode(b'{"results": []}'))
        self.assertEqual("[1, 2]", sync.decode(b"[1, 2]"))
        self.assertEqual("<html>", sync.decode(b"<html>"))

    def test_the_summary_line_tells_nothing_apart_from_no_answer(self) -> None:
        counts = dict.fromkeys(sync.STATUSES, 0)
        self.assertEqual("nothing", sync.summary_line(counts, 0))
        self.assertEqual("no batch answered", sync.summary_line(counts, 2))
        counts.update(created=2, unchanged=1)
        self.assertEqual("2 created, 1 unchanged", sync.summary_line(counts, 1))


class Post(unittest.TestCase):
    """The request and its patience: an explicit User-Agent, 5xx and connection errors retried
    with the backoff schedule, a 4xx returned at once, the host's challenge page recognised."""

    CHALLENGE = b'<!DOCTYPE HTML><html lang="en-US"><head><title>Test de s\xc3\xa9curit\xc3\xa9 / Security check...</title></head><body><form action="/o2s-cgi/security-challenge?a=verify-response"></form></body></html>'

    def fake_network(self, answers: list) -> tuple[list, list]:
        """Patch urlopen to pop one answer per call (an int status with a body, or an exception) and
        sleep to record its delays; return the recorded requests and delays."""
        requests_seen: list = []
        delays: list = []
        answers = list(answers)

        class Response:
            def __init__(self, status: int, body: bytes) -> None:
                self.status, self._body = status, body

            def read(self) -> bytes:
                return self._body

            def __enter__(self):
                return self

            def __exit__(self, *exc) -> None:
                return None

        def urlopen(request, timeout=0):
            requests_seen.append(request)
            answer = answers.pop(0)
            if isinstance(answer, Exception):
                raise answer
            status, body = answer
            if status >= 400:
                raise urllib.error.HTTPError(request.full_url, status, "error", {}, io.BytesIO(body))
            return Response(status, body)

        self.addCleanup(setattr, sync.urllib.request, "urlopen", sync.urllib.request.urlopen)
        self.addCleanup(setattr, sync.time, "sleep", sync.time.sleep)
        sync.urllib.request.urlopen = urlopen
        sync.time.sleep = delays.append
        return requests_seen, delays

    def test_the_backoff_grows_from_five_seconds_to_ninety_at_most(self) -> None:
        self.assertEqual([sync.backoff(a) for a in (1, 2, 3, 4, 5)], [5.0, 15.0, 45.0, 90.0, 90.0])

    def test_the_request_identifies_itself_and_authenticates(self) -> None:
        seen, delays = self.fake_network([(200, b'{"results": []}')])
        with redirect_stdout(io.StringIO()):
            status, payload = sync.post("https://example.test/wp-json/portfolio/v1/sync", "morgan", "secret", {"files": []})
        self.assertEqual((status, payload), (200, {"results": []}))
        self.assertEqual(seen[0].get_header("User-agent"), sync.USER_AGENT)
        self.assertTrue(seen[0].get_header("Authorization").startswith("Basic "))
        self.assertEqual(delays, [])

    def test_a_firewall_challenge_is_outlasted_with_the_backoff_schedule(self) -> None:
        seen, delays = self.fake_network([(503, self.CHALLENGE), (503, self.CHALLENGE), (200, b'{"results": []}')])
        out = io.StringIO()
        with redirect_stdout(out):
            status, payload = sync.post("https://example.test/sync", "morgan", "secret", {"files": []})
        self.assertEqual((status, payload), (200, {"results": []}))
        self.assertEqual(len(seen), 3)
        self.assertEqual(delays, [5.0, 15.0])
        self.assertIn("WAIT   HTTP 503, the host's firewall answered a JavaScript challenge page instead of the site (Tiger Protect); attempt 1 of 5, next in 5 s", out.getvalue())

    def test_five_challenges_in_a_row_are_reported_once_as_a_challenge(self) -> None:
        seen, delays = self.fake_network([(503, self.CHALLENGE)] * 5)
        with redirect_stdout(io.StringIO()):
            status, payload = sync.post("https://example.test/sync", "morgan", "secret", {"files": []})
        self.assertEqual(status, 503)
        self.assertEqual(len(seen), 5)
        self.assertEqual(delays, [5.0, 15.0, 45.0, 90.0])
        self.assertEqual(sync.challenge(payload), "the host's firewall answered a JavaScript challenge page instead of the site (Tiger Protect)")

    def test_a_client_error_is_returned_at_once(self) -> None:
        seen, delays = self.fake_network([(401, b'{"code": "rest_forbidden"}')])
        with redirect_stdout(io.StringIO()):
            status, payload = sync.post("https://example.test/sync", "morgan", "secret", {"files": []})
        self.assertEqual((status, payload), (401, {"code": "rest_forbidden"}))
        self.assertEqual((len(seen), delays), (1, []))

    def test_a_connection_error_is_retried_with_a_wait_line_then_reported_as_no_answer(self) -> None:
        seen, delays = self.fake_network([urllib.error.URLError("refused")] * 5)
        out = io.StringIO()
        with redirect_stdout(out):
            status, payload = sync.post("https://example.test/sync", "morgan", "secret", {"files": []})
        self.assertEqual(status, 0)
        self.assertTrue(str(payload).startswith("no answer from https://example.test/sync"))
        self.assertEqual(delays, [5.0, 15.0, 45.0, 90.0])
        self.assertEqual(out.getvalue().count("WAIT   no answer"), 4)

    def test_a_challenge_page_with_a_client_status_is_not_retried(self) -> None:
        seen, delays = self.fake_network([(403, self.CHALLENGE)])
        with redirect_stdout(io.StringIO()):
            status, payload = sync.post("https://example.test/sync", "morgan", "secret", {"files": []})
        self.assertEqual(status, 403)
        self.assertEqual((len(seen), delays), (1, []))
        self.assertIsNotNone(sync.challenge(payload))

    def test_the_challenge_detector_recognises_the_firewall_page_only(self) -> None:
        self.assertIsNone(sync.challenge({"code": "x"}))
        self.assertIsNone(sync.challenge("Service Unavailable"))
        self.assertIsNone(sync.challenge("<html><body><h1>Internal error</h1></body></html>"), "an ordinary HTML error page is not a challenge")
        self.assertIsNone(sync.challenge("<html><body>Security check failed.</body></html>"), "a WordPress wp_die page about a nonce is not a challenge")
        self.assertIsNotNone(sync.challenge(self.CHALLENGE.decode("utf-8")))


class WorkflowCommands(unittest.TestCase):
    def test_a_path_cannot_end_a_command_and_start_another(self) -> None:
        self.assertEqual("content/projects/x%0A::add-mask::y.md", sync.escape_data("content/projects/x\n::add-mask::y.md"))
        self.assertEqual("100%25%0D", sync.escape_data("100%\r"))

    def test_a_control_character_never_reaches_a_line_of_the_log(self) -> None:
        self.assertEqual("a?b?c", sync.one_line("a\nb\x1bc"))


class StepSummary(unittest.TestCase):
    def test_cells_keep_a_row_on_one_line(self) -> None:
        self.assertEqual("a?b", sync.text_cell("a\nb"))
        self.assertEqual("`fr/projects/a??b.md`", sync.code_cell("fr/projects/a\r\nb.md"))

    def test_cells_escape_what_would_break_the_table(self) -> None:
        self.assertEqual("a \\| b", sync.text_cell("a | b"))
        self.assertEqual("`projects/a\\|b.md`", sync.code_cell("projects/a|b.md"))  # GFM: a pipe is escaped even inside a code span
        self.assertEqual("projects/a\\`\\|b.md", sync.code_cell("projects/a`|b.md"))

    def test_writes_a_table_when_github_asks_for_one(self) -> None:
        rows = [{"status": "created", "path": "projects/a.md", "type": "project", "slug": "a", "message": "x | y"}]
        counts = dict.fromkeys(sync.STATUSES, 0)
        counts["created"] = 1
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / "summary.md"
            previous = os.environ.get("GITHUB_STEP_SUMMARY")
            os.environ["GITHUB_STEP_SUMMARY"] = str(target)
            try:
                sync.write_step_summary(rows, counts, dry_run=True, failed_batches=1)
            finally:
                if previous is None:
                    del os.environ["GITHUB_STEP_SUMMARY"]
                else:
                    os.environ["GITHUB_STEP_SUMMARY"] = previous
            text = target.read_text(encoding="utf-8")

        self.assertIn("## Content synchronization (dry run)", text)
        self.assertIn("| created | `projects/a.md` | project/a | x \\| y |", text)
        self.assertIn("**1 request(s) did not answer 200.**", text)

    def test_writes_nothing_outside_github(self) -> None:
        previous = os.environ.pop("GITHUB_STEP_SUMMARY", None)
        try:
            sync.write_step_summary([], dict.fromkeys(sync.STATUSES, 0), False, 0)  # no file, no error
        finally:
            if previous is not None:
                os.environ["GITHUB_STEP_SUMMARY"] = previous


class Main(unittest.TestCase):
    def run_main(self, *argv: str, env: dict | None = None) -> tuple[int, str]:
        saved_argv, saved_env = sys.argv, dict(os.environ)
        sys.argv = ["sync-content.py", *argv]
        os.environ.update(env or {})
        out = io.StringIO()
        try:
            with redirect_stdout(out):
                code = sync.main()
        finally:
            sys.argv = saved_argv
            os.environ.clear()
            os.environ.update(saved_env)
        return code, out.getvalue()

    def with_post(self, status: int, payload) -> None:
        """Patch the network call of the script for the duration of a test."""
        self.addCleanup(setattr, sync, "post", sync.post)
        sync.post = lambda url, user, password, body: (status, payload)

    def a_tree_with_one_document(self) -> str:
        root = tempfile.mkdtemp()
        content = Path(root) / "content" / "fr" / "pages"
        content.mkdir(parents=True)
        (content / "a-propos.md").write_text("---\ntitle: x\n---\n", encoding="utf-8")
        (Path(root) / "content" / "skills.yaml").write_text("referentials: []\n", encoding="utf-8")
        return root

    def test_a_challenge_that_outlasts_the_retries_is_reported_in_one_line_with_the_schedule(self) -> None:
        self.with_post(503, Post.CHALLENGE.decode("utf-8"))
        code, out = self.run_main("--url", "https://example.test", "--user", "morgan", "--password-env", "PW", "--all", "--repo", self.a_tree_with_one_document(), env={"PW": "secret"})
        self.assertEqual(code, 1)
        self.assertIn("ERROR  batch 1", out)
        self.assertIn("the host's firewall answered a JavaScript challenge page instead of the site (Tiger Protect), 5 attempts over about 155 s", out)
        self.assertNotIn("o2s-cgi", out, "the markup of the page is not copied")

    def test_a_challenge_page_with_a_client_status_is_reported_as_not_retried_with_its_page(self) -> None:
        self.with_post(403, Post.CHALLENGE.decode("utf-8"))
        code, out = self.run_main("--url", "https://example.test", "--user", "morgan", "--password-env", "PW", "--all", "--repo", self.a_tree_with_one_document(), env={"PW": "secret"})
        self.assertEqual(code, 1)
        self.assertIn("at the first attempt (HTTP 403, not retried)", out)
        self.assertIn("o2s-cgi/security-challenge", out, "the page follows so that the reader sees what answered")
        self.assertNotIn("attempts over", out)

    def test_an_empty_password_is_a_usage_error(self) -> None:
        code, _ = self.run_main("--url", "http://x", "--user", "u", "--password-env", "NOPE_EMPTY", "--all", env={"NOPE_EMPTY": ""})
        self.assertEqual(2, code)

    def test_a_diff_without_documents_is_nothing_to_do_and_succeeds(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            diff = Path(tmp) / "changes.nul"
            diff.write_bytes(b"M\0content/README.md\0A\0docs/x.md\0")
            code, out = self.run_main("--url", "http://x", "--user", "u", "--password-env", "PW", "--name-status", str(diff), "--repo", tmp, env={"PW": "secret"})

        self.assertEqual(0, code)
        self.assertIn("Nothing to synchronize", out)

    def test_a_push_that_only_touches_a_stray_markdown_file_warns_and_succeeds(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            diff = Path(tmp) / "changes.nul"
            diff.write_bytes(b"M\0content/projects/old.md\0")
            code, out = self.run_main("--url", "http://x", "--user", "u", "--password-env", "PW", "--name-status", str(diff), "--repo", tmp, env={"PW": "secret"})

        self.assertEqual(0, code)
        self.assertIn("::warning::content/projects/old.md is not a document path: documents live in content/<lang>/<folder>/<slug>.md", out)
        self.assertIn("only paths outside content/<lang>/<folder>/ changed", out)

    def test_a_push_that_only_deletes_stray_files_warns_about_nothing(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            diff = Path(tmp) / "changes.nul"
            diff.write_bytes(b"D\0content/projects/old.md\0D\0content/pages/cv.md\0")
            code, out = self.run_main("--url", "http://x", "--user", "u", "--password-env", "PW", "--name-status", str(diff), "--repo", tmp, env={"PW": "secret"})

        self.assertEqual(0, code)
        self.assertNotIn("::warning::", out)
        self.assertIn("Nothing to synchronize: no document of content/ changed.", out)

    def test_a_full_sync_of_a_tree_with_only_a_stray_file_warns_and_succeeds(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            stray = Path(tmp) / "content" / "projects" / "stray.md"
            stray.parent.mkdir(parents=True)
            stray.write_text("x", encoding="utf-8")
            code, out = self.run_main("--url", "http://x", "--user", "u", "--password-env", "PW", "--all", "--repo", tmp, env={"PW": "secret"})

        self.assertEqual(0, code)
        self.assertIn("::warning::content/projects/stray.md is not a document path: documents live in content/<lang>/<folder>/<slug>.md", out)
        self.assertIn("only paths outside content/<lang>/<folder>/ changed", out)

    def test_a_problem_without_documents_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            diff = Path(tmp) / "changes.nul"
            diff.write_bytes(b"A\tcontent/fr/pages/x.md\n")
            code, out = self.run_main("--url", "http://x", "--user", "u", "--password-env", "PW", "--name-status", str(diff), "--repo", tmp, env={"PW": "secret"})

        self.assertEqual(1, code)
        self.assertIn("WARN", out)


if __name__ == "__main__":
    unittest.main(verbosity=1)
