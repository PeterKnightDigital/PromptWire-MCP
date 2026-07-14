PromptWire — personal Cursor skills (all projects)

Copy skills into your personal skills folder so every ProcessWire workspace
gets PromptWire workflow guidance even without a project rule:

  cp -R install/cursor-skills/promptwire ~/.cursor/skills/promptwire
  cp -R install/cursor-skills/promptwire-page-rename ~/.cursor/skills/promptwire-page-rename

For per-project rules (stronger — applies every chat in that repo), use
install/cursor-rules/ instead.

When developing PromptWire itself, symlink your clone into site/modules/PromptWire
rather than vendoring a copy inside the site repo — see README.md.
