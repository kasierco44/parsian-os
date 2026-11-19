# PARSIAN ONE — Windows desktop shell

Initial Tauri 2 shell proof of concept that packages the shared web-panel UI as a Windows desktop window.

## Build on Windows

Requirements: Rust stable/MSVC toolchain, WebView2 runtime, and Tauri v2 build prerequisites.

```powershell
cd desktop
cargo install tauri-cli --version "^2" --locked
cargo tauri build --bundles msi,nsis
```

Expected bundle folders are under `src-tauri/target/release/bundle/`.

## Current limitations

- This is a desktop shell proof of concept, not a completed Windows accounting/management application.
- The current web UI still uses WordPress browser-cookie REST authentication and requires a same-origin WordPress REST nonce. Tauri-origin authentication, Bearer token session management, CORS policy and server URL configuration must be implemented before the shell can connect to the staging API.
- Encrypted local SQLite storage, durable sync outbox/inbox, OS credential vault, backup/restore, role-aware desktop packaging and code signing are not implemented.
- The Windows bundle workflow is a build attempt only. No installer is claimed until CI succeeds and the MSI/EXE artifacts are inspected and installed in a clean Windows VM.

## Desktop bearer authentication update (2026-11-19)

- Tauri exposes a restricted invoke bridge for saving/loading/clearing the session in Windows Credential Manager via the keyring crate. The panel can log in against a supplied HTTPS Staging WordPress site and rotate opaque bearer tokens.
- WordPress REST CORS allows only the configured origins in parsian_desktop_allowed_origins; defaults cover Tauri's local Windows origins. Requests use bearer tokens without browser cookies.
- The shared manager panel includes database snapshot download and checksum validation. It does not restore data automatically and does not include WordPress uploads/media. A complete host-level database + files backup is still required.
- MSI/NSIS build, credential-vault behavior, remote CORS, installation and uninstall tests remain unverified until the Windows CI bundle is successful and installed in a clean VM.
