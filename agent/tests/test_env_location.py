"""The installer (install.sh) writes the configuration to
<install dir>/.env next to the serverscope_agent package, e.g.
/opt/serverscope-agent/.env. These tests fail as soon as the agent looks
for its configuration anywhere else."""

import os
import shutil
import subprocess
import sys
from pathlib import Path

import serverscope_agent
from serverscope_agent import agent

PACKAGE_DIR = Path(serverscope_agent.__file__).resolve().parent


def test_env_file_is_next_to_the_package():
    assert agent.ENV_FILE == PACKAGE_DIR.parent / ".env"


def test_installed_layout_reads_its_own_env_file(tmp_path):
    install_dir = tmp_path / "serverscope-agent"
    shutil.copytree(PACKAGE_DIR, install_dir / "serverscope_agent", ignore=shutil.ignore_patterns("__pycache__"))
    # Same format as install.sh: no quotes, no spaces around '='.
    (install_dir / ".env").write_text(
        "SERVERSCOPE_URL=https://monitor.example.test\n"
        "SERVERSCOPE_TOKEN=" + "ab" * 32 + "\n"
        "SERVERSCOPE_INTERVAL=45\n"
    )

    env = {key: value for key, value in os.environ.items() if not key.startswith("SERVERSCOPE_")}
    env["PYTHONPATH"] = str(install_dir)
    script = (
        "from serverscope_agent import agent\n"
        "from serverscope_agent.config import load_config\n"
        "c = load_config()\n"
        "print(agent.ENV_FILE, c.base_url, c.token == 'ab' * 32, c.interval_seconds)\n"
    )

    # Run from an unrelated directory so a .env in the working directory
    # cannot be picked up by accident.
    result = subprocess.run([sys.executable, "-c", script], cwd=tmp_path, env=env, capture_output=True, text=True, check=True)

    assert result.stdout.split() == [str(install_dir / ".env"), "https://monitor.example.test", "True", "45"]


def test_environment_variables_take_precedence_over_the_env_file(tmp_path):
    install_dir = tmp_path / "serverscope-agent"
    shutil.copytree(PACKAGE_DIR, install_dir / "serverscope_agent", ignore=shutil.ignore_patterns("__pycache__"))
    (install_dir / ".env").write_text("SERVERSCOPE_URL=https://from-file.example.test\nSERVERSCOPE_TOKEN=" + "cd" * 32 + "\n")

    env = {key: value for key, value in os.environ.items() if not key.startswith("SERVERSCOPE_")}
    env.update({"PYTHONPATH": str(install_dir), "SERVERSCOPE_URL": "https://from-env.example.test"})
    script = "from serverscope_agent import agent\nfrom serverscope_agent.config import load_config\nprint(load_config().base_url)\n"

    result = subprocess.run([sys.executable, "-c", script], cwd=tmp_path, env=env, capture_output=True, text=True, check=True)

    assert result.stdout.strip() == "https://from-env.example.test"


# --- Phase 3: the release layout with a symlink -------------------------
#
#   <install dir>/.env
#   <install dir>/releases/<version>/serverscope_agent/...
#   <install dir>/releases/<version>/.env -> ../../.env
#   <install dir>/serverscope_agent -> releases/<version>/serverscope_agent

TOKEN = "ef" * 32


def _release_layout(tmp_path, version, agent_source=None, env_link=True):
    install_dir = tmp_path / "serverscope-agent"
    release = install_dir / "releases" / version
    shutil.copytree(PACKAGE_DIR, release / "serverscope_agent", ignore=shutil.ignore_patterns("__pycache__"))
    if agent_source is not None:
        (release / "serverscope_agent" / "agent.py").write_text(agent_source)
    if env_link:
        (release / ".env").symlink_to("../../.env")
    (install_dir / ".env").write_text("SERVERSCOPE_URL=https://monitor.example.test\nSERVERSCOPE_TOKEN=" + TOKEN + "\n")
    link = install_dir / "serverscope_agent"
    if link.is_symlink():
        link.unlink()
    link.symlink_to(Path("releases") / version / "serverscope_agent")
    return install_dir


def _run_like_systemd(install_dir):
    """python -m from the install directory, as serverscope-agent.service does."""
    env = {key: value for key, value in os.environ.items() if not key.startswith("SERVERSCOPE_") and key != "PYTHONPATH"}
    script = (
        "import runpy, sys\n"
        "sys.argv = ['x']\n"
        "from serverscope_agent import agent\n"
        "from serverscope_agent.config import load_config\n"
        "c = load_config()\n"
        "print(c.base_url, c.token == '" + TOKEN + "')\n"
    )
    return subprocess.run([sys.executable, "-c", script], cwd=install_dir, env=env, capture_output=True, text=True, check=True)


def _agent_0_2_0_source():
    """agent.py as released in 0.2.0: the .env path follows symlinks."""
    source = (PACKAGE_DIR / "agent.py").read_text()
    start = source.index("def _env_file()")
    end = source.index("ENV_FILE = _env_file()")
    source = source[:start] + source[end:]
    return source.replace("ENV_FILE = _env_file()", 'ENV_FILE = Path(__file__).resolve().parents[1] / ".env"')


def test_0_2_0_code_in_the_release_layout_reads_the_env_through_the_release_link(tmp_path):
    install_dir = _release_layout(tmp_path, "0.2.0", agent_source=_agent_0_2_0_source())

    assert _run_like_systemd(install_dir).stdout.split() == ["https://monitor.example.test", "True"]


def test_0_2_0_code_without_the_release_link_would_not_find_its_configuration(tmp_path):
    # Proves the release .env link is what makes 0.2.0 work.
    install_dir = _release_layout(tmp_path, "0.2.0", agent_source=_agent_0_2_0_source(), env_link=False)

    result = subprocess.run(
        [sys.executable, "-c", "from serverscope_agent import agent; from serverscope_agent.config import load_config; load_config()"],
        cwd=install_dir, env={k: v for k, v in os.environ.items() if not k.startswith("SERVERSCOPE_") and k != "PYTHONPATH"},
        capture_output=True, text=True,
    )
    assert result.returncode != 0
    assert "SERVERSCOPE_URL is not configured" in result.stderr


def test_current_code_finds_the_env_without_resolving_the_symlink(tmp_path):
    install_dir = _release_layout(tmp_path, "0.3.0", env_link=False)

    assert _run_like_systemd(install_dir).stdout.split() == ["https://monitor.example.test", "True"]


def test_after_switching_releases_the_agent_still_finds_its_configuration(tmp_path):
    install_dir = _release_layout(tmp_path, "0.3.0")
    _release_layout(tmp_path, "0.3.1")

    assert os.readlink(install_dir / "serverscope_agent") == "releases/0.3.1/serverscope_agent"
    assert _run_like_systemd(install_dir).stdout.split() == ["https://monitor.example.test", "True"]
