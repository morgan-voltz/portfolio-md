#!/usr/bin/env python3
"""Post the content files of this repository to the WordPress plugin's Git door.

The same script serves the GitHub Actions workflow (sync-content.yml) and a
developer at the keyboard: it takes the list of changed files (the output of
`git diff --no-renames --name-status -z`) or walks the whole content/ folder,
keeps the documents the plugin knows (content/skills.yaml and the Markdown
files of content/articles, content/projects and content/pages), reads them,
and POSTs them to /wp-json/portfolio/v1/sync in batches, authenticated with a
WordPress application password over Basic auth.

The diff is read in its `-z` form (NUL-separated, raw bytes): the default,
line-oriented form quotes and escapes any path with an accent, a space-like
control character, a quote or a backslash, which would be lost. The skills
grid always travels in the first batch, since a sheet of the same push may
cite a code it introduces. Batches are bounded by a number of files and by a
number of bytes, to stay under PHP's `post_max_size`.

Exit code 0 only when every batch answered 200: a 409 (a document waits for a
human in the admin) or a 422 (a document could not be processed) fails the
job on purpose, with the plugin's own line for each file in the log.

Examples:

  # From a CI job, after `git diff --no-renames --name-status -z BEFORE AFTER -- content/ > changes.nul`
  python3 .github/scripts/sync-content.py --url "$SYNC_URL" --user "$SYNC_USER" \
      --password-env SYNC_PASSWORD --commit "$GITHUB_SHA" --name-status changes.nul

  # From the working tree, against the local stack, deciding without writing
  export PORTFOLIO_SYNC_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
  python3 .github/scripts/sync-content.py --url http://localhost:8000 --user morgan \
      --password-env PORTFOLIO_SYNC_PASSWORD --all --dry-run
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import sys
import time
import urllib.error
import urllib.request
from collections.abc import Iterable, Iterator
from pathlib import Path

CONTENT_ROOT = Path("content")
DOCUMENT_FOLDERS = ("articles", "projects", "pages")
GRID = "skills.yaml"
ROUTE = "/wp-json/portfolio/v1/sync"
STATUSES = ("created", "updated", "unchanged", "conflict", "failed", "deleted", "skipped")
MAX_FILES_PER_REQUEST = 100  # SyncController::MAX_FILES
DEFAULT_BATCH_FILES = 50
DEFAULT_BATCH_BYTES = 4_000_000  # half of PHP's default post_max_size (8M on the official image)


def is_document(relative: str) -> bool:
    """Whether a path relative to content/ is one the plugin knows."""
    parts = relative.split("/")
    if parts == [GRID]:
        return True
    return len(parts) == 2 and parts[0] in DOCUMENT_FOLDERS and parts[1].endswith(".md")


def relative_to_content(path: str) -> str | None:
    """`content/projects/x.md` -> `projects/x.md`; None for a path outside content/."""
    normalized = path[2:] if path.startswith("./") else path
    prefix = f"{CONTENT_ROOT}/"
    if not normalized.startswith(prefix):
        return None
    return normalized[len(prefix):]


def parse_name_status(data: bytes) -> tuple[list[tuple[str, str]], list[str]]:
    """`git diff --no-renames --name-status -z` output -> (status, path) pairs.

    With -z, git writes the status, a NUL, the path, a NUL, for every file, and
    never quotes nor escapes a path. Without --no-renames a rename would carry
    two paths, which is why the workflow turns renames off.
    """
    problems: list[str] = []
    if not data.strip():
        return [], problems
    fields = data.split(b"\0")
    if fields[-1] == b"":
        fields.pop()
    if b"\0" not in data and (b"\t" in data or b"\n" in data.rstrip(b"\n")):
        problems.append("the diff is not NUL-separated: produce it with `git diff --no-renames --name-status -z`")
        return [], problems
    if len(fields) % 2 == 1:
        problems.append(f"truncated diff: {fields[-1]!r} has no path")
        fields.pop()
    entries: list[tuple[str, str]] = []
    for raw_status, raw_path in zip(fields[0::2], fields[1::2]):
        try:
            entries.append((raw_status.decode("ascii"), raw_path.decode("utf-8")))
        except UnicodeDecodeError as error:
            problems.append(f"unreadable diff entry {raw_status!r} {raw_path!r}: {error}")
    return entries, problems


def files_from_name_status(entries: Iterable[tuple[str, str]], repo: Path) -> tuple[list[dict], list[str]]:
    """Turn (status, path) pairs into payload entries, the skills grid first."""
    grid: list[dict] = []
    documents: list[dict] = []
    problems: list[str] = []
    for status, path in entries:
        relative = relative_to_content(path)
        if relative is None or not is_document(relative):
            continue
        target = grid if relative == GRID else documents
        if status.startswith("D"):
            target.append({"path": relative, "content": None, "deleted": True})
            continue
        if not status or status[0] not in "AMT":  # added, modified, type changed; renames never appear with --no-renames
            problems.append(f"unexpected diff status {status!r} for {path}")
            continue
        entry = read_document(repo / path, relative, problems)
        if entry is not None:
            target.append(entry)
    return grid + documents, problems


def files_from_tree(repo: Path) -> tuple[list[dict], list[str]]:
    """Every document of content/, for a full synchronization; the grid first."""
    files: list[dict] = []
    problems: list[str] = []
    root = repo / CONTENT_ROOT
    grid = root / GRID
    if grid.is_file():
        entry = read_document(grid, GRID, problems)
        if entry is not None:
            files.append(entry)
    for folder in DOCUMENT_FOLDERS:
        for path in sorted((root / folder).glob("*.md")):
            entry = read_document(path, f"{folder}/{path.name}", problems)
            if entry is not None:
                files.append(entry)
    return files, problems


def read_document(path: Path, relative: str, problems: list[str]) -> dict | None:
    try:
        content = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as error:
        problems.append(f"{relative}: cannot be read as UTF-8 text ({error})")
        return None
    return {"path": relative, "content": content, "deleted": False}


def encoded_size(entry: dict) -> int:
    """Bytes the entry takes in the request body, as post() serializes it."""
    return len(json.dumps(entry, ensure_ascii=False).encode("utf-8"))


def batches(files: list[dict], max_files: int, max_bytes: int) -> Iterator[list[dict]]:
    """Cut the files into requests of at most max_files entries and max_bytes of body.

    An entry larger than max_bytes travels alone (the plugin then answers for
    it, or PHP refuses the body: either way the log says which file). The
    order is kept, so the grid stays in the first batch.
    """
    batch: list[dict] = []
    size = 0
    for entry in files:
        weight = encoded_size(entry)
        if batch and (len(batch) >= max_files or size + weight > max_bytes):
            yield batch
            batch, size = [], 0
        batch.append(entry)
        size += weight
    if batch:
        yield batch


def post(url: str, user: str, password: str, body: dict, retries: int = 3) -> tuple[int, dict | str]:
    """POST the body; retry connection errors and 5xx only (a 4xx is deterministic)."""
    data = json.dumps(body, ensure_ascii=False).encode("utf-8")
    token = base64.b64encode(f"{user}:{password}".encode("utf-8")).decode("ascii")
    request = urllib.request.Request(
        url,
        data=data,
        method="POST",
        headers={"Content-Type": "application/json; charset=utf-8", "Authorization": f"Basic {token}", "Accept": "application/json"},
    )
    attempt = 0
    while True:
        attempt += 1
        try:
            with urllib.request.urlopen(request, timeout=120) as response:
                return response.status, decode(response.read())
        except urllib.error.HTTPError as error:
            payload = decode(error.read())
            if error.code >= 500 and attempt < retries:
                time.sleep(2 ** attempt)
                continue
            return error.code, payload
        except (urllib.error.URLError, TimeoutError, ConnectionError) as error:
            if attempt < retries:
                time.sleep(2 ** attempt)
                continue
            return 0, f"no answer from {url}: {error}"


def decode(raw: bytes) -> dict | str:
    text = raw.decode("utf-8", errors="replace")
    try:
        parsed = json.loads(text)
    except json.JSONDecodeError:
        return text
    return parsed if isinstance(parsed, dict) else text


def summary_line(counts: dict, failed_batches: int) -> str:
    """`2 created, 1 unchanged`; says when no request got an answer to count."""
    summary = ", ".join(f"{counts[s]} {s}" for s in STATUSES if counts[s])
    if summary:
        return summary
    return "no batch answered" if failed_batches else "nothing"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--url", required=True, help="base URL of the WordPress site, e.g. https://example.org")
    parser.add_argument("--user", required=True, help="login of the WordPress user the application password belongs to")
    parser.add_argument("--password-env", required=True, metavar="VAR", help="name of the environment variable that holds the application password")
    parser.add_argument("--commit", default=None, help="sha of the commit the files come from, for the logs")
    parser.add_argument("--dry-run", action="store_true", help="ask the plugin to decide without writing")
    parser.add_argument("--batch-size", type=int, default=DEFAULT_BATCH_FILES, help=f"files per request at most (default {DEFAULT_BATCH_FILES}; the plugin accepts {MAX_FILES_PER_REQUEST})")
    parser.add_argument("--batch-bytes", type=int, default=DEFAULT_BATCH_BYTES, help=f"bytes of files per request at most (default {DEFAULT_BATCH_BYTES}; keep it under PHP's post_max_size, 8M by default)")
    parser.add_argument("--repo", default=".", help="root of the repository (default: current directory)")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--name-status", metavar="FILE", help="file holding `git diff --no-renames --name-status -z` output, or - for stdin")
    source.add_argument("--all", action="store_true", help="send every document of content/")
    args = parser.parse_args()

    password = os.environ.get(args.password_env, "")
    if not password:
        print(f"error: the environment variable {args.password_env} is empty", file=sys.stderr)
        return 2
    if not 1 <= args.batch_size <= MAX_FILES_PER_REQUEST:
        print(f"error: --batch-size must be between 1 and {MAX_FILES_PER_REQUEST}", file=sys.stderr)
        return 2
    if args.batch_bytes < 1:
        print("error: --batch-bytes must be positive", file=sys.stderr)
        return 2

    repo = Path(args.repo)
    if args.all:
        files, problems = files_from_tree(repo)
    else:
        data = sys.stdin.buffer.read() if args.name_status == "-" else Path(args.name_status).read_bytes()
        entries, problems = parse_name_status(data)
        files, more = files_from_name_status(entries, repo)
        problems += more

    for problem in problems:
        print(f"WARN   {problem}")
    if not files:
        print("Nothing to synchronize: no document of content/ changed.")
        return 1 if problems else 0

    url = args.url.rstrip("/") + ROUTE
    counts = dict.fromkeys(STATUSES, 0)
    rows: list[dict] = []
    failed_batches = 0
    for number, batch in enumerate(batches(files, args.batch_size, args.batch_bytes), start=1):
        body: dict = {"files": batch, "dry_run": args.dry_run}
        if args.commit:
            body["commit_sha"] = args.commit
        status, payload = post(url, args.user, password, body)
        if status in (200, 409, 422) and isinstance(payload, dict) and isinstance(payload.get("results"), list):
            for row in payload["results"]:
                rows.append(row)
                counts[row.get("status", "failed")] = counts.get(row.get("status", "failed"), 0) + 1
                target = "/".join(part for part in (row.get("type"), row.get("slug")) if part)
                post_id = f" (post {row['post_id']})" if row.get("post_id") else ""
                message = f": {row['message']}" if row.get("message") else ""
                print(f"{str(row.get('status', '?')).upper():<10} {row.get('path')}  {target}{post_id}{message}")
            if status != 200:
                failed_batches += 1
        else:
            failed_batches += 1
            print(f"ERROR  batch {number} ({len(batch)} file(s)): HTTP {status}")
            print(payload if isinstance(payload, str) else json.dumps(payload, ensure_ascii=False, indent=2))
            if status == 413:
                print("       the request body is larger than the server accepts: lower --batch-bytes or raise PHP's post_max_size")

    prefix = "[dry-run] " if args.dry_run else ""
    print(f"{prefix}{len(files)} file(s): {summary_line(counts, failed_batches)}")
    write_step_summary(rows, counts, args.dry_run, failed_batches)

    if failed_batches or problems:
        if counts["conflict"]:
            print("Some documents wait for a decision in the WordPress admin (conflict): resolve them there, then push again.")
        if counts["failed"]:
            print("Some documents could not be processed (failed): fix them and push again.")
        return 1
    return 0


def text_cell(value: object) -> str:
    """A Markdown table cell: a pipe would start another column."""
    return str(value).replace("|", "\\|")


def code_cell(value: object) -> str:
    """A path in a code span; a path holding a backtick is written as plain, escaped text."""
    text = str(value)
    if "`" not in text:
        return f"`{text_cell(text)}`"
    for character in ("\\", "`", "*", "_", "[", "]", "<", "|"):
        text = text.replace(character, "\\" + character)
    return text


def write_step_summary(rows: list[dict], counts: dict, dry_run: bool, failed_batches: int) -> None:
    """A Markdown table for the GitHub Actions job summary, when there is one."""
    target = os.environ.get("GITHUB_STEP_SUMMARY")
    if not target:
        return
    lines = [f"## Content synchronization{' (dry run)' if dry_run else ''}", ""]
    lines.append(", ".join(f"**{counts[s]}** {s}" for s in STATUSES if counts[s]) or summary_line(counts, failed_batches))
    lines.append("")
    if rows:
        lines += ["| Status | File | Target | Message |", "|---|---|---|---|"]
        for row in rows:
            target_name = "/".join(part for part in (row.get("type"), row.get("slug")) if part)
            lines.append(f"| {text_cell(row.get('status'))} | {code_cell(row.get('path'))} | {text_cell(target_name)} | {text_cell(row.get('message', ''))} |")
    if failed_batches:
        lines += ["", f"**{failed_batches} request(s) did not answer 200.**"]
    with open(target, "a", encoding="utf-8") as handle:
        handle.write("\n".join(lines) + "\n")


if __name__ == "__main__":
    sys.exit(main())
