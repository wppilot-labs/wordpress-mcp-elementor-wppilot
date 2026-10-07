# builder-quality kit

Scores how editable an Elementor page is, from its saved element tree, and says what to fix.

| Ability | Kind | What it does |
|---|---|---|
| `wppilot/elementor-audit-output` | read | 0–100 score, a grade (good ≥ 85, fair ≥ 60, poor), counts per finding, the fix for each kind, and up to 200 findings with the element ID. |

## Findings

| Finding | Each | At most | Why it matters |
|---|---|---|---|
| `html_widget` | −10 | −40 | Layout pasted into an HTML widget cannot be edited in the panel. |
| `script` | −15 | −30 | Scripts in content break with caching and are invisible to editors. |
| `shortcode_widget` | −5 | −20 | Shortcodes show nothing in the editor. |
| `unknown_widget` | −5 | −20 | The widget's plugin is inactive; it renders nothing. |
| `inline_style` | −3 | −15 | `style=` attributes bypass the style controls and global classes. |
| `custom_css` | −3 | −12 | Element CSS is hard to find and reuse. |
| `hardcoded_color` | −1 | −15 | A fixed colour ignores palette changes (classic `__globals__` win over the value). |
| `deep_nesting` | −2 | −10 | More than six containers deep. |
| `empty_container` | −1 | −5 | A box with nothing in it. |

Classic (`section`, `column`, `container`, `widget`) and atomic (`e-flexbox`, `e-div-block`, `e-*`
widgets) elements are both walked. Unknown widgets are judged against Elementor's widgets
manager when Elementor is loaded.

## Host needs

Nothing beyond `Runtime\can_run()`: the audit reads post meta and changes nothing.

## Safety

Read-only. Needs `edit_post` on the page.

<!-- kit-export:omit -->
## Tests

`tests/Unit/Kits/BuilderQuality` drives `audit_tree()` with classic and atomic trees.
<!-- /kit-export:omit -->
