"""Read-only log following.

Follows the files listed in SERVERSCOPE_LOG_FILES ("source=/path,...";
without "source=" the file name is used), like `tail -f`: a file is read
from its end when the agent starts, then only new lines. Only errors and
warnings are kept; Laravel, nginx, Apache and PHP-FPM formats are
recognised, other files by words such as "error" or "fatal". Secrets
(passwords, tokens, keys, e-mail addresses) are removed before sending.
Nothing is written to the log files.
"""

import os
import re
from datetime import datetime, timezone
from pathlib import Path

SOURCE_PATTERN = re.compile(r"^[A-Za-z0-9._-]{1,50}$")
MAX_FILES = 20
MAX_READ_BYTES = 512 * 1024
MAX_ENTRIES = 200
MAX_MESSAGE = 1000
MAX_CONTEXT_LINES = 40
MAX_CONTEXT = 8000

LEVELS = {
    "emergency": "critical", "emerg": "critical", "alert": "critical", "critical": "critical", "crit": "critical", "fatal": "critical", "panic": "critical",
    "error": "error", "err": "error",
    "warning": "warning", "warn": "warning",
}

# [2026-11-03 10:00:00] production.ERROR: message
LARAVEL = re.compile(r"^\[\d{4}-\d{2}-\d{2}[ T][\d:.]+[^\]]*\]\s+[\w-]+\.(\w+):\s?(.*)$")
# 2026/11/03 10:00:00 [error] 123#0: *1 message
NGINX = re.compile(r"^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2} \[(\w+)\] \d+#\d+: (?:\*\d+ )?(.*)$")
# [Tue Nov 03 10:00:00.123 2026] [php:error] [pid 1] message  /  [03-Nov-2026 10:00:00] WARNING: [pool www] message
APACHE = re.compile(r"^\[[^\]]+\] \[(?:[\w-]+:)?(\w+)\](?: \[[^\]]*\])* (.*)$")
PHP_FPM = re.compile(r"^\[[^\]]+\] (\w+): (.*)$")
GENERIC = re.compile(r"\b(fatal|panic|critical|error|exception|warning|warn)\b", re.IGNORECASE)
STARTS_ENTRY = re.compile(r"^(\[|\d{4}[-/]\d{2}[-/]\d{2}|[A-Z][a-z]{2} [ \d]\d )")
CONTINUES = re.compile(r"^(\s|#\d+ |Stack trace:|Next |Caused by|\{|\}|\"|at )")

REDACTIONS = [
    (re.compile(r"\b(password|passwd|pwd|secret|token|api[_-]?key|authorization|auth|cookie|session)(\s*[=:]\s*)(\"[^\"]*\"|'[^']*'|[^\s,;&]+)", re.IGNORECASE), r"\1\2[redacted]"),
    (re.compile(r"\bBearer\s+[A-Za-z0-9._~+/=-]+", re.IGNORECASE), "Bearer [redacted]"),
    (re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b"), "[email]"),
    (re.compile(r"\b(?:[A-Za-z0-9+/]{40,}={0,2}|[0-9a-f]{40,})\b"), "[redacted]"),
]


def parse_log_files(value: str) -> list:
    """Parse "source=/path,..." into [(source, path)], dropping invalid entries."""
    files = []
    seen = set()

    for part in (p.strip() for p in value.split(",")):
        if not part:
            continue

        source, _, path = part.partition("=") if "=" in part else ("", "", part)
        path = path.strip()
        source = source.strip() or re.sub(r"[^A-Za-z0-9._-]", "-", Path(path).stem)[:50]

        if not path.startswith("/") or not SOURCE_PATTERN.match(source) or path in seen:
            continue

        seen.add(path)
        files.append((source, path))

    return files[:MAX_FILES]


def redact(text: str) -> str:
    for pattern, replacement in REDACTIONS:
        text = pattern.sub(replacement, text)
    return text


def parse_line(line: str):
    """(level, message, structured) for an error or warning line; ("skip", None, True)
    for a recognised line of a lower level; None for anything else."""
    for pattern in (LARAVEL, NGINX, APACHE, PHP_FPM):
        match = pattern.match(line)
        if match:
            level = LEVELS.get(match.group(1).lower())
            return (level, match.group(2).strip() or line.strip(), True) if level else ("skip", None, True)

    match = GENERIC.search(line)
    if match:
        word = match.group(1).lower()
        return ("warning" if word.startswith("warn") else "critical" if word in ("fatal", "panic", "critical") else "error", line.strip(), False)

    return None


class LogFollower:
    """Follows log files across calls; survives rotation and truncation."""

    def __init__(self, files: list):
        self.files = files
        self.positions = {}
        self.pending = []
        self.dropped = 0

        for _, path in files:
            self.positions[path] = self._end_of(path)

    def collect(self, now=None) -> list:
        """New error/warning entries since the last call (at most MAX_ENTRIES)."""
        now = now or datetime.now(timezone.utc)
        stamp = now.isoformat()
        entries = []

        for source, path in self.files:
            for level, message, context in self._read(path):
                entry = {"source": source, "level": level, "message": redact(message)[:MAX_MESSAGE], "occurred_at": stamp}
                if context:
                    entry["context"] = redact(context)[:MAX_CONTEXT]
                entries.append(entry)

        self.pending.extend(entries)

        if len(self.pending) > MAX_ENTRIES:
            self.dropped += len(self.pending) - MAX_ENTRIES
            self.pending = self.pending[-MAX_ENTRIES:]

        return list(self.pending)

    def sent(self) -> None:
        """The pending entries reached ServerScope."""
        self.pending = []
        self.dropped = 0

    def _end_of(self, path: str):
        try:
            stat = os.stat(path)
            return (stat.st_ino, stat.st_size)
        except OSError:
            return (None, 0)

    def _read(self, path: str) -> list:
        try:
            stat = os.stat(path)
        except OSError:
            return []

        inode, offset = self.positions.get(path, (None, 0))

        # Rotated (new file) or truncated: start at the beginning.
        if inode != stat.st_ino or stat.st_size < offset:
            offset = 0

        if stat.st_size == offset:
            self.positions[path] = (stat.st_ino, offset)
            return []

        try:
            with open(path, "rb") as handle:
                handle.seek(offset)
                chunk = handle.read(MAX_READ_BYTES)
        except OSError:
            return []

        # Only whole lines; a half-written last line is read next time.
        end = chunk.rfind(b"\n")
        if end == -1:
            if len(chunk) < MAX_READ_BYTES:
                return []
            end = len(chunk) - 1

        self.positions[path] = (stat.st_ino, offset + end + 1)
        text = chunk[: end + 1].decode("utf-8", errors="replace")

        return self._entries(text.splitlines())

    def _entries(self, lines: list) -> list:
        entries = []
        current = None

        for line in lines:
            if not line.strip():
                continue

            # A stack trace or wrapped message belongs to the entry above it:
            # indented and "#0 ..." lines always, and after a timestamped
            # entry every line until the next timestamp.
            if current is not None and (CONTINUES.match(line) or (current[3] and not STARTS_ENTRY.match(line))):
                if len(current[2]) < MAX_CONTEXT_LINES:
                    current[2].append(line)
                continue

            parsed = parse_line(line)

            if current is not None:
                entries.append(current)
                current = None

            if parsed and parsed[0] != "skip":
                current = [parsed[0], parsed[1], [], parsed[2]]

        if current is not None:
            entries.append(current)

        return [(level, message, "\n".join(context)) for level, message, context, _ in entries]
