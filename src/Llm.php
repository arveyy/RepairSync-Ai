<?php
declare(strict_types=1);

namespace RepairSync;

/**
 * Optional generative step of the RAG pipeline. With an Anthropic API key configured, the retrieved cases are sent
 * to Claude to write the technician summary. Ranking always comes from the retriever; the LLM only rewrites the
 * narrative and is told to use ONLY the supplied cases. On any error/timeout the deterministic summary is used.
 */
final class Llm
{
    public function __construct(private array $cfg) {}

    public function enabled(): bool { return trim((string)($this->cfg['anthropic_api_key'] ?? '')) !== ''; }

    /** @return array{text:string, mode:string} */
    public function summary(array $result, array $device, string $fallback): array
    {
        if (!$this->enabled() || $result['insufficient'] || !function_exists('curl_init')) return ['text' => $fallback, 'mode' => 'local'];
        $evidence = array_map(fn($r) => [
            'rank' => $r['rank'], 'diagnosis' => $r['diagnosis'], 'confidence' => $r['confidence'], 'root_cause' => $r['root_cause'],
            'suggested_checks' => $r['suggested_checks'], 'likely_repair' => $r['likely_repair'],
            'similar_past_cases' => array_map(fn($c) => $c['symptoms'], $r['supporting_cases']),
        ], $result['recommendations']);
        $dev = implode(' ', array_filter([$device['brand'] ?? null, $device['model'] ?? null, $device['deviceType'] ?? null]));
        $prompt = "You assist gadget repair technicians. Using ONLY the retrieved past repair cases below, write a concise (max 120 words) preliminary diagnostic summary for the technician.\n"
            . "Rules: do not invent causes, parts or prices that are not in the cases; keep the given ranking; state that the technician must verify by inspection; plain text only.\n\n"
            . "Device: $dev\nCustomer-reported symptoms: {$result['query']}\n\nRetrieved evidence (JSON):\n" . json_encode($evidence, JSON_UNESCAPED_UNICODE);
        try {
            $ch = curl_init($this->cfg['anthropic_api_url'] ?? 'https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $this->cfg['anthropic_api_key'], 'anthropic-version: 2023-06-01'],
                CURLOPT_POSTFIELDS => json_encode(['model' => $this->cfg['anthropic_model'] ?? 'claude-sonnet-5-5', 'max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) throw new \RuntimeException("LLM HTTP $code");
            $data = json_decode($body, true);
            $text = trim(implode('', array_map(fn($b) => $b['text'] ?? '', $data['content'] ?? [])));
            if ($text === '') throw new \RuntimeException('empty LLM response');
            return ['text' => $text, 'mode' => 'llm'];
        } catch (\Throwable $e) {
            error_log('[rag] LLM step failed, using local summary: ' . $e->getMessage());
            return ['text' => $fallback, 'mode' => 'local'];
        }
    }
}
