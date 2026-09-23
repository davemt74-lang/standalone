# Phase 53 — Research Desktop Files, Uploads & Recordings

Phase 53 makes files and voice recordings native durable objects on each Research Agent Desktop.

## Desktop uploads

The Desktop toolbar includes **+ Upload**. Users can also drag supported files from the operating system onto the Desktop or directly onto a folder icon. New objects are positioned near the drop point and inherit the Research Agent project and Team permissions.

Initial supported formats:

- PDF
- DOCX
- TXT
- Markdown
- CSV
- JPG / PNG / WebP
- MP3 / M4A / WAV / OGG / WebM audio

Original bytes are stored in private storage with MIME validation, size limits, SHA-256 checksums, permission-checked streaming, and immutable object identity.

Text-bearing files enter the Research file extraction queue. TXT, Markdown, and CSV are extracted natively; DOCX uses ZipArchive; PDF uses the configured PDF text command or local pdftotext when available. Images remain valid Research files even when no OCR/extraction provider is configured.

## Recordings and transcription

The Desktop toolbar includes **+ Recording**. The browser recording window supports:

- Record
- Pause / Resume
- Stop
- elapsed time
- live audio-level meter
- playback before saving
- title
- current Desktop folder destination
- Save & Transcribe

The original audio is saved first. A separate transcript record and transcription job are then queued using the existing provider-neutral transcription configuration. Uploaded audio files use the same recording/transcript pipeline.

A completed recording opens in a Desktop transcript window with audio playback, transcript text, processing status, and actions for Ask Agent, Summarize, Extract Tasks, Create Doc, Create Sticky, and Retry transcription.

## Research Agent intelligence

Ready uploaded text and ready recording transcripts participate in bounded Research Agent workspace retrieval. Explicit Ask Agent actions attach the authoritative upload/recording object to the message, producing live rich cards in the main Research Agent chat.

Workspace state, Desktop layout, and conversation handoff carry object references only; private file bytes and transcript/document bodies are not copied into layout state.

## Team model

The existing Research Agent project permissions remain authoritative. Owners, admins, and researchers can upload, record, move, trash, retry, and convert objects. Viewers may read/open permitted objects but cannot mutate the workspace. Removed Team members immediately lose access.

## Workers

Deployments should schedule these workers alongside existing Annotated workers:

- `worker/research-file-worker.php`
- `worker/research-transcription-worker.php`

The transcription worker uses the existing `transcription.command`, `transcription.provider`, and `transcription.model` configuration.
