import os
from datetime import datetime, timezone

from serverscope_agent.logs import LogFollower, parse_line, parse_log_files, redact

NOW = datetime(2026, 11, 3, 10, 0, tzinfo=timezone.utc)


def append(path, text):
    with open(path, "a", encoding="utf-8") as handle:
        handle.write(text)


def test_parse_sources_and_paths():
    assert parse_log_files("nginx=/var/log/nginx/error.log, /srv/app/storage/logs/laravel.log ,bad source=/x,rel=log.txt") == [
        ("nginx", "/var/log/nginx/error.log"),
        ("laravel", "/srv/app/storage/logs/laravel.log"),
    ]


def test_recognises_formats_and_levels():
    assert parse_line("[2026-11-03 10:00:00] production.ERROR: Undefined index: id") == ("error", "Undefined index: id", True)
    assert parse_line("[2026-11-03 10:00:00] production.INFO: User logged in") == ("skip", None, True)
    assert parse_line("2026/11/03 10:00:00 [crit] 12#0: *5 connect() failed") == ("critical", "connect() failed", True)
    assert parse_line("[Tue Nov 03 10:00:00.1 2026] [php:warn] [pid 1] Deprecated thing") == ("warning", "Deprecated thing", True)
    assert parse_line("worker panic: out of memory")[0] == "critical"
    assert parse_line("GET /health 200") is None


def test_redacts_secrets():
    text = redact("login failed password=hunter2 token: abc Bearer eyJhbGciOi.x.y for amir@example.com key " + "a" * 40)
    assert "hunter2" not in text and "abc" not in text and "eyJ" not in text and "amir@" not in text and "a" * 40 not in text
    assert "password=[redacted]" in text and "[email]" in text


def test_starts_at_the_end_then_follows_new_lines_with_stack_traces(tmp_path):
    log = tmp_path / "laravel.log"
    append(log, "[2026-11-03 09:00:00] production.ERROR: old error\n")

    follower = LogFollower([("laravel", str(log))])
    assert follower.collect(NOW) == []

    append(log, "[2026-11-03 10:00:00] production.ERROR: Call to undefined method Order::pay()\n"
                "Stack trace:\n#0 /srv/app/Http/OrderController.php(12): ErrorException\n#1 {main}\n"
                "[2026-11-03 10:00:01] production.INFO: fine\n"
                "[2026-11-03 10:00:02] production.WARNING: Slow query\n")

    entries = follower.collect(NOW)
    assert [(e["level"], e["message"]) for e in entries] == [("error", "Call to undefined method Order::pay()"), ("warning", "Slow query")]
    assert entries[0]["context"].startswith("Stack trace:\n#0 /srv/app/Http/OrderController.php(12)")
    assert entries[0]["occurred_at"] == NOW.isoformat()


def test_keeps_entries_until_sent_and_counts_overflow(tmp_path):
    log = tmp_path / "error.log"
    log.write_text("")
    follower = LogFollower([("app", str(log))])

    append(log, "".join(f"fatal error {i}\n" for i in range(250)))
    assert len(follower.collect(NOW)) == 200
    assert follower.dropped == 50

    # Not sent (network down): still there next time.
    assert len(follower.collect(NOW)) == 200
    follower.sent()
    assert follower.collect(NOW) == [] and follower.dropped == 0


def test_survives_rotation_and_half_written_lines(tmp_path):
    log = tmp_path / "error.log"
    append(log, "x\n")
    follower = LogFollower([("app", str(log))])

    append(log, "error: half")
    assert follower.collect(NOW) == []
    append(log, " written\n")
    assert [e["message"] for e in follower.collect(NOW)] == ["error: half written"]
    follower.sent()

    os.rename(log, tmp_path / "error.log.1")
    append(log, "error: after rotation\n")
    assert [e["message"] for e in follower.collect(NOW)] == ["error: after rotation"]
