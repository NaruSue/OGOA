from pathlib import Path

paths = [Path("/etc/apt/sources.list")]
paths += sorted(Path("/etc/apt/sources.list.d").glob("*.list"))

for path in paths:
    if not path.exists():
        continue
    original = path.read_text()
    updated_lines = []
    changed = False
    for line in original.splitlines():
        new_line = line
        if "nova.clouds.archive.ubuntu.com" in new_line:
            new_line = new_line.replace(
                "nova.clouds.archive.ubuntu.com", "archive.ubuntu.com"
            )
        if "security.ubuntu.com" in new_line or "nginx.org" in new_line:
            if not new_line.lstrip().startswith("#"):
                new_line = "# " + new_line
        if new_line != line:
            changed = True
        updated_lines.append(new_line)
    if changed:
        path.write_text("\n".join(updated_lines) + "\n")
