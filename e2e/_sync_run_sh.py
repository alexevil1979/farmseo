#!/usr/bin/env python3
"""Copy critical e2e scripts into WSL home tree (LF + repair docke typo)."""
from pathlib import Path

SRC_ROOT = Path("/mnt/c/Users/1/Documents/seoup/e2e")
DST_ROOT = Path("/home/hiddify/src/seoup/e2e")
FILES = ("run.sh", "setup-fixtures.sh", "entrypoint.sh", "assert.php")

for name in FILES:
    src = SRC_ROOT / name
    if not src.is_file():
        print(f"skip missing {src}")
        continue
    data = src.read_bytes().replace(b"\r\n", b"\n").replace(b"\r", b"\n")
    if name == "run.sh":
        data = data.replace(b"need_cmd docke\n", b"need_cmd docker\n")
        if b"need_cmd docker\n" not in data:
            raise SystemExit("ERROR: need_cmd docker line missing after repair")
    dst = DST_ROOT / name
    dst.parent.mkdir(parents=True, exist_ok=True)
    dst.write_bytes(data)
    mode = 0o755 if name.endswith(".sh") else 0o644
    dst.chmod(mode)
    print(f"wrote {dst} ({len(data)} bytes)")
