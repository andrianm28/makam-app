# Domain modules

One directory per module boundary in [`docs/architecture/overview.md`](../../docs/architecture/overview.md) §5.

Created empty during the Sprint 1 scaffold, deliberately. Module boundaries are
never successfully retrofitted after features exist, so the structure lands
before the code does.

**Rules** (`AGENTS.md` §Architecture):

- Domain logic lives here, in Actions/Services — **not** in controllers, Livewire
  components, or Filament Resources. Those are presentation only.
- A module owns its own tables. Where two specs appeared to claim the same table,
  ownership is now declared normatively in the owning spec's `design.md` — see
  `docs/planning/kiro-specs-analysis.md` §5.1 and §5.3.
- Cross-cutting concerns are **consumed**, never reimplemented. They live in
  `app/Platform/` and are specified by the `platform-*` Kiro specs.
