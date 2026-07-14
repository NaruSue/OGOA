# Codex Workflow

This repository uses two distinct environments:

- Local WSL Ubuntu: development, analysis, editing, and helper scripts
- VPS: production deployment target

## Working Rules

- Start local development from WSL Ubuntu 24.04.
- Keep PowerShell usage limited to Windows-specific tasks or WSL launch commands.
- Treat `deploy/deploy-vps.sh` as the script that runs on the VPS.
- Treat `deploy/deploy-wsl.sh` as the local WSL helper that SSHes into the VPS and starts the VPS deploy script.
- Treat `deploy/deploy-vps.ps1` as a launcher only.
- Put reusable shell helpers under `devscripts/`.
- Do not hardcode passwords, private keys, host credentials, or production `.env` values in scripts.
- Store text files as UTF-8 without BOM.
- Do not introduce legacy encodings or BOM-prefixed files.
- When editing files with non-ASCII content, preserve the file's existing encoding and verify the result before deploying.

## Local Development Entry

Open the WSL environment first:

```powershell
wsl -d Ubuntu-24.04
```

Use explicit users only when needed:

```powershell
wsl -d Ubuntu-24.04 -u n-cho
wsl -d Ubuntu-24.04 -u codex
wsl -d Ubuntu-24.04 -u root
```

After entering WSL, run project commands from inside Ubuntu. This avoids PowerShell quoting,
path, and command-chain differences.

Recommended local flow:

```bash
cd ~/work
git clone <repo-url>
cd <project>
```

Then run the project's own setup, build, test, or deployment helper scripts.

Use Windows hosts entries when local URL generation needs production-like hostnames:

```text
127.0.0.1  1g1a.local
127.0.0.1  s1g1a.local
```

Local development should use HTTP by default. Use staging for Let's Encrypt,
OAuth HTTPS, Secure cookie, and certificate-sensitive checks.

## Directory Roles

- `devscripts/`: reusable local helpers for analysis, inspection, and editing support
- `deploy/`: deployment scripts and deployment-specific config examples
- `docs/`: long-lived operational notes and workflow docs

## Logging Convention

- Keep console output minimal.
- Write progress, command output, and errors to log files.
- Use log files for postmortem analysis when something fails.

## Deployment Model

- Final deployment always targets the VPS.
- Local WSL Ubuntu is the default development environment and can also run helper scripts.
- Production changes are uploaded over SSH and applied on the VPS.

## Current Entry Points

- `devscripts/context.sh`: capture repo state and search results for analysis
- `deploy/deploy-vps.ps1`: start the deployment flow from Windows
- `deploy/deploy-wsl.sh`: perform the local WSL-side deployment packaging and upload
- `deploy/deploy-vps.sh`: execute the final deployment on the VPS
