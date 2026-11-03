#!/usr/bin/env python3
"""One-shot SSH helper for farm deploy. Do not commit secrets into git history of app code."""
from __future__ import annotations

import argparse
import os
import sys

import paramiko


def connect():
    host = os.environ.get("FARM_SSH_HOST", "192.168.1.152")
    user = os.environ.get("FARM_SSH_USER", "root")
    password = os.environ.get("FARM_SSH_PASS")
    if not password:
        raise SystemExit("Set FARM_SSH_PASS")
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(
        host,
        username=user,
        password=password,
        timeout=30,
        allow_agent=False,
        look_for_keys=False,
        banner_timeout=30,
    )
    return c


def run(cmd: str, timeout: int = 120) -> int:
    c = connect()
    try:
        stdin, stdout, stderr = c.exec_command(cmd, timeout=timeout)
        out = stdout.read().decode(errors="replace")
        err = stderr.read().decode(errors="replace")
        code = stdout.channel.recv_exit_status()
        sys.stdout.write(out)
        if err:
            sys.stderr.write(err)
        return code
    finally:
        c.close()


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("cmd", nargs="?", default="uname -a")
    p.add_argument("--timeout", type=int, default=120)
    args = p.parse_args()
    return run(args.cmd, timeout=args.timeout)


if __name__ == "__main__":
    raise SystemExit(main())
