# AI processing

AI is an assistive layer only. It never auto-rejects, shortlists, or changes eligibility.

## What it does

1. Reads the application email (and already extracted profile text).
2. Pulls structured facts that already appear in that text: name, email, phone, NCK registration, vacancy hint, keywords.
3. Stores a **system assessment** on `ai_extractions` with a confidence score.
4. Officers **accept**, **edit**, or **reject** it. Accept fills **empty** applicant fields only.

## Provider abstraction

```
app/Services/AI/
  AIServiceInterface.php
  AiServiceFactory.php
  MockAIService.php              # regex / keyword extraction (default)
  OpenAiCompatibleService.php    # OpenAI or Azure OpenAI when credentials exist
  ApplicationAiProcessor.php
  AiSettings.php
```

`AI_PROVIDER=mock` (default) uses `MockAIService`.  
`openai` or `azure_openai` is used only when `AI_API_KEY` is set (and `AI_ENDPOINT` for Azure).

## Settings

| Key | Source | Effect |
|-----|--------|--------|
| `ai_enabled` | Settings UI / `AI_ENABLED` | Auto-queue on new mailbox/MyJobs applications |
| `ai_confidence_threshold` | Settings UI / env | Marks low-confidence assessments for review |
| `AI_PROVIDER` | `.env` | `mock`, `openai`, `azure_openai` |

Manual **Run assessment** on an application works even when auto-queue is off.

## Rules

- Extract structured JSON with confidence scores
- Validate before persistence
- Never auto-reject or make hiring decisions
- Never invent qualifications or alter original documents/emails
- UI always separates **System assessment** vs **Human review**
- Human accept/edit/reject is audited (`ai.extraction_reviewed`)

## Operations

```bash
# Backfill applications that have no assessment yet (requires ai_enabled, or pass --force)
php artisan ai:process-applications --limit=50

# One application
php artisan ai:process-applications --id=123 --force
```

Scheduled every 5 minutes when `ai_enabled` is true. Queue worker must include the `default` queue.
