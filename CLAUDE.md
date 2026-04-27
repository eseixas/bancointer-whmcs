# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

This is the **Antigravity Kit** — a meta-framework of agent definitions, skills, and workflows designed to be loaded by AI agents. It is not a runnable application itself; it defines how an AI system should behave, route tasks, and orchestrate multi-agent work.

## Running Validation Scripts

The only executable code lives under `.agent/scripts/` and within skill script folders:

```bash
# Priority-ordered validation (Security → Lint → Schema → Tests → UX → SEO)
python .agent/scripts/checklist.py

# Full verification including Lighthouse and Playwright E2E
python .agent/scripts/verify_all.py

# Individual checks (from skill-specific script directories)
python .agent/skills/security/scripts/security_scan.py
python .agent/skills/testing-patterns/scripts/test_runner.py
python .agent/skills/testing-patterns/scripts/playwright_runner.py
python .agent/skills/performance-profiling/scripts/lighthouse_audit.py
```

## Architecture

The system has four layers, defined entirely in Markdown files:

### 1. Global Rules — `.agent/rules/GEMINI.md`
The top-level behavioral contract. Defines:
- **Intelligent routing**: which agent to invoke based on request classification
- **Socratic Gate**: mandatory 3-question clarification before complex/ambiguous tasks
- **Tier 0/1/2 rules**: universal behavior, code rules, and design rules
- **Request classification**: Question vs. Survey vs. Complex Code
- Rule priority: `GEMINI.md > Agent .md > SKILL.md`

### 2. Agents — `.agent/agents/`
20 specialist agent definitions. Each `.md` file is a system prompt fragment. Key agents:
- `orchestrator.md` — 2-phase coordination (Phase 1: planning via `project-planner`, Phase 2: parallel execution after user approval)
- `security-auditor.md` / `penetration-tester.md` — security review agents
- `frontend-specialist.md`, `backend-specialist.md`, `database-architect.md` — domain specialists

### 3. Skills — `.agent/skills/`
36+ modular knowledge modules, each with a `SKILL.md`. Loaded selectively by agents — only the sections relevant to the current task should be read. Key skills:
- `intelligent-routing/` — agent auto-selection matrix
- `app-builder/` — 13 full-stack scaffolding templates (Next.js, Nuxt, Express, FastAPI, React Native, Flutter, Electron, Chrome Extension, CLI, Monorepo)
- `clean-code/` — coding standards
- `brainstorming/` — Socratic discovery protocol
- `ui-ux-pro-max/` — design system with 50 styles, 21 palettes, 50 fonts (shared assets in `.agent/.shared/`)

### 4. Workflows — `.agent/workflows/`
Slash command procedures: `/create`, `/plan`, `/brainstorm`, `/orchestrate`, `/debug`, `/test`, `/deploy`, `/enhance`, `/preview`, `/status`, `/ui-ux-pro-max`.

## Key Conventions

**Selective reading**: Agents load only the SKILL.md sections needed for the task — avoid loading entire files when a subsection suffices.

**Socratic Gate**: Before implementing complex or ambiguous requests, always ask at least 3 clarifying questions. This is mandatory, not optional.

**Purple ban**: Design agents must avoid purple/violet as a primary color (anti-cliché rule in `GEMINI.md`).

**File dependency awareness**: Before modifying any file, check `CODEBASE.md` (if present in the target project) for dependency relationships.

**Default 2026 stack** (for generated projects): Next.js 16+, React 19, TypeScript, Tailwind CSS v4, Prisma ORM, PostgreSQL, Zod, Clerk/Better Auth, Jest/Vitest + Playwright.

## MCP Configuration

`.agent/mcp_config.json` configures MCP servers used by the framework: `context7` (library docs) and `shadcn` (component registry).

Os testes de PHP podem ser feitos com o WSL