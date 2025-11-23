# Agent Git Access Instructions

This document describes the specific configuration required for AI agents to successfully execute git commands and push code in this environment.

## 1. Authentication Setup
**Status:** ✅ Configured
**Method:** `git-credential-store`

We use the `store` credential helper because the standard Windows Credential Manager (`wincred`) often requires interactive UI access that background agent processes do not have.

- **Configuration Command:** `git config --global credential.helper store`
- **Credential Location:** `~/.git-credentials` (User's home directory)
- **Token Type:** GitHub Personal Access Token (Classic) with `repo` scope.

**If Authentication Fails:**
1. Ensure `git config --global credential.helper` returns `store`.
2. If prompted for a password, regenerate a GitHub PAT and enter it once manually to save it to the store.

## 2. Command Execution (CRITICAL)
**Issue:** The agent's persistent background shell session often hangs/freezes when running `git` commands directly (e.g., `git status` hangs indefinitely).
**Solution:** Execute all git commands using a fresh non-interactive shell wrapper: `cmd /c`.

**Correct Usage:**
```bash
cmd /c git status
cmd /c git push origin HEAD:dev
```

**Incorrect Usage (Will Hang):**
```bash
git status
git push
```

## 3. Workflow
1. **Make Changes:** Agent edits files.
2. **Commit:** Agent runs `cmd /c git commit -m "message"`.
   - *Note:* Avoid complex quoting or special characters in commit messages when using `cmd /c` to prevent parsing errors.
3. **Push:** Agent runs `cmd /c git push origin HEAD:dev`.
4. **Deploy:** Pushing to `dev` automatically triggers the GitHub Actions deployment workflow.

## 4. Troubleshooting
If `cmd /c git ...` commands start failing or hanging:
1. **Restart the IDE/Agent:** The internal shell session may be completely dead. A restart usually restores functionality.
2. **Verify Path:** Run `cmd /c where git` to ensure git is accessible.
