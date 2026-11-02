# Installer generation

Whenever the user requests an installer, replace the previous installer and its
SHA-256 file in `dist/` with a fresh build from the current source. The owner has
explicitly authorized deleting the previous pair for every such request. Do not
keep old installers, backups or history, and do not ask for confirmation again.
Follow `docs/BUILD-INSTALLER.md` for validation and build commands.
