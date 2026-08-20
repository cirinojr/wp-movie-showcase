# Real Screenshot Checklist

The repository currently has a generated presentation banner, but no fabricated product screenshots. Capture these images from a real WordPress installation after configuring a non-production OMDb key.

## Capture requirements

Use one consistent browser viewport, remove personal information, and never expose the OMDb key. Save optimized PNG or WebP files under `docs/images/screenshots/`.

1. **Block editor — `block-editor.png`**
   - Show the WordPress editor with the Movie Search block selected.
   - Include the block title and placeholder, without unrelated admin notices.

2. **Frontend autocomplete — `autocomplete.png`**
   - Type at least three characters.
   - Show the expanded accessible suggestion list with several real OMDb results.

3. **Movie result — `movie-result.png`**
   - Select a suggestion and show the completed result card.
   - Include poster, title, metadata, and rating at a readable size.

4. **Settings — `settings.png`**
   - Show **Settings > Movie Showcase**.
   - Keep the password field empty; never reveal or fake an API key.

## README update

After capturing all four real images, add a compact **Demo** section near the top of `README.md`. Use thumbnails linked to the full-size images instead of a heavy GIF.
