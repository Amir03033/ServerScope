import os

import pytest

from serverscope_agent.deploys import parse_deploy_paths, read_deploys

SHA = "a" * 40
OTHER = "b" * 40


def make_repo(root, branch="main", sha=SHA, packed=False):
    git = root / ".git"
    (git / "refs" / "heads").mkdir(parents=True)
    (git / "HEAD").write_text(f"ref: refs/heads/{branch}\n")
    if packed:
        (git / "packed-refs").write_text(f"# pack-refs with: peeled\n{sha} refs/heads/{branch}\n")
    else:
        (git / "refs" / "heads" / branch).write_text(sha + "\n")
    return root


def test_parse_names_paths_and_drops_invalid_entries():
    assert parse_deploy_paths("shop=/var/www/shop, /srv/api/ ,bad name=/x,rel=var/www,shop=/other") == [
        ("shop", "/var/www/shop"),
        ("api", "/srv/api/"),
    ]


def test_parse_keeps_at_most_ten():
    value = ",".join(f"app{i}=/srv/app{i}" for i in range(15))
    assert len(parse_deploy_paths(value)) == 10


def test_reads_branch_and_commit(tmp_path):
    repo = make_repo(tmp_path / "shop")
    assert read_deploys([("shop", str(repo))]) == [{"name": "shop", "branch": "main", "commit_sha": SHA}]


def test_reads_packed_refs(tmp_path):
    repo = make_repo(tmp_path / "shop", branch="release/2", sha=OTHER, packed=True)
    assert read_deploys([("shop", str(repo))])[0] == {"name": "shop", "branch": "release/2", "commit_sha": OTHER}


def test_detached_head(tmp_path):
    repo = make_repo(tmp_path / "shop")
    (repo / ".git" / "HEAD").write_text(OTHER + "\n")
    assert read_deploys([("shop", str(repo))])[0] == {"name": "shop", "branch": None, "commit_sha": OTHER}


def test_worktree_gitdir_file(tmp_path):
    main = make_repo(tmp_path / "main")
    worktree_git = main / ".git" / "worktrees" / "live"
    worktree_git.mkdir(parents=True)
    (worktree_git / "HEAD").write_text("ref: refs/heads/main\n")
    (worktree_git / "commondir").write_text("../..\n")
    live = tmp_path / "live"
    live.mkdir()
    (live / ".git").write_text(f"gitdir: {worktree_git}\n")

    assert read_deploys([("live", str(live))])[0]["commit_sha"] == SHA


def test_reports_missing_and_non_git_folders(tmp_path):
    (tmp_path / "plain").mkdir()
    assert read_deploys([("gone", str(tmp_path / "gone")), ("plain", str(tmp_path / "plain"))]) == [
        {"name": "gone", "error": "not_found"},
        {"name": "plain", "error": "not_git"},
    ]


def test_rejects_a_head_that_points_outside_refs(tmp_path):
    repo = make_repo(tmp_path / "shop")
    (repo / ".git" / "HEAD").write_text("ref: refs/../../../etc/passwd\n")
    assert read_deploys([("shop", str(repo))])[0] == {"name": "shop", "error": "not_git"}


@pytest.mark.skipif(os.geteuid() == 0, reason="root can read everything")
def test_reports_unreadable_folders(tmp_path):
    repo = make_repo(tmp_path / "shop")
    os.chmod(repo / ".git" / "HEAD", 0)
    try:
        assert read_deploys([("shop", str(repo))])[0] == {"name": "shop", "error": "unreadable"}
    finally:
        os.chmod(repo / ".git" / "HEAD", 0o644)
