# Private VPS preview foundation

This branch prepares only an empty noindex fixture. The existing 14-page site, GitHub Pages main, customer DNS and STROITEX are unchanged.

Deployment packages only infra/preview-placeholder/public and binds every file to this repository and an exact commit. A dedicated forced-command SSH identity has no sudo or forwarding and cannot write production/other project roots. The server independently verifies GitHub source hashes, activates an atomic release pointer, checks HTTPS and automatically rolls back on health failure. It accepts only status/deploy/rollback commands. The preview is private on VPS loopback HTTPS, not a new public customer website.

The workflow is manual and disabled unless VPS_PREVIEW_ENABLED=true. It has contents:read, a pinned checkout and an environment vps-preview. Before enabling: review/merge separately, verify owner protection and allowed branches on that environment, provision the dedicated VPS_PREVIEW_DEPLOY_KEY environment secret securely, then enable the variable. No secret was uploaded by this change. No Actions run or successful CI is claimed. Root is never granted to GitHub Actions.

The server RSA host key is owner-verified and pinned. Never use StrictHostKeyChecking=no. Application code and database migrations for STROITEX require its separate release approval.

Public customer content, public HTTPS/domain switching and production deployment remain separately gated. External backup is blocked until real storage is provisioned and an independent encrypted restore passes.
