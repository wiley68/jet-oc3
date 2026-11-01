#!/usr/bin/env bash
set -euo pipefail
jet_builder_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
jet_project_dir="$(cd -- "$jet_builder_dir/.." && pwd)"
exec python3 "$jet_builder_dir/build-installer.py" --output "$jet_project_dir/dist" "$@"
