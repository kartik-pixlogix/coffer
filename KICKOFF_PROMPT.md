# Start building Coffer in VS Code with Claude Code

This Cowork session can't be moved into VS Code. Everything it worked out is in this kit, so Claude Code in VS Code can pick up from here:

- the audit of the reference product
- the spec and the build plan
- your final design system and all 44 screens

## One-time setup (about 10 minutes)

1. Install PHP 8.3+, Composer, Node 20+, PostgreSQL 16 (or MySQL 8), Git and the Laravel installer. Make sure the Claude Code extension in VS Code is signed in.
2. Create the app in a terminal with `laravel new coffer`. When it asks, choose:
   - the **React** starter kit
   - **Laravel's built-in authentication**
   - **Pest**
   - your database
3. Copy the **contents** of this kit (`CLAUDE.md`, `KICKOFF_PROMPT.md`, `docs/`, `design/`) into the `coffer` folder, next to `app/` and `resources/`.
   - If the project already has a `CLAUDE.md` (Laravel Boost creates one), keep ours at the top and paste the old content below a heading "Laravel guidelines".
4. In the `coffer` folder run `git add -A && git commit -m "Coffer starter kit"`.
5. Open the folder in VS Code and open the Claude Code panel. **Don't run `/init`**: `CLAUDE.md` is already written, and Claude Code reads it automatically.

## Prompt 1: kickoff (paste as your first message)

```text
You're building Coffer. Read CLAUDE.md first, then docs/BUILD_PLAN.md, docs/DESIGN_MAP.md and docs/PRODUCT_SPEC.md (read §4 in full, skim the rest).

1. In about 10 lines, tell me what you understood (product, stack, phases) and list any blockers or questions.
2. Then start Phase 0. Read its "Read first" files, give me a step-by-step plan and wait for my OK. After that, build it and commit after each step.

Rules: never edit anything in design/. Don't add dependencies beyond those in CLAUDE.md without asking. Log decisions in docs/DECISIONS.md.
```

## Prompt for each next phase (change the number)

```text
Start Phase 1 from docs/BUILD_PLAN.md. Read its "Read first" list plus the matching spec sections and design screens, then give me a plan and wait for my OK.

When building:
- match the .dc.html screens and the design-system previews in light and dark
- keep tests green and the ledger invariants passing
- commit per step and tick the boxes in BUILD_PLAN.md

Finish only when every acceptance box is ticked.
```

Start a fresh conversation for each phase (`/clear`). `CLAUDE.md` reloads automatically, and the ticked boxes in `BUILD_PLAN.md` carry the progress over.

## Handy prompts during the build

- **Design check:** "Compare resources/js/pages/expenses/index.tsx with design/screens/Expenses.dc.html and design-system/components/DataTable/preview.html. List every difference in structure, classes, copy and states, then fix them."
- **Ledger check:** "Run php artisan test and the ledger invariant tests. Fix anything failing and explain the cause."
- **Security check (before finishing Phase 7):** "Audit the vault for secret leaks: index payloads, Inertia shared props, logs, exceptions, activity-log diffs, queue payloads. Add a test that proves none leak."
- **Seed and look:** "Run migrate:fresh --seed and tell me which pages I should open to compare with the designs."

## What's in this kit

| Path | What it is |
|---|---|
| `CLAUDE.md` | Project memory: stack, architecture rules, design rules, conventions, commands |
| `docs/PRODUCT_SPEC.md` | Full behaviour spec: roles, accounting model and formulas, every module, vault security |
| `docs/BUILD_PLAN.md` | 10 phases with "Read first" lists, checklists and acceptance criteria |
| `docs/DESIGN_MAP.md` | Every design screen mapped to a route and React page, plus how to read `.dc.html` files |
| `docs/DECISIONS.md` | Decisions so far. Lines marked `[VERIFY]` need your confirmation |
| `design/design-system/` | Final design system: README, `tokens.json`, `components/bundle.css`, 54 components (README + preview), logos and favicons |
| `design/screens/` | All 44 screens exported from your Claude Design canvas (desktop, mobile, states) |
