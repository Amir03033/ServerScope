"""Read-only deploy detection.

Watches the git checkouts listed in SERVERSCOPE_DEPLOY_PATHS
("name=/path,other=/path"; without "name=" the folder name is used) and
reports which branch and commit each one is on. ServerScope turns a new
commit into a deployment. Only .git/HEAD, the ref it points to and
packed-refs are read; no git command is run and nothing is written.
"""

import os
import re
from pathlib import Path

NAME_PATTERN = re.compile(r"^[A-Za-z0-9._-]{1,100}$")
SHA_PATTERN = re.compile(r"^[0-9a-f]{7,64}$")
REF_PATTERN = re.compile(r"^refs/[A-Za-z0-9._/-]{1,240}$")
MAX_WATCHES = 10
MAX_FILE_BYTES = 1024 * 1024


def parse_deploy_paths(value: str) -> list:
    """Parse "name=/path,..." into [(name, path)], dropping invalid entries."""
    watches = []
    names = set()

    for part in (p.strip() for p in value.split(",")):
        if not part:
            continue

        name, _, path = part.partition("=") if "=" in part else ("", "", part)
        path = path.strip()
        name = (name.strip() or Path(path.rstrip("/")).name)

        if not path.startswith("/") or not NAME_PATTERN.match(name) or name in names:
            continue

        names.add(name)
        watches.append((name, path))

    return watches[:MAX_WATCHES]


def read_deploys(watches: list) -> list:
    """Return [{name, branch, commit_sha}] or [{name, error}] per watch."""
    return [_read_one(name, path) for name, path in watches]


def _read_one(name: str, path: str) -> dict:
    try:
        git_dir = _git_dir(Path(path))
    except PermissionError:
        return {"name": name, "error": "unreadable"}

    if git_dir is None:
        if not os.path.exists(path):
            return {"name": name, "error": "not_found"}
        return {"name": name, "error": "not_git"}

    try:
        head = _read_text(git_dir / "HEAD")
    except PermissionError:
        return {"name": name, "error": "unreadable"}
    except OSError:
        return {"name": name, "error": "not_git"}

    if head.startswith("ref:"):
        ref = head[4:].strip()

        if not REF_PATTERN.match(ref) or ".." in ref:
            return {"name": name, "error": "not_git"}

        branch = ref[len("refs/heads/"):] if ref.startswith("refs/heads/") else None
        try:
            sha = _resolve_ref(git_dir, ref)
        except PermissionError:
            return {"name": name, "error": "unreadable"}

        return {"name": name, "branch": branch, "commit_sha": sha}

    sha = head.strip().lower()

    # A detached HEAD (a tag or a specific commit checked out).
    return {"name": name, "branch": None, "commit_sha": sha if SHA_PATTERN.match(sha) else None}


def _git_dir(path: Path):
    """The .git directory of a checkout, following a "gitdir:" file (worktrees)."""
    dot_git = path / ".git"

    if dot_git.is_dir():
        return dot_git

    if dot_git.is_file():
        line = _read_text(dot_git)
        if line.startswith("gitdir:"):
            target = Path(line[7:].strip())
            target = target if target.is_absolute() else (path / target)
            return target if target.is_dir() else None

    # A bare repository given directly.
    if (path / "HEAD").is_file() and (path / "refs").is_dir():
        return path

    return None


def _resolve_ref(git_dir: Path, ref: str):
    # Worktrees keep refs in the main repository (commondir).
    roots = [git_dir]
    common = git_dir / "commondir"

    if common.is_file():
        target = Path(_read_text(common).strip())
        roots.append(target if target.is_absolute() else (git_dir / target).resolve())

    for root in roots:
        loose = root / ref
        if loose.is_file():
            sha = _read_text(loose).strip().lower()
            return sha if SHA_PATTERN.match(sha) else None

    for root in roots:
        packed = root / "packed-refs"
        if not packed.is_file():
            continue

        for line in _read_text(packed).splitlines():
            parts = line.strip().split(" ", 1)
            if len(parts) == 2 and parts[1] == ref and SHA_PATTERN.match(parts[0].lower()):
                return parts[0].lower()

    # A new repository without commits yet.
    return None


def _read_text(path: Path) -> str:
    with open(path, "r", encoding="utf-8", errors="replace") as handle:
        return handle.read(MAX_FILE_BYTES)
