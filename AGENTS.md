# AGENTS.md

Instructions for any coding agent other than Claude Code (Codex, OpenCode, Cline and the
like). In this repository your job is **code analysis**: read the code, find problems and write
a report. Claude Code does the development work and follows `CLAUDE.md`.

## What you may write

- **Only inside `code-analysis/`.** Do not change any other file, including to fix what you
  find. Fixes are made separately, from your report
- `dev-notes/` belongs to Claude Code. Read it if useful (`dev-notes/02-snagging-list.md`, if
  present, lists known issues already agreed), but never write to it
- If `code-analysis/` doesn't exist, create it and copy `dev-notes/.htaccess` into it, changing
  only the first comment line to `# External code-analysis results. Block all remote access.`
  The directory sits under a web root, and that file stops it being served
- Don't commit, stage or push anything. The maintainer decides what goes into git

## Context

Read `CLAUDE.md` first, but only for what this plugin is: its architecture, request flow, key
files, high-impact code and public contracts. Its working rules are for Claude Code, not you.
`docs/` describes the plugin's behaviour for site operators and developers.

This is a WordPress plugin with no build step: no Composer, no npm. Its PHP and WordPress
floors are `Requires PHP` and `Requires at least` in the main plugin file's header.

## What to look for

In order of priority:

1. **Security:** missing capability checks or nonce verification, unescaped output, unsanitised
   input, SQL not built with `$wpdb->prepare()`, authentication or authorisation bypasses,
   secrets or tokens stored or compared unsafely, open redirects
2. **Correctness:** logic errors, unhandled failure paths (a `WP_Error`, `false` or `null` used
   as if it were a value), race conditions, wrong behaviour at boundaries
3. **Robustness:** code that fails open where it should fail closed, errors caught and dropped
   without a trace

Not style, formatting or naming; `phpcs` covers those. Not features the plugin could have.
Check each finding against the code before reporting it, and drop any you can't support with a
concrete failure.

## The report

One Markdown file per run: `code-analysis/YYYY-MM-DD-<scope>.md`, where `<scope>` is a short
slug (`full`, `login-flow`, `settings`). Never edit or overwrite an earlier report; a second run
on the same day adds a suffix (`-2`).

Start with the date, the commit analysed (`git rev-parse --short HEAD`), the agent and model,
and what was in scope. Then one section per finding, most severe first:

```markdown
## 1. Short title

- **Severity:** critical | high | medium | low
- **Location:** `includes/class-example.php:123`
- **Problem:** one or two sentences
- **Failure scenario:** the concrete input or state, and what goes wrong
- **Suggested fix:** brief; no patch needed
```

End with anything checked and found sound, briefly, so the next run needn't repeat it.
Reports can describe unfixed vulnerabilities: `code-analysis/` is never committed to a public
repository and never published.
