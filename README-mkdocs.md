# MkDocs Documentation Setup Guide

A concise guide to setting up, running, and maintaining project documentation using MkDocs.

---

## 1. What is MkDocs?

- MkDocs is a fast, simple static site generator designed specifically for project documentation.
- You write docs in Markdown, configure using `mkdocs.yml`, and build a clean documentation website.

## 2. Requirements

- Python 3.6+
- pip (included with Python)

## 3. Install MkDocs

```sh
pip install mkdocs
```

**Optional: Install Material Theme (recommended)**

```sh
pip install mkdocs-material
```

## 4. Project Structure

- `docs/` → All Markdown files for your documentation
- `mkdocs.yml` → Main configuration (site name, navigation, theme, plugins, etc.)

## 5. Creating Documentation (from scratch)

**Initialize MkDocs**

```sh
mkdocs new .
```

This creates:
- `docs/` folder
- Default `mkdocs.yml`

**Configure the site**

Open `mkdocs.yml` and set:
- `site_name:`
- `theme:` (e.g., material)
- `nav:` for navigation

**Add Markdown Files**

- Create `.md` files inside `docs/`
- Organize using folders as needed

## 6. Run Documentation Locally

```sh
mkdocs serve
```

- Opens at [http://127.0.0.1:8000](http://127.0.0.1:8000)
- Automatically reloads when you edit `.md` or `mkdocs.yml`

## 7. Build Static Site

```sh
mkdocs build
```

- Outputs the final static site into the `site/` folder
- This folder is ready for hosting anywhere


## 8. Deploying to GitHub Pages

The generated `site/` folder is **not** committed to the repository (it is listed in
`.gitignore`) — it is a build artifact and goes stale the moment `docs/` changes.
Publish with MkDocs' own deploy command, which builds the site and pushes it to the
`gh-pages` branch:

```sh
mkdocs gh-deploy
```

Then, in the repository on GitHub: **Settings → Pages → Build and deployment →
Source: Deploy from a branch**, and select the `gh-pages` branch with `/ (root)`.
The site becomes available at:
`https://<your-username>.github.io/<your-repository-name>/`

---

## 9. Useful MkDocs Commands

| Action            | Command           |
|-------------------|------------------|
| Run local server  | mkdocs serve     |
| Build static site | mkdocs build     |
| Deploy to GH Pages| mkdocs gh-deploy |
| Help menu         | mkdocs --help    |

## 10. Adding a New Documentation Page

1. **Create the Markdown file**
   - Example: `docs/iot/sensors.md`
2. **Add it to navigation in `mkdocs.yml`**
   ```yaml
   nav:
     - Home: index.md
     - IoT Modules:
         - Sensors: iot/sensors.md
   ```
3. **Preview or build**
   ```sh
   mkdocs serve
   ```

## 11. Should You Edit the `site/` Folder?

❌ **No. Never edit `site/` manually.**
It is auto-generated and overwritten every time you run:

```sh
mkdocs build
```

✔ **Edit only:**
- Markdown files in `docs/`
- `mkdocs.yml` configuration

## Best Practices

- Keep Markdown files organized inside `docs/`
- Update navigation in `mkdocs.yml`
