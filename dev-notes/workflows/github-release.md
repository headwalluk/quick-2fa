# GitHub Release Workflow

**Purpose:** Steps for tagging and releasing via GitHub, and how the in-plugin updater interacts with releases.  
**Last Updated:** 10 April 2026

---

## Release Steps

1. Update version in `quick-2fa.php` (both the header comment and `QUICK_2FA_VERSION` constant)
2. Update `CHANGELOG.md` with changes
3. Update `readme.txt` stable tag
4. Run `phpcs` to verify compliance
5. Commit: `git commit -m "release: vX.Y.Z"`
6. Tag: `git tag vX.Y.Z`
7. Push: `git push && git push --tags`
8. Create a GitHub Release from the tag, attaching a `quick-2fa.zip` build artifact

---

## GitHub Updater: How It Works

The `Headwall_GitHub_Plugin_Updater` class hooks into `pre_set_site_transient_update_plugins`. On each check it:

1. Calls the GitHub Releases API (`/repos/{owner}/{repo}/releases/latest`)
2. Compares the release tag (e.g. `v1.0.0`) against the installed `Version` header
3. Looks for a `.zip` asset matching the plugin slug (e.g. `quick-2fa.zip`)
4. If a newer version exists with a valid zip, injects it into the WordPress update transient

Results are cached in a transient for 12 hours.

---

## Version Consistency Rule

**The `Version` in `quick-2fa.php` must always correspond to a real GitHub Release tag.**

If the installed version doesn't match any GitHub release, the updater will query GitHub for the "latest" release and compare versions. This works correctly for detecting updates, but the WordPress plugin info modal (View Details) will fail to load metadata for the currently installed version if it doesn't exist as a GitHub release.

**What breaks if you set a version that doesn't exist on GitHub:**

- The "View Details" link on the plugins page may show incomplete information
- Debug logs will show `GitHub returned HTTP 404` if anything tries to look up the non-existent version
- Version comparison still works (the updater only checks `releases/latest`, not the installed version's tag), but the overall experience is degraded

**Safe testing approach:** If you need to test update detection, create a real GitHub release with a lower version number rather than faking the installed version to a non-existent tag.

---

## Re-Releasing a Version

If you need to re-release the same version (e.g. to fix the build artifact):

```bash
# Delete the remote tag
git push origin --delete vX.Y.Z

# Delete the local tag
git tag -d vX.Y.Z

# Re-tag at the current commit
git tag vX.Y.Z

# Push the new tag
git push --tags
```

Then delete the old GitHub Release and create a new one from the new tag.

**Important:** After re-releasing, sites that already updated will have the release cached in a transient (`headwall_ghu_*`) for up to 12 hours. The cached data includes the zip URL, which will be stale if the release was recreated. Sites won't re-fetch until the transient expires.
