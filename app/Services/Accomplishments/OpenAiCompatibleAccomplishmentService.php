<?php

namespace App\Services\Accomplishments;

use App\Contracts\AccomplishmentAiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAiCompatibleAccomplishmentService implements AccomplishmentAiService
{
    public function improve(string $text): string
    {
        $key = config('accomplishment.ai.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('AI enhancement is not configured. Set ACCOMPLISHMENT_AI_API_KEY.');
        }

        try {
            $response = Http::withToken($key)
                ->connectTimeout(5)
                ->timeout(30)
                ->post(rtrim(config('accomplishment.ai.endpoint'), '/').'/chat/completions', [
                    'model' => config('accomplishment.ai.model'),
                    'temperature' => (float) config('accomplishment.ai.temperature'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an assistant for writing official employee accomplishment reports. Correct grammar, spelling, clarity, and sentence structure. Write one concise, professional past-tense accomplishment statement. Preserve the original meaning. Do not invent accomplishments, technologies, results, dates, quantities, responsibilities, testing, or details not supplied by the user. If context is insufficient, only improve the wording. Return only the improved statement.'],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            $response->throw();
            $suggestion = trim((string) $response->json('choices.0.message.content'));

            if ($suggestion === '') {
                throw new RuntimeException('The AI provider returned an empty suggestion.');
            }

            return trim($suggestion, " \t\n\r\0\x0B\"“”");
        } catch (Throwable $exception) {
            Log::error('Accomplishment AI request failed', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
            throw new RuntimeException('AI enhancement is temporarily unavailable.', previous: $exception);
        }
    }
}
