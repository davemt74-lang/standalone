# Admin Subscriptions & Packages V1

This module introduces Annotated's commercial account/package layer without changing the manual Research Team model.

## Seed packages

The migration seeds three editable packages:

- **Free Trial** — personal starter package, 14-day trial setting, one account member.
- **Basic User** — individual package, one account member.
- **Team Builder** — multi-member commercial account package, seeded with five account members.

Prices and monthly AI-token allowances intentionally remain configurable in Admin rather than being treated as product constants.

## Architecture

A login/user is not itself the package record.

- `accounts` are the commercial/container layer.
- `account_members` are account membership only.
- `subscription_packages` define commercial package settings.
- `accounts.package_id` is the current package.
- `subscription_package_events` records attributable package assignment history.
- `subscription_package_admin_events` records package-catalog creation and changes.

Every existing user is backfilled into one personal account. Legacy `free` users map to **Free Trial** and legacy `pro` users map to **Basic User**. New password/OAuth users receive a personal Free Trial account when migration 062 is present.

Research `teams` / `team_members` remain separate. Selecting Team Builder does not create a Research Team and does not change Research Team membership.

## Admin

`/admin/packages.php` lets an administrator:

- create packages
- edit package names/descriptions
- set monthly price
- set future monthly AI-token allowance
- set account member limit
- set trial days
- map the package to the current legacy Free/Pro access gate
- publish/archive packages
- inspect account counts and package-definition audit history.

`/admin/users.php` shows each user's personal account/package and lets an administrator assign an active package with an optional reason. The assignment resets that account's monthly period and writes an immutable assignment event.

## Compatibility

The package model synchronizes `users.plan_tier` from the package's legacy access mapping so existing Annotated Free/Pro feature gates continue to work until the new entitlement layer replaces them.

Migration 062 is required for the module.
