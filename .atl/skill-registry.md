# Skill Registry

**Project**: kinesilk-api
**Detected**: 2026-04-30

## User Skills (from ~/.claude/skills/)

| Skill | Trigger | Description |
|-------|---------|-------------|
| sdd-init | "sdd init", "iniciar sdd" | Initialize SDD context in project |
| sdd-explore | /sdd-explore | Explore and investigate ideas |
| sdd-propose | /sdd-new | Create change proposal |
| sdd-spec | — | Write specifications (delta specs) |
| sdd-design | — | Create technical design |
| sdd-tasks | — | Break down into implementation tasks |
| sdd-apply | /sdd-apply | Implement tasks from change |
| sdd-verify | /sdd-verify | Validate implementation against specs |
| sdd-archive | /sdd-archive | Archive completed change |
| sdd-onboard | — | Guided SDD walkthrough |
| issue-creation | Creating GitHub issue, reporting bug | Issue-first workflow |
| branch-pr | Creating PR, opening PR | PR creation workflow |
| judgment-day | "judgment day", "review adversarial" | Parallel adversarial review |
| skill-creator | Creating new skill | Create AI agent skills |

## Project Conventions

**None detected** — no AGENTS.md, CLAUDE.md, or .cursorrules found.

## Recommended Skills for This Stack

Since this is a Laravel/PHP project:

- **sdd-*** for all SDD phases
- **issue-creation** / **branch-pr** for GitHub workflow
- Consider adding: PHP testing skill (PHPUnit), Laravel-specific patterns

## Notes

- Project uses Laravel 13.0 with PHPUnit 12.5.12
- API-first architecture with Sanctum auth
- No E2E testing currently configured