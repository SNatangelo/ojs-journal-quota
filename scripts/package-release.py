# scripts/package-release.py
# Copyright (C) 2026 Stefano Natangelo
# SPDX-License-Identifier: AGPL-3.0-only

"""Build and verify an installable Journal Quota archive from tracked files."""

import gzip
import hashlib
import io
import os
import re
import subprocess
import tarfile
import xml.etree.ElementTree as ET
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[1]
PRODUCT = "journalQuota"
TOP_LEVEL = {
    "JournalQuotaPlugin.php", "version.xml", "emailTemplates.xml",
    "LICENSE", "README.md", "CHANGELOG.md", "SECURITY.md", "uninstall.sql",
}
SUFFIXES = {"classes": ".php", "templates": ".tpl", "locale": ".po"}


def fail(message):
    raise SystemExit(message)


def source_date_epoch():
    """Return a fixed, non-zero timestamp for every archive entry.

    OJS 3.5's Smarty resource loader treats a template whose mtime is 0 as
    missing, so entries dated 1970-01-01 break a manual `tar -xzf` install.
    SOURCE_DATE_EPOCH (reproducible-builds.org) wins; otherwise the committer
    date of HEAD keeps the build reproducible for a given commit.
    """
    value = os.environ.get("SOURCE_DATE_EPOCH") or subprocess.check_output(
        ["git", "log", "-1", "--format=%ct", "HEAD"], cwd=ROOT
    ).decode("ascii").strip()
    if not re.fullmatch(r"[0-9]+", value) or int(value) <= 0:
        fail("SOURCE_DATE_EPOCH must be a positive integer")
    return int(value)


def build():
    xml = ET.parse(ROOT / "version.xml").getroot()
    version = xml.findtext("release") or ""
    if xml.findtext("application") != PRODUCT:
        fail("Unexpected product in version.xml")
    if not re.fullmatch(r"[0-9]+(?:\.[0-9]+){3}", version):
        fail("Release version must contain four numeric components")

    tracked = subprocess.check_output(
        ["git", "ls-files", "-z"], cwd=ROOT
    ).decode("utf-8").split("\0")
    files = {}
    for name in sorted(filter(None, tracked)):
        path = PurePosixPath(name)
        if name not in TOP_LEVEL:
            if path.parts[0] not in SUFFIXES:
                continue
            if (len(path.parts) < 2 or
                    path.suffix != SUFFIXES[path.parts[0]] or
                    any(part.startswith(".") or part in
                        {"tests", "demos", "node_modules"}
                        for part in path.parts)):
                fail(f"Unexpected runtime path: {name}")
        source = ROOT / name
        if (not source.is_file() or source.is_symlink() or
                not source.resolve().is_relative_to(ROOT)):
            fail(f"Not a regular tracked file: {name}")
        files[f"{PRODUCT}/{name}"] = source.read_bytes()

    if any(f"{PRODUCT}/{name}" not in files for name in TOP_LEVEL):
        fail("Missing required top-level release file")
    for directory in SUFFIXES:
        if not any(name.startswith(f"{PRODUCT}/{directory}/") for name in files):
            fail(f"Missing runtime directory: {directory}")

    dist = ROOT / "dist"
    dist.mkdir(exist_ok=True)
    archive = dist / f"{PRODUCT}-{version}.tar.gz"

    # Stable metadata makes identical inputs produce identical archives.
    mtime = source_date_epoch()
    directories = {PRODUCT}
    for name in files:
        directories.update(str(p) for p in PurePosixPath(name).parents if str(p) != ".")
    with archive.open("wb") as raw:
        with gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=mtime) as compressed:
            with tarfile.open(fileobj=compressed, mode="w", format=tarfile.USTAR_FORMAT) as tar:
                for name in sorted(directories):
                    member = tarfile.TarInfo(name)
                    member.type = tarfile.DIRTYPE
                    member.mode = 0o755
                    member.mtime = mtime
                    tar.addfile(member)
                for name, data in sorted(files.items()):
                    member = tarfile.TarInfo(name)
                    member.size = len(data)
                    member.mode = 0o644
                    member.mtime = mtime
                    tar.addfile(member, io.BytesIO(data))

    # Verify the actual artifact, not just the staging selection.
    with tarfile.open(archive, "r:gz") as tar:
        members = tar.getmembers()
        names = [member.name for member in members]
        if len(names) != len(set(names)) or set(names) != directories | set(files):
            fail("Archive contents do not match the allowlist")
        for member in members:
            if member.mtime != mtime:
                fail(f"Unexpected archive mtime: {member.name}")
            if member.name in directories:
                if not member.isdir():
                    fail(f"Unexpected directory type: {member.name}")
            elif (not member.isfile() or
                  tar.extractfile(member).read() != files[member.name]):
                fail(f"Unexpected archive file: {member.name}")
        packaged = ET.fromstring(tar.extractfile(f"{PRODUCT}/version.xml").read())
        if packaged.findtext("release") != version:
            fail("Packaged version mismatch")

    data = archive.read_bytes()
    for algorithm in ("md5", "sha256"):
        digest = hashlib.new(algorithm, data, usedforsecurity=(algorithm != "md5")).hexdigest()
        (dist / f"{archive.name}.{algorithm}").write_text(
            f"{digest}  {archive.name}\n", encoding="ascii")
        print(f"{algorithm}: {digest}")
    print(f"Verified {archive.name}: {len(files)} files")


if __name__ == "__main__":
    build()
