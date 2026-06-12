# LinkedIn Feed Intel

AI-powered tool for curating LinkedIn screenshots into filterable knowledge cards. Local OCR extracts text from screenshots, MiMo structures them into categorized cards.

## Goals

- Upload LinkedIn screenshots and auto-extract post content
- Categorize posts (3DGS, VP, AI, Tools, Contacts)
- Filter and browse the curated feed
- Keep all image processing local (no images sent to cloud)

## In Progress

- [ ] Verify end-to-end upload flow with local OCR + MiMo
- [ ] Test with actual LinkedIn screenshots
- [ ] Optimize Ollama model loading (keep vision model warm)

## To Do

- [ ] Add search/filter by text content
- [ ] Export posts as JSON/CSV
- [ ] Add batch upload with progress indicator
- [ ] Duplicate detection via image similarity
- [ ] Add authentication for upload endpoint
- [ ] Mobile-responsive layout improvements
- [ ] Add API rate limiting

## Done

- [x] SQLite database with full CRUD
- [x] PHP backend with upload, read, update, delete endpoints
- [x] Frontend SPA with card grid, modal, filters, category chart
- [x] Dark theme with animated ambient effects
- [x] Duplicate detection dialog (client-side)
- [x] Local OCR via Ollama (qwen2.5vl:3b)
- [x] Card structuring via MiMo v2.5 (OpenRouter)
- [x] Removed direct Anthropic API calls from frontend
- [x] Fixed Authorization header bug in upload.php
- [x] GitHub repo created and pushed

## Blocked

-

## Releases

### v1.0 — Local OCR + MiMo Pipeline
- Replaced Claude-only API with hybrid: local Ollama vision + MiMo structuring
- Frontend no longer calls external APIs directly
- All processing happens server-side

### v0.1 — Initial Release
- Upload screenshots, manual card creation
- SQLite persistence, category filters
- Dark theme UI

## Notes

- RTX 4060 Ti (8GB VRAM) — qwen2.5vl:3b uses ~2GB, leaves room for other models
- OpenRouter key required for MiMo structuring step
- Ollama must be running locally for OCR
- Database is at `data/posts.db` (outside webroot)
- Config at `html/api/config.php` — change VISION_MODEL and CARD_MODEL to swap models
