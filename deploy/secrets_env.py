#!/usr/bin/env python3
"""Secret resolution for deploy scripts — env-var first (GitHub Actions / CI),
falling back to the local secrets_loader (Hermes box, /root/.secrets/krtaker.env).
"""
import os, sys


def get_secret(name):
    v = os.environ.get(name)
    if v:
        return v
    # local fallback: /root/krtaker-deploy/secrets_loader.py (or anywhere on path)
    try:
        from secrets_loader import get_secret as _local  # noqa
        return _local(name)
    except Exception:
        pass
    try:
        sys.path.insert(0, '/root/krtaker-deploy')
        from secrets_loader import get_secret as _local  # noqa
        return _local(name)
    except Exception:
        raise RuntimeError(f'secret {name} not found in env or local secrets_loader')
