---
trigger: always_on
---

# Project Rules (Concise)
1. Deploy: NEVER deploy, push to remote, or run remote sync without explicit user confirmation.
2. Changelog: Update CHANGELOG.md using [YYYY-MM-DD] under Added/Changed/Fixed. Keep descriptions brief.
3. UI: Mobile-first, responsive (<768px, 768-1024px, >1024px). No horizontal overflow. 44px min touch targets.
4. Validation: Test locally before marking complete (`php -l`, curl/browser check). Fix errors autonomously.
5. Git: Atomic conventional commits on feature branches. No `git add .` secret leaks.
6. Architecture: Zero unnecessary dependencies. Match existing patterns. Keep secrets in .env only.
7. Data: No raw SQL string concatenation. No destructive queries (DROP/TRUNCATE).