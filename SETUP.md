# LinkedIn Feed Intel

AI-powered tool for curating and analyzing LinkedIn screenshots. Upload screenshots, local OCR extracts text, MiMo structures it into filterable cards.

## Architecture

```
User drops image → PHP upload endpoint
    → Step 1: Ollama (qwen2.5vl:3b) — local OCR, extracts raw text
    → Step 2: MiMo v2.5 via OpenRouter — structures text into card JSON
    → SQLite storage → Frontend renders filterable card grid
```

**No images or data leave the server** except the text sent to OpenRouter for structuring.

## Stack

- **Frontend:** Vanilla JS single-page app (dark theme, card grid, modal, filters)
- **Backend:** PHP + SQLite (no framework)
- **Local OCR:** Ollama + qwen2.5vl:3b (RTX 4060 Ti, runs locally)
- **Card structuring:** MiMo v2.5 via OpenRouter API
- **Web server:** Apache + PHP-FPM on Ubuntu

## Prerequisites

- Apache with PHP-FPM (8.x)
- SQLite3 PHP extension
- PHP curl extension
- Ollama running locally (`ollama serve`)
- Vision model pulled: `ollama pull qwen2.5vl:3b`
- OpenRouter API key

## Setup

1. Clone the repo:
   ```bash
   git clone https://github.com/lcevelik/linkedin-feed-intel.git
   cd linkedin-feed-intel
   ```

2. Ensure Ollama is running with the vision model:
   ```bash
   ollama pull qwen2.5vl:3b
   ollama list  # verify it's there
   ```

3. Configure API key in `html/api/config.php`:
   ```php
   define('OPENROUTER_API_KEY', 'sk-or-your-key-here');
   ```

4. Set permissions:
   ```bash
   sudo chown -R www-data:www-data data/
   sudo chmod -R 775 data/
   sudo chown -R www-data:www-data html/uploads/
   sudo chmod -R 775 html/uploads/
   ```

5. Configure Apache vhost to point to `html/` as document root.

6. Visit https://links.steadiczech.com

## How It Works

### Upload Flow

1. User drops image(s) into the upload zone
2. Frontend POSTs file to `/api/upload.php`
3. PHP saves the image to `html/uploads/`
4. **Local OCR:** PHP sends image to Ollama (`qwen2.5vl:3b`) which extracts all visible text
5. **Card structuring:** Extracted text is sent to MiMo v2.5 via OpenRouter, which returns structured JSON (source, category, summary, bullets, links)
6. Result is stored in SQLite and returned to the frontend

### Categories

| Category | Description |
|----------|-------------|
| `3dgs` | Gaussian Splatting, 4DGS, 3D reconstruction, NeRF |
| `vp` | Virtual Production, Unreal Engine, LED stages, ICVFX |
| `ai` | AI workflows, ComfyUI, LLMs, agents, diffusion |
| `tools` | Software, plugins, apps, SDKs, services |
| `contact` | Direct messages, networking, people |

## API Endpoints

### `GET /api/posts.php`
Returns all posts as JSON array (newest first).

### `POST /api/upload.php`
Upload image. Multipart form with `file` field.
Returns the new post object with AI-generated analysis.

### `DELETE /api/posts.php?id=XXX`
Delete post by ID. Removes from database and deletes image file.

### `POST /api/add-post.php`
Add a post via JSON body (no image). Useful for manual entries.

## File Structure

```
linkedin-feed-intel/
├── PROJECT.md
├── SETUP.md
├── data/
│   └── posts.db              ← SQLite database (outside webroot)
└── html/
    ├── index.html             ← Frontend SPA
    ├── .htaccess              ← Protects config.php
    ├── uploads/               ← Uploaded images
    └── api/
        ├── config.php         ← Configuration (API keys, model names)
        ├── db.php             ← Database connection + schema
        ├── posts.php          ← GET/PUT/DELETE posts
        ├── upload.php         ← POST image upload + OCR + structuring
        └── add-post.php       ← POST manual entry (no image)
```

## Configuration

All settings in `html/api/config.php`:

| Setting | Default | Description |
|---------|---------|-------------|
| `OLLAMA_URL` | `http://localhost:11434` | Ollama API endpoint |
| `VISION_MODEL` | `qwen2.5vl:3b` | Local model for OCR |
| `CARD_MODEL` | `xiaomi/mimo-v2.5` | OpenRouter model for card structuring |
| `OPENROUTER_API_KEY` | (required) | Your OpenRouter API key |
| `MAX_UPLOAD_SIZE` | `10MB` | Max file size |

## Changing Models

### Different local vision model
```php
define('VISION_MODEL', 'minicpm-v');  // or any Ollama vision model
```

### Different structuring model
```php
define('CARD_MODEL', 'anthropic/claude-3.5-sonnet');  // or any OpenRouter model
```

## Troubleshooting

**Uploads fail with 500 error:**
```bash
tail -f /var/log/apache2/error.log
systemctl status php8.3-fpm
```

**Ollama OCR timeout:**
- Check Ollama is running: `curl http://localhost:11434/api/tags`
- Check VRAM: `nvidia-smi`
- Try a smaller model if VRAM is tight

**Cards show "Image processing in progress...":**
- OpenRouter API key not set in config.php
- Check PHP error log for curl errors

**Posts not persisting:**
- Check `data/` directory is writable by www-data
- `sudo chown -R www-data:www-data data/`
