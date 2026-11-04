# Working in this repo

- Be efficient. Take the quickest path and don't send the user on manual chases. If a tool can do a step, do it.
- Before any plugin work, check the latest GitHub release and the version in `impressions-control.php`. Never build from an older copy, including any copy in another repo.
- Bump the version, update README "Current release" and `PLUGIN-REFERENCE.md` (in `impressionsagency/imp-hub`) in the same pass.
- Host allowlist changes are global to every client site: use exact hostnames, never broad wildcards, and flag it on the PR.
- Cloud sessions can't create GitHub releases. If a release is needed, hand the user the zip (Artifact `downloads` capability) or a branch a local session can release.
