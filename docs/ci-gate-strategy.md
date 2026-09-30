# CI Gate Strategy

Annotated uses three CI levels.

## Ordinary pull requests

Every pull request runs `Annotated CI`.

This covers:
- PHP 8.1 / 8.3 syntax
- JavaScript syntax
- static architecture/security contracts
- the existing model-governance integration suite

Ordinary PRs do not run the historical release matrix.

## Section gate

A PR title containing `[section-gate]` runs `Annotated Section Gate` in addition to Annotated CI.

The Section Gate:
- discovers DB journey tests changed by the PR
- discovers migration upgrade rehearsals changed by the PR
- runs only those changed DB/rehearsal tests
- runs them against MariaDB 11.4
- runs them against MySQL 8
- prepares a clean current schema before each ordinary DB journey
- isolates upgrade rehearsals from ordinary DB journeys

This is the default gate for feature sections.

Section work should include its own focused DB journey and migration rehearsal when the section changes schema.

## Release gate

A PR title containing `[release-gate]` runs `Annotated Full Regression`.

This is reserved for:
- final phase completion
- release candidates
- release hardening
- explicit historical compatibility validation

It runs the complete historical MariaDB regression and full MySQL 8 compatibility / upgrade matrix.

Do not use `[release-gate]` for normal section development.

## Packaging

Production package creation remains a separate release step after the accepted merge. Package contents and smoke checks remain authoritative for deployable ZIPs.

## Rationale

Historical compatibility coverage remains available, but it no longer blocks every feature section with unrelated old fixtures. Section PRs prove the code they changed on both supported database families, while release gates periodically prove the complete historical matrix.
