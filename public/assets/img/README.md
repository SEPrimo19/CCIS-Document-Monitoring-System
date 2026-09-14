# Logo

Drop the college / department logo here as **`logo.svg`** (preferred) or
`logo.png`. It then appears automatically in the sidebar and the mobile header
on every screen — no code change needed.

Accepted filenames, in the order they are looked for:

    logo.svg    logo.png    logo.webp    logo.jpg    logo.jpeg

Guidance:

- **Square-ish** artwork works best; it is displayed at 40x40 in the sidebar
  and 28x28 in the mobile header.
- **Transparent background** (SVG or PNG) so it sits cleanly on both the light
  and dark themes.
- If the logo is dark-on-transparent it will disappear against the dark theme —
  supply one with a light outline, or a version that reads on both.

Until a file is present the layout falls back to the "CCIS-DMS" wordmark, so
nothing breaks while you are waiting for the artwork.

See `brand_logo()` in `app/Core/helpers.php`.
