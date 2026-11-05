# Meridian Workforce: component class names

Every layout block and component has its own stable class name, in the spirit of Bootstrap: you can tell what an element *is* from its class, not just how it looks. This applies to the HTML mockup's new and changed parts now, and to every screen of the real frontend from M6 onwards.

## Naming rule

| Pattern | Meaning | Example |
|---|---|---|
| `mw-<block>` | A component or layout block | `mw-sidebar`, `mw-card`, `mw-tabs` |
| `mw-<block>__<element>` | A part of that block | `mw-sidebar__footer`, `mw-tabs__item` |
| `mw-<block>--<modifier>` | A variant | `mw-alert--warning`, `mw-btn--primary` |
| `is-<state>` | A temporary state | `is-active`, `is-on`, `is-met`, `is-open` |

- Prefix `mw-` (Meridian Workforce) everywhere, so our classes never clash with Ant Design (`ant-…`) or Tailwind utilities.
- Utility classes (Tailwind) are allowed only for small layout tweaks (spacing, grid columns) inside a component, never instead of a component class.
- In the Next.js app, each component sets its `mw-` class on its root element, even when it is built on an Ant Design component (e.g. `<Button className="mw-btn mw-btn--primary">`).
- Colours, radii and fonts come from the design tokens (CSS variables) in the design decision, never hard-coded in a component.

## Component set

| Class | What it is |
|---|---|
| `mw-app` | The signed-in application frame (sidebar + main area) |
| `mw-sidebar` | Navigation sidebar; labelled by default, collapses to an icon rail |
| `mw-sidebar__group` | A group heading in the sidebar (Overview, Work, …) |
| `mw-nav-item` | One menu link; `is-active` on the current page |
| `mw-sidebar__version` | The version line above the profile card |
| `mw-sidebar__footer` | The sidebar's bottom area |
| `mw-profile-card` | The signed-in user's picture + display name + role; opens My Profile |
| `mw-topbar` | Top bar: breadcrumb, page title, search, language switch, actions |
| `mw-page` | The scrollable page content area |
| `mw-lang-switch` | EN / 中文 language switch (`--floating` on the sign-in page) |
| `mw-login`, `mw-login__panel` | Sign-in page and its form panel |
| `mw-tabs`, `mw-tabs__item` | Tab bar and its tabs |
| `mw-section`, `mw-section__title`, `mw-section__hint` | A titled content panel |
| `mw-form-grid` | Responsive grid of form fields |
| `mw-field__readonly` | A value the user can see but not edit (e.g. legal name) |
| `mw-alert` (`--warning`, `--info`) | An inline message at the top of a page or section |
| `mw-switch-field` | An on/off switch with its On/Off label |
| `mw-avatar-editor` | Profile picture with upload/remove controls |
| `mw-official-photo` | HR's official photo (org chart, HR records, management views) |
| `mw-progress`, `mw-progress__bar` | Progress bar (e.g. profile completeness) |
| `mw-pw-rules`, `mw-pw-rules__item` | Live password-rule checklist; `is-met` when satisfied |
| `mw-device` | A registered 2FA authenticator device |
| `mw-qr` | QR code for adding an authenticator |
| `mw-code-list` | Recovery codes |
| `mw-version` | Installed version and its status |
| `mw-update-log`, `mw-update-log__row`, `mw-update-log__steps` | System update history and each run's step log |

Existing mockup screens that were not changed still use the original classes (`card`, `btn`, `tbl`…). They get `mw-` names when they are rebuilt in Next.js.
