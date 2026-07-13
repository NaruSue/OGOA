# Devscripts

Reusable shell helpers for analysis, inspection, and deployment tasks.

Local WSL Ubuntu is the preferred execution environment for these helpers.
The final deployment target is still the VPS.
Text files in this repo should be UTF-8 without BOM.

See [`docs/codex-workflow.md`](../docs/codex-workflow.md) for the repository-wide working rules.

## Scripts

- `lib.sh`: shared shell helpers for logging, environment loading, and command checks.
- `context.sh`: writes a compact repo snapshot and optional search results to `logs/devscripts/`.

These scripts are intended to stay lightweight and safe to reuse from other bash scripts in this repo.
