EAO Plugin - Dev to Staging to Production flow

This repo is wired so that any change you push to dev auto-deploys to Kinsta Staging, and a reviewed change merged to main can be manually deployed to Kinsta Production from GitHub Actions.

Below is the complete, copy-pasteable guide you can put in README.md.

Prerequisites (already set up)

Branches

dev - integration branch, auto-deploys to Staging.

main - release branch, deploys to Production when triggered.

GitHub Actions workflow

.github/workflows/deploy.yml

Triggers:

push to dev -> deploy to Staging (automatic)

workflow_dispatch (manual form) -> deploy to either Staging or Production based on selected branch/environment.

Kinsta SSH + rsync deploy

The workflow uses SSH keys and rsync to copy the plugin to the server, then runs post-deploy WP-CLI steps (cache flush and plugin (re)activate).

Secrets (GitHub -> Settings -> Environments)

Staging environment secrets:

KINSTA_HOST, KINSTA_PORT, KINSTA_USER, KINSTA_PLUGIN_PATH, KINSTA_SSH_KEY

Production environment secrets:

KINSTAPROD_HOST, KINSTAPROD_PORT, KINSTAPROD_USER, KINSTAPROD_PLUGIN_PATH, KINSTAPROD_SSH_KEY

Notes:

*_SSH_KEY = the private key text (BEGIN/END included).

*_PLUGIN_PATH is the absolute path to the plugin folder on the server (e.g. /www/site_123/public/wp-content/plugins/enhanced-admin-order-plugin).

.gitignore

Tracks .github/workflows/ and .github/deploy-exclude.txt (everything else you don't want deployed can go in that exclude file).

"Commit" vs "Sync" in Cursor

Commit = save changes to your local repo with a message.

Sync = push your local commits to GitHub (and pull any remote changes).

You must Commit -> Sync to trigger the pipeline.

Daily Workflow (Dev -> Staging)

Start on dev

In Cursor: check the status bar shows you're on the dev branch.

If you need to update your local branch: git pull (or "Sync").

Make your changes

Edit files, save.

Stage & Commit

Cursor Source Control: stage only the files you intend to ship.

Write a clear message (e.g. feat: adjust admin table layout) and Commit.

Sync (push)

Click Sync.

This pushes to GitHub -> auto-deploys to Staging via the Action.

Verify on Staging

Smoke test the plugin on the Kinsta staging site.

If something's wrong, repeat steps 2-4 until it's good.

Promote to Production (Dev -> Main -> Production)

Open a Pull Request (PR) from dev -> main

On GitHub, either:

Click the yellow "Compare & pull request" banner (when GitHub detects dev is ahead of main), or

Go to Pull requests -> New pull request, set base=main, compare=dev, then Create pull request.

Add a descriptive title and summary (what/why).

Review & Merge

Choose Merge commit (recommended to keep history clear for back-merges).

Click Merge pull request.

Optional: protect main with branch rules/reviewers if needed.

Deploy to Production (manual)

Go to Actions -> select "Deploy EAO plugin to Kinsta".

Click Run workflow (top-right).

Choose:

Branch: main

Environment (if shown): Production

Click Run workflow.

Wait for green check, then verify on Production.

(Important) Keep dev in sync

After releasing, open a PR from main back to dev and Merge commit.
This prevents drift ("2 ahead / 3 behind") and keeps dev ready for the next change.

Quick Links / Where Things Live

Actions (deploys): Repo -> Actions -> Deploy EAO plugin to Kinsta

Secrets: Repo -> Settings -> Environments -> Staging / Production

Workflow file: .github/workflows/deploy.yml

Rsync exclude list: .github/deploy-exclude.txt
