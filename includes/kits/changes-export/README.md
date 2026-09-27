# changes-export kit

Exports the change ledger as flat rows for reports and audits.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/export-changes` | read | Ledger rows newest first, filtered by time, agent, ability, batch, kind and status. At most 500 rows / 256 KB per call, paged with `offset` / `next_offset`. Before-images never leave the site. |

## Host needs

- `Ledger::query()` and `Ledger::export_row()`. Inside WPPilot these are the Changes screen's
  own reader (`wppilot_query_change_log()`), so the ability, the screen and the CSV/JSON
  download always agree. Standalone, the runtime's MiniLedger answers, covering the changes
  the plugin's own kits recorded.
- `Ledger::download_url()`: WPPilot's Changes screen, or empty standalone.

## Safety

Read-only (`readonly: true`), so it runs under every safety profile and is never
confirmation-gated. The row shape leaves out rollback snapshots by construction.

## Tests

`wppilot/tests/Unit/Kits/ChangesExport/` (not shipped).
