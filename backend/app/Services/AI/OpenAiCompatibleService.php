<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiCompatibleService implements AIServiceInterface
{
    public function __construct(private readonly AiSettings $settings)
    {
    }

    public function providerName(): string
    {
        return $this->settings->provider();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function extract(array $payload): array
    {
        $response = Http::timeout($this->settings->timeout())
            ->withHeaders($this->headers())
            ->post($this->url(), [
                'model' => $this->settings->model(),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($payload)],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI provider returned HTTP '.$response->status());
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('AI provider returned an empty extraction.');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('AI provider returned invalid JSON.');
        }

        return $this->normalize($decoded);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $key = (string) $this->settings->apiKey();

        if ($this->settings->provider() === 'azure_openai') {
            return [
                'api-key' => $key,
                'Content-Type' => 'application/json',
            ];
        }

        return [
            'Authorization' => 'Bearer '.$key,
            'Content-Type' => 'application/json',
        ];
    }

    private function url(): string
    {
        if ($this->settings->provider() === 'azure_openai') {
            $endpoint = $this->settings->endpoint();
            if (! $endpoint) {
                throw new RuntimeException('AI_ENDPOINT is required for Azure OpenAI.');
            }

            return $endpoint.'/openai/deployments/'.$this->settings->model().'/chat/completions?api-version='.$this->settings->apiVersion();
        }

        return ($this->settings->endpoint() ?: 'https://api.openai.com/v1').'/chat/completions';
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract structured facts from a job application email for the Nursing Council of Kenya.
Return JSON only. Extract only values that appear in the source text.
Never invent names, qualifications, registration numbers, or experience.
Never recommend hire, reject, shortlist, or eligibility.
If a field is not clearly present, use null.
JSON shape:
{
  "confidence": 0.0,
  "applicant": {
    "full_name": null,
    "email": null,
    "phone": null,
    "registration_number": null
  },
  "position_hint": null,
  "keywords": [],
  "summary": ""
}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function userPrompt(array $payload): string
    {
        return json_encode([
            'subject' => $payload['subject'] ?? null,
            'sender_name' => $payload['sender_name'] ?? null,
            'sender_email' => $payload['sender_email'] ?? null,
            'body' => mb_substr((string) ($payload['body'] ?? $payload['body_text'] ?? ''), 0, 12000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function normalize(array $decoded): array
    {
        $applicant = is_array($decoded['applicant'] ?? null) ? $decoded['applicant'] : [];
        $keywords = $decoded['keywords'] ?? [];
        if (! is_array($keywords)) {
            $keywords = [];
        }

        $confidence = isset($decoded['confidence']) ? (float) $decoded['confidence'] : 0.5;

        return [
            'provider' => $this->providerName(),
            'status' => 'completed',
            'confidence' => max(0.0, min(1.0, round($confidence, 4))),
            'applicant' => [
                'full_name' => $this->nullableString($applicant['full_name'] ?? null),
                'email' => $this->nullableString($applicant['email'] ?? null),
                'phone' => $this->nullableString($applicant['phone'] ?? null),
                'registration_number' => $this->nullableString($applicant['registration_number'] ?? null),
            ],
            'position_hint' => $this->nullableString($decoded['position_hint'] ?? null),
            'keywords' => array_values(array_filter(array_map(
                fn ($word) => is_string($word) ? mb_strtolower(trim($word)) : '',
                $keywords
            ))),
            'summary' => $this->nullableString($decoded['summary'] ?? null) ?? 'Extraction completed.',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strcasecmp($trimmed, 'null') === 0) {
            return null;
        }

        return $trimmed;
    }
}
