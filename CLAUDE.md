# Porthole

## Agent context (IMPORTANT)

All Claude/AI agent context for this project lives in the git submodule at:

```
agent-CTRS/porthole/
```

Specifically:
- `agent-CTRS/porthole/CLAUDE.md` — agent instructions for this project
- `agent-CTRS/porthole/.claude/` — Claude Code settings and skills
- `agent-CTRS/porthole/.superpowers/` — Superpowers configuration
- `agent-CTRS/porthole/docs/` — agent-facing documentation

**Always read and follow `agent-CTRS/porthole/CLAUDE.md` in addition to this file.**
If the submodule is missing, run `make submodule-init` first.
