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


class LegacyDocuments(unittest.TestCase):
    def test_lists_the_markdown_files_of_the_former_layout_only(self) -> None:
        paths = ["content/projects/x.md", "content/fr/projects/x.md", "content/skills.yaml", "docs/x.md", "content/projects/sub/x.md", "content/projects/notes.txt", "./content/pages/cv.md"]

        self.assertEqual(["content/projects/x.md", "./content/pages/cv.md"], sync.legacy_documents(paths))


class RelativeToContent(unittest.TestCase):
    def test_strips_the_content_prefix_and_a_leading_dot_slash(self) -> None:
        self.assertEqual("projects/x.md", sync.relative_to_content("content/projects/x.md"))
        self.assertEqual("skills.yaml", sync.relative_to_content("./content/skills.yaml"))

    def test_keeps_the_path_bytes_as_git_gave_them(self) -> None:
        self.assertEqual("projects/fiche accentuée.md", sync.relative_to_content("content/projects/fiche accentuée.md"))
        self.assertEqual("projects/back\\slash.md", sync.relative_to_content("content/projects/back\\slash.md"))

    def test_a_path_outside_content_is_none(self) -> None:
        for path in ("docs/x.md", "contents/x.md", "content", "README.md"):
            self.assertIsNone(sync.relative_to_content(path), path)


class ParseNameStatus(unittest.TestCase):
    def test_reads_nul_separated_pairs_with_unquoted_paths(self) -> None:
        data = b"A\0content/pages/about.md\0M\0content/projects/fiche accentu\xc3\xa9e.md\0D\0content/projects/tab\tname.md\0"

        entries, problems = sync.parse_name_status(data)

        self.assertEqual([], problems)
        self.assertEqual([
            ("A", "content/pages/about.md"),
            ("M", "content/projects/fiche accentuée.md"),
            ("D", "content/projects/tab\tname.md"),
        ], entries)

    def test_an_empty_diff_is_nothing_to_do(self) -> None:
        self.assertEqual(([], []), sync.parse_name_status(b""))
        self.assertEqual(([], []), sync.parse_name_status(b"\n"))

    def test_refuses_the_line_oriented_form_with_a_hint(self) -> None:
        entries, problems = sync.parse_name_status(b"A\tcontent/pages/about.md\nM\tcontent/skills.yaml\n")

        self.assertEqual([], entries)
        self.assertEqual(1, len(problems))
        self.assertIn("--name-status -z", problems[0])

    def test_a_status_without_path_is_reported_and_the_rest_kept(self) -> None:
        entries, problems = sync.parse_name_status(b"A\0content/pages/about.md\0M\0")

        self.assertEqual([("A", "content/pages/about.md")], entries)
        self.assertEqual(1, len(problems))
        self.assertIn("truncated", problems[0])

    def test_a_path_that_is_not_utf8_is_reported_and_the_rest_kept(self) -> None:
        entries, problems = sync.parse_name_status(b"A\0content/pages/\xff.md\0M\0content/skills.yaml\0")

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

        self.assertEqual(([], []), (files, problems), "a document of the former layout is not sent: the plugin would skip it")
        self.assertEqual(["content/projects/old.md", "content/projects/gone.md"], sync.legacy_documents(path for _, path in entries))

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
            for relative in ("content/skills.yaml", "content/fr/projects/b.md", "content/fr/projects/a.md", "content/fr/pages/cv.md", "content/de/projects/c-de.md", "content/README.md", "content/fr/projects/a/x.md", "content/projects/legacy.md"):
                path = repo / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("x", encoding="utf-8")

            files, problems = sync.files_from_tree(repo)
            legacy = sync.legacy_documents_of_tree(repo)

        self.assertEqual([], problems)
        self.assertEqual(["skills.yaml", "fr/projects/a.md", "fr/projects/b.md", "fr/pages/cv.md", "de/projects/c-de.md"], [f["path"] for f in files])
        self.assertEqual(["content/projects/legacy.md"], legacy, "the former layout is reported, not sent")


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

    def test_a_push_that_only_touches_the_former_layout_warns_and_succeeds(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            diff = Path(tmp) / "changes.nul"
            diff.write_bytes(b"M\0content/projects/old.md\0D\0content/pages/cv.md\0")
            code, out = self.run_main("--url", "http://x", "--user", "u", "--password-env", "PW", "--name-status", str(diff), "--repo", tmp, env={"PW": "secret"})

        self.assertEqual(0, code)
        self.assertIn("::warning::content/projects/old.md is still laid out", out)
        self.assertIn("::warning::content/pages/cv.md", out)
        self.assertIn("only documents of the former layout changed", out)

    def test_a_problem_without_documents_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            diff = Path(tmp) / "changes.nul"
            diff.write_bytes(b"A\tcontent/pages/x.md\n")
            code, out = self.run_main("--url", "http://x", "--user", "u", "--password-env", "PW", "--name-status", str(diff), "--repo", tmp, env={"PW": "secret"})

        self.assertEqual(1, code)
        self.assertIn("WARN", out)


if __name__ == "__main__":
    unittest.main(verbosity=1)
