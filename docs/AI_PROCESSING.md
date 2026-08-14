# AI processing

AI is an assistive layer only.

## Abstraction

```
app/Services/AI/
  AIServiceInterface.php
  DocumentExtractionService.php
  ApplicantExtractionService.php
  ScreeningAnalysisService.php
```

## Rules

- Extract structured JSON with confidence scores
- Validate before persistence
- Never auto-reject or make hiring decisions
- Never invent qualifications or alter original documents/emails
- UI always separates **System Assessment** vs **Human Review**
- Human accept/edit/reject is audited
