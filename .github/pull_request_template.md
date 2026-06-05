<!--
  PR title should follow Conventional Commits, e.g.:
    feat: add address autocomplete to checkout
    fix: prevent duplicate API calls on field blur
  Include the Jira key where relevant, e.g. "LOQ-1234: ...".
-->

## 🎫 Jira ticket

<!-- Link the ticket, e.g. [LOQ-1234](https://gbgplc.atlassian.net/browse/LOQ-1234) -->

## 📝 Description

<!-- Why is this change needed? What problem does it solve? Context for the reviewer. -->

## 🔧 What changed

<!-- Bullet the concrete changes. Call out anything risky or non-obvious. -->

-
-

## ✅ Tests

- [ ] Unit tests pass locally (`vendor/bin/phpunit`)
- [ ] New/changed behaviour is covered by tests
- [ ] Manually verified the change (describe how below, if applicable)

<!-- Notes on what you tested and the results: -->

## 📦 Conventional commits & versioning

- [ ] Commits follow [Conventional Commits](https://www.conventionalcommits.org/)
- [ ] I understand the version impact of this PR:
  - `feat:` → **minor** bump · `fix:` → **patch** bump · `BREAKING CHANGE` → **major** bump
  - other types (`chore:`, `docs:`, `ci:`, `refactor:`, `test:`, …) → **no release**

## 👀 Reviewer checklist

- [ ] Code is readable and follows the conventions of the surrounding code
- [ ] Change matches the linked Jira ticket's scope (no unrelated changes)
- [ ] Tests are present and meaningful; CI is green
- [ ] Commit messages / PR title are correct Conventional Commits
- [ ] No secrets, credentials, or debug code committed
